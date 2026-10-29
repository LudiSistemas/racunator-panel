<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

use RacunatorPanel\Controllers\Panel\Format;
use RacunatorPanel\Models\Receipt;
use RacunatorPanel\Repositories\ReceiptRepository;

/**
 * Zajednički izvoz računa u CSV (panel „Preuzmi CSV" i API za knjigovođu `GET /export?format=csv`).
 * Format za Excel u srpskom okruženju: UTF-8 sa BOM-om, razdvajač `;`, decimalni zarez, redovi CRLF.
 * Poslednja kolona „Izvor": „QR" (račun iz QR koda) ili „PFR broj" (unet po PFR broju, link je stranica /verify).
 */
final class ReceiptCsv
{
    /** Računi se iz baze čitaju u paketima ove veličine. */
    public const CHUNK = 1000;
    /** Najviše računa jednog korisnika u jednom mesecu koji se izvoze odjednom. */
    public const MAX_ROWS = 50000;

    public const COLUMNS = ['Datum', 'Prodavac', 'PIB prodavca', 'PIB kupca', 'Iznos', 'PFR broj', 'Vrsta računa',
        'Vrsta transakcije', 'Link za proveru', 'Izvor'];

    /**
     * CSV od računa. Ako je dat `$clientNames` (clientId => naziv), dodaje se prva kolona „Korisnik" — za izvoz više
     * korisnika odjednom; bez njega su kolone iste kao u panelu.
     * @param list<Receipt> $receipts
     * @param array<int,string>|null $clientNames
     */
    public static function build(array $receipts, ?array $clientNames = null): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Nije moguće napraviti CSV.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        $row = static function (array $fields) use ($out): void {
            fputcsv($out, $fields, ';', '"', '', "\r\n");
        };
        $row($clientNames === null ? self::COLUMNS : array_merge(['Korisnik'], self::COLUMNS));
        foreach ($receipts as $r) {
            $fields = [
                Format::sdcDateTime($r->sdcDateTime, 'd.m.Y H:i:s'),
                self::safeCell((string) $r->sellerName),
                self::safeCell((string) $r->sellerPib),
                self::safeCell((string) $r->buyerPib),
                Format::amount($r->totalAmountMinor, false),
                self::safeCell($r->invoiceNumber),
                self::safeCell(Format::invoiceType($r->invoiceType)),
                self::safeCell(Format::transactionType($r->transactionType)),
                self::safeCell($r->verificationUrl),
                Format::receiptSource($r->source),
            ];
            if ($clientNames !== null) {
                array_unshift($fields, self::safeCell($clientNames[$r->clientId] ?? ''));
            }
            $row($fields);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * Svi računi korisnika za mesec (po vremenu rastuće), čitano u paketima, najviše `$max` računa.
     * @return list<Receipt>
     */
    public static function allForMonth(ReceiptRepository $receipts, int $clientId, string $month, int $max = self::MAX_ROWS): array
    {
        $all = [];
        $offset = 0;
        do {
            $batch = $receipts->forClientMonth($clientId, $month, self::CHUNK, $offset);
            foreach ($batch as $receipt) {
                $all[] = $receipt;
            }
            $offset += self::CHUNK;
        } while (count($batch) === self::CHUNK && $offset < $max);
        return array_slice($all, 0, $max);
    }

    /** Zaštita od „CSV injection": tekst koji počinje znakom formule dobija apostrof ispred. */
    public static function safeCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}
