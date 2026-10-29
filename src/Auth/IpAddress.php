<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * Pomoćne funkcije za IP adrese: provera pripadnosti CIDR opsegu (pouzdani proksiji) i ključ za ograničenje pokušaja
 * prijave (IPv6 se grupiše po /64 — jedan korisnik obično dobija ceo /64 blok, pa bi brojanje po pojedinačnoj adresi
 * bilo lako zaobići).
 */
final class IpAddress
{
    public static function isValid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** Da li adresa pripada opsegu `a.b.c.d/n`, `x::/n` ili je jednaka adresi bez maske. Neispravan opseg = ne pripada. */
    public static function inRange(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $ipBin = @inet_pton(self::unmapIpv4($ip));
        $netBin = @inet_pton(self::unmapIpv4(trim($net)));
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        if ($bits === null) {
            $bits = $max;
        } elseif (!ctype_digit($bits) || (int) $bits > $max) {
            return false;
        }
        $bits = (int) $bits;
        $full = intdiv($bits, 8);
        if (substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
    }

    /** @param iterable<string> $ranges */
    public static function inAny(string $ip, iterable $ranges): bool
    {
        foreach ($ranges as $range) {
            if (is_string($range) && $range !== '' && self::inRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ključ za brojanje pokušaja prijave: IPv4 kao jeste, IPv6 kao njegov /64 blok (npr. `2001:db8:1:2::/64`).
     * IPv4 upakovan u IPv6 (`::ffff:1.2.3.4`) se svodi na IPv4.
     */
    public static function limiterKey(string $ip): string
    {
        $ip = self::unmapIpv4(trim($ip));
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return substr($ip, 0, 45);
        }
        if (strlen($bin) === 4) {
            return (string) inet_ntop($bin);
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    private static function unmapIpv4(string $ip): string
    {
        return preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) ? $m[1] : $ip;
    }
}
