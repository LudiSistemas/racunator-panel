<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Models\Receipt;
use RacunatorPanel\Repositories\ReceiptRepository;

/** Računi u memoriji; mesec se određuje iz `sdcDateTime` (kao u jezgru). */
final class InMemoryReceiptRepository implements ReceiptRepository
{
    /** @var array<int,Receipt> */
    public array $receipts = [];
    private int $nextId = 1;

    public function insertIfNew(int $clientId, array $data): bool
    {
        $source = ($data['source'] ?? Receipt::SOURCE_QR) === Receipt::SOURCE_PFR ? Receipt::SOURCE_PFR : Receipt::SOURCE_QR;
        $id = null;
        $receivedAt = gmdate('Y-m-d H:i:s');
        foreach ($this->receipts as $r) {
            if ($r->clientId === $clientId && $r->invoiceNumber === $data['invoiceNumber']) {
                // Isto pravilo nadogradnje kao u jezgru: pfr → qr sa pravim linkom iz QR koda.
                $upgrade = $r->source === Receipt::SOURCE_PFR && $source === Receipt::SOURCE_QR
                    && str_starts_with((string) $data['verificationUrl'], Receipt::SUF_QR_URL_PREFIX);
                if (!$upgrade) {
                    return false;
                }
                $id = $r->id;
                $receivedAt = $r->receivedAt;
                break;
            }
        }
        $id ??= $this->nextId++;
        $this->receipts[$id] = new Receipt(
            $id,
            $clientId,
            (string) $data['invoiceNumber'],
            (string) $data['verificationUrl'],
            (string) $data['sdcDateTime'],
            (int) $data['totalAmountMinor'],
            $data['sellerName'] ?? null,
            $data['sellerPib'] ?? null,
            $data['buyerPib'] ?? null,
            $data['invoiceType'] ?? null,
            $data['transactionType'] ?? null,
            $receivedAt,
            $source,
        );
        return true;
    }

    public function forClientMonth(int $clientId, string $month, int $limit = 1000, int $offset = 0): array
    {
        $rows = array_values(array_filter(
            $this->receipts,
            static fn (Receipt $r): bool => $r->clientId === $clientId && str_starts_with($r->sdcDateTime, $month),
        ));
        usort($rows, static fn (Receipt $a, Receipt $b): int => strcmp($a->sdcDateTime, $b->sdcDateTime));
        return array_slice($rows, $offset, $limit);
    }

    public function findById(int $id): ?Receipt
    {
        return $this->receipts[$id] ?? null;
    }

    public function monthlySummary(int $clientId): array
    {
        $out = [];
        foreach ($this->receipts as $r) {
            if ($r->clientId !== $clientId) {
                continue;
            }
            $m = substr($r->sdcDateTime, 0, 7);
            $out[$m] ??= ['count' => 0, 'total' => 0];
            $out[$m]['count']++;
            $out[$m]['total'] += $r->totalAmountMinor;
        }
        ksort($out);
        return $out;
    }

    public function countsForMonth(string $month): array
    {
        $out = [];
        foreach ($this->receipts as $r) {
            if (str_starts_with($r->sdcDateTime, $month)) {
                $out[$r->clientId] = ($out[$r->clientId] ?? 0) + 1;
            }
        }
        return $out;
    }
}
