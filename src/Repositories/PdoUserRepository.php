<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Auth\SecretCipher;
use RacunatorPanel\Models\User;

/**
 * Nalozi u tabeli `users`. Korisničko ime se čuva i traži u malim slovima (bez razmaka na krajevima),
 * da bi se MySQL (neosetljiv na veličinu slova) i SQLite (osetljiv) ponašali isto.
 * TOTP tajna se uz `SecretCipher` (ključ iz app_key) upisuje šifrovana; stari zapis u čistom tekstu se čita kakav jeste i
 * šifruje pri sledećem upisu (uključivanje 2FA ili prijava). Zapis koji ne može da se dešifruje (npr. promenjen app_key)
 * ostaje u modelu kakav jeste — nijedan kod mu ne odgovara, pa nalog sa 2FA ne može da se prijavi dok administrator ne
 * resetuje 2FA (bezbedno odbijanje umesto tihog isključivanja 2FA).
 */
final class PdoUserRepository implements UserRepository
{
    public function __construct(private readonly \PDO $pdo, private readonly ?SecretCipher $cipher = null)
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
        return $row ? $this->map($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([self::normalizeUsername($username)]);
        $row = $stmt->fetch();
        return $row ? $this->map($row) : null;
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM users ORDER BY username')->fetchAll();
        return array_map(fn (array $r): User => $this->map($r), $rows);
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
        $stored = $secret === null || $this->cipher === null ? $secret : $this->cipher->encrypt($secret);
        $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$stored, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
        $this->encryptLegacySecret($id);
    }

    public function bumpSessionVersion(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$id]);
    }

    /** Stara TOTP tajna u čistom tekstu → šifrovana (ako je šifra podešena). */
    private function encryptLegacySecret(int $id): void
    {
        if ($this->cipher === null) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT totp_secret FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && $stored !== '' && !SecretCipher::isEncrypted($stored)) {
            $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ? AND totp_secret = ?')
                ->execute([$this->cipher->encrypt($stored), $id, $stored]);
        }
    }

    /** @param array<string,mixed> $r */
    private function map(array $r): User
    {
        $secret = $r['totp_secret'] !== null && $r['totp_secret'] !== '' ? (string) $r['totp_secret'] : null;
        if ($secret !== null && $this->cipher !== null) {
            $secret = $this->cipher->decrypt($secret) ?? $secret;
        }
        return new User(
            (int) $r['id'],
            (string) $r['username'],
            (string) $r['password_hash'],
            (string) $r['role'],
            $secret,
            (bool) (int) $r['active'],
            $r['created_by'] !== null ? (int) $r['created_by'] : null,
            (string) $r['created_at'],
            $r['last_login_at'] !== null ? (string) $r['last_login_at'] : null,
            (int) ($r['session_version'] ?? 1),
        );
    }
}
