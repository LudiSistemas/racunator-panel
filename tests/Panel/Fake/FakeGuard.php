<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Auth\Guard;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\SessionGuard;
use RacunatorPanel\Auth\Totp;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\UserRepository;

/** Lažna prijava: drži ID prijavljenog naloga u memoriji, CSRF token je fiksan. */
final class FakeGuard implements Guard
{
    public const TOKEN = 'test-csrf-token';
    public ?int $userId = null;

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function user(): ?User
    {
        $user = $this->userId === null ? null : $this->users->findById($this->userId);
        return $user !== null && $user->active ? $user : null;
    }

    public function login(Request $request, string $username, string $password, ?string $totpCode): ?string
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || !(new PasswordHasher())->verify($password, $user->passwordHash)) {
            return 'Pogrešno korisničko ime ili lozinka.';
        }
        if ($user->totpSecret !== null) {
            if ($totpCode === null || $totpCode === '') {
                return SessionGuard::NEEDS_TOTP;
            }
            if (!Totp::verify($user->totpSecret, $totpCode)) {
                return SessionGuard::BAD_TOTP;
            }
        }
        $this->userId = $user->id;
        return null;
    }

    public function logout(): void
    {
        $this->userId = null;
    }

    public function csrfToken(): string
    {
        return self::TOKEN;
    }

    public function checkCsrf(Request $request): bool
    {
        return ($request->form['_token'] ?? null) === self::TOKEN;
    }
}
