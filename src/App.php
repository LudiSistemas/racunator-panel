<?php
declare(strict_types=1);

namespace RacunatorPanel;

use RacunatorPanel\Auth\ApiDailyLimiter;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Auth\Authorization;
use RacunatorPanel\Auth\Guard;
use RacunatorPanel\Auth\NativeSession;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\RateLimiter;
use RacunatorPanel\Auth\SecretCipher;
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
use RacunatorPanel\Services\Maintenance;

/**
 * Sastavljanje aplikacije: konfiguracija, baza, repozitorijumi, prijava, šabloni, ruter i middleware.
 * Rute panela i ažuriranja registruju `Routes\PanelRoutes::register($app)` i `Routes\UpdateRoutes::register($app)` ako postoje.
 *
 * Opcioni ključevi u config.php (pored onih iz Kernel\Config):
 *   'trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32'], // proksiji kojima se veruje za X-Forwarded-For/-Proto (podraz. nijedan)
 *   'force_https'     => true,                           // HTTP → HTTPS preusmerenje u aplikaciji (podraz. false; /health izuzet)
 */
final class App
{
    /** Zaglavlje kojim samoprovera (instaler, ažuriranje) traži verziju od /health; vrednost je `healthToken()`. */
    public const HEALTH_TOKEN_HEADER = 'X-Racunator-Health-Token';

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

        $users = new PdoUserRepository($pdo, SecretCipher::fromAppKey((string) $config->get('app_key', '')));
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

    /** CIDR opsezi pouzdanih proksija iz config `trusted_proxies` (niz ili tekst razdvojen zarezima). @return list<string> */
    public function trustedProxies(): array
    {
        $value = $this->config->get('trusted_proxies', []);
        $list = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $list), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Tajna vrednost zaglavlja HEALTH_TOKEN_HEADER: uz nju /health vraća i verziju (samoprovera posle ažuriranja).
     * Izvedena iz app_key (HMAC), pa sam app_key ne putuje mrežom. Prazan string ako app_key nije podešen.
     */
    public static function healthToken(string $appKey): string
    {
        return $appKey === '' ? '' : hash_hmac('sha256', 'racunator-panel/health', $appKey);
    }

    /** Odgovor 413 za telo veće od Request::MAX_BODY (public/index.php ga šalje pre čitanja tela). */
    public static function payloadTooLarge(): Response
    {
        return Response::json(
            ['error' => 'payload_too_large', 'message' => 'Telo zahteva je veće od 1 MB. Pošaljite račune u manjim paketima.'],
            413,
            ['X-Racunator-Api' => ClientApiController::API_VERSION, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
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

        // 2) Opciono: HTTP → HTTPS (config `force_https`). /health je izuzet (samoprovera može da ide preko HTTP-a).
        $this->router->use(function (Request $r, callable $next): Response {
            $force = filter_var($this->config->get('force_https', false), FILTER_VALIDATE_BOOLEAN);
            if ($r->https || $r->path === '/health' || !$force) {
                return $next($r);
            }
            $host = (string) preg_replace('#^https?://#i', '', $this->config->publicUrl());
            $base = (string) parse_url($this->config->publicUrl(), PHP_URL_PATH);
            $host = $base !== '' && str_ends_with($host, $base) ? substr($host, 0, -strlen($base)) : $host;
            if ($host === '') {
                return $next($r);
            }
            $query = $r->query !== [] ? '?' . http_build_query($r->query, '', '&', PHP_QUERY_RFC3986) : '';
            $target = 'https://' . $host . $this->url($r->path) . $query;
            return Response::redirect($target, in_array($r->method, ['GET', 'HEAD'], true) ? 301 : 308);
        });

        // 3) Režim održavanja (tokom ažuriranja): 503 + Retry-After.
        $this->router->use(function (Request $r, callable $next): Response {
            $storage = $this->activeMaintenanceStorage();
            if ($storage === null || self::allowedInMaintenance($r->path)) {
                return $next($r);
            }
            $headers = ['Retry-After' => (string) Maintenance::retryAfter($storage), 'Cache-Control' => 'no-store'];
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
        // Verzija samo prijavljenom administratoru ili uz tajno zaglavlje samoprovere — da se tačna verzija (i time
        // poznati propusti) ne otkriva svakome.
        $this->router->get('/health', function (Request $r): Response {
            try {
                $this->db->query('SELECT 1')->fetchAll();
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
            $data = ['ok' => $ok];
            if ($this->maySeeVersion($r, $ok)) {
                $data['version'] = $this->version;
            }
            return Response::json($data, $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
        });

        $api = new ClientApiController($this->clients, $this->receipts, $this->settings, new ApiRateLimiter($this->db), $this->db,
            new ApiDailyLimiter($this->db), $this->audit);
        $this->router->get('/u/{slug}/ping', [$api, 'ping']);
        $this->router->post('/u/{slug}/receipts', [$api, 'receipts']);
    }

    private function maySeeVersion(Request $r, bool $dbOk): bool
    {
        $expected = self::healthToken((string) $this->config->get('app_key', ''));
        $given = $r->header(self::HEALTH_TOKEN_HEADER) ?? '';
        if ($expected !== '' && $given !== '' && hash_equals($expected, $given)) {
            return true;
        }
        if (!$dbOk) {
            return false;
        }
        try {
            return $this->guard->user()?->isAdmin() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Direktorijum sa važećom zastavicom održavanja ili null. Rok (`until`) se poštuje preko `Maintenance::isActive()`,
     * pa zastavica zaostala posle pada procesa usred ažuriranja ne blokira API i panel zauvek.
     */
    private function activeMaintenanceStorage(): ?string
    {
        foreach ($this->maintenanceFlags() as $flag) {
            $storage = dirname($flag);
            if (Maintenance::isActive($storage)) {
                return $storage;
            }
        }
        return null;
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
