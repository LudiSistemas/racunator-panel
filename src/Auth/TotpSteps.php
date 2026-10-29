<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Repositories\AtomicCounter;
use RacunatorPanel\Repositories\SettingsRepository;

/**
 * Poslednji iskorišćen TOTP korak po nalogu (settings `totp_last_step.<id>`): kod koji je jednom prihvaćen (prijava,
 * uključivanje ili isključivanje 2FA) ne može ponovo da se upotrebi. Upis je atomičan kad skladište to podržava
 * (`AtomicCounter`, npr. PdoSettingsRepository).
 */
final class TotpSteps
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public static function key(int $userId): string
    {
        return 'totp_last_step.' . $userId;
    }

    public function last(int $userId): int
    {
        return (int) ($this->settings->get(self::key($userId), '0') ?? 0);
    }

    /** Proverava kod i troši njegov korak. True samo ako je kod tačan i njegov korak još nije iskorišćen. */
    public function verifyAndConsume(int $userId, string $secret, string $code, ?int $time = null): bool
    {
        $step = Totp::verifyStep($secret, $code, $time);
        return $step !== null && $this->consume($userId, $step);
    }

    /** Beleži korak kao iskorišćen; false ako je isti ili noviji korak već iskorišćen. */
    public function consume(int $userId, int $step): bool
    {
        if ($this->settings instanceof AtomicCounter) {
            return $this->settings->advanceCounter(self::key($userId), $step);
        }
        if ($step <= $this->last($userId)) {
            return false;
        }
        $this->settings->set(self::key($userId), (string) $step);
        return true;
    }
}
