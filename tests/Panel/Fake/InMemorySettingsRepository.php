<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Repositories\SettingsRepository;

final class InMemorySettingsRepository implements SettingsRepository
{
    /** @var array<string,string> */
    public array $values = [];

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}
