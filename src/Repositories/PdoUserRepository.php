<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Models\User;

/**
 * Nalozi u tabeli `users`. Korisničko ime se čuva i traži u malim slovima (bez razmaka na krajevima),
 * da bi se MySQL (neosetljiv na veličinu slova) i SQLite (osetljiv) ponašali isto.
 */
final class PdoUserRepository implements UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username), 'UTF-8');
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::map($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([self::normalizeUsername($username)]);
        $row = $stmt->fetch();
        return $row ? self::map($row) : null;
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM users ORDER BY username')->fetchAll();
        return array_map([self::class, 'map'], $rows);
    }

    public function countAdmins(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE role = ? AND active = 1');
        $stmt->execute([User::ROLE_ADMIN]);
        return (int) $stmt->fetch()['c'];
    }

    public function create(string $username, string $passwordHash, string $role, ?int $createdBy): User
    {
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_STAFF], true)) {
            throw new \InvalidArgumentException('Nepoznata uloga: ' . $role);
        }
        $username = self::normalizeUsername($username);
        if ($username === '') {
            throw new \InvalidArgumentException('Korisničko ime je obavezno.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash, role, totp_secret, active, created_by, created_at)'
            . ' VALUES (?, ?, ?, NULL, 1, ?, ?)');
        $stmt->execute([$username, $passwordHash, $role, $createdBy, gmdate('Y-m-d H:i:s')]);
        $user = $this->findById((int) $this->pdo->lastInsertId());
        if ($user === null) {
            throw new \RuntimeException('Nalog nije upisan.');
        }
        return $user;
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $id]);
    }

    public function updateTotpSecret(int $id, ?string $secret): void
    {
        $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$secret, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    /** @param array<string,mixed> $r */
    private static function map(array $r): User
    {
        return new User(
            (int) $r['id'],
            (string) $r['username'],
            (string) $r['password_hash'],
            (string) $r['role'],
            $r['totp_secret'] !== null && $r['totp_secret'] !== '' ? (string) $r['totp_secret'] : null,
            (bool) (int) $r['active'],
            $r['created_by'] !== null ? (int) $r['created_by'] : null,
            (string) $r['created_at'],
            $r['last_login_at'] !== null ? (string) $r['last_login_at'] : null,
        );
    }
}
