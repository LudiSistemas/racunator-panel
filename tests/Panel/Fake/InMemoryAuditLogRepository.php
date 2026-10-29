<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Repositories\AuditLogRepository;

final class InMemoryAuditLogRepository implements AuditLogRepository
{
    /** @var list<array{id:int,user_id:?int,username:?string,action:string,subject_type:?string,subject_id:?int,at:string,ip:string,details:?string}> */
    public array $entries = [];

    public function log(?int $userId, string $action, ?string $subjectType, ?int $subjectId, string $ip, ?string $details = null): void
    {
        $this->entries[] = [
            'id' => count($this->entries) + 1, 'user_id' => $userId, 'username' => $userId !== null ? 'nalog' . $userId : null,
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'at' => gmdate('Y-m-d H:i:s'),
            'ip' => $ip, 'details' => $details,
        ];
    }

    public function recent(int $limit = 100): array
    {
        return array_slice(array_reverse($this->entries), 0, $limit);
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_column($this->entries, 'action');
    }
}
