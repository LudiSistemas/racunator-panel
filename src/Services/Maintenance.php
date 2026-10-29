<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/**
 * Režim održavanja tokom ažuriranja: fajl `storage/maintenance.flag` (JSON sa `until`). Jezgro ga proverava pre obrade
 * zahteva (API za aplikaciju vraća 503 + Retry-After). Zastavica koja je istekla (`until` u prošlosti, npr. posle pada
 * procesa usred ažuriranja) se ne poštuje, da panel ne bi ostao zaključan zauvek.
 */
final class Maintenance
{
    public const FILE = 'maintenance.flag';
    public const MAX_SECONDS = 1800;

    public static function path(string $storage): string
    {
        return rtrim($storage, '/') . '/' . self::FILE;
    }

    public static function enable(string $storage, string $reason, int $seconds = self::MAX_SECONDS): void
    {
        $data = ['since' => time(), 'until' => time() + $seconds, 'reason' => $reason];
        if (@file_put_contents(self::path($storage), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new \RuntimeException('Ne mogu da uključim režim održavanja (storage/ nije upisiv).');
        }
    }

    public static function disable(string $storage): void
    {
        if (is_file(self::path($storage))) {
            @unlink(self::path($storage));
        }
    }

    public static function isActive(string $storage, ?int $now = null): bool
    {
        $file = self::path($storage);
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        $until = is_array($data) ? (int) ($data['until'] ?? 0) : 0;
        return $until === 0 || $until > ($now ?? time());
    }

    /** Sekunde do kraja (za Retry-After). */
    public static function retryAfter(string $storage): int
    {
        $data = json_decode((string) @file_get_contents(self::path($storage)), true);
        $left = is_array($data) ? (int) ($data['until'] ?? 0) - time() : 0;
        return max(30, min(300, $left));
    }
}
