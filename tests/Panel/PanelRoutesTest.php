<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use RacunatorPanel\Models\User;

final class PanelRoutesTest extends PanelTestCase
{
    public function testSveRuteSuRegistrovane(): void
    {
        $this->loginAs();
        $client = $this->clients->create('Pera DOO', str_repeat('a', 32), 'abcdefgh', hash('sha256', 'k'), null);
        $gets = ['/', '/korisnici', '/korisnici/novi', '/korisnici/' . $client->id, '/korisnici/' . $client->id . '/racuni',
            '/korisnici/' . $client->id . '/racuni.csv', '/nalozi', '/nalozi/novi', '/podesavanja', '/podesavanja/lozinka',
            '/podesavanja/2fa', '/podesavanja/api', '/dnevnik'];
        foreach ($gets as $path) {
            $status = $this->get($path)->status;
            self::assertSame(200, $status, "GET $path");
        }
        $posts = ['/korisnici/novi', '/korisnici/1', '/korisnici/1/novi-kljuc', '/korisnici/1/deaktiviraj', '/korisnici/1/aktiviraj',
            '/nalozi/novi', '/nalozi/1/deaktiviraj', '/nalozi/1/aktiviraj', '/nalozi/1/lozinka', '/podesavanja',
            '/podesavanja/lozinka', '/podesavanja/2fa', '/podesavanja/api', '/odjava', '/prijava'];
        foreach ($posts as $path) {
            // Bez CSRF tokena svaka POST ruta postoji i odbija zahtev sa 403.
            self::assertSame(403, $this->post($path, [], false)->status, "POST $path");
        }
    }

    public function testNeprijavljenSePreusmeravaNaPrijavu(): void
    {
        foreach (['/', '/korisnici', '/korisnici/1', '/korisnici/1/racuni', '/korisnici/1/racuni.csv', '/nalozi', '/dnevnik',
            '/podesavanja', '/podesavanja/2fa'] as $path) {
            $this->assertRedirect($this->get($path), '/panel/prijava');
        }
        $this->assertRedirect($this->post('/korisnici/novi', ['ime' => 'X']), '/panel/prijava');
        self::assertSame([], $this->clients->all());
    }

    public function testStranicaPrijaveImaPoljeZaKod(): void
    {
        $response = $this->get('/prijava');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="kod"', $response->body);
        self::assertStringContainsString('name="_token" value="test-csrf-token"', $response->body);
        self::assertStringContainsString('/panel/assets/panel.css', $response->body);
        self::assertStringNotContainsString('Odjava', $response->body);
    }

