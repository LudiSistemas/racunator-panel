<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use RacunatorPanel\Models\User;
use RacunatorPanel\Services\Terms;

/**
 * Uslovi korišćenja u panelu: administrator koji nije prihvatio važeću verziju (instalacija starija od uslova ili posle
 * ažuriranja na verziju sa većim Terms::VERSION) preusmerava se na /uslovi i mora da prihvati; ostali nalozi nisu blokirani.
 */
final class TermsTest extends PanelTestCase
{
    /** Instalacija pre promene uslova: prihvaćena je samo starija verzija. */
    private function olderTermsAccepted(): void
    {
        $this->settings->set(Terms::SETTING_VERSION, (string) (Terms::VERSION - 1));
        $this->settings->set(Terms::SETTING_AT, '2026-01-01 08:00:00');
    }

    public function testPrihvaceniUsloviNeBlokiraju(): void
    {
        $this->loginAs();
        self::assertSame(200, $this->get('/')->status);
        $page = $this->get('/uslovi');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Verzija ' . Terms::VERSION, $page->body);
        self::assertStringContainsString(Terms::URL, $page->body);
        self::assertStringNotContainsString('name="prihvatam_uslove"', $page->body);
    }

    public function testAdminPosleNoveVerzijeMoraDaPrihvati(): void
    {
        $this->olderTermsAccepted();
        $admin = $this->loginAs();
        foreach (['/', '/korisnici', '/podesavanja', '/dnevnik', '/nalozi'] as $path) {
            $this->assertRedirect($this->get($path), '/panel/uslovi');
        }
        // I POST ekrani su zaključani (ništa se ne menja).
        $this->assertRedirect($this->post('/podesavanja', ['accountant_name' => 'Novo ime']), '/panel/uslovi');
        self::assertSame('Knjigovodstvo Šušnjar', $this->settings->get('accountant_name'));

        $page = $this->get('/uslovi');
        self::assertSame(200, $page->status);
        // Sa VERSION = 1 „starija verzija" je 0 (nikad prihvaćeni); od VERSION 2 poruka kaže da su uslovi izmenjeni.
        self::assertStringContainsString(Terms::VERSION > 1 ? 'Uslovi korišćenja su izmenjeni' : 'Pre nastavka rada', $page->body);
        self::assertStringContainsString('name="prihvatam_uslove"', $page->body);
        foreach (Terms::summary() as $point) {
            self::assertStringContainsString(htmlspecialchars($point, ENT_QUOTES, 'UTF-8'), $page->body);
        }

        // Bez označenog polja: odbijeno na serveru.
        $res = $this->post('/uslovi');
        self::assertSame(422, $res->status);
        self::assertStringContainsString('označite', $res->body);
        $res = $this->post('/uslovi', ['prihvatam_uslove' => 'ne']);
        self::assertSame(422, $res->status);
        self::assertTrue(Terms::needsAcceptance($this->settings));
        self::assertSame([], $this->audit->actions());
        $this->assertRedirect($this->get('/'), '/panel/uslovi');

        // Bez CSRF tokena: 403.
        self::assertSame(403, $this->post('/uslovi', ['prihvatam_uslove' => 'da'], false)->status);
        self::assertTrue(Terms::needsAcceptance($this->settings));

        $this->assertRedirect($this->post('/uslovi', ['prihvatam_uslove' => 'da']), '/panel/');
        self::assertSame((string) Terms::VERSION, $this->settings->get(Terms::SETTING_VERSION));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $this->settings->get(Terms::SETTING_AT));
        self::assertNotSame('2026-01-01 08:00:00', $this->settings->get(Terms::SETTING_AT));
        self::assertSame([Terms::AUDIT_ACTION], $this->audit->actions());
        self::assertSame($admin->id, $this->audit->entries[0]['user_id']);
        self::assertSame('10.0.0.1', $this->audit->entries[0]['ip']);
        self::assertSame(['version' => Terms::VERSION], json_decode((string) $this->audit->entries[0]['details'], true));

        // Jednom: posle prihvatanja nema više preusmeravanja ni novog upisa.
        self::assertSame(200, $this->get('/')->status);
        $this->assertRedirect($this->post('/uslovi', ['prihvatam_uslove' => 'da']), '/panel/');
        self::assertSame([Terms::AUDIT_ACTION], $this->audit->actions());
        self::assertStringContainsString('Prihvaćeno: verzija ' . Terms::VERSION, $this->get('/uslovi')->body);
    }

    public function testInstalacijaBezZapisaOUslovimaTraziPrihvatanje(): void
    {
        $this->settings->delete(Terms::SETTING_VERSION); // npr. ažuriranje sa 0.3.0, kad uslova još nije bilo
        $this->loginAs();
        $this->assertRedirect($this->get('/'), '/panel/uslovi');
        self::assertStringContainsString('Pre nastavka rada', $this->get('/uslovi')->body);
    }

    public function testZaposleniNijeBlokiranINeMozeDaPrihvati(): void
    {
        $this->olderTermsAccepted();
        $this->loginAs(User::ROLE_STAFF, 'radnik');
        self::assertSame(200, $this->get('/')->status);
        self::assertSame(200, $this->get('/korisnici')->status);
        $page = $this->get('/uslovi');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('name="prihvatam_uslove"', $page->body);
        self::assertStringContainsString('Administrator panela još nije prihvatio', $page->body);
        self::assertSame(403, $this->post('/uslovi', ['prihvatam_uslove' => 'da'])->status);
        self::assertTrue(Terms::needsAcceptance($this->settings));
        self::assertSame([], $this->audit->actions());
    }

    public function testNeprijavljenIdeNaPrijavu(): void
    {
        $this->olderTermsAccepted();
        $this->assertRedirect($this->get('/uslovi'), '/panel/prijava');
        $this->assertRedirect($this->post('/uslovi', ['prihvatam_uslove' => 'da']), '/panel/prijava');
        self::assertTrue(Terms::needsAcceptance($this->settings));
    }

    public function testOdjavaRadiIBezPrihvatanja(): void
    {
        $this->olderTermsAccepted();
        $this->loginAs();
        $this->assertRedirect($this->post('/odjava'), '/panel/prijava');
        self::assertNull($this->guard->user());
    }

    public function testDnevnikImaNazivRadnje(): void
    {
        self::assertSame('Prihvaćeni uslovi korišćenja', \RacunatorPanel\Controllers\Panel\Format::auditAction(Terms::AUDIT_ACTION));
    }
}
