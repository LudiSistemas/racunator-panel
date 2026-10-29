<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\PasswordHasher;

final class PasswordHasherTest extends TestCase
{
    public function testHesIProvera(): void
    {
        $h = new PasswordHasher();
        $hash = $h->hash('tajna-lozinka-123');
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', $hash);
        }
        self::assertTrue($h->verify('tajna-lozinka-123', $hash));
        self::assertFalse($h->verify('pogresna', $hash));
        self::assertFalse($h->verify('x', ''));
        self::assertFalse($h->needsRehash($hash));
        self::assertTrue($h->needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])) || !defined('PASSWORD_ARGON2ID'));
    }

    public function testJacinaNoveLozinke(): void
    {
        $h = new PasswordHasher();
        self::assertNotNull($h->validateNew('kratka'));
        self::assertNull($h->validateNew('dovoljno-duga'));
    }
}
