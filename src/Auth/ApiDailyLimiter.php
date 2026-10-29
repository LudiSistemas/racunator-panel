<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Db\Connection;

/**
 * Dnevni broj prihvaćenih računa po korisniku (tabela `api_daily`, jedan red po korisniku; dan je UTC datum).
 * Brojač se menja u istoj transakciji kao upis računa: `add()` vraća novi zbir za danas, pa pozivalac može da poništi
 * transakciju ako je zbir prešao limit. `markWarned()` beleži da je upozorenje (50 % ili 100 %) za danas već upisano.
 */
final class ApiDailyLimiter
{
    public const DEFAULT_LIMIT = 2000;
    public const WARN_HALF = 1;
    public const WARN_LIMIT = 2;

    /** @var \Closure():int */
    private readonly \Closure $clock;

    public function __construct(private readonly \PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function today(): string
    {
        return gmdate('Y-m-d', ($this->clock)());
    }

    /** Sekunde do početka sledećeg (UTC) dana — za Retry-After. */
    public function secondsUntilTomorrow(): int
    {
        $now = ($this->clock)();
        return max(1, (intdiv($now, 86400) + 1) * 86400 - $now);
    }

    /** Broj prihvaćenih računa danas. */
    public function acceptedToday(int $clientId): int
    {
        $row = $this->row($clientId);
        return $row !== null && $row['day'] === $this->today() ? $row['accepted'] : 0;
    }

    /** Dodaje $count prihvaćenih računa za danas i vraća novi zbir. */
    public function add(int $clientId, int $count): int
    {
        $day = $this->today();
        $update = $this->pdo->prepare('UPDATE api_daily SET accepted = CASE WHEN day = ? THEN accepted + ? ELSE ? END,'
            . ' warned = CASE WHEN day = ? THEN warned ELSE 0 END, day = ? WHERE client_id = ?');
        $update->execute([$day, $count, $count, $day, $day, $clientId]);
        if ($update->rowCount() === 0) {
            try {
                $this->pdo->prepare('INSERT INTO api_daily (client_id, day, accepted, warned) VALUES (?, ?, ?, 0)')
                    ->execute([$clientId, $day, $count]);
            } catch (\PDOException $e) {
                if (!Connection::isUniqueViolation($e)) {
                    throw $e;
                }
                $update->execute([$day, $count, $count, $day, $day, $clientId]);
            }
        }
        return $this->acceptedToday($clientId);
    }

    /** Upozorenje nivoa $level (WARN_HALF ili WARN_LIMIT) za danas: true ako ranije nije upisano (pa ga treba zabeležiti). */
    public function markWarned(int $clientId, int $level): bool
    {
        $day = $this->today();
        $stmt = $this->pdo->prepare('UPDATE api_daily SET warned = ? WHERE client_id = ? AND day = ? AND warned < ?');
        $stmt->execute([$level, $clientId, $day, $level]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        if ($this->row($clientId) !== null) {
            return false;
        }
        // Korisnik danas još nema red (npr. limit 0 računa): pravimo ga sa nulom.
        $this->add($clientId, 0);
        $stmt->execute([$level, $clientId, $day, $level]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{day:string,accepted:int,warned:int}|null */
    private function row(int $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT day, accepted, warned FROM api_daily WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $r = $stmt->fetch();
        return $r ? ['day' => (string) $r['day'], 'accepted' => (int) $r['accepted'], 'warned' => (int) $r['warned']] : null;
    }
}
