<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Auth\Totp;
use RacunatorPanel\Models\User;
use RacunatorPanel\Services\KeyGenerator;

final class UsersAndSettingsTest extends PanelTestCase
{
    public function testAdminPraviNalogSaDugomLozinkom(): void
    {
        $admin = $this->loginAs();
        $short = $this->post('/nalozi/novi', ['korisnicko_ime' => 'mika', 'uloga' => 'zaposleni',
            'lozinka' => 'kratka', 'lozinka_ponovo' => 'kratka']);
        self::assertSame(422, $short->status);
        self::assertStringContainsString('najmanje 12 znakova', $short->body);

        $mismatch = $this->post('/nalozi/novi', ['korisnicko_ime' => 'mika', 'uloga' => 'zaposleni',
            'lozinka' => self::PASSWORD, 'lozinka_ponovo' => self::PASSWORD . 'x']);
        self::assertSame(422, $mismatch->status);
        self::assertNull($this->users->findByUsername('mika'));

        $badRole = $this->post('/nalozi/novi', ['korisnicko_ime' => 'mika', 'uloga' => 'bog',
            'lozinka' => self::PASSWORD, 'lozinka_ponovo' => self::PASSWORD]);
        self::assertSame(422, $badRole->status);

        $ok = $this->post('/nalozi/novi', ['korisnicko_ime' => 'mika', 'uloga' => 'zaposleni',
            'lozinka' => self::PASSWORD, 'lozinka_ponovo' => self::PASSWORD]);
        $this->assertRedirect($ok, '/panel/nalozi');
        $mika = $this->users->findByUsername('mika');
        self::assertNotNull($mika);
        self::assertSame(User::ROLE_STAFF, $mika->role);
        self::assertSame($admin->id, $mika->createdBy);
        self::assertTrue((new PasswordHasher())->verify(self::PASSWORD, $mika->passwordHash));
        self::assertSame(['user.create'], $this->audit->actions());
        self::assertStringContainsString('je napravljen', $this->get('/nalozi')->body);

        $dup = $this->post('/nalozi/novi', ['korisnicko_ime' => 'MIKA', 'uloga' => 'zaposleni',
            'lozinka' => self::PASSWORD, 'lozinka_ponovo' => self::PASSWORD]);
        self::assertSame(422, $dup->status);
        self::assertStringContainsString('već postoji', $dup->body);

        $list = $this->get('/nalozi')->body;
        self::assertStringContainsString('mika', $list);
        self::assertStringNotContainsString('je napravljen', $list);
    }

    public function testDeaktivacijaNalogaIZastitaPoslednjegAdmina(): void
    {
        $admin = $this->loginAs();
        $staff = $this->makeUser('zika', User::ROLE_STAFF);

        $this->assertRedirect($this->post('/nalozi/' . $admin->id . '/deaktiviraj'), '/panel/nalozi');
        self::assertTrue($this->users->findById($admin->id)->active);
        self::assertStringContainsString('sopstveni nalog', $this->get('/nalozi')->body);

        $this->assertRedirect($this->post('/nalozi/' . $staff->id . '/deaktiviraj'), '/panel/nalozi');
        self::assertFalse($this->users->findById($staff->id)->active);
        self::assertSame(2, $this->users->findById($staff->id)->sessionVersion, 'deaktivacija gasi sesije');
        $this->assertRedirect($this->post('/nalozi/' . $staff->id . '/aktiviraj'), '/panel/nalozi');
        self::assertTrue($this->users->findById($staff->id)->active);
        self::assertSame(['user.deactivate', 'user.activate'], $this->audit->actions());
    }

    public function testAdminMozeDaDeaktiviraDrugogAdmina(): void
    {
        $admin = $this->loginAs();
        $other = $this->makeUser('admin2', User::ROLE_ADMIN);
        $this->assertRedirect($this->post('/nalozi/' . $other->id . '/deaktiviraj'), '/panel/nalozi');
        self::assertFalse($this->users->findById($other->id)->active);
        self::assertTrue($this->users->findById($admin->id)->active);
    }

