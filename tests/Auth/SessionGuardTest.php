<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Auth\Authorization;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\RateLimiter;
use RacunatorPanel\Auth\SessionGuard;
use RacunatorPanel\Auth\Totp;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\PdoAuditLogRepository;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Repositories\PdoUserRepository;
use RacunatorPanel\Tests\Support\TestDb;

final class SessionGuardTest extends TestCase
{
    private \PDO $pdo;
    private PdoUserRepository $users;
    private PdoAuditLogRepository $audit;
    private ArraySession $session;
    private int $now = 1_800_000_000;
    private static ?string $hash = null;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo();
        $this->users = new PdoUserRepository($this->pdo);
        $this->audit = new PdoAuditLogRepository($this->pdo);
        $this->session = new ArraySession();
        self::$hash ??= (new PasswordHasher())->hash('ispravna-lozinka');
    }

    private function guard(): SessionGuard
    {
        $clock = fn (): int => $this->now;
        return new SessionGuard(
            $this->users,
            $this->audit,
            new RateLimiter($this->pdo, $clock),
            new PasswordHasher(),
            $this->session,
            new PdoSettingsRepository($this->pdo),
            $clock,
        );
    }

    private static function req(array $form = []): Request
    {
        return new Request('POST', '/prijava', form: $form, ip: '10.0.0.1');
    }

    public function testUspesnaPrijavaIOdjava(): void
    {
        $u = $this->users->create('ana', self::$hash, User::ROLE_ADMIN, null);
        $g = $this->guard();
        self::assertNull($g->user());
        $tokenBefore = $g->csrfToken();

        self::assertNull($g->login(self::req(), 'Ana', 'ispravna-lozinka', null));
        self::assertSame($u->id, $g->user()?->id);
        self::assertSame(1, $this->session->regenerations);
        self::assertNotSame($tokenBefore, $g->csrfToken(), 'CSRF token se menja pri prijavi');
        self::assertNotNull($this->users->findById($u->id)?->lastLoginAt);
        self::assertSame('login', $this->audit->recent()[0]['action']);

        // Novi zahtev (nova instanca) sa istom sesijom.
        self::assertSame($u->id, $this->guard()->user()?->id);

        $g->logout();
        self::assertNull($g->user());
        self::assertTrue($this->session->destroyed);
        self::assertSame('logout', $this->audit->recent()[0]['action']);
        self::assertNull($this->guard()->user());
    }

    public function testPogresnaLozinkaINepostojeciNalog(): void
    {
        $this->users->create('ana', self::$hash, User::ROLE_STAFF, null);
        $g = $this->guard();
        self::assertSame(SessionGuard::BAD_CREDENTIALS, $g->login(self::req(), 'ana', 'pogresna', null));
        self::assertSame(SessionGuard::BAD_CREDENTIALS, $g->login(self::req(), 'nema', 'pogresna', null));
        self::assertSame(SessionGuard::BAD_CREDENTIALS, $g->login(self::req(), '', '', null));
        self::assertNull($g->user());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function testBlokadaPosle5Pokusaja(): void
    {
        $this->users->create('ana', self::$hash, User::ROLE_STAFF, null);
        $g = $this->guard();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(SessionGuard::BAD_CREDENTIALS, $g->login(self::req(), 'ana', 'pogresna', null));
        }
        $msg = $g->login(self::req(), 'ana', 'ispravna-lozinka', null);
        self::assertStringContainsString('Previše neuspelih pokušaja', (string) $msg);
        self::assertNull($g->user());
        $this->now += 901;
        self::assertNull($this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
    }

    public function testDeaktiviranNalog(): void
    {
        $u = $this->users->create('ana', self::$hash, User::ROLE_STAFF, null);
        $this->users->setActive($u->id, false);
        self::assertSame(SessionGuard::INACTIVE, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
    }

    public function testDeaktivacijaTokomSesije(): void
    {
        $u = $this->users->create('ana', self::$hash, User::ROLE_STAFF, null);
        self::assertNull($this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        $this->users->setActive($u->id, false);
        self::assertNull($this->guard()->user());
    }

    public function testIstekSesije(): void
    {
        $this->users->create('ana', self::$hash, User::ROLE_STAFF, null);
        self::assertNull($this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        $this->now += SessionGuard::IDLE_TIMEOUT + 1;
        self::assertNull($this->guard()->user());
    }

    public function testTotp(): void
    {
        $u = $this->users->create('ana', self::$hash, User::ROLE_ADMIN, null);
        $secret = Totp::generateSecret();
        $this->users->updateTotpSecret($u->id, $secret);
        $g = $this->guard();

        self::assertSame(SessionGuard::NEEDS_TOTP, $g->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertSame(SessionGuard::BAD_TOTP, $g->login(self::req(), 'ana', 'ispravna-lozinka', '000000' === Totp::code($secret, $this->now) ? '111111' : '000000'));
        self::assertNull($g->user());

        $code = Totp::code($secret, $this->now);
        self::assertNull($g->login(self::req(), 'ana', 'ispravna-lozinka', $code));
        self::assertSame($u->id, $g->user()?->id);

        // Isti kod se ne može iskoristiti dva puta.
        $g->logout();
        self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', $code));
    }

    public function testCsrfIzForme(): void
    {
        $g = $this->guard();
        $t = $g->csrfToken();
        self::assertTrue($g->checkCsrf(self::req(['_token' => $t])));
        self::assertTrue($g->checkCsrf(new Request('POST', '/', headers: ['x-csrf-token' => $t])));
        self::assertFalse($g->checkCsrf(self::req(['_token' => 'pogresan'])));
        self::assertFalse($g->checkCsrf(self::req()));
        self::assertFalse($g->checkCsrf(self::req(['_token' => ['niz']])));
    }

    public function testAuthorization(): void
    {
        Authorization::setBasePath('/panel');
        try {
            $staff = $this->users->create('pera', self::$hash, User::ROLE_STAFF, null);
            $g = $this->guard();
            $r = Authorization::requireUser($g);
            self::assertSame(303, $r?->status);
            self::assertSame('/panel/prijava', $r?->headers['Location']);
            self::assertSame(303, Authorization::requireAdmin($g)?->status);

            self::assertNull($g->login(self::req(), 'pera', 'ispravna-lozinka', null));
            self::assertNull(Authorization::requireUser($g));
            self::assertSame(403, Authorization::requireAdmin($g)?->status);
            self::assertSame($staff->id, $g->user()?->id);

            $this->users->create('ana', self::$hash, User::ROLE_ADMIN, null);
            $g2 = new SessionGuard($this->users, $this->audit, new RateLimiter($this->pdo), new PasswordHasher(), new ArraySession());
            self::assertNull($g2->login(self::req(), 'ana', 'ispravna-lozinka', null));
            self::assertNull(Authorization::requireAdmin($g2));
        } finally {
            Authorization::setBasePath('');
        }
    }
}
