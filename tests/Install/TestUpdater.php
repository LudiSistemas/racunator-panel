<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Install\Updater;
use RacunatorPanel\Services\Maintenance;

require_once __DIR__ . '/../../install/bootstrap.php';

/** Updater sa podmetnutim migracijama i samoproverom. */
final class TestUpdater extends Updater
{
    /** @var (\Closure(\PDO):list<string>)|null */
    public ?\Closure $migrations = null;
    /** @var list<string> redom vraćani rezultati probeHealth */
    public array $health = [];
    public bool $flagSeenDuringMigrations = false;
    /** Simulacija hostinga bez ekstenzije sodium (vratiti na false u tearDown). */
    public static bool $noSodium = false;

    protected static function sodiumAvailable(): bool
    {
        return !self::$noSodium && parent::sodiumAvailable();
    }

    protected function runMigrations(): array
    {
        $this->flagSeenDuringMigrations = Maintenance::isActive($this->storage);
        return $this->migrations !== null ? ($this->migrations)($this->pdo) : parent::runMigrations();
    }

    protected function probeHealth(?string $expect = null): string
    {
        return $this->health === [] ? 'nedostupno' : (count($this->health) > 1 ? array_shift($this->health) : $this->health[0]);
    }
}
