<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\UserRepository;

/** Nalozi u memoriji (za testove panela). */
final class InMemoryUserRepository implements UserRepository
{
    /** @var array<int,User> */
    public array $users = [];
    private int $nextId = 1;

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->users as $u) {
            if (strcasecmp($u->username, $username) === 0) {
                return $u;
            }
        }
        return null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function countAdmins(): int
    {
        return count(array_filter($this->users, static fn (User $u): bool => $u->isAdmin() && $u->active));
    }

    public function create(string $username, string $passwordHash, string $role, ?int $createdBy): User
    {
        $id = $this->nextId++;
        return $this->users[$id] = new User($id, $username, $passwordHash, $role, null, true, $createdBy, gmdate('Y-m-d H:i:s'), null);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->replace($id, ['passwordHash' => $passwordHash]);
    }

    public function updateTotpSecret(int $id, ?string $secret): void
    {
        $this->replace($id, ['totpSecret' => $secret]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->replace($id, ['active' => $active]);
    }

    public function touchLogin(int $id): void
    {
        $this->replace($id, ['lastLoginAt' => gmdate('Y-m-d H:i:s')]);
    }

    /** @param array<string,mixed> $changes */
    private function replace(int $id, array $changes): void
    {
        $u = $this->users[$id] ?? null;
        if ($u === null) {
            return;
        }
        $v = $changes + [
            'username' => $u->username, 'passwordHash' => $u->passwordHash, 'role' => $u->role, 'totpSecret' => $u->totpSecret,
            'active' => $u->active, 'createdBy' => $u->createdBy, 'createdAt' => $u->createdAt, 'lastLoginAt' => $u->lastLoginAt,
        ];
        $this->users[$id] = new User($id, $v['username'], $v['passwordHash'], $v['role'], $v['totpSecret'], $v['active'],
            $v['createdBy'], $v['createdAt'], $v['lastLoginAt']);
    }
}
