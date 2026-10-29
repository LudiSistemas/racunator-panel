<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

/** Dnevnik radnji (`audit_log`). Nikad ne upisivati ključeve ni lozinke u `details`. Vreme je UTC. */
final class PdoAuditLogRepository implements AuditLogRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function log(?int $userId, string $action, ?string $subjectType, ?int $subjectId, string $ip, ?string $details = null): void
    {
        $this->pdo->prepare('INSERT INTO audit_log (user_id, action, subject_type, subject_id, at, ip, details)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $userId,
                mb_substr($action, 0, 64),
                $subjectType !== null ? mb_substr($subjectType, 0, 32) : null,
                $subjectId,
                gmdate('Y-m-d H:i:s'),
                substr($ip, 0, 45),
                $details,
            ]);
    }

    public function recent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        $rows = $this->pdo->query('SELECT a.id, a.user_id, u.username, a.action, a.subject_type, a.subject_id, a.at, a.ip, a.details'
            . ' FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ' . $limit)->fetchAll();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'username' => $r['username'] !== null ? (string) $r['username'] : null,
            'action' => (string) $r['action'],
            'subject_type' => $r['subject_type'] !== null ? (string) $r['subject_type'] : null,
            'subject_id' => $r['subject_id'] !== null ? (int) $r['subject_id'] : null,
            'at' => (string) $r['at'],
            'ip' => (string) $r['ip'],
            'details' => $r['details'] !== null ? (string) $r['details'] : null,
        ], $rows);
    }
}
