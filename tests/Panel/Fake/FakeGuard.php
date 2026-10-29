<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Auth\AccountGuard;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\SessionGuard;
use RacunatorPanel\Auth\Totp;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\UserRepository;

/**
 * Lažna prijava: drži ID prijavljenog naloga u memoriji, CSRF token je fiksan. Prijava u dva koraka po uzoru na
 * SessionGuard (nalog sa 2FA uvek prelazi na korak za kod; ishod lozinke se saopštava tek posle koda).
 * `sessionVersion` (ako je postavljen pri prijavi) se poredi sa nalogom kao u jezgru.
 */
final class FakeGuard implements AccountGuard
{
    public const TOKEN = 'test-csrf-token';
    public ?int $userId = null;
    public ?int $sessionVersion = null;
    /** @var array{uid:int,username:string,ok:bool}|null */
    public ?array $pending = null;
    public int $refreshes = 0;

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function user(): ?User
    {
        $user = $this->userId === null ? null : $this->users->findById($this->userId);
        if ($user !== null && $this->sessionVersion !== null && $user->sessionVersion !== $this->sessionVersion) {
            return null;
        }
        return $user !== null && $user->active ? $user : null;
    }

    public function login(Request $request, string $username, string $password, ?string $totpCode): ?string
    {
        $this->pending = null;
        $user = $this->users->findByUsername($username);
        if ($user === null) {
            return SessionGuard::BAD_CREDENTIALS;
        }
        $ok = (new PasswordHasher())->verify($password, $user->passwordHash);
        if ($user->totpSecret !== null) {
            if ($totpCode === null || $totpCode === '') {
                $this->pending = ['uid' => $user->id, 'username' => $username, 'ok' => $ok];
                return SessionGuard::NEEDS_TOTP;
            }
            return $ok && Totp::verify($user->totpSecret, $totpCode) ? $this->complete($user) : SessionGuard::BAD_TOTP;
        }
        return $ok ? $this->complete($user) : SessionGuard::BAD_CREDENTIALS;
    }

    public function pendingLogin(): ?string
    {
        return $this->pending['username'] ?? null;
    }

    public function completeLogin(Request $request, string $code): ?string
    {
        $p = $this->pending;
        $this->pending = null;
        if ($p === null) {
            return SessionGuard::PENDING_EXPIRED;
        }
        $user = $this->users->findById($p['uid']);
        if ($user === null || !$p['ok'] || $user->totpSecret === null || !Totp::verify($user->totpSecret, $code)) {
            return SessionGuard::BAD_TOTP;
        }
        return $this->complete($user);
    }

    public function cancelPendingLogin(): void
    {
        $this->pending = null;
    }

    public function refreshSession(): void
    {
        $this->refreshes++;
        $user = $this->userId === null ? null : $this->users->findById($this->userId);
        if ($user !== null && $this->sessionVersion !== null) {
            $this->sessionVersion = $user->sessionVersion;
        }
    }

    public function logout(): void
    {
        $this->userId = null;
        $this->sessionVersion = null;
    }

    public function csrfToken(): string
    {
        return self::TOKEN;
    }

    public function checkCsrf(Request $request): bool
    {
        return ($request->form['_token'] ?? null) === self::TOKEN;
    }

    private function complete(User $user): ?string
    {
        $this->userId = $user->id;
        $this->sessionVersion = $user->sessionVersion;
        return null;
    }
}
