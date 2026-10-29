<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Repositories\UserRepository;

/**
 * Prijava preko sesije.
 *
 * Prvi korak (`login` sa korisničkim imenom i lozinkom): rate limit → nalog i lozinka. Nalog bez 2FA se odmah prijavljuje.
 * Nalog sa 2FA uvek prelazi na drugi korak (NEEDS_TOTP) — i kad lozinka nije tačna — pa prvi korak ne otkriva da li je
 * lozinka pogođena; u sesiju se upisuje privremeno stanje (nalog, ishod provere lozinke, rok 5 min).
 * Drugi korak (`completeLogin` sa kodom): pogrešan kod ili ranije pogrešna lozinka daju istu poruku, broje se u
 * ograničenje pokušaja i vraćaju na prvi korak. `login` sa lozinkom I kodom proverava oba odjednom.
 *
 * Uspešna prijava: nov ID sesije, nov CSRF token, `session_version` naloga u sesiju, `touchLogin`, audit `login`.
 * Sesija ističe posle 2 h neaktivnosti, 12 h od prijave, ili kad se `session_version` naloga poveća.
 */
final class SessionGuard implements AccountGuard
{
    /** Poruka kad nalog traži TOTP kod (panel tada prikazuje drugi korak — samo polje za kod). */
    public const NEEDS_TOTP = 'Unesite šestocifreni kod iz aplikacije za autentifikaciju.';
    public const BAD_CREDENTIALS = 'Pogrešno korisničko ime ili lozinka.';
    /** Drugi korak nije prošao: pogrešan kod ili pogrešna lozinka u prvom koraku (namerno ista poruka). */
    public const BAD_TOTP = 'Pogrešna lozinka ili kod za dvofaktorsku prijavu. Prijavite se ponovo.';
    public const PENDING_EXPIRED = 'Rok za unos koda je istekao. Prijavite se ponovo.';
    public const INACTIVE = 'Nalog je deaktiviran. Obratite se administratoru.';

    public const IDLE_TIMEOUT = 7200;
    public const ABSOLUTE_TIMEOUT = 43200;

    private const K_USER = 'uid';
    private const K_LOGIN_AT = 'login_at';
    private const K_SEEN_AT = 'seen_at';
    private const K_VERSION = 'sv';
    private const K_PENDING = 'pending_login';

    private ?User $user = null;
    private bool $resolved = false;
    private readonly Csrf $csrf;
    private readonly ?TotpSteps $steps;
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
        $this->steps = $settings !== null ? new TotpSteps($settings) : null;
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
        // Sesija bez zapisane verzije (napravljena pre uvođenja verzija) važi kao verzija 1.
        $version = (int) $this->session->get(self::K_VERSION, 1);
        if ($user === null || !$user->active || $version !== $user->sessionVersion) {
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
        $this->session->remove(self::K_PENDING);
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
        $passwordOk = $this->hasher->verify($password, $user->passwordHash);
        if ($passwordOk && $this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePassword($user->id, $this->hasher->hash($password));
        }

        if ($user->totpSecret !== null) {
            $code = trim((string) $totpCode);
            if ($code !== '') {
                return $this->finishTotp($request, $user, $passwordOk, $code, $username);
            }
            if (!$passwordOk) {
                $this->limiter->hit($ip, $username);
            }
            $this->session->set(self::K_PENDING, [
                'uid' => $user->id,
                'username' => $username,
                'ok' => $passwordOk,
                'until' => ($this->clock)() + self::PENDING_TTL,
            ]);
            return self::NEEDS_TOTP;
        }

        if (!$passwordOk) {
            $this->limiter->hit($ip, $username);
            return self::BAD_CREDENTIALS;
        }
        if (!$user->active) {
            $this->limiter->hit($ip, $username);
            return self::INACTIVE;
        }
        $this->complete($request, $user, $username);
        return null;
    }

    public function pendingLogin(): ?string
    {
        $pending = $this->pending();
        return $pending === null ? null : $pending['username'];
    }

    public function completeLogin(Request $request, string $code): ?string
    {
        $pending = $this->pending();
        $this->session->remove(self::K_PENDING);
        if ($pending === null) {
            return self::PENDING_EXPIRED;
        }
        $wait = $this->limiter->secondsUntilAllowed($request->ip, $pending['username']);
        if ($wait !== null) {
            return RateLimiter::message($wait);
        }
        $user = $this->users->findById($pending['uid']);
        if ($user === null) {
            $this->limiter->hit($request->ip, $pending['username']);
            return self::BAD_TOTP;
        }
        return $this->finishTotp($request, $user, $pending['ok'], trim($code), $pending['username']);
    }

    public function cancelPendingLogin(): void
    {
        $this->session->remove(self::K_PENDING);
    }

    public function refreshSession(): void
    {
        $id = $this->session->get(self::K_USER);
        $user = is_int($id) ? $this->users->findById($id) : null;
        if ($user === null) {
            return;
        }
        $this->session->regenerate();
        $this->session->set(self::K_VERSION, $user->sessionVersion);
        $this->user = $user->active ? $user : null;
        $this->resolved = true;
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

    /** Provera koda (i ishoda provere lozinke) za nalog sa 2FA; sve greške daju istu poruku i broje se kao pokušaj. */
    private function finishTotp(Request $request, User $user, bool $passwordOk, string $code, string $username): ?string
    {
        $secret = $user->totpSecret;
        if ($secret !== null) {
            $step = Totp::verifyStep($secret, $code, ($this->clock)());
            // Korak se troši samo uz tačnu lozinku (inače bi napadač bez lozinke mogao da „potroši" vlasnikov kod).
            $codeOk = $step !== null && $passwordOk && ($this->steps?->consume($user->id, $step) ?? true);
        } else {
            // 2FA je isključena između prvog i drugog koraka: dovoljna je tačna lozinka iz prvog koraka.
            $codeOk = true;
        }
        if (!$passwordOk || !$codeOk) {
            $this->limiter->hit($request->ip, $username);
            return self::BAD_TOTP;
        }
        if (!$user->active) {
            $this->limiter->hit($request->ip, $username);
            return self::INACTIVE;
        }
        $this->complete($request, $user, $username);
        return null;
    }

    private function complete(Request $request, User $user, string $username): void
    {
        $this->session->remove(self::K_PENDING);
        $this->session->regenerate();
        $now = ($this->clock)();
        $this->session->set(self::K_USER, $user->id);
        $this->session->set(self::K_LOGIN_AT, $now);
        $this->session->set(self::K_SEEN_AT, $now);
        $this->session->set(self::K_VERSION, $user->sessionVersion);
        $this->csrf->rotate();
        $this->limiter->clear($request->ip, $username);
        $this->users->touchLogin($user->id);
        $this->audit->log($user->id, 'login', 'user', $user->id, $request->ip);
        $this->user = $this->users->findById($user->id);
        $this->resolved = true;
    }

    /** @return array{uid:int,username:string,ok:bool,until:int}|null */
    private function pending(): ?array
    {
        $p = $this->session->get(self::K_PENDING);
        if (!is_array($p) || !is_int($p['uid'] ?? null) || !is_string($p['username'] ?? null) || !is_bool($p['ok'] ?? null)
            || !is_int($p['until'] ?? null)) {
            return null;
        }
        if ($p['until'] < ($this->clock)()) {
            $this->session->remove(self::K_PENDING);
            return null;
        }
        return ['uid' => $p['uid'], 'username' => $p['username'], 'ok' => $p['ok'], 'until' => $p['until']];
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= (new PasswordHasher())->hash(bin2hex(random_bytes(16)));
    }
}
