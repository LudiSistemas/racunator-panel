<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Api;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\App;
use RacunatorPanel\Auth\ApiAuthFailLimiter;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Auth\RateLimiter;
use RacunatorPanel\Controllers\AccountantApiController;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Kernel\Router;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Repositories\PdoAuditLogRepository;
use RacunatorPanel\Repositories\PdoClientRepository;
use RacunatorPanel\Repositories\PdoReceiptRepository;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Services\KeyGenerator;
use RacunatorPanel\Tests\Support\TestDb;

final class AccountantApiTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;
    private PdoClientRepository $clients;
    private PdoReceiptRepository $receipts;
    private PdoSettingsRepository $settings;
    private PdoAuditLogRepository $audit;
    private Client $pera;
    private Client $mika;
    private string $slug;
    private string $key;
    private int $now = 1_800_000_000;
    private int $rateLimit = 120;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo();
        $this->clients = new PdoClientRepository($this->pdo);
        $this->receipts = new PdoReceiptRepository($this->pdo);
        $this->settings = new PdoSettingsRepository($this->pdo);
        $this->audit = new PdoAuditLogRepository($this->pdo);
        $this->settings->set('accountant_name', 'Knjigovodstvo XY');
        $this->enableApi();
        $this->pera = $this->makeClient('Pera DOO');
        $this->mika = $this->makeClient('=Mika preduzetnik');
        $this->buildRouter();
    }

    private function enableApi(): void
    {
        $this->slug = KeyGenerator::slug();
        $this->key = KeyGenerator::apiKey();
        $this->settings->set('accountant_api_enabled', '1');
        $this->settings->set('accountant_api_slug', $this->slug);
        $this->settings->set('accountant_api_key_prefix', KeyGenerator::prefix($this->key));
        $this->settings->set('accountant_api_key_hash', KeyGenerator::hash($this->key));
    }

    private function makeClient(string $name): Client
    {
        $key = KeyGenerator::apiKey();
        return $this->clients->create($name, KeyGenerator::slug(), KeyGenerator::prefix($key), KeyGenerator::hash($key), null);
    }

    private function buildRouter(): void
    {
        $clock = fn (): int => $this->now;
        $this->router = new Router();
        (new AccountantApiController(
            $this->clients,
            $this->receipts,
            $this->settings,
            new ApiRateLimiter($this->pdo, $this->rateLimit, $clock),
            new ApiAuthFailLimiter($this->pdo, $clock),
            $this->audit,
        ))->register($this->router);
    }

    /** @param array<string,string> $query */
    private function call(string $path, array $query = [], ?string $key = null, ?string $slug = null, string $ip = '203.0.113.5', string $method = 'GET'): Response
    {
        $headers = $key === '' ? [] : ['authorization' => 'Bearer ' . ($key ?? $this->key)];
        return $this->router->dispatch(new Request($method, '/api/' . ($slug ?? $this->slug) . '/v1' . $path, $query,
            headers: $headers, ip: $ip));
    }

    private static function data(Response $r): array
    {
        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function addReceipt(Client $client, string $invoice, string $sdc, int $amount = 123456, array $override = []): void
    {
        $data = $override + [
            'invoiceNumber' => $invoice,
            'verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=' . rawurlencode(base64_encode($invoice)),
            'sdcDateTime' => $sdc,
            'totalAmountMinor' => $amount,
            'sellerName' => 'ZORA MARKET DOO',
            'sellerPib' => '100000000',
            'buyerPib' => '101234567',
            'invoiceType' => 'Normal',
            'transactionType' => 'Sale',
        ];
        $data['rawJson'] ??= json_encode(['invoiceNumber' => $invoice, 'extra' => 'izvorno']);
        self::assertTrue($this->receipts->insertIfNew($client->id, $data));
    }

    private static function assertApiHeaders(Response $r): void
    {
        self::assertSame('1', $r->headers['X-Racunator-Api'] ?? null);
        self::assertSame('no-store', $r->headers['Cache-Control'] ?? null);
    }

    private function auditActions(): array
    {
        return array_column($this->audit->recent(1000), 'action');
    }

    public function testPing(): void
    {
        $r = $this->call('/ping');
        self::assertSame(200, $r->status);
        self::assertApiHeaders($r);
        self::assertStringStartsWith('application/json', $r->headers['Content-Type']);
        self::assertSame(['ok' => true, 'accountant' => 'Knjigovodstvo XY', 'api' => 1], self::data($r));
    }

    public function testIsti401ZaSveGreskeAutentifikacije(): void
    {
        $other = KeyGenerator::apiKey();
        $cases = [
            'pogrešan ključ' => $this->call('/ping', key: KeyGenerator::apiKey()),
            'bez zaglavlja' => $this->call('/ping', key: ''),
            'pogrešan oblik' => $this->call('/ping', key: 'nije-kljuc'),
            'isti prefiks, drugi ključ' => $this->call('/ping', key: substr($this->key, 0, 11) . substr($other, 11)),
            'pogrešan slug' => $this->call('/ping', slug: KeyGenerator::slug()),
            'neispravan slug' => $this->call('/clients', slug: 'xyz'),
            'ključ korisnika umesto knjigovođe' => $this->call('/clients', key: $other),
        ];
        $this->settings->set('accountant_api_enabled', '0');
        $cases['isključen API'] = $this->call('/ping');
        $cases['isključen API, export'] = $this->call('/export', ['month' => '2026-10']);
        foreach ($cases as $name => $r) {
            self::assertSame(401, $r->status, $name);
            self::assertSame(['error' => 'unauthorized', 'message' => 'Neispravan ključ ili adresa API-ja.'], self::data($r), $name);
            self::assertSame('Bearer', $r->headers['WWW-Authenticate'], $name);
            self::assertApiHeaders($r);
        }
    }

    public function testIskljucenPaPonovoUkljucenImaNovuAdresuIKljuc(): void
    {
        $oldSlug = $this->slug;
        $oldKey = $this->key;
        // Isključivanje u panelu briše heš i prefiks.
        $this->settings->set('accountant_api_enabled', '0');
        $this->settings->delete('accountant_api_key_hash');
        $this->settings->delete('accountant_api_key_prefix');
        self::assertSame(401, $this->call('/ping')->status);
        $this->settings->set('accountant_api_enabled', '1');
        self::assertSame(401, $this->call('/ping')->status, 'bez ključa u podešavanjima nema pristupa');
        $this->enableApi();
        self::assertSame(401, $this->call('/ping', key: $oldKey, slug: $oldSlug)->status);
        self::assertSame(401, $this->call('/ping', key: $oldKey)->status);
        self::assertSame(200, $this->call('/ping')->status);
    }

    public function testKlijenti(): void
    {
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-1', '2026-10-01T08:00:00');
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-2', '2026-09-30T23:59:59');
        $this->clients->setActive($this->mika->id, false);
        $this->clients->touchSeen($this->pera->id);

        $r = $this->call('/clients');
        self::assertSame(200, $r->status);
        self::assertApiHeaders($r);
        $items = self::data($r)['items'];
        self::assertCount(2, $items);
        $byName = array_column($items, null, 'name');
        $pera = $byName['Pera DOO'];
        self::assertSame(['id', 'name', 'active', 'createdAt', 'lastSeenAt', 'receiptsCount'], array_keys($pera));
        self::assertSame($this->pera->id, $pera['id']);
        self::assertTrue($pera['active']);
        self::assertSame(2, $pera['receiptsCount']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $pera['createdAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $pera['lastSeenAt']);
        $mika = $byName['=Mika preduzetnik'];
        self::assertFalse($mika['active']);
        self::assertNull($mika['lastSeenAt']);
        self::assertSame(0, $mika['receiptsCount']);
        self::assertArrayNotHasKey('slug', $pera, 'adresa i ključ korisnika se ne otkrivaju');
    }

    public function testRacuniPoMesecuIStranice(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-' . $i, sprintf('2026-10-%02dT10:00:00', $i), 100 * $i);
        }
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-99', '2026-11-01T00:00:00');
        $this->addReceipt($this->mika, 'CCCCCCCC-DDDDDDDD-1', '2026-10-03T10:00:00');

        $r = $this->call('/clients/' . $this->pera->id . '/receipts', ['month' => '2026-10', 'perPage' => '2', 'page' => '2']);
        self::assertSame(200, $r->status);
        self::assertApiHeaders($r);
        $d = self::data($r);
        self::assertSame(['items', 'month', 'page', 'perPage', 'total'], array_keys($d));
        self::assertSame(['2026-10', 2, 2, 5], [$d['month'], $d['page'], $d['perPage'], $d['total']]);
        self::assertSame(['AAAAAAAA-BBBBBBBB-3', 'AAAAAAAA-BBBBBBBB-4'], array_column($d['items'], 'invoiceNumber'));
        $first = $d['items'][0];
        self::assertSame([
            'id', 'clientId', 'invoiceNumber', 'verificationUrl', 'sdcDateTime', 'totalAmountMinor', 'sellerName',
            'sellerPib', 'buyerPib', 'invoiceType', 'transactionType', 'receivedAt',
        ], array_keys($first));
        self::assertSame('2026-10-03T10:00:00', $first['sdcDateTime']);
        self::assertSame(300, $first['totalAmountMinor']);
        self::assertSame($this->pera->id, $first['clientId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['receivedAt']);
        self::assertArrayNotHasKey('rawJson', $first);

        $last = self::data($this->call('/clients/' . $this->pera->id . '/receipts', ['month' => '2026-10', 'perPage' => '2', 'page' => '3']));
        self::assertSame(['AAAAAAAA-BBBBBBBB-5'], array_column($last['items'], 'invoiceNumber'));
        $beyond = self::data($this->call('/clients/' . $this->pera->id . '/receipts', ['month' => '2026-10', 'perPage' => '2', 'page' => '9']));
        self::assertSame([], $beyond['items']);
        self::assertSame(5, $beyond['total']);

        $default = self::data($this->call('/clients/' . $this->pera->id . '/receipts', ['month' => '2026-10']));
        self::assertSame([1, 100, 5], [$default['page'], $default['perPage'], $default['total']]);
        self::assertCount(5, $default['items']);

        $empty = self::data($this->call('/clients/' . $this->pera->id . '/receipts', ['month' => '2025-01']));
        self::assertSame(['items' => [], 'month' => '2025-01', 'page' => 1, 'perPage' => 100, 'total' => 0], $empty);
    }

    public function testValidacijaParametara(): void
    {
        $path = '/clients/' . $this->pera->id . '/receipts';
        $bad = [
            'bez meseca' => [],
            'pogrešan mesec' => ['month' => '2026-13'],
            'mesec sa danom' => ['month' => '2026-10-01'],
            'page 0' => ['month' => '2026-10', 'page' => '0'],
            'page tekst' => ['month' => '2026-10', 'page' => 'abc'],
            'perPage preko 500' => ['month' => '2026-10', 'perPage' => '501'],
            'perPage 0' => ['month' => '2026-10', 'perPage' => '0'],
            'negativan perPage' => ['month' => '2026-10', 'perPage' => '-5'],
        ];
        foreach ($bad as $name => $query) {
            $r = $this->call($path, $query);
            self::assertSame(400, $r->status, $name);
            self::assertSame('invalid_parameter', self::data($r)['error'], $name);
            self::assertNotSame('', self::data($r)['message']);
            self::assertApiHeaders($r);
        }
        self::assertSame(200, $this->call($path, ['month' => '2026-10', 'perPage' => '500'])->status);
        self::assertSame(400, $this->call('/export', ['month' => 'oktobar'])->status);
        self::assertSame(400, $this->call('/export', ['month' => '2026-10', 'format' => 'xml'])->status);
    }

    public function testNepostojeciKorisnikIRacun(): void
    {
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-1', '2026-10-01T08:00:00');
        foreach (['/clients/999/receipts', '/clients/abc/receipts', '/clients/0/receipts', '/receipts/999', '/receipts/abc', '/receipts/-1'] as $path) {
            $r = $this->call($path, ['month' => '2026-10']);
            self::assertSame(404, $r->status, $path);
            self::assertSame('not_found', self::data($r)['error'], $path);
            self::assertApiHeaders($r);
        }
        $r = $this->call('/export', ['month' => '2026-10', 'clientId' => '999']);
        self::assertSame(404, $r->status);
    }

    public function testJedanRacunIIzvorniJson(): void
    {
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-7', '2026-10-24T21:42:31', 123456, ['sellerPib' => null]);
        $id = $this->receipts->forClientMonth($this->pera->id, '2026-10')[0]->id;

        $r = $this->call('/receipts/' . $id);
        self::assertSame(200, $r->status);
        self::assertApiHeaders($r);
        $d = self::data($r);
        self::assertSame($id, $d['id']);
        self::assertSame('AAAAAAAA-BBBBBBBB-7', $d['invoiceNumber']);
        self::assertSame('2026-10-24T21:42:31', $d['sdcDateTime']);
        self::assertNull($d['sellerPib']);
        self::assertArrayNotHasKey('rawJson', $d);

        $raw = self::data($this->call('/receipts/' . $id, ['raw' => '1']));
        self::assertSame(['invoiceNumber' => 'AAAAAAAA-BBBBBBBB-7', 'extra' => 'izvorno'], json_decode($raw['rawJson'], true));
    }

    public function testIzvozCsvJednogKorisnikaKaoUPanelu(): void
    {
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31', 123456);
        $this->addReceipt($this->mika, 'CCCCCCCC-DDDDDDDD-1', '2026-10-03T10:00:00');

        $r = $this->call('/export', ['month' => '2026-10', 'format' => 'csv', 'clientId' => (string) $this->pera->id]);
        self::assertSame(200, $r->status);
        self::assertApiHeaders($r);
        self::assertStringStartsWith('text/csv; charset=utf-8', $r->headers['Content-Type']);
        self::assertSame('attachment; filename="racuni-korisnik-' . $this->pera->id . '-2026-10.csv"', $r->headers['Content-Disposition']);
        $panelCsv = \RacunatorPanel\Controllers\Panel\ReceiptsController::buildCsv($this->receipts->forClientMonth($this->pera->id, '2026-10'));
        self::assertSame($panelCsv, $r->body, 'iste kolone i zapis kao CSV u panelu');
        self::assertStringStartsWith("\xEF\xBB\xBFDatum;Prodavac;", $r->body);
        self::assertStringContainsString('"24.10.2026 21:42:31";"ZORA MARKET DOO";100000000;101234567;1234,56;AAAAAAAA-BBBBBBBB-1;', $r->body);
        self::assertSame(['api.accountant_export'], $this->auditActions());
    }

    public function testIzvozCsvSvihKorisnika(): void
    {
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31');
        $this->addReceipt($this->mika, 'CCCCCCCC-DDDDDDDD-1', '2026-10-03T10:00:00');
        $this->addReceipt($this->mika, 'CCCCCCCC-DDDDDDDD-2', '2026-09-03T10:00:00');

        $r = $this->call('/export', ['month' => '2026-10']);
        self::assertSame(200, $r->status);
        self::assertSame('attachment; filename="racuni-2026-10.csv"', $r->headers['Content-Disposition']);
        $lines = explode("\r\n", rtrim(substr($r->body, 3), "\r\n"));
        self::assertCount(3, $lines);
        self::assertStringStartsWith('Korisnik;Datum;Prodavac;', $lines[0]);
        // Korisnici po nazivu; naziv koji liči na formulu dobija apostrof (zaštita od CSV injection).
        self::assertStringStartsWith('"\'=Mika preduzetnik";"03.10.2026 10:00:00";', $lines[1]);
        self::assertStringStartsWith('"Pera DOO";"24.10.2026 21:42:31";', $lines[2]);
    }

    public function testIzvozJson(): void
    {
        $this->addReceipt($this->pera, 'AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31');
        $this->addReceipt($this->mika, 'CCCCCCCC-DDDDDDDD-1', '2026-10-03T10:00:00');

        $all = $this->call('/export', ['month' => '2026-10', 'format' => 'json']);
        self::assertSame(200, $all->status);
        self::assertApiHeaders($all);
        self::assertStringStartsWith('application/json', $all->headers['Content-Type']);
        $items = self::data($all);
        self::assertTrue(array_is_list($items));
        self::assertSame(['CCCCCCCC-DDDDDDDD-1', 'AAAAAAAA-BBBBBBBB-1'], array_column($items, 'invoiceNumber'));
        self::assertSame([$this->mika->id, $this->pera->id], array_column($items, 'clientId'));

        $one = self::data($this->call('/export', ['month' => '2026-10', 'format' => 'json', 'clientId' => (string) $this->pera->id]));
        self::assertSame(['AAAAAAAA-BBBBBBBB-1'], array_column($one, 'invoiceNumber'));
        self::assertSame(self::data($this->call('/receipts/' . $one[0]['id'])), $one[0], 'isti JSON oblik računa svuda');

        self::assertSame([], self::data($this->call('/export', ['month' => '2025-01', 'format' => 'json'])));
    }

    public function testOgranicenjeBrojaZahteva(): void
    {
        $this->rateLimit = 3;
        $this->buildRouter();
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $this->call('/ping')->status);
        }
        $r = $this->call('/clients');
        self::assertSame(429, $r->status);
        self::assertSame('rate_limited', self::data($r)['error']);
        self::assertApiHeaders($r);
        self::assertGreaterThanOrEqual(1, (int) $r->headers['Retry-After']);
        self::assertLessThanOrEqual(60, (int) $r->headers['Retry-After']);
        $this->now += 60;
        self::assertSame(200, $this->call('/ping')->status, 'novi minut');
        self::assertSame(120, AccountantApiController::RATE_LIMIT);
    }

    public function testBlokadaPosleNeuspelihPokusaja(): void
    {
        $bad = KeyGenerator::apiKey();
        for ($i = 1; $i <= ApiAuthFailLimiter::MAX_FAILS; $i++) {
            self::assertSame(401, $this->call('/ping', key: $bad)->status, "promašaj $i");
            $this->now += 5;
        }
        // Blokirana adresa ne prolazi ni sa ispravnim ključem; druga adresa radi.
        $blocked = $this->call('/ping');
        self::assertSame(429, $blocked->status);
        self::assertSame('auth_blocked', self::data($blocked)['error']);
        self::assertApiHeaders($blocked);
        $wait = (int) $blocked->headers['Retry-After'];
        self::assertSame(ApiAuthFailLimiter::WINDOW - 5 * ApiAuthFailLimiter::MAX_FAILS, $wait);
        self::assertSame(200, $this->call('/ping', ip: '198.51.100.7')->status);

        $actions = $this->auditActions();
        self::assertCount(ApiAuthFailLimiter::MAX_FAILS, $actions, 'promašaji u dnevniku, posle blokade ništa');
        self::assertSame(['api.accountant_auth_fail'], array_values(array_unique($actions)));
        $details = array_column($this->audit->recent(1000), 'details');
        self::assertStringContainsString('privremeno blokirana', $details[0]);
        self::assertStringNotContainsString($this->slug, implode("\n", $details));
        self::assertStringNotContainsString($bad, implode("\n", $details));

        $this->now += $wait;
        self::assertSame(200, $this->call('/ping')->status, 'blokada ističe');
        // Uspešan pristup briše promašaje te adrese.
        $count = $this->pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'api:accountant'")->fetchColumn();
        self::assertSame(0, (int) $count);
    }

    public function testPromasajiNeBlokirajuPrijavuUPanel(): void
    {
        $bad = KeyGenerator::apiKey();
        for ($i = 0; $i < ApiAuthFailLimiter::MAX_FAILS; $i++) {
            $this->call('/ping', key: $bad, ip: '203.0.113.9');
        }
        $limiter = new RateLimiter($this->pdo, fn (): int => $this->now);
        self::assertNull($limiter->secondsUntilAllowed('203.0.113.9', 'admin'), 'prijava sa iste adrese i dalje radi');
    }

    public function testSamoCitanjeINepoznatePutanje(): void
    {
        $post = $this->call('/clients', method: 'POST');
        self::assertSame(405, $post->status);
        self::assertSame('method_not_allowed', self::data($post)['error']);
        self::assertSame('GET', $post->headers['Allow']);
        self::assertApiHeaders($post);

        $unknown = $this->call('/nepostoji');
        self::assertSame(404, $unknown->status);
        self::assertSame('not_found', self::data($unknown)['error']);
        self::assertApiHeaders($unknown);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->call('/ping')->headers, 'bez CORS-a');
    }

    public function testKrozCeluAplikacijuIOdrzavanje(): void
    {
        $storage = sys_get_temp_dir() . '/rp-acc-' . bin2hex(random_bytes(4));
        mkdir($storage);
        try {
            $app = App::create(dirname(__DIR__, 2), new Config(['public_url' => 'https://domen.rs/panel', 'storage' => $storage,
                'app_key' => str_repeat('a', 64)]), $this->pdo, new ArraySession());
            $headers = ['authorization' => 'Bearer ' . $this->key];
            $r = $app->handle(new Request('GET', '/api/' . $this->slug . '/v1/ping', headers: $headers, https: true));
            self::assertSame(200, $r->status);
            self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
            self::assertSame('no-store', $r->headers['Cache-Control']);

            file_put_contents($storage . '/maintenance.flag', json_encode(['until' => time() + 600]));
            $m = $app->handle(new Request('GET', '/api/' . $this->slug . '/v1/clients', headers: $headers));
            self::assertSame(503, $m->status);
            self::assertSame('maintenance', self::data($m)['error']);
        } finally {
            @unlink($storage . '/maintenance.flag');
            @rmdir($storage);
        }
    }
}
