<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * Heširanje lozinki: Argon2id kad ga PHP podržava (na nekim hostinzima nedostaje — tada bcrypt).
 * `needsRehash` javlja kad treba prepisati heš (npr. posle prelaska hostinga na Argon2id).
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 10;

    public function hash(string $password): string
    {
        return password_hash($password, self::algorithm(), self::options());
    }

    public function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /** Osnovna provera jačine nove lozinke; vraća poruku o grešci ili null. */
    public function validateNew(string $password): ?string
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_LENGTH) {
            return 'Lozinka mora imati najmanje ' . self::MIN_LENGTH . ' znakova.';
        }
        if (strlen($password) > 1024) {
            return 'Lozinka je predugačka.';
        }
        return null;
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string,int> */
    private static function options(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [
                'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
                'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
                'threads' => 1,
            ];
        }
        return ['cost' => 12];
    }
}
