<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use RacunatorPanel\Services\KeyGenerator;

final class ClientsTest extends PanelTestCase
{
    public function testKreiranjeKorisnikaUpisujeKlijentaIDnevnikIPrikazujeQrJednom(): void
    {
        $user = $this->loginAs();
        $form = $this->get('/korisnici/novi');
        self::assertSame(200, $form->status);
        $nonce = self::hiddenValue($form->body, '_nonce');

        $response = $this->post('/korisnici/novi', ['ime' => 'Pera Đurić DOO', 'napomena' => 'mesečno', '_nonce' => $nonce]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('no-store', $response->headers['Cache-Control'] ?? '');

        $all = $this->clients->all();
        self::assertCount(1, $all);
        $client = $all[0];
        self::assertSame('Pera Đurić DOO', $client->name);
        self::assertSame('mesečno', $client->note);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $client->slug);

        $payload = json_decode(self::qrData($response->body), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['v']);
        self::assertSame('racunator-knjigovodja', $payload['t']);
        self::assertSame(self::PUBLIC_URL . '/u/' . $client->slug, $payload['endpoint']);
        self::assertSame('Pera Đurić DOO', $payload['name']);
        self::assertSame('Knjigovodstvo Šušnjar', $payload['accountant']);
        self::assertSame($client->apiKeyHash, KeyGenerator::hash($payload['key']));
        self::assertSame(KeyGenerator::prefix($payload['key']), $client->apiKeyPrefix);
        self::assertStringContainsString($payload['key'], $response->body);
        self::assertStringContainsString('Štampaj karticu', $response->body);
        self::assertStringContainsString('Podešavanja → Knjigovođa → Skeniraj', $response->body);
        self::assertStringContainsString('/panel/assets/qrcode.min.js', $response->body);

        self::assertSame(['client.create'], $this->audit->actions());
        self::assertSame($user->id, $this->audit->entries[0]['user_id']);
        self::assertSame($client->id, $this->audit->entries[0]['subject_id']);

        // Ključ se ne vidi na stranici korisnika.
        $details = $this->get('/korisnici/' . $client->id)->body;
        self::assertStringNotContainsString($payload['key'], $details);
        self::assertStringContainsString($client->apiKeyPrefix . '…', $details);

        // Ponovno slanje iste forme (osvežavanje) ne pravi drugog korisnika.
        $again = $this->post('/korisnici/novi', ['ime' => 'Pera Đurić DOO', '_nonce' => $nonce]);
        $this->assertRedirect($again, '/panel/korisnici');
        self::assertCount(1, $this->clients->all());
    }

    public function testValidacijaImena(): void
    {
        $this->loginAs();
        $nonce = self::hiddenValue($this->get('/korisnici/novi')->body, '_nonce');
        $response = $this->post('/korisnici/novi', ['ime' => '   ', '_nonce' => $nonce]);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Unesite ime korisnika', $response->body);
        $response = $this->post('/korisnici/novi', ['ime' => str_repeat('x', 151), '_nonce' => $nonce]);
        self::assertSame(422, $response->status);
        self::assertSame([], $this->clients->all());
        self::assertSame([], $this->audit->entries);
    }

    public function testPostBezCsrfTokenaSeOdbija(): void
    {
        $this->loginAs();
        $nonce = self::hiddenValue($this->get('/korisnici/novi')->body, '_nonce');
        $response = $this->post('/korisnici/novi', ['ime' => 'Pera', '_nonce' => $nonce, '_token' => 'pogresan']);
        self::assertSame(403, $response->status);
        self::assertSame([], $this->clients->all());
    }

    public function testIzlazJeEscapovan(): void
    {
        $this->loginAs();
        $client = $this->clients->create('<script>alert(1)</script>', str_repeat('c', 32), 'cccccccc', 'h', '"><img src=x>');
        foreach (['/', '/korisnici', '/korisnici/' . $client->id, '/korisnici/' . $client->id . '/racuni'] as $path) {
            $body = $this->get($path)->body;
            self::assertStringNotContainsString('<script>alert(1)', $body, $path);
            self::assertStringNotContainsString('"><img', $body, $path);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body, $path);
        }
    }

    public function testNovKljucMenjaHesIPrikazujeNoviQr(): void
    {
        $this->loginAs();
        $client = $this->clients->create('Alfa', str_repeat('d', 32), 'old00000', hash('sha256', 'stari'), null);
        $nonce = self::hiddenValue($this->get('/korisnici/' . $client->id)->body, '_nonce');
        $response = $this->post('/korisnici/' . $client->id . '/novi-kljuc', ['_nonce' => $nonce]);
        self::assertSame(200, $response->status);
        $payload = json_decode(self::qrData($response->body), true, 8, JSON_THROW_ON_ERROR);
        $fresh = $this->clients->findById($client->id);
        self::assertNotSame(hash('sha256', 'stari'), $fresh->apiKeyHash);
        self::assertSame($fresh->apiKeyHash, KeyGenerator::hash($payload['key']));
        self::assertSame($client->slug, $fresh->slug);
        self::assertStringContainsString('Stari ključ je upravo prestao da važi', $response->body);
        self::assertSame(['client.rotate_key'], $this->audit->actions());

        // Isti zahtev ponovo (osvežavanje) ne menja ključ još jednom.
        $this->assertRedirect($this->post('/korisnici/' . $client->id . '/novi-kljuc', ['_nonce' => $nonce]), '/panel/korisnici/' . $client->id);
        self::assertSame($fresh->apiKeyHash, $this->clients->findById($client->id)->apiKeyHash);
    }

    public function testDeaktivacijaAktivacijaIIzmena(): void
    {
        $this->loginAs();
        $client = $this->clients->create('Alfa', str_repeat('e', 32), 'eeeeeeee', 'h', null);
        $this->assertRedirect($this->post('/korisnici/' . $client->id . '/deaktiviraj'), '/panel/korisnici/' . $client->id);
        self::assertFalse($this->clients->findById($client->id)->active);
        $body = $this->get('/korisnici/' . $client->id)->body;
        self::assertStringContainsString('Korisnik je deaktiviran', $body);
        self::assertStringContainsString('/aktiviraj', $body);

        $this->assertRedirect($this->post('/korisnici/' . $client->id . '/aktiviraj'), '/panel/korisnici/' . $client->id);
        self::assertTrue($this->clients->findById($client->id)->active);

        $this->assertRedirect($this->post('/korisnici/' . $client->id, ['ime' => 'Alfa Nova', 'napomena' => '']), '/panel/korisnici/' . $client->id);
        self::assertSame('Alfa Nova', $this->clients->findById($client->id)->name);
        self::assertNull($this->clients->findById($client->id)->note);
        self::assertSame(['client.deactivate', 'client.activate', 'client.update'], $this->audit->actions());
    }

    public function testNepostojeciKorisnik(): void
    {
        $this->loginAs();
        self::assertSame(404, $this->get('/korisnici/999')->status);
        self::assertSame(404, $this->get('/korisnici/abc')->status);
        self::assertSame(404, $this->get('/korisnici/999/racuni')->status);
        self::assertSame(404, $this->post('/korisnici/999/deaktiviraj')->status);
    }
}
