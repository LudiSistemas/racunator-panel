<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\Totp;

final class TotpTest extends TestCase
{
    /** RFC 6238, dodatak B (SHA1, tajna "12345678901234567890"); poslednjih 6 od 8 cifara. */
    public static function vektori(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    /** @dataProvider vektori */
    public function testRfc6238(int $time, string $expected8): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        self::assertSame($expected8, Totp::code($secret, $time, 8));
        self::assertSame(substr($expected8, -6), Totp::code($secret, $time));
    }

    public function testProveraSaTolerancijom(): void
    {
        $secret = Totp::generateSecret();
        self::assertSame(32, strlen($secret));
        $t = 1_800_000_000;
        $code = Totp::code($secret, $t);
        self::assertTrue(Totp::verify($secret, $code, $t));
        self::assertTrue(Totp::verify($secret, $code, $t + 30));
        self::assertTrue(Totp::verify($secret, $code, $t - 30));
        self::assertFalse(Totp::verify($secret, $code, $t + 90));
        self::assertSame(intdiv($t, 30), Totp::verifyStep($secret, $code, $t));
        self::assertTrue(Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $t));
        self::assertFalse(Totp::verify($secret, '12345', $t));
        self::assertFalse(Totp::verify($secret, 'abcdef', $t));
        self::assertFalse(Totp::verify('!!!', '123456', $t));
    }

    public function testBase32(): void
    {
        foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', random_bytes(20)] as $s) {
            if ($s === '') {
                self::assertSame('', Totp::base32Encode($s));
                continue;
            }
            self::assertSame($s, Totp::base32Decode(Totp::base32Encode($s)));
        }
        self::assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        self::assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi======'));
    }

    public function testUri(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'ana@knjigovodstvo', 'Računator panel');
        self::assertStringStartsWith('otpauth://totp/Ra%C4%8Dunator%20panel:ana%40knjigovodstvo?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Ra%C4%8Dunator%20panel', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
