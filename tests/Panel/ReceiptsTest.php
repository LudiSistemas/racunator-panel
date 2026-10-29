<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use RacunatorPanel\Models\Client;

final class ReceiptsTest extends PanelTestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs();
        $this->client = $this->clients->create('Pera DOO', str_repeat('f', 32), 'ffffffff', 'h', null);
        $this->addReceipt($this->client->id, 'RCVG4AFL-RCVG4AFL-2', '2026-10-24T21:42:31', 123456);
        $this->addReceipt($this->client->id, 'RCVG4AFL-RCVG4AFL-1', '2026-10-02T08:00:00', 10000, ['sellerName' => '=HYPERLINK("x")']);
        $this->addReceipt($this->client->id, 'RCVG4AFL-RCVG4AFL-3', '2026-09-30T23:59:59', 500, ['transactionType' => 'Refund']);
        $this->addReceipt($this->client->id, 'RCVG4AFL-RCVG4AFL-4', '2026-10-05T12:00:00', 100, ['verificationUrl' => 'https://zlo.example/v']);
    }

    public function testStranicaRacunaImaLinkKaSufIZbir(): void
    {
        $response = $this->get('/korisnici/' . $this->client->id . '/racuni', ['mesec' => '2026-10']);
        self::assertSame(200, $response->status);
        $body = $response->body;
        $url = 'https://suf.purs.gov.rs/v/?vl=' . rawurlencode(base64_encode('RCVG4AFL-RCVG4AFL-2'));
        self::assertStringContainsString('href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener', $body);
        self::assertStringContainsString('Otvori na sajtu Poreske uprave', $body);
        self::assertStringNotContainsString('zlo.example', $body);
        self::assertStringContainsString('neispravan link', $body);
        self::assertStringContainsString('24.10.2026. 21:42', $body);
        self::assertStringContainsString('1.234,56', $body);
        self::assertStringContainsString('Zbir (3 računa)', $body);
        self::assertStringContainsString('1.335,56', $body);
        self::assertStringContainsString('Promet – prodaja', $body);
        self::assertStringNotContainsString('RCVG4AFL-RCVG4AFL-3', $body);
        // Izbor meseca iz monthlySummary.
        self::assertStringContainsString('<option value="2026-10" selected>oktobar 2026 (3)</option>', $body);
        self::assertStringContainsString('<option value="2026-09">septembar 2026 (1)</option>', $body);
        self::assertStringContainsString('href="/panel/korisnici/' . $this->client->id . '/racuni.csv?mesec=2026-10"', $body);
        // Redosled po vremenu rastuće.
        self::assertLessThan(strpos($body, 'RCVG4AFL-RCVG4AFL-2'), strpos($body, 'RCVG4AFL-RCVG4AFL-1'));
    }

    public function testNeispravanMesecDajeTekuciMesec(): void
    {
        $body = $this->get('/korisnici/' . $this->client->id . '/racuni', ['mesec' => '2026-13'])->body;
        $current = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Belgrade')))->format('Y-m');
        self::assertStringContainsString('<option value="' . $current . '" selected>', $body);
    }

    public function testCsvImaBomZaglavljeIZarez(): void
    {
        $response = $this->get('/korisnici/' . $this->client->id . '/racuni.csv', ['mesec' => '2026-10']);
        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/csv; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('attachment; filename="racuni-pera-doo-2026-10.csv"', $response->headers['Content-Disposition']);
        $csv = $response->body;
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));
        self::assertSame('Datum;Prodavac;"PIB prodavca";"PIB kupca";Iznos;"PFR broj";"Vrsta računa";"Vrsta transakcije";"Link za proveru";Izvor', $lines[0]);
        self::assertCount(4, $lines);
        self::assertStringStartsWith('"02.10.2026 08:00:00";', $lines[1]);
        // Zaštita od formula u Excel-u.
        self::assertStringContainsString(';"\'=HYPERLINK(""x"")";', $lines[1]);
        self::assertStringContainsString(';1234,56;', $lines[3]);
        self::assertStringContainsString(';1,00;', $lines[2]);
        self::assertStringContainsString('RCVG4AFL-RCVG4AFL-2;Promet;prodaja;https://suf.purs.gov.rs/v/?vl=', $lines[3]);
        self::assertStringEndsWith(';QR', $lines[3]);
        self::assertSame(['receipts.export'], $this->audit->actions());
    }

    public function testRacunUnetPoPfrBrojuImaOznakuIDugmeZaProveru(): void
    {
        $this->addReceipt($this->client->id, 'RCVG4AFL-RCVG4AFL-7', '2026-10-10T10:00:00', 2000,
            ['source' => 'pfr', 'verificationUrl' => 'https://suf.purs.gov.rs/verify', 'sellerName' => '<b>PFR</b> & co']);
        $pfr = null;
        foreach ($this->receipts->receipts as $r) {
            if ($r->invoiceNumber === 'RCVG4AFL-RCVG4AFL-7') {
                $pfr = $r;
            }
        }
        self::assertNotNull($pfr);
        self::assertTrue($pfr->isPfrEntry());

        $body = $this->get('/korisnici/' . $this->client->id . '/racuni', ['mesec' => '2026-10'])->body;
        // Oznaka, dugme ka /verify (nova kartica) i PFR broj za kopiranje.
        self::assertStringContainsString('<code id="pfr-' . $pfr->id . '">RCVG4AFL-RCVG4AFL-7</code>', $body);
        self::assertStringContainsString('title="Račun je unet po PFR broju (bez QR koda)">PFR broj</span>', $body);
        self::assertStringContainsString('href="https://suf.purs.gov.rs/verify" target="_blank" rel="noopener noreferrer"', $body);
        self::assertStringContainsString('Proveri na sajtu Poreske uprave', $body);
        self::assertStringContainsString('data-copy="pfr-' . $pfr->id . '">Kopiraj PFR broj</button>', $body);
        self::assertSame(1, substr_count($body, 'Proveri na sajtu Poreske uprave</a>'));
        // QR računi i dalje imaju „Otvori…" (2 ispravna linka u oktobru), oznaka je samo uz PFR račun.
        self::assertSame(2, substr_count($body, 'Otvori na sajtu Poreske uprave</a>'));
        self::assertSame(1, substr_count($body, '>PFR broj</span>'));
        self::assertStringContainsString('&lt;b&gt;PFR&lt;/b&gt; &amp; co', $body);
        self::assertStringNotContainsString('<b>PFR</b>', $body);
        self::assertStringContainsString('Zbir (4 računa)', $body);

        $lines = explode("\r\n", rtrim(substr($this->get('/korisnici/' . $this->client->id . '/racuni.csv', ['mesec' => '2026-10'])->body, 3), "\r\n"));
        self::assertCount(5, $lines);
        self::assertStringContainsString('RCVG4AFL-RCVG4AFL-7;Promet;prodaja;https://suf.purs.gov.rs/verify;"PFR broj"', $lines[3]);
        self::assertStringEndsWith(';QR', $lines[1]);
    }

    public function testMnozinaUPregleduPoMesecima(): void
    {
        $body = $this->get('/korisnici/' . $this->client->id)->body;
        self::assertStringContainsString('— 3 računa, 1.335,56 RSD', $body);
        self::assertStringContainsString('— 1 račun, 5,00 RSD', $body);
        self::assertStringNotContainsString('1 računa', $body);

        $body = $this->get('/korisnici/' . $this->client->id . '/racuni', ['mesec' => '2026-09'])->body;
        self::assertStringContainsString('Zbir (1 račun)', $body);
        // Bez PFR računa nema ni objašnjenja oznake.
        self::assertStringNotContainsString('Kopiraj PFR broj', $body);
    }

    public function testCsvPrazanMesecImaSamoZaglavlje(): void
    {
        $csv = $this->get('/korisnici/' . $this->client->id . '/racuni.csv', ['mesec' => '2025-01'])->body;
        self::assertSame(1, substr_count($csv, "\r\n"));
        self::assertStringStartsWith("\xEF\xBB\xBFDatum;", $csv);
    }
}
