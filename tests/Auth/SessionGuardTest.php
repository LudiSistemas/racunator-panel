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

    private function guard(?ArraySession $session = null): SessionGuard
    {
        $clock = fn (): int => $this->now;
        return new SessionGuard(
            $this->users,
            $this->audit,
            new RateLimiter($this->pdo, $clock),
            new PasswordHasher(),
            $session ?? $this->session,
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

    private function withTotp(string $role = User::ROLE_ADMIN): array
    {
        $u = $this->users->create('ana', self::$hash, $role, null);
        $secret = Totp::generateSecret();
        $this->users->updateTotpSecret($u->id, $secret);
        return [$u, $secret];
    }

    private static function wrongCode(string $secret, int $now): string
    {
        $code = Totp::code($secret, $now);
        foreach (['000000', '111111', '222222'] as $c) {
            if (!Totp::verify($secret, $c, $now)) {
                return $c;
            }
        }
        return $code === '333333' ? '444444' : '333333';
    }

    private function attempts(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    }

    public function testPrijavaUDvaKorakaSaTotp(): void
    {
        [$u, $secret] = $this->withTotp();
        $g = $this->guard();

        // 1. korak: tačna lozinka → traži se kod; ništa se ne broji kao neuspeh.
        self::assertSame(SessionGuard::NEEDS_TOTP, $g->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertSame('ana', $this->guard()->pendingLogin());
        self::assertNull($this->guard()->user());
        self::assertSame(0, $this->attempts());

        // 2. korak: pogrešan kod → ista poruka kao za pogrešnu lozinku, broji se, nazad na 1. korak.
        self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->completeLogin(self::req(), self::wrongCode($secret, $this->now)));
        self::assertNull($this->guard()->pendingLogin());
        self::assertSame(1, $this->attempts());

        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        $code = Totp::code($secret, $this->now);
        $g2 = $this->guard();
        self::assertNull($g2->completeLogin(self::req(), $code));
        self::assertSame($u->id, $g2->user()?->id);
        self::assertSame(1, $this->session->regenerations);
        self::assertNull($g2->pendingLogin());
        self::assertSame($u->id, $this->guard()->user()?->id);

        // Isti kod se ne može iskoristiti dva puta.
        $g2->logout();
        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->completeLogin(self::req(), $code));
    }

    public function testPogresnaLozinkaSa2faSeOtkrivaTekPosleKoda(): void
    {
        [$u, $secret] = $this->withTotp();
        // Prvi korak izgleda isto i za pogrešnu lozinku: ne otkriva da li je lozinka pogođena.
        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'pogresna', null));
        self::assertSame('ana', $this->guard()->pendingLogin());
        $code = Totp::code($secret, $this->now);
        self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->completeLogin(self::req(), $code));
        self::assertNull($this->guard()->user());
        // Kod nije potrošen pokušajem bez lozinke — vlasnik ga i dalje može upotrebiti.
        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertNull($this->guard()->completeLogin(self::req(), $code));
        self::assertSame($u->id, $this->guard()->user()?->id);
    }

    public function testJedanPozivSaLozinkomIKodom(): void
    {
        [$u, $secret] = $this->withTotp();
        $code = Totp::code($secret, $this->now);
        self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->login(self::req(), 'ana', 'pogresna', $code));
        self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', self::wrongCode($secret, $this->now)));
        self::assertNull($this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', $code));
        self::assertSame($u->id, $this->guard()->user()?->id);
    }

    public function testRokZaUnosKoda(): void
    {
        [, $secret] = $this->withTotp();
        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        $this->now += SessionGuard::PENDING_TTL + 1;
        self::assertNull($this->guard()->pendingLogin());
        self::assertSame(SessionGuard::PENDING_EXPIRED, $this->guard()->completeLogin(self::req(), Totp::code($secret, $this->now)));
        self::assertNull($this->guard()->user());
        // Bez započetog prvog koraka drugi korak ne radi.
        self::assertSame(SessionGuard::PENDING_EXPIRED, $this->guard()->completeLogin(self::req(), Totp::code($secret, $this->now)));
    }

    public function testOdustajanjeOdPrijave(): void
    {
        $this->withTotp();
        $g = $this->guard();
        self::assertSame(SessionGuard::NEEDS_TOTP, $g->login(self::req(), 'ana', 'ispravna-lozinka', null));
        $g->cancelPendingLogin();
        self::assertNull($this->guard()->pendingLogin());
    }

    public function testPogresniKodoviSeBrojeUOgranicenje(): void
    {
        [, $secret] = $this->withTotp();
        for ($i = 0; $i < RateLimiter::MAX_PER_USER; $i++) {
            self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
            self::assertSame(SessionGuard::BAD_TOTP, $this->guard()->completeLogin(self::req(), self::wrongCode($secret, $this->now)));
        }
        $msg = $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null);
        self::assertStringContainsString('Previše neuspelih pokušaja', (string) $msg);
        self::assertNull($this->guard()->pendingLogin());
    }

    public function testKorakZaKodBlokiranKadJeImeBlokirano(): void
    {
        [, $secret] = $this->withTotp();
        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        // U međuvremenu neko sa drugih adresa pogađa lozinku istog naloga.
        $limiter = new RateLimiter($this->pdo, fn (): int => $this->now);
        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('198.51.100.' . $i, 'ana');
        }
        $msg = $this->guard()->completeLogin(self::req(), Totp::code($secret, $this->now));
        self::assertStringContainsString('Previše neuspelih pokušaja', (string) $msg);
        self::assertNull($this->guard()->user());
    }

    public function testDeaktiviranNalogSa2fa(): void
    {
        [$u, $secret] = $this->withTotp();
        $this->users->setActive($u->id, false);
        self::assertSame(SessionGuard::NEEDS_TOTP, $this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertSame(SessionGuard::INACTIVE, $this->guard()->completeLogin(self::req(), Totp::code($secret, $this->now)));
    }

    public function testVerzijaSesijeOdjavljujeSveSesije(): void
    {
        $u = $this->users->create('ana', self::$hash, User::ROLE_ADMIN, null);
        $other = new ArraySession();
        self::assertNull($this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertNull($this->guard($other)->login(self::req(), 'ana', 'ispravna-lozinka', null));
        self::assertSame($u->id, $this->guard()->user()?->id);
        self::assertSame($u->id, $this->guard($other)->user()?->id);

        // Promena lozinke na „ovom" uređaju: verzija raste, ova sesija se osvežava, druga prestaje da važi.
        $current = $this->guard();
        self::assertNotNull($current->user());
        $this->users->bumpSessionVersion($u->id);
        $before = $this->session->regenerations;
        $current->refreshSession();
        self::assertSame($before + 1, $this->session->regenerations, 'tekuća sesija dobija nov ID');
        self::assertSame($u->id, $current->user()?->id);
        self::assertSame($u->id, $this->guard()->user()?->id);
        self::assertNull($this->guard($other)->user());
        self::assertTrue($other->destroyed);

        // Sledeće povećanje (npr. administrator postavi novu lozinku) odjavljuje i ovu sesiju.
        $this->users->bumpSessionVersion($u->id);
        self::assertNull($this->guard()->user());
    }

    public function testSesijaIzPrethodneVerzijeBezZapisaVerzije(): void
    {
        $u = $this->users->create('ana', self::$hash, User::ROLE_STAFF, null);
        self::assertNull($this->guard()->login(self::req(), 'ana', 'ispravna-lozinka', null));
        unset($this->session->data['sv']);
        self::assertSame($u->id, $this->guard()->user()?->id, 'sesija bez verzije važi kao verzija 1');
        $this->users->bumpSessionVersion($u->id);
        self::assertNull($this->guard()->user());
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
