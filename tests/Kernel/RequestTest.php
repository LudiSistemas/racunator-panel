<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Kernel\Request;

final class RequestTest extends TestCase
{
    public function testBearerToken(): void
    {
        self::assertSame('rk_abc', (new Request('GET', '/', headers: ['authorization' => 'Bearer rk_abc']))->bearerToken());
        self::assertSame('rk_abc', (new Request('GET', '/', headers: ['authorization' => 'bearer   rk_abc']))->bearerToken());
        self::assertNull((new Request('GET', '/', headers: ['authorization' => 'Basic xyz']))->bearerToken());
        self::assertNull((new Request('GET', '/', headers: ['authorization' => 'Bearer a b']))->bearerToken());
        self::assertNull((new Request('GET', '/'))->bearerToken());
    }

    public function testJsonTelo(): void
    {
        self::assertSame(['a' => 1], (new Request('POST', '/', rawBody: '{"a":1}'))->json());
        self::assertNull((new Request('POST', '/', rawBody: 'nije json'))->json());
        self::assertNull((new Request('POST', '/', rawBody: '"string"'))->json());
        self::assertNull((new Request('POST', '/', rawBody: ''))->json());
        self::assertNull((new Request('POST', '/'))->json());
    }

    public function testZaglavljaNeosetljivaNaVelicinuSlova(): void
    {
        $r = new Request('GET', '/', headers: ['content-type' => 'application/json']);
        self::assertSame('application/json', $r->header('Content-Type'));
        self::assertFalse($r->isPost());
    }

    /** @param array<string,string> $server */
    private static function fromServer(array $server, array $trusted = []): Request
    {
        $backup = $_SERVER;
        try {
            $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
            return Request::fromGlobals($trusted);
        } finally {
            $_SERVER = $backup;
        }
    }

    public function testProksiZaglavljaSeBezPouzdanogProksijaIgnorisu(): void
    {
        $r = self::fromServer(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        self::assertSame('203.0.113.9', $r->ip);
        self::assertFalse($r->https, 'X-Forwarded-Proto se ne poštuje bez pouzdanog proksija');

        // Proksi je podešen, ali zahtev ne dolazi sa njega.
        $r = self::fromServer(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['10.0.0.0/8']);
        self::assertSame('203.0.113.9', $r->ip);
        self::assertFalse($r->https);

        self::assertTrue(self::fromServer(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on'])->https);
        self::assertFalse(self::fromServer(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'off'])->https);
    }

    public function testPouzdanProksi(): void
    {
        $trusted = ['10.0.0.0/8', '2001:db8::/32'];
        $r = self::fromServer(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https'], $trusted);
        self::assertSame('198.51.100.7', $r->ip);
        self::assertTrue($r->https);

        // Klijent je podmetnuo svoju vrednost na početak lanca — uzima se prva adresa zdesna koja nije pouzdan proksi.
        $r = self::fromServer(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.5'], $trusted);
        self::assertSame('198.51.100.7', $r->ip);

        $r = self::fromServer(['REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '2001:db9::77'], $trusted);
        self::assertSame('2001:db9::77', $r->ip);

        // Neispravan zapis ili prazno zaglavlje: ostaje adresa proksija.
        self::assertSame('10.0.0.2', self::fromServer(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'nije-ip'], $trusted)->ip);
        self::assertSame('10.0.0.2', self::fromServer(['REMOTE_ADDR' => '10.0.0.2'], $trusted)->ip);
    }

    public function testLanacProksija(): void
    {
        self::assertSame('198.51.100.7', Request::forwardedClient('198.51.100.7', ['10.0.0.0/8']));
        self::assertSame('5.5.5.5', Request::forwardedClient('9.9.9.9, 5.5.5.5, 10.1.1.1', ['10.0.0.0/8']));
        self::assertSame('10.2.2.2', Request::forwardedClient('10.2.2.2, 10.1.1.1', ['10.0.0.0/8']), 'sve pouzdano → krajnja leva');
        self::assertNull(Request::forwardedClient('', ['10.0.0.0/8']));
        self::assertNull(Request::forwardedClient('x, y', ['10.0.0.0/8']));
    }
}
