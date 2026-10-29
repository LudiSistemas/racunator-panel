<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * TOTP po RFC 6238 (HMAC-SHA1, korak 30 s, 6 cifara), bez zavisnosti. Tajna je u base32 (RFC 4648, bez `=`).
 * Proverava se tekući korak ±1 (tolerancija za nesinhronizovan sat telefona).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nova nasumična tajna (podrazumevano 160 bita = 32 base32 znaka). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** Kod za dati trenutak (Unix vreme) ili za sada. */
    public static function code(string $secret, ?int $time = null, int $digits = self::DIGITS): string
    {
        return self::codeForStep(self::base32Decode($secret), intdiv($time ?? time(), self::PERIOD), $digits);
    }

    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1): bool
    {
        return self::verifyStep($secret, $code, $time, $window) !== null;
    }

    /**
     * Vraća korak (time step) koji odgovara kodu ili null. Pozivalac može da zapamti korak i odbije ponovnu upotrebu istog koda.
     */
    public static function verifyStep(string $secret, string $code, ?int $time = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        try {
            $key = self::base32Decode($secret);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $step = intdiv($time ?? time(), self::PERIOD);
        $found = null;
        for ($i = -$window; $i <= $window; $i++) {
            // Bez ranog izlaska: isto vreme bez obzira na to koji korak odgovara.
            if (hash_equals(self::codeForStep($key, $step + $i, self::DIGITS), $code) && $found === null) {
                $found = $step + $i;
            }
        }
        return $found;
    }

    /** `otpauth://` URI za QR kod u aplikaciji za autentifikaciju. */
    public static function uri(string $secret, string $account, string $issuer = 'Računator panel'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $bin === '' ? '' : $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(str_replace([' ', '-', '='], '', $b32));
        if ($b32 === '' || strspn($b32, self::ALPHABET) !== strlen($b32)) {
            throw new \InvalidArgumentException('Neispravna base32 tajna.');
        }
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    private static function codeForStep(string $key, int $step, int $digits): string
    {
        $msg = pack('J', $step); // 64-bitni big-endian brojač
        $h = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($h[19]) & 0x0f;
        $bin = ((ord($h[$offset]) & 0x7f) << 24)
            | (ord($h[$offset + 1]) << 16)
            | (ord($h[$offset + 2]) << 8)
            | ord($h[$offset + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