    public function testPostavljanjeLozinkeNaloga(): void
    {
        $this->loginAs();
        $staff = $this->makeUser('zika', User::ROLE_STAFF);
        $bad = $this->post('/nalozi/' . $staff->id . '/lozinka', ['lozinka' => 'kratko', 'lozinka_ponovo' => 'kratko']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('najmanje 12 znakova', $bad->body);
        $new = 'sasvim-nova-lozinka-42';
        $this->assertRedirect($this->post('/nalozi/' . $staff->id . '/lozinka', ['lozinka' => $new, 'lozinka_ponovo' => $new]), '/panel/nalozi');
        self::assertTrue((new PasswordHasher())->verify($new, $this->users->findById($staff->id)->passwordHash));
        self::assertSame(2, $this->users->findById($staff->id)->sessionVersion, 'nova lozinka odjavljuje sve sesije naloga');
        self::assertSame(0, $this->guard->refreshes, 'tuđi nalog: tekuća sesija administratora se ne dira');
        self::assertSame(['user.password_reset'], $this->audit->actions());
    }

    public function testPromenaSopstveneLozinke(): void
    {
        $user = $this->loginAs(User::ROLE_STAFF, 'zaposleni1');
        $new = 'moja-nova-lozinka-2026';
        $wrong = $this->post('/podesavanja/lozinka', ['trenutna' => 'nije-ta-lozinka', 'lozinka' => $new, 'lozinka_ponovo' => $new]);
        self::assertSame(422, $wrong->status);
        self::assertStringContainsString('Trenutna lozinka nije tačna.', $wrong->body);
        $this->assertRedirect($this->post('/podesavanja/lozinka', ['trenutna' => self::PASSWORD, 'lozinka' => $new, 'lozinka_ponovo' => $new]), '/panel/podesavanja');
        self::assertTrue((new PasswordHasher())->verify($new, $this->users->findById($user->id)->passwordHash));
        self::assertSame(2, $this->users->findById($user->id)->sessionVersion);
        self::assertSame(1, $this->guard->refreshes, 'tekuća sesija se osvežava');
        self::assertSame(['user.password_change'], $this->audit->actions());
    }

    public function testUkljucivanjeIIskljucivanjeDvofaktorskePrijave(): void
    {
        $user = $this->loginAs(User::ROLE_STAFF, 'zaposleni1');
        $page = $this->get('/podesavanja/2fa');
        self::assertSame(200, $page->status);
        $uri = self::qrData($page->body);
        self::assertStringStartsWith('otpauth://totp/', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $secret = (string) $query['secret'];
        self::assertNotSame('', $secret);
        // Ista tajna dok se ne potvrdi (osvežavanje stranice ne menja QR).
        self::assertSame($uri, self::qrData($this->get('/podesavanja/2fa')->body));

        $bad = $this->post('/podesavanja/2fa', ['radnja' => 'ukljuci', 'kod' => '000000' === Totp::code($secret) ? '111111' : '000000']);
        self::assertSame(422, $bad->status);
        self::assertNull($this->users->findById($user->id)->totpSecret);

        $now = time();
        $code = Totp::code($secret, $now);
        $this->assertRedirect($this->post('/podesavanja/2fa', ['radnja' => 'ukljuci', 'kod' => $code]), '/panel/podesavanja/2fa');
        self::assertSame($secret, $this->users->findById($user->id)->totpSecret);
        self::assertSame(intdiv($now, 30), (int) $this->settings->get('totp_last_step.' . $user->id), 'kod za uključivanje je iskorišćen');
        self::assertSame(2, $this->users->findById($user->id)->sessionVersion, 'ostale sesije se odjavljuju');
        self::assertSame(1, $this->guard->refreshes);
        $page = $this->get('/podesavanja/2fa')->body;
        self::assertStringContainsString('Isključi dvofaktorsku prijavu', $page);
        self::assertStringContainsString('id="kod-iskljuci" name="kod"', $page);

        // Isključivanje traži lozinku I važeći, neiskorišćen kod.
        $next = Totp::code($secret, $now + 30);
        foreach ([
            ['lozinka' => self::PASSWORD],                              // bez koda
            ['lozinka' => 'pogresna-lozinka', 'kod' => $next],          // pogrešna lozinka
            ['lozinka' => self::PASSWORD, 'kod' => $code],              // kod kojim je 2FA uključena
            ['lozinka' => self::PASSWORD, 'kod' => $next === '000000' ? '111111' : '000000'],
        ] as $i => $form) {
            $wrong = $this->post('/podesavanja/2fa', ['radnja' => 'iskljuci'] + $form);
            self::assertSame(422, $wrong->status, "slučaj $i");
            self::assertNotNull($this->users->findById($user->id)->totpSecret);
        }
        $this->assertRedirect($this->post('/podesavanja/2fa', ['radnja' => 'iskljuci', 'lozinka' => self::PASSWORD, 'kod' => $next]), '/panel/podesavanja/2fa');
        self::assertNull($this->users->findById($user->id)->totpSecret);
        self::assertSame(3, $this->users->findById($user->id)->sessionVersion);
        self::assertSame(['user.2fa_enable', 'user.2fa_disable'], $this->audit->actions());
    }

    public function testPogadjanjeKodaZaIskljucivanjeOdjavljuje(): void
    {
        $user = $this->loginAs(User::ROLE_STAFF, 'zaposleni1');
        $secret = Totp::generateSecret();
        $this->users->updateTotpSecret($user->id, $secret);
        $wrong = Totp::verify($secret, '000000') ? '111111' : '000000';
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(422, $this->post('/podesavanja/2fa', ['radnja' => 'iskljuci', 'lozinka' => self::PASSWORD, 'kod' => $wrong])->status);
        }
        $this->assertRedirect($this->post('/podesavanja/2fa', ['radnja' => 'iskljuci', 'lozinka' => self::PASSWORD, 'kod' => $wrong]), '/panel/prijava');
        self::assertNull($this->guard->user());
        self::assertNotNull($this->users->findById($user->id)->totpSecret);
        self::assertSame(['user.2fa_disable_blocked'], $this->audit->actions());
    }

    public function testAdminResetujeDvofaktorskuDrugomNalogu(): void
    {
        $admin = $this->loginAs();
        $staff = $this->makeUser('zika', User::ROLE_STAFF);
        $this->users->updateTotpSecret($staff->id, Totp::generateSecret());
        $this->users->updateTotpSecret($admin->id, Totp::generateSecret());

        $list = $this->get('/nalozi')->body;
        self::assertStringContainsString('action="/panel/nalozi/' . $staff->id . '/resetuj-2fa"', $list);
        self::assertStringNotContainsString('action="/panel/nalozi/' . $admin->id . '/resetuj-2fa"', $list, 'ne za sopstveni nalog');

        $this->assertRedirect($this->post('/nalozi/' . $staff->id . '/resetuj-2fa'), '/panel/nalozi');
        self::assertNull($this->users->findById($staff->id)->totpSecret);
        self::assertSame(2, $this->users->findById($staff->id)->sessionVersion);
        self::assertSame(['user.2fa_reset'], $this->audit->actions());

        // Sopstvenu 2FA administrator ne može da resetuje ovde (to traži kod u Podešavanjima).
        $this->assertRedirect($this->post('/nalozi/' . $admin->id . '/resetuj-2fa'), '/panel/nalozi');
        self::assertNotNull($this->users->findById($admin->id)->totpSecret);
        self::assertStringContainsString('Sopstvenu dvofaktorsku prijavu', $this->get('/nalozi')->body);
        self::assertSame(['user.2fa_reset'], $this->audit->actions());
    }

    public function testZaposleniNeMozeDaResetujeDvofaktorsku(): void
    {
        $this->loginAs(User::ROLE_STAFF, 'zaposleni1');
        $other = $this->makeUser('admin9', User::ROLE_ADMIN);
        $this->users->updateTotpSecret($other->id, Totp::generateSecret());
        self::assertSame(403, $this->post('/nalozi/' . $other->id . '/resetuj-2fa')->status);
        self::assertNotNull($this->users->findById($other->id)->totpSecret);
    }

    public function testOdjavaSaSvihUredjaja(): void
    {
        $user = $this->loginAs(User::ROLE_STAFF, 'zaposleni1');
        self::assertStringContainsString('action="/panel/podesavanja/odjava-svuda"', $this->get('/podesavanja')->body);
        $this->assertRedirect($this->post('/podesavanja/odjava-svuda'), '/panel/podesavanja');
        self::assertSame(2, $this->users->findById($user->id)->sessionVersion);
        self::assertSame(1, $this->guard->refreshes, 'tekuća sesija ostaje (nov ID)');
        self::assertNotNull($this->guard->user());
        self::assertSame(['user.sessions_revoked'], $this->audit->actions());
        self::assertStringContainsString('Odjavljeni ste sa svih ostalih uređaja', $this->get('/podesavanja')->body);
    }

    public function testNazivKnjigovodstva(): void
    {
        $this->loginAs();
        $page = $this->get('/podesavanja')->body;
        self::assertStringContainsString('value="Knjigovodstvo Šušnjar"', $page);
        self::assertStringContainsString(self::PUBLIC_URL, $page);
        self::assertStringContainsString('0.1.0', $page);
        self::assertSame(422, $this->post('/podesavanja', ['naziv' => ''])->status);
        $this->assertRedirect($this->post('/podesavanja', ['naziv' => 'Knjigovodstvo Novak']), '/panel/podesavanja');
        self::assertSame('Knjigovodstvo Novak', $this->settings->get('accountant_name'));
        self::assertSame(['settings.update'], $this->audit->actions());
    }

    public function testPrekidacApiZaKnjigovodju(): void
    {
        $this->loginAs();
        self::assertStringContainsString('Uključi API za knjigovođu', $this->get('/podesavanja/api')->body);

        $on = $this->post('/podesavanja/api', ['radnja' => 'ukljuci']);
        self::assertSame(200, $on->status);
        self::assertSame('1', $this->settings->get('accountant_api_enabled'));
        $slug = (string) $this->settings->get('accountant_api_slug');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $slug);
        preg_match('/id="api-key" class="secret">([^<]+)</', $on->body, $m);
        $key = html_entity_decode($m[1] ?? '', ENT_QUOTES);
        self::assertTrue(KeyGenerator::looksLikeKey($key));
        self::assertSame(KeyGenerator::hash($key), $this->settings->get('accountant_api_key_hash'));
        self::assertStringContainsString(self::PUBLIC_URL . '/api/' . $slug . '/v1', $on->body);

        // Posle osvežavanja ključ se više ne vidi.
        $later = $this->get('/podesavanja/api')->body;
        self::assertStringNotContainsString($key, $later);

        $nonce = self::hiddenValue($later, '_nonce');
        $rotated = $this->post('/podesavanja/api', ['radnja' => 'novi-kljuc', '_nonce' => $nonce]);
        self::assertSame(200, $rotated->status);
        self::assertNotSame(KeyGenerator::hash($key), $this->settings->get('accountant_api_key_hash'));

        $this->assertRedirect($this->post('/podesavanja/api', ['radnja' => 'iskljuci']), '/panel/podesavanja/api');
        self::assertSame('0', $this->settings->get('accountant_api_enabled'));
        self::assertNull($this->settings->get('accountant_api_key_hash'));
        self::assertSame(['api.enable', 'api.rotate_key', 'api.disable'], $this->audit->actions());
    }
}
