<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Auth\Csrf;

final class CsrfTest extends TestCase
{
    public function testToken(): void
    {
        $csrf = new Csrf(new ArraySession());
        self::assertFalse($csrf->check('bilo-sta'));
        $t = $csrf->token();
        self::assertSame(64, strlen($t));
        self::assertSame($t, $csrf->token());
        self::assertTrue($csrf->check($t));
        self::assertFalse($csrf->check($t . 'x'));
        self::assertFalse($csrf->check(''));
        self::assertFalse($csrf->check(null));
        $csrf->rotate();
        self::assertFalse($csrf->check($t));
        self::assertNotSame($t, $csrf->token());
    }
}
