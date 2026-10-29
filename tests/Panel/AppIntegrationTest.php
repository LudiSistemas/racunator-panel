<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\App;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Tests\Support\TestDb;

/** Ceo tok kroz pravu aplikaciju jezgra (SQLite u memoriji, SessionGuard, sesija u memoriji). */
final class AppIntegrationTest extends TestCase
{
    private const PASSWORD = 'integracija-lozinka-1';
    private App $app;
    private ArraySession $session;
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/rp-panel-' . bin2hex(random_bytes(4));
        mkdir($this->storage);
        $this->session = new ArraySession();
        $config = new Config(['public_url' => 'https://knjig.test/panel', 'storage' => $this->storage, 'app_key' => str_repeat('b', 64)]);
        $this->app = App::create(dirname(__DIR__, 2), $config, TestDb::pdo(), $this->session);
        $this->app->users->create('admin', (new PasswordHasher())->hash(self::PASSWORD), 'admin', null);
        $this->app->settings->set('accountant_name', 'Knjigovodstvo XY');
    }

    protected function tearDown(): void
    {
        @rmdir($this->storage);
    }

    private function request(string $method, string $path, array $form = [], array $headers = [], array $query = []): Response
    {
        if ($method === 'POST' && !isset($form['_token'])) {
            $form['_token'] = $this->app->guard->csrfToken();
        }
        return $this->app->handle(new Request($method, $path, $query, $form, null, $headers, [], '192.0.2.10', true));
    }

    public function testPrijavaKreiranjeKorisnikaIUparivanje(): void
    {
        $login = $this->request('GET', '/prijava');
        self::assertSame(200, $login->status);
        self::assertArrayHasKey('Content-Security-Policy', $login->headers);
        self::assertStringNotContainsString('<script>', $login->body); // nema inline skripti (CSP)
        self::assertStringNotContainsString('onclick', $login->body);

        $this->assertSame('/panel/prijava', $this->request('GET', '/')->headers['Location'] ?? null);

        $ok = $this->request('POST', '/prijava', ['korisnicko_ime' => 'admin', 'lozinka' => self::PASSWORD]);
        self::assertSame(303, $ok->status);
        self::assertSame('/panel/', $ok->headers['Location']);
        self::assertSame(200, $this->request('GET', '/')->status);

        $form = $this->request('GET', '/korisnici/novi');
        preg_match('/name="_nonce" value="([^"]+)"/', $form->body, $m);
        $created = $this->request('POST', '/korisnici/novi', ['ime' => 'Pera DOO', '_nonce' => $m[1] ?? '']);
        self::assertSame(200, $created->status, substr($created->body, 0, 500));
        preg_match('/data-qr="([^"]+)"/', $created->body, $q);
        $payload = json_decode(html_entity_decode($q[1] ?? '', ENT_QUOTES), true);
        self::assertIsArray($payload);
        self::assertSame('Knjigovodstvo XY', $payload['accountant']);

        // Ključ iz QR koda radi na API-ju za aplikaciju (jezgro).
        $path = (string) parse_url($payload['endpoint'], PHP_URL_PATH);
        $path = substr($path, strlen('/panel'));
        $ping = $this->request('GET', $path . '/ping', [], ['authorization' => 'Bearer ' . $payload['key'], 'x-racunator-api' => '1']);
        self::assertSame(200, $ping->status, $ping->body);
        self::assertSame('Pera DOO', json_decode($ping->body, true)['client']);

        $actions = array_column($this->app->audit->recent(10), 'action');
        self::assertContains('login', $actions);
        self::assertContains('client.create', $actions);

        $logout = $this->request('POST', '/odjava');
        self::assertSame('/panel/prijava', $logout->headers['Location']);
        self::assertSame('/panel/prijava', $this->request('GET', '/korisnici')->headers['Location'] ?? null);
    }
}
