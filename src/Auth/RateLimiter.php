<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * Ograničenje pokušaja prijave preko tabele `login_attempts` (beleže se samo neuspeli pokušaji). Tri nezavisna brojača:
 *  1. isti par IP + korisničko ime: 5 neuspelih u 15 minuta → blokada do isteka prozora;
 *  2. ista IP adresa (bilo koje ime): 30 neuspelih u 15 minuta — protiv isprobavanja mnogo naloga;
 *  3. isto korisničko ime sa BILO KOJE adrese (u poslednja 24 h), sa rastućim čekanjem od poslednjeg neuspeha:
 *     5 → 1 min, 10 → 5 min, 20 → 30 min — protiv pogađanja lozinke jednog naloga sa mnogo adresa.
 * IPv6 adrese se grupišu po /64 (`IpAddress::limiterKey`), jer korisnik obično raspolaže celim /64 blokom.
 */
final class RateLimiter
{
    public const MAX_PER_USER = 5;
    public const MAX_PER_IP = 30;
    public const WINDOW = 900;
    /** Broj neuspeha za korisničko ime (sa svih adresa) → čekanje u sekundama od poslednjeg neuspeha. */
    public const USERNAME_TIERS = [20 => 1800, 10 => 300, 5 => 60];
    private const KEEP = 86400;

    /** @var \Closure():int */
    private readonly \Closure $clock;

    public function __construct(private readonly \PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Broj sekundi čekanja ako je prijava trenutno blokirana, inače null. */
    public function secondsUntilAllowed(string $ip, string $username): ?int
    {
        $now = ($this->clock)();
        $since = gmdate('Y-m-d H:i:s', $now - self::WINDOW);
        $key = IpAddress::limiterKey($ip);
        $user = self::normalize($username);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c, MIN(at) AS first FROM login_attempts WHERE ip = ? AND username = ? AND at > ?');
        $stmt->execute([$key, $user, $since]);
        $waits = [self::waitFrom($stmt->fetch(), self::MAX_PER_USER, $now)];

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c, MIN(at) AS first FROM login_attempts WHERE ip = ? AND at > ?');
        $stmt->execute([$key, $since]);
        $waits[] = self::waitFrom($stmt->fetch(), self::MAX_PER_IP, $now);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c, MAX(at) AS last FROM login_attempts WHERE username = ? AND at > ?');
        $stmt->execute([$user, gmdate('Y-m-d H:i:s', $now - self::KEEP)]);
        $waits[] = self::usernameWait($stmt->fetch(), $now);

        $waits = array_filter($waits, static fn (?int $w): bool => $w !== null);
        return $waits === [] ? null : max($waits);
    }

    public function hit(string $ip, string $username): void
    {
        $now = ($this->clock)();
        $this->pdo->prepare('INSERT INTO login_attempts (ip, username, at) VALUES (?, ?, ?)')
            ->execute([IpAddress::limiterKey($ip), self::normalize($username), gmdate('Y-m-d H:i:s', $now)]);
        $this->cleanup();
    }

    /**
     * Posle uspešne prijave brišu se neuspeli pokušaji za taj par (IP + ime). Pokušaji istog imena sa drugih adresa
     * ostaju — ako neko napada nalog, uspešna prijava vlasnika ne poništava čekanje napadaču.
     */
    public function clear(string $ip, string $username): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND username = ?')
            ->execute([IpAddress::limiterKey($ip), self::normalize($username)]);
    }

    /** Briše zapise starije od jednog dana. */
    public function cleanup(): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE at < ?')
            ->execute([gmdate('Y-m-d H:i:s', ($this->clock)() - self::KEEP)]);
    }

    public static function message(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $word = match (true) {
            $minutes % 10 === 1 && $minutes % 100 !== 11 => 'minut',
            default => 'minuta',
        };
        return 'Previše neuspelih pokušaja prijave. Pokušajte ponovo za ' . $minutes . ' ' . $word . '.';
    }

    private static function normalize(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username), 'UTF-8'), 0, 64, 'UTF-8');
    }

    /** @param array<string,mixed>|false $row */
    private static function waitFrom(array|false $row, int $max, int $now): ?int
    {
        if (!$row || (int) $row['c'] < $max || $row['first'] === null) {
            return null;
        }
        $first = strtotime((string) $row['first'] . ' UTC');
        return max(1, $first + self::WINDOW - $now);
    }

    /** @param array<string,mixed>|false $row */
    private static function usernameWait(array|false $row, int $now): ?int
    {
        if (!$row || $row['last'] === null) {
            return null;
        }
        $count = (int) $row['c'];
        foreach (self::USERNAME_TIERS as $threshold => $seconds) {
            if ($count >= $threshold) {
                $until = strtotime((string) $row['last'] . ' UTC') + $seconds;
                return $until > $now ? $until - $now : null;
            }
        }
        return null;
    }
}
