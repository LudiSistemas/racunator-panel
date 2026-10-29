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

    private function app(): App
    {
        $config = new Config(['public_url' => 'https://domen.rs/panel', 'storage' => $this->storage, 'app_key' => str_repeat('a', 64)]);
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
        self::assertSame(['ok' => true, 'version' => $app->version], json_decode($r->body, true));
        self::assertSame('DENY', $r->headers['X-Frame-Options']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        self::assertArrayHasKey('Referrer-Policy', $r->headers);
        self::assertArrayHasKey('Strict-Transport-Security', $r->headers);

        $http = $app->handle(new Request('GET', '/health'));
        self::assertArrayNotHasKey('Strict-Transport-Security', $http->headers);
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
