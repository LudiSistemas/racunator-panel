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
}
