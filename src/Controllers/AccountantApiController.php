<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers;

use RacunatorPanel\Auth\ApiAuthFailLimiter;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Controllers\Panel\Format;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Kernel\Router;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\ClientRepository;
use RacunatorPanel\Repositories\PdoReceiptRepository;
use RacunatorPanel\Repositories\ReceiptRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Services\KeyGenerator;
use RacunatorPanel\Services\ReceiptCsv;
use RacunatorPanel\Services\ReceiptJson;

/**
 * API za knjigovođu (v1, samo čitanje, docs/api.md „API za knjigovođu"), osnovna adresa `/api/{slug}/v1`:
 *   GET /ping                                         — provera ključa;
 *   GET /clients                                      — korisnici;
 *   GET /clients/{id}/receipts?month=YYYY-MM&page=&perPage= — računi korisnika po mesecu, sa stranicama;
 *   GET /receipts/{id}[?raw=1]                        — jedan račun;
 *   GET /export?month=YYYY-MM&format=csv|json[&clientId=] — izvoz meseca (jedan ili svi korisnici).
 *
 * Uključuje se i podešava u panelu (settings `accountant_api_enabled`, `accountant_api_slug`,
 * `accountant_api_key_prefix`, `accountant_api_key_hash`). Autentifikacija: `Authorization: Bearer <ključ>`; isključen
 * API, pogrešan slug i pogrešan ključ daju isti 401. Posle ApiAuthFailLimiter::MAX_FAILS promašaja sa iste adrese u
 * 15 minuta adresa je privremeno blokirana (429 `auth_blocked`); promašaji se upisuju u dnevnik radnji
 * (`api.accountant_auth_fail`). Ispravan ključ: najviše RATE_LIMIT zahteva u minutu (ApiRateLimiter, 429 `rate_limited`).
 */
