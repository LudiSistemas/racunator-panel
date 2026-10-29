<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Db\Connection;
use RacunatorPanel\Models\Receipt;

/**
 * Računi u tabeli `receipts`. `insertIfNew` očekuje već proverene podatke sa ključevima kao u API-ju:
 * invoiceNumber, verificationUrl, sdcDateTime ('YYYY-MM-DDTHH:MM:SS'), totalAmountMinor (int),
 * opciono sellerName, sellerPib, buyerPib, invoiceType, transactionType, rawJson.
 * Mesec se računa po `sdc_datetime` (vreme sa računa), ne po vremenu prijema.
 */
final class PdoReceiptRepository implements ReceiptRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insertIfNew(int $clientId, array $data): bool
    {
        $invoice = (string) ($data['invoiceNumber'] ?? '');
        if ($invoice === '') {
            throw new \InvalidArgumentException('invoiceNumber je obavezan.');
        }
        if ($this->exists($clientId, $invoice)) {
            return false;
        }
        $stmt = $this->pdo->prepare('INSERT INTO receipts (client_id, invoice_number, verification_url, sdc_datetime,'
            . ' total_amount_minor, seller_name, seller_pib, buyer_pib, invoice_type, transaction_type, received_at, raw_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        try {
            $stmt->execute([
                $clientId,
                $invoice,
                (string) $data['verificationUrl'],
                (string) $data['sdcDateTime'],
                (int) $data['totalAmountMinor'],
                $data['sellerName'] ?? null,
                $data['sellerPib'] ?? null,
                $data['buyerPib'] ?? null,
                $data['invoiceType'] ?? null,
                $data['transactionType'] ?? null,
                gmdate('Y-m-d H:i:s'),
                $data['rawJson'] ?? null,
            ]);
        } catch (\PDOException $e) {
            // Trka dva istovremena zahteva: drugi upis pada na UNIQUE(client_id, invoice_number).
            if (Connection::isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
        return true;
    }

    public function forClientMonth(int $clientId, string $month, int $limit = 1000, int $offset = 0): array
    {
        $range = self::monthRange($month);
        if ($range === null) {
            return [];
        }
        $limit = max(1, min($limit, 10000));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare('SELECT * FROM receipts WHERE client_id = ? AND sdc_datetime >= ? AND sdc_datetime < ?'
            . ' ORDER BY sdc_datetime, id LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute([$clientId, $range[0], $range[1]]);
        return array_map([self::class, 'map'], $stmt->fetchAll());
    }

    public function findById(int $id): ?Receipt
    {
        $stmt = $this->pdo->prepare('SELECT * FROM receipts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::map($row) : null;
    }

    /** Izvorni JSON računa kako ga je aplikacija poslala (ako je sačuvan, do 8 KB) — van ugovora ReceiptRepository. */
    public function rawJson(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT raw_json FROM receipts WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function monthlySummary(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT SUBSTR(sdc_datetime, 1, 7) AS m, COUNT(*) AS c, SUM(total_amount_minor) AS t'
            . ' FROM receipts WHERE client_id = ? GROUP BY SUBSTR(sdc_datetime, 1, 7) ORDER BY m');
        $stmt->execute([$clientId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['m']] = ['count' => (int) $row['c'], 'total' => (int) $row['t']];
        }
        return $out;
    }

    public function countsForMonth(string $month): array
    {
        $range = self::monthRange($month);
        if ($range === null) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT client_id, COUNT(*) AS c FROM receipts WHERE sdc_datetime >= ? AND sdc_datetime < ?'
            . ' GROUP BY client_id');
        $stmt->execute($range);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['client_id']] = (int) $row['c'];
        }
        return $out;
    }

    private function exists(int $clientId, string $invoice): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM receipts WHERE client_id = ? AND invoice_number = ?');
        $stmt->execute([$clientId, $invoice]);
        return $stmt->fetch() !== false;
    }

    /** @return array{0:string,1:string}|null [početak, početak sledećeg meseca] za 'YYYY-MM' */
    private static function monthRange(string $month): ?array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $next = $mo === 12 ? sprintf('%04d-01', $y + 1) : sprintf('%04d-%02d', $y, $mo + 1);
        return [$month . '-01', $next . '-01'];
    }

    /** @param array<string,mixed> $r */
    private static function map(array $r): Receipt
    {
        $opt = static fn (mixed $v): ?string => $v !== null ? (string) $v : null;
        return new Receipt(
            (int) $r['id'],
            (int) $r['client_id'],
            (string) $r['invoice_number'],
            (string) $r['verification_url'],
            (string) $r['sdc_datetime'],
            (int) $r['total_amount_minor'],
            $opt($r['seller_name']),
            $opt($r['seller_pib']),
            $opt($r['buyer_pib']),
            $opt($r['invoice_type']),
            $opt($r['transaction_type']),
            (string) $r['received_at'],
        );
    }
}
