<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * Privremena blokada IP adrese posle uzastopnih promašaja ključa API-ja za knjigovođu. Promašaji se beleže u postojećoj
 * tabeli `login_attempts` pod posebnom oznakom umesto korisničkog imena (`api:accountant` — dvotačka nije dozvoljena u
 * imenima naloga, pa nema mešanja sa prijavama). Broji se samo po adresi (IPv6 po /64, `IpAddress::limiterKey`):
 * MAX_FAILS promašaja u WINDOW sekundi → blokada do isteka prozora (računato od prvog promašaja u prozoru).
 * Namerno nema brojača „sa svih adresa": napadač ne sme moći da zaključa knjigovođin program sa svoje adrese.
 */
final class ApiAuthFailLimiter
{
    public const MARKER = 'api:accountant';
    public const MAX_FAILS = 10;
    public const WINDOW = 900;
    private const KEEP = 86400;

    /** @var \Closure():int */
    private readonly \Closure $clock;

    public function __construct(private readonly \PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Sekunde do kraja blokade adrese, ili null ako adresa nije blokirana. */
    public function secondsUntilAllowed(string $ip): ?int
    {
        $now = ($this->clock)();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c, MIN(at) AS first FROM login_attempts WHERE ip = ? AND username = ? AND at > ?');
        $stmt->execute([IpAddress::limiterKey($ip), self::MARKER, gmdate('Y-m-d H:i:s', $now - self::WINDOW)]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['c'] < self::MAX_FAILS || $row['first'] === null) {
            return null;
        }
        $first = strtotime((string) $row['first'] . ' UTC');
        return max(1, $first + self::WINDOW - $now);
    }

    /** Beleži promašaj; vraća broj promašaja sa te adrese u tekućem prozoru (uključujući ovaj). */
    public function hit(string $ip): int
    {
        $now = ($this->clock)();
        $key = IpAddress::limiterKey($ip);
        $this->pdo->prepare('INSERT INTO login_attempts (ip, username, at) VALUES (?, ?, ?)')
            ->execute([$key, self::MARKER, gmdate('Y-m-d H:i:s', $now)]);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND at < ?')
            ->execute([self::MARKER, gmdate('Y-m-d H:i:s', $now - self::KEEP)]);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND at > ?');
        $stmt->execute([$key, self::MARKER, gmdate('Y-m-d H:i:s', $now - self::WINDOW)]);
        return (int) $stmt->fetchColumn();
    }

    /** Posle uspešne provere ključa brišu se promašaji sa te adrese. */
    public function clear(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND username = ?')
            ->execute([IpAddress::limiterKey($ip), self::MARKER]);
    }
}
