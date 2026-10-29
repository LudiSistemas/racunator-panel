<?php
declare(strict_types=1);

namespace RacunatorPanel\Kernel;

/**
 * Konfiguracija instalacije iz `config.php` (van `public/`; piše ga instaler). Oblik:
 * return [
 *   'db' => ['driver' => 'mysql'|'sqlite', 'dsn' => 'mysql:host=…;dbname=…;charset=utf8mb4' | 'sqlite:/putanja/panel.sqlite',
 *            'user' => '', 'pass' => ''],
 *   'public_url' => 'https://domen.rs/panel',  // bez završne kose crte
 *   'app_key'    => '<64 hex>',                // tajna instalacije (sesije, potpisi)
 *   'storage'    => '/apsolutna/putanja/storage',
 *   'trusted_proxies' => ['10.0.0.0/8'],      // opciono: samo sa ovih adresa se veruje X-Forwarded-For/Proto
 *   'force_https' => false,                   // opciono: preusmeri HTTP → HTTPS (osim /health)
 * ];
 */
final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Nema config.php — pokrenite instalaciju.');
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException('config.php mora da vrati niz.');
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cur = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public function publicUrl(): string
    {
        return rtrim((string) $this->get('public_url', ''), '/');
    }

    public function storagePath(): string
    {
        return rtrim((string) $this->get('storage', dirname(__DIR__, 2) . '/storage'), '/');
    }
}
