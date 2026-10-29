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
    }

    /**
     * Izmena bilo kog bajta (nonce/IV, MAC/tag, šifrat) mora da obori dešifrovanje — za oba zapisa.
     * Menja se dekodirani bajt, ne znak base64 teksta: poslednji znak pre `=` nosi neiskorišćene bitove, pa bi izmena
     * samo tih bitova dala iste bajtove (tako je raniji test povremeno padao).
     */
    public function testIzmenjenZapisSeOdbija(): void
    {
        $c = new SecretCipher(str_repeat('a', 64));
        foreach (['enc:s1:' => $c->encrypt('X'), 'enc:g1:' => $c->encryptWithOpenSsl('X')] as $prefix => $enc) {
            if ($prefix === 'enc:s1:' && !str_starts_with($enc, 'enc:s1:')) {
                continue; // bez sodium-a encrypt() pravi g1 zapis — pokriven drugom stavkom
            }
            $raw = base64_decode(substr($enc, strlen($prefix)), true);
            self::assertIsString($raw);
            self::assertSame('X', $c->decrypt($prefix . base64_encode($raw)));
            for ($i = 0; $i < strlen($raw); $i++) {
                $bad = $raw;
                $bad[$i] = chr(ord($bad[$i]) ^ 0x01);
                self::assertNull($c->decrypt($prefix . base64_encode($bad)), $prefix . ' bajt ' . $i);
            }
            self::assertNull($c->decrypt($prefix . base64_encode(substr($raw, 0, -1))), $prefix . ' skraćeno');
        }
    }

    public function testPogresanKljucNikadNeVracaCistTekst(): void
    {
        $c = new SecretCipher(str_repeat('a', 64));
        $other = new SecretCipher(str_repeat('c', 64));
        for ($i = 0; $i < 50; $i++) {
            $plain = 'JBSWY3DPEHPK3PXP' . $i;
            self::assertNull($other->decrypt($c->encrypt($plain)));
            self::assertNull($other->decrypt($c->encryptWithOpenSsl($plain)));
        }
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