    public function testPrijavaIOdjava(): void
    {
        $this->makeUser('ana', User::ROLE_STAFF);
        $bad = $this->post('/prijava', ['korisnicko_ime' => 'ana', 'lozinka' => 'pogresna-lozinka-xx']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Pogrešno korisničko ime ili lozinka.', $bad->body);
        self::assertStringContainsString('value="ana"', $bad->body);

        $this->assertRedirect($this->post('/prijava', ['korisnicko_ime' => 'ana', 'lozinka' => self::PASSWORD]), '/panel/');
        self::assertNotNull($this->guard->user());
        $this->assertRedirect($this->get('/prijava'), '/panel/');

        $this->assertRedirect($this->post('/odjava'), '/panel/prijava');
        self::assertNull($this->guard->user());
        self::assertStringContainsString('Odjavljeni ste.', $this->get('/prijava')->body);
    }

    public function testPrijavaSaDvofaktorskomTraziKod(): void
    {
        $user = $this->makeUser('maja', User::ROLE_STAFF);
        $secret = \RacunatorPanel\Auth\Totp::generateSecret();
        $this->users->updateTotpSecret($user->id, $secret);
        self::assertStringNotContainsString('class="totp-field" open', $this->get('/prijava')->body);

        $first = $this->post('/prijava', ['korisnicko_ime' => 'maja', 'lozinka' => self::PASSWORD]);
        self::assertSame(422, $first->status);
        self::assertStringContainsString(\RacunatorPanel\Auth\SessionGuard::NEEDS_TOTP, $first->body);
        self::assertStringContainsString('<details class="totp-field" open>', $first->body);
        self::assertNull($this->guard->user());

        $ok = $this->post('/prijava', ['korisnicko_ime' => 'maja', 'lozinka' => self::PASSWORD,
            'kod' => \RacunatorPanel\Auth\Totp::code($secret)]);
        $this->assertRedirect($ok, '/panel/');
    }

    public function testZaposleniNeMozeNaAdminEkrane(): void
    {
        $this->loginAs(User::ROLE_STAFF, 'zaposleni1');
        foreach (['/nalozi', '/nalozi/novi', '/podesavanja/api'] as $path) {
            self::assertSame(403, $this->get($path)->status, $path);
        }
        $before = count($this->users->all());
        $response = $this->post('/nalozi/novi', ['korisnicko_ime' => 'novi', 'uloga' => 'admin',
            'lozinka' => self::PASSWORD, 'lozinka_ponovo' => self::PASSWORD]);
        self::assertSame(403, $response->status);
        self::assertCount($before, $this->users->all());
        self::assertSame(403, $this->post('/podesavanja', ['naziv' => 'Hak'])->status);
        self::assertSame(403, $this->post('/podesavanja/api', ['radnja' => 'ukljuci'])->status);

        // Navigacija zaposlenom ne prikazuje admin stavke.
        $body = $this->get('/')->body;
        self::assertStringNotContainsString('href="/panel/nalozi"', $body);
        self::assertStringNotContainsString('href="/panel/azuriranje"', $body);
        self::assertStringContainsString('href="/panel/dnevnik"', $body);
    }

    public function testAdminVidiCeluNavigaciju(): void
    {
        $this->loginAs();
        $body = $this->get('/')->body;
        foreach (['/panel/', '/panel/korisnici', '/panel/nalozi', '/panel/dnevnik', '/panel/podesavanja', '/panel/azuriranje'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $body);
        }
        self::assertStringContainsString('action="/panel/odjava"', $body);
        self::assertStringContainsString('verzija 0.1.0', $body);
    }

    public function testDeaktiviranNalogJeOdjavljen(): void
    {
        $user = $this->loginAs(User::ROLE_STAFF, 'pera');
        $this->users->setActive($user->id, false);
        $this->assertRedirect($this->get('/'), '/panel/prijava');
    }

    public function testKontrolnaTablaPrikazujeBrojRacunaUMesecu(): void
    {
        $this->loginAs();
        $a = $this->clients->create('Alfa DOO', str_repeat('1', 32), 'aaaaaaaa', 'h', null);
        $this->clients->create('Beta SZR', str_repeat('2', 32), 'bbbbbbbb', 'h', null);
        $month = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('Y-m');
        $this->addReceipt($a->id, 'AAAAAAAA-AAAAAAAA-1', $month . '-02T10:00:00', 1000);
        $this->addReceipt($a->id, 'AAAAAAAA-AAAAAAAA-2', $month . '-03T10:00:00', 2000);
        $this->addReceipt($a->id, 'AAAAAAAA-AAAAAAAA-3', '2020-01-03T10:00:00', 2000);
        $this->clients->touchSeen($a->id);

        $body = $this->get('/')->body;
        self::assertStringContainsString('Alfa DOO', $body);
        self::assertStringContainsString('Beta SZR', $body);
        self::assertMatchesRegularExpression('/Alfa DOO<\/a><\/td>\s*<td data-label="Računa u mesecu" class="num">2</', $body);
        self::assertStringContainsString('još nije slao', $body);
        self::assertStringContainsString('ukupno 2', $body);
    }

    public function testDnevnikPrikazujeRadnje(): void
    {
        $user = $this->loginAs();
        $this->audit->log($user->id, 'client.create', 'client', 7, '10.0.0.9', 'Pera <DOO>');
        $body = $this->get('/dnevnik')->body;
        self::assertStringContainsString('Dodat korisnik', $body);
        self::assertStringContainsString('href="/panel/korisnici/7"', $body);
        self::assertStringContainsString('Pera &lt;DOO&gt;', $body);
        self::assertStringContainsString('10.0.0.9', $body);
    }
}
