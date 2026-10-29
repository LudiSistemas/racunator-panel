<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Services;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Services\KeyGenerator;
use RacunatorPanel\Services\QrPayload;

final class KeyGeneratorTest extends TestCase
{
    public function testSlugIKljuc(): void
    {
        $slug = KeyGenerator::slug();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $slug);
        self::assertNotSame($slug, KeyGenerator::slug());

        $key = KeyGenerator::apiKey();
        self::assertMatchesRegularExpression('/^rk_[A-Za-z0-9_-]{43}$/', $key);
        self::assertTrue(KeyGenerator::looksLikeKey($key));
        self::assertSame(substr($key, 3, 8), KeyGenerator::prefix($key));
        self::assertSame(64, strlen(KeyGenerator::hash($key)));
        self::assertTrue(KeyGenerator::verify($key, KeyGenerator::hash($key)));
        self::assertFalse(KeyGenerator::verify($key . 'x', KeyGenerator::hash($key)));
        self::assertSame(hash('sha256', 'rk_abc'), KeyGenerator::hash('rk_abc'));
        // Poziv preko instance takođe radi.
        self::assertSame(32, strlen((new KeyGenerator())->slug()));
    }

    public function testQrPayload(): void
    {
        $client = new Client(1, 'Pera „Žika" DOO', str_repeat('ab', 16), 'p', 'h', true, null, '2026-10-29 10:00:00', null);
        $json = QrPayload::build($client, 'rk_KLJUC', 'https://domen.rs/panel/', 'Knjigovodstvo XY');
        self::assertSame(
            '{"v":1,"t":"racunator-knjigovodja","endpoint":"https://domen.rs/panel/u/abababababababababababababababab",'
            . '"name":"Pera „Žika\" DOO","key":"rk_KLJUC","accountant":"Knjigovodstvo XY"}',
            $json,
        );
    }
}
