<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Api;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\App;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Tests\Support\TestDb;

final class AppTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/rp-app-' . bin2hex(random_bytes(4));
        mkdir($this->storage);
    }

    protected function tearDown(): void
    {
        @unlink($this->storage . '/maintenance.flag');
        @rmdir($this->storage);
    }

    private function app(array $extra = []): App
    {
        $config = new Config($extra + ['public_url' => 'https://domen.rs/panel', 'storage' => $this->storage, 'app_key' => str_repeat('a', 64)]);
        return App::create(dirname(__DIR__, 2), $config, TestDb::pdo(), new ArraySession());
    }

    public function testJavniOblik(): void
    {
        $app = $this->app();
        self::assertSame(dirname(__DIR__, 2), $app->root);
        self::assertSame(require dirname(__DIR__, 2) . '/src/version.php', $app->version);
        self::assertSame('/panel/prijava', $app->url('prijava'));
        self::assertInstanceOf(\RacunatorPanel\Auth\SessionGuard::class, $app->guard);
    }

    public function testHealthIZaglavlja(): void
    {
        $app = $this->app();
        $r = $app->handle(new Request('GET', '/health', https: true));
        self::assertSame(200, $r->status);
        self::assertSame(['ok' => true], json_decode($r->body, true), 'neprijavljen ne vidi verziju');
        self::assertSame('DENY', $r->headers['X-Frame-Options']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        self::assertArrayHasKey('Referrer-Policy', $r->headers);
        self::assertArrayHasKey('Strict-Transport-Security', $r->headers);

        $http = $app->handle(new Request('GET', '/health'));
        self::assertArrayNotHasKey('Strict-Transport-Security', $http->headers);
    }

    public function testHealthVerzijaZaSamoproveruIAdministratora(): void
    {
        $app = $this->app();
        $token = App::healthToken(str_repeat('a', 64));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertStringNotContainsString(str_repeat('a', 64), $token);
        $withToken = $app->handle(new Request('GET', '/health', headers: [strtolower(App::HEALTH_TOKEN_HEADER) => $token]));
        self::assertSame(['ok' => true, 'version' => $app->version], json_decode($withToken->body, true));
        $badToken = $app->handle(new Request('GET', '/health', headers: [strtolower(App::HEALTH_TOKEN_HEADER) => 'pogresan']));
        self::assertSame(['ok' => true], json_decode($badToken->body, true));
        self::assertSame('', App::healthToken(''));

        $hasher = new \RacunatorPanel\Auth\PasswordHasher();
        $app->users->create('pera', $hasher->hash('lozinka-za-test-1'), 'zaposleni', null);
        $app->users->create('admin', $hasher->hash('lozinka-za-test-2'), 'admin', null);
        $login = static fn (App $a, string $u, string $p) => $a->guard->login(new Request('POST', '/prijava', ip: '10.0.0.1'), $u, $p, null);

        $staff = $this->app();
        $staff->users->create('pera', $hasher->hash('lozinka-za-test-1'), 'zaposleni', null);
        self::assertNull($login($staff, 'pera', 'lozinka-za-test-1'));
        self::assertSame(['ok' => true], json_decode($staff->handle(new Request('GET', '/health'))->body, true), 'zaposleni ne vidi verziju');

        self::assertNull($login($app, 'admin', 'lozinka-za-test-2'));
        self::assertSame($app->version, json_decode($app->handle(new Request('GET', '/health'))->body, true)['version'] ?? null);
    }

    public function testPreusmerenjeNaHttps(): void
    {
        $off = $this->app();
        self::assertSame(303, $off->handle(new Request('GET', '/'))->status, 'bez force_https nema preusmerenja na HTTPS');

        $app = $this->app(['force_https' => true, 'public_url' => 'http://domen.rs/panel']);
        $r = $app->handle(new Request('GET', '/korisnici', ['mesec' => '2026-10']));
        self::assertSame(301, $r->status);
        self::assertSame('https://domen.rs/panel/korisnici?mesec=2026-10', $r->headers['Location']);
        self::assertSame(308, $app->handle(new Request('POST', '/u/abc/receipts'))->status);
        self::assertSame(200, $app->handle(new Request('GET', '/health'))->status, '/health je izuzet');
        self::assertSame(303, $app->handle(new Request('GET', '/', https: true))->status, 'HTTPS zahtev ide dalje');
    }

    public function testPouzdaniProksijiIzKonfiguracije(): void
    {
        self::assertSame([], $this->app()->trustedProxies());
        self::assertSame(['10.0.0.0/8', '::1'], $this->app(['trusted_proxies' => ['10.0.0.0/8', ' ::1 ', '']])->trustedProxies());
        self::assertSame(['10.0.0.0/8', '192.168.0.0/16'], $this->app(['trusted_proxies' => '10.0.0.0/8, 192.168.0.0/16'])->trustedProxies());
    }

    public function testOdgovor413(): void
    {
        $r = App::payloadTooLarge();
        self::assertSame(413, $r->status);
        self::assertSame('payload_too_large', json_decode($r->body, true)['error']);
        self::assertSame('1', $r->headers['X-Racunator-Api']);
    }

    public function testApiRuteRegistrovane(): void
    {
        $r = $this->app()->handle(new Request('GET', '/u/' . str_repeat('a', 32) . '/ping'));
        self::assertSame(401, $r->status);
        self::assertSame('1', $r->headers['X-Racunator-Api']);
        self::assertSame('DENY', $r->headers['X-Frame-Options']);
    }

    public function testRezimOdrzavanja(): void
    {
        $app = $this->app();
        touch($this->storage . '/maintenance.flag');
        $api = $app->handle(new Request('POST', '/u/' . str_repeat('a', 32) . '/receipts', rawBody: '{"receipts":[]}'));
        self::assertSame(503, $api->status);
        self::assertSame('120', $api->headers['Retry-After']);
        self::assertSame('maintenance', json_decode($api->body, true)['error']);
        self::assertSame('DENY', $api->headers['X-Frame-Options']);

        $page = $app->handle(new Request('GET', '/nesto'));
        self::assertSame(503, $page->status);
        self::assertStringContainsString('Ažuriranje u toku', $page->body);

        self::assertSame(200, $app->handle(new Request('GET', '/health'))->status);

        unlink($this->storage . '/maintenance.flag');
        self::assertSame(401, $app->handle(new Request('POST', '/u/' . str_repeat('a', 32) . '/receipts', rawBody: '{}'))->status);
    }
}
