<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Repositories\SettingsRepository;

final class MemorySettings implements SettingsRepository
{
    public array $data = [];
    public function get(string $key, ?string $default = null): ?string { return $this->data[$key] ?? $default; }
    public function set(string $key, string $value): void { $this->data[$key] = $value; }
    public function delete(string $key): void { unset($this->data[$key]); }
}
