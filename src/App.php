<?php
declare(strict_types=1);

namespace RacunatorPanel;

use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Auth\Authorization;
use RacunatorPanel\Auth\Guard;
use RacunatorPanel\Auth\NativeSession;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\RateLimiter;
use RacunatorPanel\Auth\SessionGuard;
use RacunatorPanel\Auth\SessionStore;
use RacunatorPanel\Controllers\ClientApiController;
use RacunatorPanel\Db\Connection;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Kernel\Router;
use RacunatorPanel\Kernel\View;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\ClientRepository;
use RacunatorPanel\Repositories\PdoAuditLogRepository;
use RacunatorPanel\Repositories\PdoClientRepository;
use RacunatorPanel\Repositories\PdoReceiptRepository;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Repositories\PdoUserRepository;
use RacunatorPanel\Repositories\ReceiptRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Repositories\UserRepository;

/**
 * Sastavljanje aplikacije: konfiguracija, baza, repozitorijumi, prijava, šabloni, ruter i middleware.
 * Rute panela i ažuriranja registruju `Routes\PanelRoutes::register($app)` i `Routes\UpdateRoutes::register($app)` ako postoje.
 */
final class App
{
    /** Putanje koje rade i u režimu održavanja (provera zdravlja, prijava i stranica ažuriranja radi oporavka). */
    private const MAINTENANCE_ALLOWED = ['#^/health$#', '#^/prijava$#', '#^/odjava$#', '#^/azuriranje(/.*)?$#'];

    private function __construct(
        public readonly string $root,
        public readonly string $version,
        public readonly Config $config,
        public readonly \PDO $db,
        public readonly UserRepository $users,
        public readonly ClientRepository $clients,
        public readonly ReceiptRepository $receipts,
        public readonly SettingsRepository $settings,
        public readonly AuditLogRepository $audit,
        public readonly SessionStore $session,
        public readonly Guard $guard,
        public readonly View $view,
        public readonly Router $router,
    ) {
    }

    /** Učitava `$root/config.php`, otvara bazu i sastavlja aplikaciju. */
    public static function boot(string $root): self
    {
        $root = rtrim($root, '/');
        $config = Config::load($root . '/config.php');
        $pdo = Connection::open((array) $config->get('db', []));
        return self::create($root, $config, $pdo);
    }

    /** Sastavljanje bez čitanja config.php — za testove i instaler (npr. SQLite u memoriji i ArraySession). */
    public static function create(string $root, Config $config, \PDO $pdo, ?SessionStore $session = null): self
    {
        $root = rtrim($root, '/');
        $version = is_file($root . '/src/version.php') ? (string) (require $root . '/src/version.php') : '0.0.0';
        $basePath = rtrim((string) (parse_url($config->publicUrl(), PHP_URL_PATH) ?? ''), '/');
        Authorization::setBasePath($basePath);

        $users = new PdoUserRepository($pdo);
        $audit = new PdoAuditLogRepository($pdo);
        $settings = new PdoSettingsRepository($pdo);
        $session ??= new NativeSession(
            str_starts_with($config->publicUrl(), 'https://'),
            $basePath === '' ? '/' : $basePath . '/',
            $config->storagePath() . '/tmp/sessions',
        );
        $guard = new SessionGuard($users, $audit, new RateLimiter($pdo), new PasswordHasher(), $session, $settings);

        $app = new self(
            $root,
            $version,
            $config,
            $pdo,
            $users,
            new PdoClientRepository($pdo),
            new PdoReceiptRepository($pdo),
            $settings,
            $audit,
            $session,
            $guard,
            new View($root . '/src/Views'),
            new Router(),
        );
        $app->registerMiddleware();
        $app->registerCoreRoutes();
        if (class_exists(\RacunatorPanel\Routes\PanelRoutes::class)) {
            \RacunatorPanel\Routes\PanelRoutes::register($app);
        }
        if (class_exists(\RacunatorPanel\Routes\UpdateRoutes::class)) {
            \RacunatorPanel\Routes\UpdateRoutes::register($app);
        }
        return $app;
    }

    public function handle(Request $r): Response
    {
        if ($this->session instanceof NativeSession) {
            $this->session->setSecure($r->https || str_starts_with($this->config->publicUrl(), 'https://'));
        }
        return $this->router->dispatch($r);
    }

    /** Apsolutna putanja unutar panela za linkove i preusmerenja, npr. url('/prijava') → '/panel/prijava'. */
    public function url(string $path = '/'): string
    {
        return Authorization::basePath() . '/' . ltrim($path, '/');
    }

    /** Putanje do oznake režima održavanja (`storage/maintenance.flag`). */
    public function maintenanceFlags(): array
    {
        return array_values(array_unique([
            $this->root . '/storage/maintenance.flag',
            $this->config->storagePath() . '/maintenance.flag',
        ]));
    }

    private function registerMiddleware(): void
    {
        // 1) Bezbednosna zaglavlja na svaki odgovor (kontroler može da postavi svoje vrednosti — one imaju prednost).
        $this->router->use(static function (Request $r, callable $next): Response {
            $response = $next($r);
            $headers = [
                'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                    . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'same-origin',
                'Cross-Origin-Opener-Policy' => 'same-origin',
                'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            ];
            if ($r->https) {
                $headers['Strict-Transport-Security'] = 'max-age=31536000';
            }
            $existing = array_change_key_case($response->headers, CASE_LOWER);
            foreach ($headers as $name => $value) {
                if (!array_key_exists(strtolower($name), $existing)) {
                    $response = $response->withHeader($name, $value);
                }
            }
            return $response;
        });

        // 2) Režim održavanja (tokom ažuriranja): 503 + Retry-After.
        $this->router->use(function (Request $r, callable $next): Response {
            if (!$this->inMaintenance() || self::allowedInMaintenance($r->path)) {
                return $next($r);
            }
            $headers = ['Retry-After' => '120', 'Cache-Control' => 'no-store'];
            if (str_starts_with($r->path, '/u/') || str_starts_with($r->path, '/api/')) {
                return Response::json(
                    ['error' => 'maintenance', 'message' => 'Panel se ažurira. Pokušajte ponovo za nekoliko minuta.'],
                    503,
                    $headers + ['X-Racunator-Api' => ClientApiController::API_VERSION],
                );
            }
            return Response::html(
                '<!doctype html><html lang="sr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
                . '<title>Ažuriranje u toku</title></head><body><h1>Ažuriranje u toku</h1>'
                . '<p>Panel se trenutno ažurira. Pokušajte ponovo za nekoliko minuta.</p></body></html>',
                503,
                $headers,
            );
        });
    }

    private function registerCoreRoutes(): void
    {
        $this->router->get('/health', function (): Response {
            try {
                $this->db->query('SELECT 1')->fetchAll();
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
            return Response::json(['ok' => $ok, 'version' => $this->version], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
        });

        $api = new ClientApiController($this->clients, $this->receipts, $this->settings, new ApiRateLimiter($this->db), $this->db);
        $this->router->get('/u/{slug}/ping', [$api, 'ping']);
        $this->router->post('/u/{slug}/receipts', [$api, 'receipts']);
    }

    private function inMaintenance(): bool
    {
        foreach ($this->maintenanceFlags() as $flag) {
            if (is_file($flag)) {
                return true;
            }
        }
        return false;
    }

    private static function allowedInMaintenance(string $path): bool
    {
        foreach (self::MAINTENANCE_ALLOWED as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }
        return false;
    }
}
