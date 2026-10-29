<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * Šifrovanje tajni u bazi (TOTP tajne naloga) ključem izvedenim iz `app_key` iz config.php.
 * Oblik zapisa: `enc:s1:<base64(nonce|šifrat)>` (sodium secretbox, XSalsa20-Poly1305) ili
 * `enc:g1:<base64(iv|tag|šifrat)>` (OpenSSL AES-256-GCM, kad nema ekstenzije sodium). Vrednost bez prefiksa `enc:` je
 * stari zapis u čistom tekstu — čita se kakva jeste i šifruje pri sledećem upisu.
 * Ko ima bazu bez config.php ne može da pročita tajne; ko ima i config.php (npr. cela rezervna kopija) — može.
 */
final class SecretCipher
{
    public const PREFIX = 'enc:';
    private const SODIUM = 'enc:s1:';
    private const GCM = 'enc:g1:';
    private const GCM_AAD = 'racunator-panel/secret';

    private readonly string $key;

    public function __construct(string $appKey)
    {
        if ($appKey === '') {
            throw new \InvalidArgumentException('Za šifrovanje je potreban app_key.');
        }
        // 32 bajta, nezavisno od oblika app_key; drugi namenski string → drugi ključ za drugu namenu.
        $this->key = hash_hmac('sha256', 'racunator-panel/totp-secret/v1', $appKey, true);
    }

    /** Šifra iz app_key, ili null ako ključ nije podešen (tada se tajne čuvaju kao do sada). */
    public static function fromAppKey(?string $appKey): ?self
    {
        return $appKey === null || $appKey === '' ? null : new self($appKey);
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plain): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return self::SODIUM . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
        }
        return $this->encryptWithOpenSsl($plain);
    }

    /** Čist tekst; vrednost bez prefiksa `enc:` se vraća neizmenjena; null = ne može da se dešifruje (drugi ključ, oštećeno). */
    public function decrypt(string $stored): ?string
    {
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        if (str_starts_with($stored, self::SODIUM)) {
            $raw = base64_decode(substr($stored, strlen(self::SODIUM)), true);
            if (!function_exists('sodium_crypto_secretbox_open') || $raw === false
                || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key,
            );
            return $plain === false ? null : $plain;
        }
        if (str_starts_with($stored, self::GCM)) {
            $raw = base64_decode(substr($stored, strlen(self::GCM)), true);
            if ($raw === false || strlen($raw) <= 28) {
                return null;
            }
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12),
                substr($raw, 12, 16), self::GCM_AAD);
            return $plain === false ? null : $plain;
        }
        return null;
    }

    /** AES-256-GCM preko OpenSSL-a (rezerva kad nema sodium-a; javno i radi testova zapisa `enc:g1:`). */
    public function encryptWithOpenSsl(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::GCM_AAD, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Šifrovanje nije uspelo (OpenSSL).');
        }
        return self::GCM . base64_encode($iv . $tag . $cipher);
    }
}
