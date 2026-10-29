<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Db\Connection;

/**
 * Ograničenje broja API zahteva po korisniku (ključu): fiksni prozor od 60 s u tabeli `api_rate`
 * (jedan red po korisniku — tabela ne raste). Podrazumevano 60 zahteva u minutu.
 */
final class ApiRateLimiter
{
    /** @var \Closure():int */
    private readonly \Closure $clock;

    public function __construct(private readonly \PDO $pdo, private readonly int $limit = 60, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Beleži zahtev; vraća null ako je dozvoljen, ili broj sekundi do sledećeg prozora (za Retry-After). */
    public function hit(int $clientId): ?int
    {
        $now = ($this->clock)();
        $window = intdiv($now, 60) * 60;
        $update = $this->pdo->prepare('UPDATE api_rate SET hits = CASE WHEN window_start = ? THEN hits + 1 ELSE 1 END,'
            . ' window_start = ? WHERE client_id = ?');
        $update->execute([$window, $window, $clientId]);
        if ($update->rowCount() === 0) {
            try {
                $this->pdo->prepare('INSERT INTO api_rate (client_id, window_start, hits) VALUES (?, ?, 1)')
                    ->execute([$clientId, $window]);
            } catch (\PDOException $e) {
                if (!Connection::isUniqueViolation($e)) {
                    throw $e;
                }
                $update->execute([$window, $window, $clientId]);
            }
        }
        $stmt = $this->pdo->prepare('SELECT hits FROM api_rate WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $hits = (int) ($stmt->fetch()['hits'] ?? 0);
        return $hits > $this->limit ? max(1, $window + 60 - $now) : null;
    }
}
