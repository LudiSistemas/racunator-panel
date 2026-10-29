<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Repositories\UserRepository;

/**
 * Prijava preko sesije. Tok `login`: rate limit → nalog i lozinka → aktivnost naloga → TOTP (ako je uključen) →
 * nov ID sesije, nov CSRF token, `touchLogin`, audit `login`. Neuspeli pokušaji idu u `login_attempts`.
 * Sesija ističe posle 2 h neaktivnosti ili 12 h od prijave.
 */
final class SessionGuard implements Guard
{
    /** Poruka kad je lozinka tačna, a nalog traži TOTP kod (panel tada prikazuje polje za kod). */
    public const NEEDS_TOTP = 'Unesite šestocifreni kod iz aplikacije za autentifikaciju.';
    public const BAD_CREDENTIALS = 'Pogrešno korisničko ime ili lozinka.';
    public const BAD_TOTP = 'Pogrešan kod za dvofaktorsku prijavu.';
    public const INACTIVE = 'Nalog je deaktiviran. Obratite se administratoru.';

    public const IDLE_TIMEOUT = 7200;
    public const ABSOLUTE_TIMEOUT = 43200;

    private const K_USER = 'uid';
    private const K_LOGIN_AT = 'login_at';
    private const K_SEEN_AT = 'seen_at';

    private ?User $user = null;
    private bool $resolved = false;
    private readonly Csrf $csrf;
    /** @var \Closure():int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly RateLimiter $limiter,
        private readonly PasswordHasher $hasher,
        private readonly SessionStore $session,
        private readonly ?SettingsRepository $settings = null,
        ?\Closure $clock = null,
    ) {
        $this->csrf = new Csrf($session);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        $id = $this->session->get(self::K_USER);
        if (!is_int($id)) {
            return null;
        }
        $now = ($this->clock)();
        $loginAt = (int) $this->session->get(self::K_LOGIN_AT, 0);
        $seenAt = (int) $this->session->get(self::K_SEEN_AT, 0);
        if ($now - $seenAt > self::IDLE_TIMEOUT || $now - $loginAt > self::ABSOLUTE_TIMEOUT) {
            $this->session->destroy();
            return null;
        }
        $user = $this->users->findById($id);
        if ($user === null || !$user->active) {
            $this->session->destroy();
            return null;
        }
        if ($now - $seenAt > 60) {
            $this->session->set(self::K_SEEN_AT, $now);
        }
        return $this->user = $user;
    }

    public function login(Request $request, string $username, string $password, ?string $totpCode): ?string
    {
        $username = trim($username);
        $ip = $request->ip;
        if ($username === '' || $password === '') {
            return self::BAD_CREDENTIALS;
        }
        $wait = $this->limiter->secondsUntilAllowed($ip, $username);
        if ($wait !== null) {
            return RateLimiter::message($wait);
        }

        $user = $this->users->findByUsername($username);
        if ($user === null) {
            // Isto trajanje kao kod pravog naloga: ne otkriva da li korisničko ime postoji.
            $this->hasher->verify($password, self::dummyHash());
            $this->limiter->hit($ip, $username);
            return self::BAD_CREDENTIALS;
        }
        if (!$this->hasher->verify($password, $user->passwordHash)) {
            $this->limiter->hit($ip, $username);
            return self::BAD_CREDENTIALS;
        }
        if (!$user->active) {
            $this->limiter->hit($ip, $username);
            return self::INACTIVE;
        }
        if ($user->totpSecret !== null) {
            $code = trim((string) $totpCode);
            if ($code === '') {
                return self::NEEDS_TOTP;
            }
            $step = Totp::verifyStep($user->totpSecret, $code, ($this->clock)());
            if ($step === null || $step <= $this->lastTotpStep($user->id)) {
                $this->limiter->hit($ip, $username);
                return self::BAD_TOTP;
            }
            $this->settings?->set('totp_last_step.' . $user->id, (string) $step);
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePassword($user->id, $this->hasher->hash($password));
        }
        $this->session->regenerate();
        $now = ($this->clock)();
        $this->session->set(self::K_USER, $user->id);
        $this->session->set(self::K_LOGIN_AT, $now);
        $this->session->set(self::K_SEEN_AT, $now);
        $this->csrf->rotate();
        $this->limiter->clear($ip, $username);
        $this->users->touchLogin($user->id);
        $this->audit->log($user->id, 'login', 'user', $user->id, $ip);
        $this->user = $this->users->findById($user->id);
        $this->resolved = true;
        return null;
    }

    public function logout(): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->audit->log($user->id, 'logout', 'user', $user->id, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        }
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    public function csrfToken(): string
    {
        return $this->csrf->token();
    }

    public function checkCsrf(Request $request): bool
    {
        $token = $request->form['_token'] ?? $request->header('x-csrf-token');
        return $this->csrf->check(is_string($token) ? $token : null);
    }

    private function lastTotpStep(int $userId): int
    {
        return (int) ($this->settings?->get('totp_last_step.' . $userId, '0') ?? 0);
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= (new PasswordHasher())->hash(bin2hex(random_bytes(16)));
    }
}
