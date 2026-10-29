<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Models\User;

interface UserRepository
{
    public function findById(int $id): ?User;
    public function findByUsername(string $username): ?User;
    /** @return list<User> */
    public function all(): array;
    public function countAdmins(): int;
    public function create(string $username, string $passwordHash, string $role, ?int $createdBy): User;
    public function updatePassword(int $id, string $passwordHash): void;
    public function updateTotpSecret(int $id, ?string $secret): void;
    public function setActive(int $id, bool $active): void;
    public function touchLogin(int $id): void;
    /** Povećava `session_version` naloga: sve postojeće sesije tog naloga prestaju da važe. */
    public function bumpSessionVersion(int $id): void;
}
