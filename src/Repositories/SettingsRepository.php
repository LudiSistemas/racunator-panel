<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

/** Ključ/vrednost podešavanja (public_url, version, install_lock, accountant_api_*, last_update_check…). */
interface SettingsRepository
{
    public function get(string $key, ?string $default = null): ?string;
    public function set(string $key, string $value): void;
    public function delete(string $key): void;
}
