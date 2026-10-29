<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/**
 * Nasumični identifikatori korisnika. Sve metode su statičke (mogu se zvati i preko instance).
 *  - slug: 32 hex (128 bita) — deo adrese `/u/{slug}`, nije tajna sam po sebi;
 *  - API ključ: `rk_` + 43 znaka base64url (256 bita) — tajna; u bazi se čuva samo SHA-256 heš i prefiks.
 */
final class KeyGenerator
{
    public const KEY_PREFIX = 'rk_';

    public static function slug(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function apiKey(): string
    {
        return self::KEY_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Prvih 8 znakova posle `rk_` (za prikaz i brzu proveru; nije dovoljno za pogađanje ključa). */
    public static function prefix(string $key): string
    {
        $body = str_starts_with($key, self::KEY_PREFIX) ? substr($key, strlen(self::KEY_PREFIX)) : $key;
        return substr($body, 0, 8);
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function verify(string $key, string $hash): bool
    {
        return hash_equals($hash, self::hash($key));
    }

    /** Da li niz ima oblik API ključa (`rk_` + 43 znaka base64url). */
    public static function looksLikeKey(string $key): bool
    {
        return preg_match('/^rk_[A-Za-z0-9_-]{43}$/', $key) === 1;
    }
}
