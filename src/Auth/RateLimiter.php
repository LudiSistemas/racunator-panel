<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * Ograničenje pokušaja prijave preko tabele `login_attempts` (beleže se samo neuspeli pokušaji):
 * najviše 5 neuspelih za isti par IP + korisničko ime u 15 minuta, i najviše 30 sa iste IP adrese
 * (bilo koje korisničko ime) — protiv isprobavanja mnogo naloga.
 */
final class RateLimiter
{
    public const MAX_PER_USER = 5;
    public const MAX_PER_IP = 30;
    public const WINDOW = 900;
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
        $user = self::normalize($username);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c, MIN(at) AS first FROM login_attempts WHERE ip = ? AND username = ? AND at > ?');
        $stmt->execute([$ip, $user, $since]);
        $wait = self::waitFrom($stmt->fetch(), self::MAX_PER_USER, $now);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c, MIN(at) AS first FROM login_attempts WHERE ip = ? AND at > ?');
        $stmt->execute([$ip, $since]);
        $waitIp = self::waitFrom($stmt->fetch(), self::MAX_PER_IP, $now);

        if ($wait === null && $waitIp === null) {
            return null;
        }
        return max((int) $wait, (int) $waitIp);
    }

    public function hit(string $ip, string $username): void
    {
        $now = ($this->clock)();
        $this->pdo->prepare('INSERT INTO login_attempts (ip, username, at) VALUES (?, ?, ?)')
            ->execute([substr($ip, 0, 45), self::normalize($username), gmdate('Y-m-d H:i:s', $now)]);
        $this->cleanup();
    }

    /** Posle uspešne prijave brišu se neuspeli pokušaji za taj par. */
    public function clear(string $ip, string $username): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND username = ?')->execute([$ip, self::normalize($username)]);
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
}