final class AccountantApiController
{
    public const API_VERSION = '1';
    public const RATE_LIMIT = 120;
    /** Red u `api_rate` za ključ knjigovođe (ID-jevi korisnika počinju od 1, pa nema sudara). */
    public const RATE_ID = 0;
    public const PER_PAGE_DEFAULT = 100;
    public const PER_PAGE_MAX = 500;
    /** Najviše računa u jednom izvozu (svi korisnici zajedno). */
    public const EXPORT_MAX = ReceiptCsv::MAX_ROWS;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ReceiptRepository $receipts,
        private readonly SettingsRepository $settings,
        private readonly ApiRateLimiter $limiter,
        private readonly ApiAuthFailLimiter $fails,
        private readonly ?AuditLogRepository $audit = null,
    ) {
    }

    /** Registracija ruta (App::registerCoreRoutes i testovi). */
    public function register(Router $router): void
    {
        $router->get('/api/{slug}/v1/ping', $this->guarded($this->ping(...)));
        $router->get('/api/{slug}/v1/clients', $this->guarded($this->clientList(...)));
        $router->get('/api/{slug}/v1/clients/{id}/receipts', $this->guarded($this->clientReceipts(...)));
        $router->get('/api/{slug}/v1/receipts/{id}', $this->guarded($this->receipt(...)));
        $router->get('/api/{slug}/v1/export', $this->guarded($this->export(...)));
        // Nepoznata putanja ili metoda pod /api/ dobija JSON grešku umesto tekstualne stranice.
        $router->use(static function (Request $r, callable $next): Response {
            $response = $next($r);
            if (!str_starts_with($r->path, '/api/') || !in_array($response->status, [404, 405], true)
                || !str_starts_with($response->headers['Content-Type'] ?? '', 'text/plain')) {
                return $response;
            }
            return $response->status === 405
                ? self::error(405, 'method_not_allowed', 'API za knjigovođu podržava samo čitanje (GET).', ['Allow' => 'GET'])
                : self::error(404, 'not_found', 'Nepoznata putanja API-ja.');
        });
    }

    /**
     * Zajednički tok svakog zahteva: blokada adrese → provera ključa → ograničenje broja zahteva → handler.
     * @param \Closure(Request,array<string,string>):Response $handler
     */
    private function guarded(\Closure $handler): \Closure
    {
        return function (Request $request, array $params) use ($handler): Response {
            if (($wait = $this->fails->secondsUntilAllowed($request->ip)) !== null) {
                return self::error(429, 'auth_blocked',
                    'Previše neuspelih pokušaja sa ove adrese. Pokušajte ponovo za ' . $wait . ' s.', ['Retry-After' => (string) $wait]);
            }
            if (!$this->authenticate($request, (string) ($params['slug'] ?? ''))) {
                $count = $this->fails->hit($request->ip);
                $details = $request->method . ' ' . self::pathForLog($request->path)
                    . ($count >= ApiAuthFailLimiter::MAX_FAILS ? ' — adresa privremeno blokirana' : '');
                $this->audit?->log(null, 'api.accountant_auth_fail', 'settings', null, $request->ip, $details);
                return self::error(401, 'unauthorized', 'Neispravan ključ ili adresa API-ja.', ['WWW-Authenticate' => 'Bearer']);
            }
            $this->fails->clear($request->ip);
            if (($wait = $this->limiter->hit(self::RATE_ID)) !== null) {
                return self::error(429, 'rate_limited', 'Previše zahteva. Pokušajte ponovo za ' . $wait . ' s.', ['Retry-After' => (string) $wait]);
            }
            return $handler($request, $params);
        };
    }

    /** @param array<string,string> $params */
    public function ping(Request $request, array $params): Response
    {
        return self::json([
            'ok' => true,
            'accountant' => (string) $this->settings->get('accountant_name', ''),
            'api' => (int) self::API_VERSION,
        ]);
    }

    /** @param array<string,string> $params */
    public function clientList(Request $request, array $params): Response
    {
        $items = [];
        foreach ($this->clients->all() as $client) {
            $count = 0;
            foreach ($this->receipts->monthlySummary($client->id) as $month) {
                $count += $month['count'];
            }
            $items[] = ReceiptJson::client($client, $count);
        }
        return self::json(['items' => $items]);
    }

    /** @param array<string,string> $params */
    public function clientReceipts(Request $request, array $params): Response
    {
        $client = $this->findClient($params['id'] ?? '');
        if ($client === null) {
            return self::error(404, 'not_found', 'Korisnik ne postoji.');
        }
        $month = self::query($request, 'month');
        if (!Format::isMonth($month)) {
            return self::invalidMonth();
        }
        $page = self::intQuery($request, 'page', 1);
        $perPage = self::intQuery($request, 'perPage', self::PER_PAGE_DEFAULT);
        if ($page === null || $page < 1 || $page > 1000000) {
            return self::error(400, 'invalid_parameter', 'page: očekuje se ceo broj ≥ 1.');
        }
        if ($perPage === null || $perPage < 1 || $perPage > self::PER_PAGE_MAX) {
            return self::error(400, 'invalid_parameter', 'perPage: očekuje se ceo broj od 1 do ' . self::PER_PAGE_MAX . '.');
        }
        $total = $this->receipts->monthlySummary($client->id)[$month]['count'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $items = $offset < $total ? $this->receipts->forClientMonth($client->id, $month, $perPage, $offset) : [];
        return self::json([
            'items' => array_map([ReceiptJson::class, 'fromModel'], $items),
            'month' => $month,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ]);
    }

    /** @param array<string,string> $params */
    public function receipt(Request $request, array $params): Response
    {
        $id = self::id($params['id'] ?? '');
        $receipt = $id === null ? null : $this->receipts->findById($id);
        if ($receipt === null || $this->clients->findById($receipt->clientId) === null) {
            return self::error(404, 'not_found', 'Račun ne postoji.');
        }
        $data = ReceiptJson::fromModel($receipt);
        if (self::query($request, 'raw') === '1') {
            $data['rawJson'] = $this->receipts instanceof PdoReceiptRepository ? $this->receipts->rawJson($receipt->id) : null;
        }
        return self::json($data);
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params): Response
    {
        $month = self::query($request, 'month');
        if (!Format::isMonth($month)) {
            return self::invalidMonth();
        }
        $format = self::query($request, 'format');
        $format = $format === '' ? 'csv' : $format;
        if (!in_array($format, ['csv', 'json'], true)) {
            return self::error(400, 'invalid_parameter', 'format: dozvoljeno je csv ili json.');
        }
        $clientIdRaw = self::query($request, 'clientId');
        if ($clientIdRaw !== '') {
            $client = $this->findClient($clientIdRaw);
            if ($client === null) {
                return self::error(404, 'not_found', 'Korisnik ne postoji.');
            }
            $clients = [$client];
        } else {
            $clients = $this->clients->all();
        }

        $receipts = [];
        $names = [];
        foreach ($clients as $client) {
            $names[$client->id] = $client->name;
            $left = self::EXPORT_MAX - count($receipts) + 1;
            foreach (ReceiptCsv::allForMonth($this->receipts, $client->id, $month, $left) as $receipt) {
                $receipts[] = $receipt;
            }
            if (count($receipts) > self::EXPORT_MAX) {
                return self::error(413, 'export_too_large', 'Izvoz bi imao više od ' . self::EXPORT_MAX
                    . ' računa. Izvezite korisnike pojedinačno (clientId).');
            }
        }
        $this->audit?->log(null, 'api.accountant_export', $clientIdRaw !== '' ? 'client' : null,
            $clientIdRaw !== '' ? $clients[0]->id : null, $request->ip, $month . ' ' . $format . ' (' . count($receipts) . ')');

        if ($format === 'json') {
            return self::json(array_map([ReceiptJson::class, 'fromModel'], $receipts));
        }
        $filename = 'racuni-' . ($clientIdRaw !== '' ? 'korisnik-' . $clients[0]->id . '-' : '') . $month . '.csv';
        return new Response(200, ReceiptCsv::build($receipts, $clientIdRaw !== '' ? null : $names), [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ] + self::apiHeaders());
    }

    /**
     * Ključ iz zaglavlja → poređenje slug-a, prefiksa i heša u konstantnom vremenu. Heš se računa uvek, da vreme odgovora
     * ne otkriva da li je API uključen ni da li je slug tačan.
     */
    private function authenticate(Request $request, string $slug): bool
    {
        $token = $request->bearerToken() ?? '';
        $hash = KeyGenerator::hash($token);
        $enabled = $this->settings->get('accountant_api_enabled') === '1';
        $expectedSlug = (string) $this->settings->get('accountant_api_slug', '');
        $expectedPrefix = (string) $this->settings->get('accountant_api_key_prefix', '');
        $expectedHash = (string) $this->settings->get('accountant_api_key_hash', '');
        $slugOk = preg_match('/^[0-9a-f]{32}$/', $slug) === 1 && preg_match('/^[0-9a-f]{32}$/', $expectedSlug) === 1
            && hash_equals($expectedSlug, $slug);
        $prefixOk = $expectedPrefix !== '' && hash_equals($expectedPrefix, KeyGenerator::prefix($token));
        $hashOk = $expectedHash !== '' && hash_equals($expectedHash, $hash);
        return $enabled && $slugOk && KeyGenerator::looksLikeKey($token) && $prefixOk && $hashOk;
    }

    private function findClient(string $raw): ?Client
    {
        $id = self::id($raw);
        return $id === null ? null : $this->clients->findById($id);
    }

    private static function id(string $raw): ?int
    {
        return preg_match('/^[1-9]\d{0,17}$/', $raw) ? (int) $raw : null;
    }

    private static function query(Request $request, string $name): string
    {
        $value = $request->query[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** Ceo broj iz upita; podrazumevana vrednost ako ga nema, null ako nije ceo broj. */
    private static function intQuery(Request $request, string $name, int $default): ?int
    {
        $value = self::query($request, $name);
        if ($value === '') {
            return $default;
        }
        return preg_match('/^\d{1,9}$/', $value) ? (int) $value : null;
    }

    /** Putanja za dnevnik bez slug-a (slug nije tajna, ali nema potrebe da se gomila u dnevniku). */
    private static function pathForLog(string $path): string
    {
        return mb_substr((string) preg_replace('#^/api/[^/]*/#', '/api/…/', $path), 0, 120, 'UTF-8');
    }

    private static function invalidMonth(): Response
    {
        return self::error(400, 'invalid_parameter', 'month: obavezan, oblik YYYY-MM (npr. 2026-10).');
    }

    /** @return array<string,string> */
    private static function apiHeaders(): array
    {
        return ['X-Racunator-Api' => self::API_VERSION, 'Cache-Control' => 'no-store'];
    }

    private static function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers + self::apiHeaders());
    }

    private static function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return self::json(['error' => $code, 'message' => $message], $status, $headers);
    }
}
