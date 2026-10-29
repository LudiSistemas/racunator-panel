<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

interface AuditLogRepository
{
    /** Radnje: login, logout, client.create, client.rotate_key, client.deactivate, user.create, user.deactivate, api.enable, api.disable, update.run, update.revert… */
    public function log(?int $userId, string $action, ?string $subjectType, ?int $subjectId, string $ip, ?string $details = null): void;
    /** @return list<array{id:int,user_id:?int,username:?string,action:string,subject_type:?string,subject_id:?int,at:string,ip:string,details:?string}> */
    public function recent(int $limit = 100): array;
}
