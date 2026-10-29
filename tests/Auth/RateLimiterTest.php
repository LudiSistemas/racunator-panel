<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Auth\RateLimiter;
use RacunatorPanel\Tests\Support\TestDb;

final class RateLimiterTest extends TestCase
{
    public function testPetNeuspelihPa15Minuta(): void
    {
        $now = 1_800_000_000;
        $pdo = TestDb::pdo();
        $rl = new RateLimiter($pdo, static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < 4; $i++) {
            $rl->hit('1.1.1.1', 'Ana');
            $now += 10;
        }
        self::assertNull($rl->secondsUntilAllowed('1.1.1.1', 'ana'));
        $rl->hit('1.1.1.1', 'ana');
        $wait = $rl->secondsUntilAllowed('1.1.1.1', 'ANA');
        self::assertSame(900 - 40, $wait);
        self::assertStringContainsString('15 minuta', RateLimiter::message((int) $wait));
        // Drugi nalog i druga IP adresa nisu blokirani.
        self::assertNull($rl->secondsUntilAllowed('1.1.1.1', 'pera'));
        self::assertNull($rl->secondsUntilAllowed('2.2.2.2', 'ana'));
        // Kad najstariji pokušaj izađe iz prozora, prijava je ponovo dozvoljena.
        $now += 900 - 40;
        self::assertNull($rl->secondsUntilAllowed('1.1.1.1', 'ana'));
    }

    public function testBrisanjePosleUspehaIStarihZapisa(): void
    {
        $now = 1_800_000_000;
        $pdo = TestDb::pdo();
        $rl = new RateLimiter($pdo, static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < 5; $i++) {
            $rl->hit('1.1.1.1', 'ana');
        }
        self::assertNotNull($rl->secondsUntilAllowed('1.1.1.1', 'ana'));
        $rl->clear('1.1.1.1', 'ana');
        self::assertNull($rl->secondsUntilAllowed('1.1.1.1', 'ana'));

        $rl->hit('3.3.3.3', 'x');
        $now += 86400 + 1;
        $rl->cleanup();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function testLimitPoIpAdresi(): void
    {
        $pdo = TestDb::pdo();
        $rl = new RateLimiter($pdo, static fn (): int => 1_800_000_000);
        for ($i = 0; $i < RateLimiter::MAX_PER_IP; $i++) {
            $rl->hit('9.9.9.9', 'nalog' . $i);
        }
        self::assertNotNull($rl->secondsUntilAllowed('9.9.9.9', 'novi'));
    }

    public function testPoruka(): void
    {
        self::assertSame('Previše neuspelih pokušaja prijave. Pokušajte ponovo za 1 minut.', RateLimiter::message(30));
        self::assertStringContainsString('za 2 minuta', RateLimiter::message(61));
        self::assertStringContainsString('za 21 minut.', RateLimiter::message(21 * 60));
    }

    public function testApiLimit(): void
    {
        $now = 1_800_000_010;
        $pdo = TestDb::pdo();
        $rl = new ApiRateLimiter($pdo, 3, static function () use (&$now): int {
            return $now;
        });
        self::assertNull($rl->hit(1));
        self::assertNull($rl->hit(1));
        self::assertNull($rl->hit(1));
        $wait = $rl->hit(1);
        self::assertNotNull($wait);
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(60, $wait);
        self::assertNull($rl->hit(2));
        $now += 60;
        self::assertNull($rl->hit(1));
    }
}
