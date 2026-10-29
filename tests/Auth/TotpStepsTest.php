<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\Totp;
use RacunatorPanel\Auth\TotpSteps;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Tests\Panel\Fake\InMemorySettingsRepository;
use RacunatorPanel\Tests\Support\TestDb;

final class TotpStepsTest extends TestCase
{
    public function testKodSeTrosi(): void
    {
        foreach ([new PdoSettingsRepository(TestDb::pdo()), new InMemorySettingsRepository()] as $settings) {
            $steps = new TotpSteps($settings);
            $secret = Totp::generateSecret();
            $now = 1_800_000_000;
            $code = Totp::code($secret, $now);
            self::assertTrue($steps->verifyAndConsume(7, $secret, $code, $now));
            self::assertFalse($steps->verifyAndConsume(7, $secret, $code, $now), 'isti kod drugi put');
            self::assertSame(intdiv($now, 30), $steps->last(7));
            self::assertTrue($steps->verifyAndConsume(7, $secret, Totp::code($secret, $now + 30), $now + 30));
            self::assertTrue($steps->verifyAndConsume(8, $secret, $code, $now), 'drugi nalog ima svoj brojač');
            self::assertFalse($steps->verifyAndConsume(9, $secret, 'abcdef', $now));
        }
    }
}
