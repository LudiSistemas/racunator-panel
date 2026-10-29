<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Api;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\ApiDailyLimiter;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Controllers\ClientApiController;
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

final class ClientApiTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;
    private PdoClientRepository $clients;
    private Client $client;
    private string $key;
    private int $rateLimit = 60;
    private int $now = 1_800_000_000;
    private PdoSettingsRepository $settings;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo();
        $this->clients = new PdoClientRepository($this->pdo);
        $settings = $this->settings = new PdoSettingsRepository($this->pdo);
        $settings->set('accountant_name', 'Knjigovodstvo XY');
        $this->key = KeyGenerator::apiKey();
        $this->client = $this->clients->create('Pera DOO', KeyGenerator::slug(), KeyGenerator::prefix($this->key), KeyGenerator::hash($this->key), null);
        $this->buildRouter();
    }

    private function buildRouter(): void
    {
        $api = new ClientApiController(
            $this->clients,
            new PdoReceiptRepository($this->pdo),
            new PdoSettingsRepository($this->pdo),
            new ApiRateLimiter($this->pdo, $this->rateLimit),
            $this->pdo,
            new ApiDailyLimiter($this->pdo, fn (): int => $this->now),
            new PdoAuditLogRepository($this->pdo),
        );
        $this->router = new Router();
        $this->router->get('/u/{slug}/ping', [$api, 'ping']);
        $this->router->post('/u/{slug}/receipts', [$api, 'receipts']);
    }

    private function ping(?string $key = null, ?string $slug = null): Response
    {
        $headers = $key === '' ? [] : ['authorization' => 'Bearer ' . ($key ?? $this->key)];
        return $this->router->dispatch(new Request('GET', '/u/' . ($slug ?? $this->client->slug) . '/ping', headers: $headers));
    }

    private function post(mixed $body, ?string $key = null, ?string $slug = null): Response
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->router->dispatch(new Request(
            'POST',
            '/u/' . ($slug ?? $this->client->slug) . '/receipts',
            rawBody: $raw,
            headers: ['authorization' => 'Bearer ' . ($key ?? $this->key), 'content-type' => 'application/json'],
        ));
    }

    private static function data(Response $r): array
    {
        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function receipt(string $invoice = 'RCVG4AFL-RCVG4AFL-234120', array $override = []): array
    {
        return $override + [
            'invoiceNumber' => $invoice,
            'verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=A1JDVkc0QUZMUkNWRzRBRkzVkwAAAAAAAAAAAAAAAAAAAA%3D%3D',
            'sdcDateTime' => '2026-10-24T21:42:31',
            'totalAmountMinor' => 123456,
            'sellerName' => 'ZORA MARKET DOO',
            'sellerPib' => '100000000',
            'buyerPib' => '101234567',
            'invoiceType' => 'Normal',
            'transactionType' => 'Sale',
        ];
    }

    public function testPing(): void
    {
        $r = $this->ping();
        self::assertSame(200, $r->status);
        self::assertSame('1', $r->headers['X-Racunator-Api']);
        self::assertSame(['ok' => true, 'client' => 'Pera DOO', 'accountant' => 'Knjigovodstvo XY', 'api' => 1], self::data($r));
        self::assertNotNull($this->clients->findById($this->client->id)?->lastSeenAt);
    }

    public function testIsti401ZaSveGreskeAutentifikacije(): void
    {
        $other = KeyGenerator::apiKey();
        $cases = [
            $this->ping(KeyGenerator::apiKey()),                       // pogrešan ključ
            $this->ping(''),                                           // bez zaglavlja
            $this->ping('nije-kljuc'),                                 // pogrešan oblik
            $this->ping($this->key, KeyGenerator::slug()),             // nepostojeći slug
            $this->ping($this->key, 'xyz'),                            // neispravan slug
            $this->ping(substr($this->key, 0, 11) . substr($other, 11)), // isti prefiks, drugi ključ
        ];
        $this->clients->setActive($this->client->id, false);
        $cases[] = $this->ping();                                      // neaktivan korisnik
        $cases[] = $this->post(['receipts' => [self::receipt()]]);
        foreach ($cases as $i => $r) {
            self::assertSame(401, $r->status, "slučaj $i");
            self::assertSame(['error' => 'unauthorized', 'message' => 'Neispravan ključ ili adresa korisnika.'], self::data($r));
            self::assertSame('Bearer', $r->headers['WWW-Authenticate']);
            self::assertSame('1', $r->headers['X-Racunator-Api']);
        }
    }

    public function testStariKljucPosleRotacije(): void
    {
        $new = KeyGenerator::apiKey();
        $this->clients->rotateKey($this->client->id, KeyGenerator::prefix($new), KeyGenerator::hash($new));
        self::assertSame(401, $this->ping($this->key)->status);
        self::assertSame(200, $this->ping($new)->status);
    }

    public function testPrijemIDuplikati(): void
    {
        $r = $this->post(['receipts' => [self::receipt('AAAAAAAA-BBBBBBBB-1'), self::receipt('AAAAAAAA-BBBBBBBB-2'), self::receipt('AAAAAAAA-BBBBBBBB-1')]]);
        self::assertSame(200, $r->status);
        self::assertSame('1', $r->headers['X-Racunator-Api']);
        self::assertSame([
            'accepted' => ['AAAAAAAA-BBBBBBBB-1', 'AAAAAAAA-BBBBBBBB-2'],
            'duplicates' => ['AAAAAAAA-BBBBBBBB-1'],
            'rejected' => [],
        ], self::data($r));

        $r2 = $this->post(['receipts' => [self::receipt('AAAAAAAA-BBBBBBBB-2'), self::receipt('AAAAAAAA-BBBBBBBB-3')]]);
        self::assertSame(['AAAAAAAA-BBBBBBBB-3'], self::data($r2)['accepted']);
        self::assertSame(['AAAAAAAA-BBBBBBBB-2'], self::data($r2)['duplicates']);

        $saved = (new PdoReceiptRepository($this->pdo))->forClientMonth($this->client->id, '2026-10');
        self::assertCount(3, $saved);
        self::assertSame('2026-10-24T21:42:31', $saved[0]->sdcDateTime);
        self::assertSame(123456, $saved[0]->totalAmountMinor);
        self::assertSame('101234567', $saved[0]->buyerPib);
    }

    public function testBrojacINastavakBrojaca(): void
    {
        $good = [
            'AAAAAAAA-BBBBBBBB-40' => [['invoiceCounter' => '123/456', 'invoiceCounterExtension' => 'ПП'], ['123/456', 'ПП']],
            'AAAAAAAA-BBBBBBBB-41' => [['invoiceCounter' => '1/1', 'invoiceCounterExtension' => 'PR'], ['1/1', 'PR']],
            'AAAAAAAA-BBBBBBBB-42' => [['invoiceCounter' => '1234567890/1234567890', 'invoiceCounterExtension' => 'ЂЏЉЊ'],
                ['1234567890/1234567890', 'ЂЏЉЊ']],
            'AAAAAAAA-BBBBBBBB-43' => [['invoiceCounter' => null, 'invoiceCounterExtension' => null], [null, null]],
            'AAAAAAAA-BBBBBBBB-44' => [[], [null, null]],
            'AAAAAAAA-BBBBBBBB-45' => [['invoiceCounter' => '', 'invoiceCounterExtension' => ''], [null, null]],
            'AAAAAAAA-BBBBBBBB-46' => [['source' => 'pfr', 'verificationUrl' => 'https://suf.purs.gov.rs/verify',
                'invoiceCounter' => '5/9', 'invoiceCounterExtension' => 'КП'], ['5/9', 'КП']],
        ];
        $bad = [
            'AAAAAAAA-BBBBBBBB-50' => ['invoiceCounter' => '123-456'],
            'AAAAAAAA-BBBBBBBB-51' => ['invoiceCounter' => '123/'],
            'AAAAAAAA-BBBBBBBB-52' => ['invoiceCounter' => '12345678901/1'],
            'AAAAAAAA-BBBBBBBB-53' => ['invoiceCounter' => 123],
            'AAAAAAAA-BBBBBBBB-54' => ['invoiceCounter' => "123/456\n"],
            'AAAAAAAA-BBBBBBBB-55' => ['invoiceCounter' => ' 123/456'],
            'AAAAAAAA-BBBBBBBB-56' => ['invoiceCounterExtension' => 'пп'],
            'AAAAAAAA-BBBBBBBB-57' => ['invoiceCounterExtension' => 'ППППП'],
            'AAAAAAAA-BBBBBBBB-58' => ['invoiceCounterExtension' => 'П1'],
            'AAAAAAAA-BBBBBBBB-59' => ['invoiceCounterExtension' => 'ΠΠ'],
            'AAAAAAAA-BBBBBBBB-60' => ['invoiceCounterExtension' => ['ПП']],
            'AAAAAAAA-BBBBBBBB-61' => ['invoiceCounterExtension' => "ПП\n"],
            'AAAAAAAA-BBBBBBBB-62' => ['invoiceCounterExtension' => 'П П'],
        ];
        $items = [];
        foreach ($good as $inv => [$override]) {
            $items[] = self::receipt($inv, $override);
        }
        foreach ($bad as $inv => $override) {
            $items[] = self::receipt($inv, $override);
        }
        $data = self::data($this->post(['receipts' => $items]));
        self::assertSame(array_keys($good), $data['accepted']);
        $rejected = array_column($data['rejected'], 'reason', 'invoiceNumber');
        self::assertSame(array_keys($bad), array_keys($rejected));
        foreach ($bad as $inv => $override) {
            self::assertStringStartsWith(array_key_first($override) . ': ', $rejected[$inv], $inv);
        }
        self::assertStringContainsString('123/456', $rejected['AAAAAAAA-BBBBBBBB-50']);
        self::assertStringContainsString('ПП', $rejected['AAAAAAAA-BBBBBBBB-56']);

        $saved = [];
        foreach ((new PdoReceiptRepository($this->pdo))->forClientMonth($this->client->id, '2026-10') as $r) {
            $saved[$r->invoiceNumber] = [$r->invoiceCounter, $r->invoiceCounterExtension];
        }
        foreach ($good as $inv => [, $expected]) {
            self::assertSame($expected, $saved[$inv], $inv);
        }
    }

    public function testValidacija(): void
    {
        $bad = [
            'AAAAAAAA-BBBBBBBB-10' => ['invoiceNumber' => 'aaaaaaaa-BBBBBBBB-1'],
            'AAAAAAAA-BBBBBBBB-11' => ['verificationUrl' => 'https://evil.example/?vl=x'],
            'AAAAAAAA-BBBBBBBB-12' => ['verificationUrl' => 'http://suf.purs.gov.rs/v/?vl=x'],
            'AAAAAAAA-BBBBBBBB-13' => ['sdcDateTime' => '24.10.2026 21:42'],
            'AAAAAAAA-BBBBBBBB-14' => ['sdcDateTime' => '2026-02-30T10:00:00'],
            'AAAAAAAA-BBBBBBBB-15' => ['totalAmountMinor' => -1],
            'AAAAAAAA-BBBBBBBB-16' => ['totalAmountMinor' => 12.5],
            'AAAAAAAA-BBBBBBBB-17' => ['totalAmountMinor' => '100'],
            'AAAAAAAA-BBBBBBBB-18' => ['sellerPib' => '12345678'],
            'AAAAAAAA-BBBBBBBB-19' => ['buyerPib' => 101234567],
            'AAAAAAAA-BBBBBBBB-20' => ['sellerName' => str_repeat('Ž', 201)],
            'AAAAAAAA-BBBBBBBB-21' => ['invoiceType' => ['Normal']],
            'AAAAAAAA-BBBBBBBB-22' => ['transactionType' => "Sale\n"],
            'AAAAAAAA-BBBBBBBB-23' => ['verificationUrl' => 'https://suf.purs.gov.rs/' . str_repeat('a', 2100)],
        ];
        $items = [];
        foreach ($bad as $inv => $override) {
            $items[] = self::receipt($inv, $override);
        }
        $items[] = self::receipt('AAAAAAAA-BBBBBBBB-30', ['buyerPib' => null, 'sellerName' => str_repeat('Ž', 200)]);
        $items[] = self::receipt('AAAAAAAA-BBBBBBBB-31', ['sdcDateTime' => '2026-10-24T21:42:31.123+02:00']);
        $items[] = 'nije objekat';
        $items[] = ['invoiceNumber' => 'AAAAAAAA-BBBBBBBB-32'];

        $data = self::data($this->post(['receipts' => $items]));
        self::assertSame(['AAAAAAAA-BBBBBBBB-30', 'AAAAAAAA-BBBBBBBB-31'], $data['accepted']);
        self::assertCount(count($bad) + 2, $data['rejected']);
        $rejected = array_column($data['rejected'], 'reason', 'invoiceNumber');
        self::assertStringStartsWith('invoiceNumber', $rejected['aaaaaaaa-BBBBBBBB-1']);
        self::assertStringStartsWith('verificationUrl', $rejected['AAAAAAAA-BBBBBBBB-11']);
        self::assertStringStartsWith('sdcDateTime', $rejected['AAAAAAAA-BBBBBBBB-14']);
        self::assertStringStartsWith('totalAmountMinor', $rejected['AAAAAAAA-BBBBBBBB-17']);
        self::assertStringStartsWith('sellerPib', $rejected['AAAAAAAA-BBBBBBBB-18']);
        self::assertStringStartsWith('sellerName', $rejected['AAAAAAAA-BBBBBBBB-20']);
        self::assertStringStartsWith('verificationUrl', $rejected['AAAAAAAA-BBBBBBBB-32']);
        self::assertNull($data['rejected'][count($bad)]['invoiceNumber']);

        $saved = (new PdoReceiptRepository($this->pdo))->forClientMonth($this->client->id, '2026-10');
        self::assertSame('2026-10-24T21:42:31', $saved[1]->sdcDateTime);
    }

    public function testNeispravnoTelo(): void
    {
        foreach (['nije json', '[]', '{"receipts":{"a":1}}', '{"nesto":[]}', ''] as $body) {
            $r = $this->post($body);
            self::assertSame(400, $r->status, $body);
            self::assertSame('invalid_body', self::data($r)['error']);
        }
        self::assertSame(['accepted' => [], 'duplicates' => [], 'rejected' => []], self::data($this->post(['receipts' => []])));
    }

    public function testLimit200Racuna(): void
    {
        $items = [];
        for ($i = 1; $i <= 200; $i++) {
            $items[] = self::receipt('AAAAAAAA-BBBBBBBB-' . $i);
        }
        $ok = $this->post(['receipts' => $items]);
        self::assertSame(200, $ok->status);
        self::assertCount(200, self::data($ok)['accepted']);

        $items[] = self::receipt('AAAAAAAA-BBBBBBBB-201');
        $r = $this->post(['receipts' => $items]);
        self::assertSame(413, $r->status);
        self::assertSame('too_many_receipts', self::data($r)['error']);
    }

    public function testPrevelikoTelo(): void
    {
        $body = json_encode(['receipts' => [], 'pad' => str_repeat('x', ClientApiController::MAX_BODY)]);
        $r = $this->post($body);
        self::assertSame(413, $r->status);
        self::assertSame('payload_too_large', self::data($r)['error']);
        self::assertSame('1', $r->headers['X-Racunator-Api']);

        $declared = $this->router->dispatch(new Request('POST', '/u/' . $this->client->slug . '/receipts', rawBody: '{"receipts":[]}',
            headers: ['authorization' => 'Bearer ' . $this->key, 'content-length' => '2000000']));
        self::assertSame(413, $declared->status);
    }

    public function testRateLimit(): void
    {
        $this->rateLimit = 3;
        $this->buildRouter();
        self::assertSame(200, $this->ping()->status);
        self::assertSame(200, $this->post(['receipts' => []])->status);
        self::assertSame(200, $this->ping()->status);
        $r = $this->ping();
        self::assertSame(429, $r->status);
        self::assertSame('rate_limited', self::data($r)['error']);
        self::assertGreaterThan(0, (int) $r->headers['Retry-After']);
        // Pogrešni ključevi ne troše limit pravog korisnika i ne dobijaju 429.
        self::assertSame(401, $this->ping(KeyGenerator::apiKey())->status);
    }

    public function testValidacijaDirektno(): void
    {
        [$ok, $reason] = ClientApiController::validate(self::receipt('AAAAAAAA-BBBBBBBB-1', ['sellerName' => '  ', 'sdcDateTime' => '2026-10-24T21:42:31Z']));
        self::assertNull($reason);
        self::assertNull($ok['sellerName']);
        self::assertSame('2026-10-24T21:42:31', $ok['sdcDateTime']);
        self::assertIsString($ok['rawJson']);
        self::assertNull(ClientApiController::validate([1, 2])[0]);
    }

    /** @return array<string,mixed> račun unet po PFR broju (kako ga šalje aplikacija) */
    private static function pfrReceipt(string $invoice = 'RCVG4AFL-RCVG4AFL-234120', array $override = []): array
    {
        return $override + ['source' => 'pfr', 'verificationUrl' => 'https://suf.purs.gov.rs/verify'] + self::receipt($invoice);
    }

    /** @return array<string,mixed> */
    private function row(string $invoice): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM receipts WHERE client_id = ? AND invoice_number = ?');
        $stmt->execute([$this->client->id, $invoice]);
        return (array) $stmt->fetch();
    }

    public function testValidacijaPoljaSource(): void
    {
        $data = self::data($this->post(['receipts' => [
            self::receipt('AAAAAAAA-BBBBBBBB-1'),                                              // bez polja → qr
            self::receipt('AAAAAAAA-BBBBBBBB-2', ['source' => 'qr']),
            self::receipt('AAAAAAAA-BBBBBBBB-3', ['source' => null]),                         // null → qr
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-4'),
            self::receipt('AAAAAAAA-BBBBBBBB-10', ['source' => 'xyz']),
            self::receipt('AAAAAAAA-BBBBBBBB-11', ['source' => 'PFR']),
            self::receipt('AAAAAAAA-BBBBBBBB-12', ['source' => 1]),
            self::receipt('AAAAAAAA-BBBBBBBB-13', ['source' => ['qr']]),
            // pfr sa pravim linkom iz QR koda ili bilo kojim drugim osim tačno /verify se odbija.
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-20', ['verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=A1JDVkc0QUZM']),
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-21', ['verificationUrl' => 'https://suf.purs.gov.rs/verify/']),
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-22', ['verificationUrl' => 'https://suf.purs.gov.rs/verify?x=1']),
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-23', ['verificationUrl' => 'http://suf.purs.gov.rs/verify']),
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-24', ['verificationUrl' => 'https://SUF.purs.gov.rs/verify']),
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-25', ['verificationUrl' => null]),
            self::pfrReceipt('AAAAAAAA-BBBBBBBB-26', ['verificationUrl' => '']),
        ]]));
        self::assertSame(['AAAAAAAA-BBBBBBBB-1', 'AAAAAAAA-BBBBBBBB-2', 'AAAAAAAA-BBBBBBBB-3', 'AAAAAAAA-BBBBBBBB-4'], $data['accepted']);
        $rejected = array_column($data['rejected'], 'reason', 'invoiceNumber');
        self::assertCount(11, $rejected);
        foreach ([10, 11, 12, 13] as $n) {
            self::assertSame('source: dozvoljeno je "qr" ili "pfr"', $rejected['AAAAAAAA-BBBBBBBB-' . $n]);
        }
        foreach ([20, 21, 22, 23, 24, 25, 26] as $n) {
            self::assertSame('verificationUrl: za source "pfr" mora biti tačno https://suf.purs.gov.rs/verify', $rejected['AAAAAAAA-BBBBBBBB-' . $n]);
        }
        self::assertSame('qr', $this->row('AAAAAAAA-BBBBBBBB-1')['source']);
        self::assertSame('qr', $this->row('AAAAAAAA-BBBBBBBB-2')['source']);
        self::assertSame('qr', $this->row('AAAAAAAA-BBBBBBBB-3')['source']);
        self::assertSame('pfr', $this->row('AAAAAAAA-BBBBBBBB-4')['source']);
        self::assertSame('https://suf.purs.gov.rs/verify', $this->row('AAAAAAAA-BBBBBBBB-4')['verification_url']);

        [$ok] = ClientApiController::validate(self::pfrReceipt('AAAAAAAA-BBBBBBBB-5'));
        self::assertSame('pfr', $ok['source']);
        self::assertSame('qr', ClientApiController::validate(self::receipt('AAAAAAAA-BBBBBBBB-6'))[0]['source']);
        $saved = (new PdoReceiptRepository($this->pdo))->forClientMonth($this->client->id, '2026-10');
        self::assertSame(['qr', 'qr', 'qr', 'pfr'], array_map(static fn ($r) => $r->source, $saved));
    }

    public function testNadogradnjaPfrUQr(): void
    {
        $invoice = 'RCVG4AFL-RCVG4AFL-77';
        $first = self::data($this->post(['receipts' => [self::pfrReceipt($invoice, ['totalAmountMinor' => 100, 'sellerName' => null])]]));
        self::assertSame([$invoice], $first['accepted']);
        $before = $this->row($invoice);
        self::assertSame('pfr', $before['source']);

        // Isti račun stiže iz QR koda sa pravim linkom → nadogradnja, u odgovoru kao prihvaćen.
        $link = 'https://suf.purs.gov.rs/v/?vl=A1JDVkc0QUZMUkNWRzRBRkzVkwAAAAAAAAAAAAAAAAAAAA%3D%3D';
        $qr = self::receipt($invoice, ['source' => 'qr', 'verificationUrl' => $link, 'totalAmountMinor' => 123456,
            'sellerName' => 'ZORA MARKET DOO', 'sdcDateTime' => '2026-10-24T21:42:31.500']);
        self::assertSame(['accepted' => [$invoice], 'duplicates' => [], 'rejected' => []], self::data($this->post(['receipts' => [$qr]])));
        $after = $this->row($invoice);
        self::assertSame($before['id'], $after['id']);
        self::assertSame('qr', $after['source']);
        self::assertSame($link, $after['verification_url']);
        self::assertSame(123456, (int) $after['total_amount_minor']);
        self::assertSame('ZORA MARKET DOO', $after['seller_name']);
        self::assertSame('2026-10-24T21:42:31', $after['sdc_datetime']);
        self::assertSame($before['received_at'], $after['received_at']);
        self::assertSame('qr', json_decode((string) $after['raw_json'], true)['source']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn());

        // Posle nadogradnje: i qr i pfr su obični duplikati, zapis se više ne menja.
        $again = self::data($this->post(['receipts' => [$qr, self::pfrReceipt($invoice)]]));
        self::assertSame(['accepted' => [], 'duplicates' => [$invoice, $invoice], 'rejected' => []], $again);
        self::assertSame('qr', $this->row($invoice)['source']);
        self::assertSame($link, $this->row($invoice)['verification_url']);
    }

    public function testOstaliDuplikatiSeNeNadogradjuju(): void
    {
        // qr → qr: duplikat kao do sada.
        $this->post(['receipts' => [self::receipt('AAAAAAAA-BBBBBBBB-1')]]);
        $dup = self::data($this->post(['receipts' => [self::receipt('AAAAAAAA-BBBBBBBB-1', ['totalAmountMinor' => 1])]]));
        self::assertSame(['AAAAAAAA-BBBBBBBB-1'], $dup['duplicates']);
        self::assertSame(123456, (int) $this->row('AAAAAAAA-BBBBBBBB-1')['total_amount_minor']);

        // qr → pfr: nema „vraćanja" na PFR unos.
        $dup = self::data($this->post(['receipts' => [self::pfrReceipt('AAAAAAAA-BBBBBBBB-1')]]));
        self::assertSame(['AAAAAAAA-BBBBBBBB-1'], $dup['duplicates']);
        self::assertSame('qr', $this->row('AAAAAAAA-BBBBBBBB-1')['source']);

        // pfr → pfr: duplikat.
        $this->post(['receipts' => [self::pfrReceipt('AAAAAAAA-BBBBBBBB-2')]]);
        self::assertSame(['AAAAAAAA-BBBBBBBB-2'], self::data($this->post(['receipts' => [self::pfrReceipt('AAAAAAAA-BBBBBBBB-2')]]))['duplicates']);

        // pfr → qr bez pravog linka iz QR koda (`/v/?vl=`): duplikat, zapis ostaje PFR unos.
        foreach (['https://suf.purs.gov.rs/verify', 'https://suf.purs.gov.rs/v/x', 'https://suf.purs.gov.rs/'] as $url) {
            $dup = self::data($this->post(['receipts' => [self::receipt('AAAAAAAA-BBBBBBBB-2', ['verificationUrl' => $url])]]));
            self::assertSame(['AAAAAAAA-BBBBBBBB-2'], $dup['duplicates'], $url);
        }
        self::assertSame('pfr', $this->row('AAAAAAAA-BBBBBBBB-2')['source']);
        self::assertSame('https://suf.purs.gov.rs/verify', $this->row('AAAAAAAA-BBBBBBBB-2')['verification_url']);
    }

    /** @return list<array<string,mixed>> */
    private static function batch(int $from, int $count): array
    {
        $out = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $out[] = self::receipt('RCVG4AFL-RCVG4AFL-' . $i);
        }
        return $out;
    }

    private function auditActions(): array
    {
        return $this->pdo->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testDnevniLimit(): void
    {
        $this->settings->set('api_daily_limit', '10');
        $ok = $this->post(['receipts' => self::batch(1, 4)]);
        self::assertSame(200, $ok->status);
        self::assertCount(4, self::data($ok)['accepted']);
        self::assertSame([], $this->auditActions());

        // Prelazak 50 % limita se jednom beleži u dnevnik.
        self::assertSame(200, $this->post(['receipts' => self::batch(5, 2)])->status);
        self::assertSame(['api.daily_warning'], $this->auditActions());
        self::assertSame(200, $this->post(['receipts' => self::batch(7, 1)])->status);
        self::assertSame(['api.daily_warning'], $this->auditActions());

        // Duplikati se ne broje.
        self::assertSame(200, $this->post(['receipts' => self::batch(1, 7)])->status);

        // Zahtev koji bi prešao limit se odbija u celosti (ništa se ne upisuje), uz broj preostalih.
        $over = $this->post(['receipts' => self::batch(8, 5)]);
        self::assertSame(429, $over->status);
        self::assertSame('daily_limit', self::data($over)['error']);
        self::assertSame(3, self::data($over)['remaining']);
        self::assertGreaterThan(0, (int) $over->headers['Retry-After']);
        self::assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn());

        self::assertSame(200, $this->post(['receipts' => self::batch(8, 3)])->status);
        $full = $this->post(['receipts' => self::batch(20, 1)]);
        self::assertSame(429, $full->status);
        self::assertSame(0, self::data($full)['remaining']);
        self::assertStringContainsString('sutra', self::data($full)['message']);
        $this->post(['receipts' => self::batch(21, 1)]);
        self::assertSame(['api.daily_warning', 'api.daily_limit'], $this->auditActions(), 'dostizanje limita jednom dnevno');
        // Ping i dalje radi.
        self::assertSame(200, $this->ping()->status);

        // Sledećeg dana brojač kreće od nule.
        $this->now += 86400;
        self::assertSame(200, $this->post(['receipts' => self::batch(20, 2)])->status);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM api_daily')->fetchColumn());
    }

    public function testDnevniLimitPodrazumevanoIIskljucen(): void
    {
        $daily = new ApiDailyLimiter($this->pdo, fn (): int => $this->now);
        self::assertSame(2000, ApiDailyLimiter::DEFAULT_LIMIT);
        self::assertSame(200, $this->post(['receipts' => self::batch(1, 3)])->status);
        self::assertSame(3, $daily->acceptedToday($this->client->id));

        $this->settings->set('api_daily_limit', '0');
        $this->pdo->exec('UPDATE api_daily SET accepted = 1000000');
        self::assertSame(200, $this->post(['receipts' => self::batch(4, 1)])->status, '0 = bez dnevnog limita');
        self::assertSame(86400 - ($this->now % 86400), $daily->secondsUntilTomorrow());
    }
}
