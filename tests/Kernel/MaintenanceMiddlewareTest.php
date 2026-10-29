<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\App;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Services\Maintenance;
use RacunatorPanel\Tests\Support\TestDb;

/** Middleware režima održavanja poštuje rok (`until`) iz zastavice koju piše `Maintenance::enable()`. */
final class MaintenanceMiddlewareTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/rp-maint-' . bin2hex(random_bytes(4));
        mkdir($this->storage);
    }

    protected function tearDown(): void
    {
        Maintenance::disable($this->storage);
        @rmdir($this->storage);
    }

    private function app(): App
    {
        $config = new Config(['public_url' => 'https://domen.rs/panel', 'storage' => $this->storage, 'app_key' => str_repeat('a', 64)]);
        return App::create(dirname(__DIR__, 2), $config, TestDb::pdo(), new ArraySession());
    }

    private function postReceipts(App $app): \RacunatorPanel\Kernel\Response
    {
        return $app->handle(new Request('POST', '/u/' . str_repeat('a', 32) . '/receipts', rawBody: '{"receipts":[]}'));
    }

    public function testVazecaZastavicaBlokiraApiSaRetryAfter(): void
    {
        $app = $this->app();
        Maintenance::enable($this->storage, 'test', 90);
        $r = $this->postReceipts($app);
        self::assertSame(503, $r->status);
        self::assertSame('maintenance', json_decode($r->body, true)['error']);
        $retry = (int) $r->headers['Retry-After'];
        self::assertGreaterThanOrEqual(30, $retry);
        self::assertLessThanOrEqual(90, $retry);
        self::assertSame(503, $app->handle(new Request('GET', '/nesto'))->status);
    }

    public function testIstekliRokViseNeBlokira(): void
    {
        $app = $this->app();
        // Zastavica zaostala posle pada procesa: rok je prošao, fajl je i dalje tu.
        file_put_contents(Maintenance::path($this->storage), json_encode(['since' => time() - 4000, 'until' => time() - 10, 'reason' => 'pad']));
        self::assertFileExists(Maintenance::path($this->storage));
        self::assertSame(401, $this->postReceipts($app)->status);
        self::assertNotSame(503, $app->handle(new Request('GET', '/nesto'))->status);
    }

    public function testZastavicaBezRokaOstajeAktivna(): void
    {
        $app = $this->app();
        touch(Maintenance::path($this->storage));
        $r = $this->postReceipts($app);
        self::assertSame(503, $r->status);
        self::assertSame('120', $r->headers['Retry-After']);
    }
}
