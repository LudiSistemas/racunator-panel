<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\SecretCipher;

final class SecretCipherTest extends TestCase
{
    public function testSifrovanjeIDesifrovanje(): void
    {
        $c = new SecretCipher(str_repeat('a', 64));
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $enc = $c->encrypt($secret);
        self::assertStringStartsWith(SecretCipher::PREFIX, $enc);
        self::assertStringNotContainsString($secret, $enc);
        self::assertNotSame($enc, $c->encrypt($secret), 'svaki upis ima nov nonce');
        self::assertSame($secret, $c->decrypt($enc));
        self::assertLessThanOrEqual(255, strlen($enc), 'staje u users.totp_secret VARCHAR(255)');
        self::assertTrue(SecretCipher::isEncrypted($enc));
    }

    public function testOpenSslZapis(): void
    {
        $c = new SecretCipher(str_repeat('b', 64));
        $enc = $c->encryptWithOpenSsl('TAJNA234');
        self::assertStringStartsWith('enc:g1:', $enc);
        self::assertSame('TAJNA234', $c->decrypt($enc));
    }

    public function testStariZapisIPogresanKljuc(): void
    {
        $c = new SecretCipher(str_repeat('a', 64));
        self::assertSame('JBSWY3DPEHPK3PXP', $c->decrypt('JBSWY3DPEHPK3PXP'), 'čist tekst (stari zapis) se čita kakav jeste');
        $other = new SecretCipher(str_repeat('c', 64));
        self::assertNull($other->decrypt($c->encrypt('X')));
        self::assertNull($other->decrypt($c->encryptWithOpenSsl('X')));
        self::assertNull($c->decrypt('enc:s1:nije-base64!'));
        self::assertNull($c->decrypt('enc:zz:abc'));
        $tampered = $c->encrypt('X');
        $tampered[strlen($tampered) - 2] = $tampered[strlen($tampered) - 2] === 'A' ? 'B' : 'A';
        self::assertNull($c->decrypt($tampered));
    }

    public function testBezKljuca(): void
    {
        self::assertNull(SecretCipher::fromAppKey(''));
        self::assertNull(SecretCipher::fromAppKey(null));
        self::assertInstanceOf(SecretCipher::class, SecretCipher::fromAppKey('k'));
        $this->expectException(\InvalidArgumentException::class);
        new SecretCipher('');
    }
}
