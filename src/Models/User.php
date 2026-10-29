<?php
declare(strict_types=1);

namespace RacunatorPanel\Models;

/** Nalog knjigovođe ili zaposlenog. Uloge: `admin` (nalozi, API, ažuriranje) i `zaposleni` (korisnici i računi). */
final class User
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'zaposleni';

    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly ?string $totpSecret,
        public readonly bool $active,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly ?string $lastLoginAt,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
