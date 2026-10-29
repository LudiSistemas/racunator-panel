<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Models\Receipt;

/** Računi jednog korisnika po mesecu (tabela i CSV). */
final class ReceiptsController extends BaseController
{
    private const CHUNK = 1000;
    private const MAX_ROWS = 50000;

    public function index(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        $client = $this->findClient($params);
        if ($client === null) {
            return $this->notFound('Korisnik ne postoji.');
        }
        $month = $this->month($request);
        $summary = $this->app->receipts->monthlySummary($client->id);
        $months = array_keys($summary);
        $months[] = $this->currentMonth();
        $months[] = $month;
        $months = array_values(array_unique(array_filter($months, static fn ($m): bool => is_string($m) && Format::isMonth($m))));
        rsort($months);

        $receipts = $this->allForMonth($client->id, $month);
        $total = 0;
        foreach ($receipts as $r) {
            $total += $r->totalAmountMinor;
        }
        return $this->page('korisnici/racuni', [
            'title' => 'Računi — ' . $client->name,
            'nav' => 'korisnici',
            'client' => $client,
            'month' => $month,
            'months' => $months,
            'summary' => $summary,
            'receipts' => $receipts,
            'total' => $total,
        ]);
    }

    public function csv(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        $client = $this->findClient($params);
        if ($client === null) {
            return $this->notFound('Korisnik ne postoji.');
        }
        $month = $this->month($request);
        $receipts = $this->allForMonth($client->id, $month);
        $this->audit($request, 'receipts.export', 'client', $client->id, $month . ' (' . count($receipts) . ')');

        $filename = 'racuni-' . $this->fileSlug($client->name) . '-' . $month . '.csv';
        return new Response(200, self::buildCsv($receipts), [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * CSV za Excel u srpskom okruženju: UTF-8 sa BOM-om, razdvajač `;`, decimalni zarez, redovi CRLF.
     * Poslednja kolona „Izvor": „QR" (račun iz QR koda) ili „PFR broj" (unet po PFR broju, link je stranica /verify).
     * @param list<Receipt> $receipts
     */
    public static function buildCsv(array $receipts): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Nije moguće napraviti CSV.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        $row = static function (array $fields) use ($out): void {
            fputcsv($out, $fields, ';', '"', '', "\r\n");
        };
        $row(['Datum', 'Prodavac', 'PIB prodavca', 'PIB kupca', 'Iznos', 'PFR broj', 'Vrsta računa', 'Vrsta transakcije', 'Link za proveru', 'Izvor']);
        foreach ($receipts as $r) {
            $row([
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
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** Zaštita od „CSV injection": tekst koji počinje znakom formule dobija apostrof ispred. */
    private static function safeCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /** @return list<Receipt> */
    private function allForMonth(int $clientId, string $month): array
    {
        $all = [];
        $offset = 0;
        do {
            $batch = $this->app->receipts->forClientMonth($clientId, $month, self::CHUNK, $offset);
            foreach ($batch as $receipt) {
                $all[] = $receipt;
            }
            $offset += self::CHUNK;
        } while (count($batch) === self::CHUNK && $offset < self::MAX_ROWS);
        return $all;
    }

    private function month(Request $request): string
    {
        $month = $this->queryParam($request, 'mesec');
        return Format::isMonth($month) ? $month : $this->currentMonth();
    }

    private function currentMonth(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Format::DISPLAY_TZ)))->format('Y-m');
    }

    private function findClient(array $params): ?Client
    {
        $id = $this->idParam($params);
        return $id === null ? null : $this->app->clients->findById($id);
    }

    /** Ime za naziv fajla: samo ASCII slova, cifre i crtice. */
    private function fileSlug(string $name): string
    {
        $map = ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj', 'Č' => 'c', 'Ć' => 'c', 'Š' => 's', 'Ž' => 'z', 'Đ' => 'dj'];
        $ascii = strtolower(strtr($name, $map));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        return $slug === '' ? 'korisnik' : substr($slug, 0, 40);
    }
}
