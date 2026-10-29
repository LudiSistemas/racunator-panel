<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Api;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Controllers\ClientApiController;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Kernel\Router;
use RacunatorPanel\Models\Client;
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

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo();
        $this->clients = new PdoClientRepository($this->pdo);
        $settings = new PdoSettingsRepository($this->pdo);
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
}
