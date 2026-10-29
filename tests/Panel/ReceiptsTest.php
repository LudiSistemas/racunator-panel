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
        self::assertSame('Datum;Prodavac;"PIB prodavca";"PIB kupca";Iznos;"PFR broj";"Vrsta računa";"Vrsta transakcije";"Link za proveru"', $lines[0]);
        self::assertCount(4, $lines);
        self::assertStringStartsWith('"02.10.2026 08:00:00";', $lines[1]);
        // Zaštita od formula u Excel-u.
        self::assertStringContainsString(';"\'=HYPERLINK(""x"")";', $lines[1]);
        self::assertStringContainsString(';1234,56;', $lines[3]);
        self::assertStringContainsString(';1,00;', $lines[2]);
        self::assertStringContainsString('RCVG4AFL-RCVG4AFL-2;Promet;prodaja;https://suf.purs.gov.rs/v/?vl=', $lines[3]);
        self::assertSame(['receipts.export'], $this->audit->actions());
    }

    public function testCsvPrazanMesecImaSamoZaglavlje(): void
    {
        $csv = $this->get('/korisnici/' . $this->client->id . '/racuni.csv', ['mesec' => '2025-01'])->body;
        self::assertSame(1, substr_count($csv, "\r\n"));
        self::assertStringStartsWith("\xEF\xBB\xBFDatum;", $csv);
    }
}
