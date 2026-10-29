<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Models\Receipt;
use RacunatorPanel\Services\ReceiptCsv;

/** Računi jednog korisnika po mesecu (tabela i CSV). */
final class ReceiptsController extends BaseController
{
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
     * CSV za Excel u srpskom okruženju (zajednički kod: `Services\ReceiptCsv`); poslednja kolona „Izvor" (QR / PFR broj).
     * @param list<Receipt> $receipts
     */
    public static function buildCsv(array $receipts): string
    {
        return ReceiptCsv::build($receipts);
    }

    /** @return list<Receipt> */
    private function allForMonth(int $clientId, string $month): array
    {
        return ReceiptCsv::allForMonth($this->app->receipts, $clientId, $month);
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
