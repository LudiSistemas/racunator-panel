<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Db\Connection;
use RacunatorPanel\Models\Receipt;

/**
 * Računi u tabeli `receipts`. `insertIfNew` očekuje već proverene podatke sa ključevima kao u API-ju:
 * invoiceNumber, verificationUrl, sdcDateTime ('YYYY-MM-DDTHH:MM:SS'), totalAmountMinor (int),
 * opciono source ('qr' podrazumevano | 'pfr'), sellerName, sellerPib, buyerPib, invoiceType, transactionType,
 * invoiceCounter, invoiceCounterExtension, rawJson.
 * Mesec se računa po `sdc_datetime` (vreme sa računa), ne po vremenu prijema.
 * Nadogradnja: postojeći račun sa source = 'pfr' + nova stavka sa source = 'qr' i pravim linkom iz QR koda
 * (`https://suf.purs.gov.rs/v/?vl=…`) → red se ažurira podacima nove stavke (vreme prijema ostaje prvobitno; brojač i
 * nastavak brojača se zamenjuju samo ako ih nova stavka ima, inače ostaju sačuvani).
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
        $source = self::source($data);
        $existing = $this->existingSource($clientId, $invoice);
        if ($existing !== null) {
            return self::isUpgrade($existing, $source, (string) ($data['verificationUrl'] ?? ''))
                && $this->upgrade($clientId, $invoice, $data);
        }
        $stmt = $this->pdo->prepare('INSERT INTO receipts (client_id, invoice_number, verification_url, sdc_datetime,'
            . ' total_amount_minor, seller_name, seller_pib, buyer_pib, invoice_type, transaction_type, received_at, raw_json, source,'
            . ' invoice_counter, invoice_counter_extension) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
                $source,
                $data['invoiceCounter'] ?? null,
                $data['invoiceCounterExtension'] ?? null,
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

    /** Izvor već sačuvanog računa ('qr'/'pfr') ili null ako ga nema. */
    private function existingSource(int $clientId, string $invoice): ?string
    {
        $stmt = $this->pdo->prepare('SELECT source FROM receipts WHERE client_id = ? AND invoice_number = ?');
        $stmt->execute([$clientId, $invoice]);
        $row = $stmt->fetch();
        return $row === false ? null : (string) $row['source'];
    }

    /** Pravilo nadogradnje: samo pfr → qr, i samo sa pravim linkom iz QR koda. */
    private static function isUpgrade(string $existing, string $incoming, string $url): bool
    {
        return $existing === Receipt::SOURCE_PFR && $incoming === Receipt::SOURCE_QR
            && str_starts_with($url, Receipt::SUF_QR_URL_PREFIX);
    }

    /**
     * Zamena podataka računa unetog po PFR broju podacima iz QR koda. Uslov `source = 'pfr'` u WHERE čini nadogradnju
     * jednokratnom i kod dva istovremena zahteva (drugi ne menja ništa i dobija duplikat).
     * @param array<string,mixed> $data
     */
    private function upgrade(int $clientId, string $invoice, array $data): bool
    {
        $stmt = $this->pdo->prepare('UPDATE receipts SET verification_url = ?, source = ?, sdc_datetime = ?, total_amount_minor = ?,'
            . ' seller_name = ?, seller_pib = ?, buyer_pib = ?, invoice_type = ?, transaction_type = ?, raw_json = ?,'
            . ' invoice_counter = COALESCE(?, invoice_counter), invoice_counter_extension = COALESCE(?, invoice_counter_extension)'
            . ' WHERE client_id = ? AND invoice_number = ? AND source = ?');
        $stmt->execute([
            (string) $data['verificationUrl'],
            Receipt::SOURCE_QR,
            (string) $data['sdcDateTime'],
            (int) $data['totalAmountMinor'],
            $data['sellerName'] ?? null,
            $data['sellerPib'] ?? null,
            $data['buyerPib'] ?? null,
            $data['invoiceType'] ?? null,
            $data['transactionType'] ?? null,
            $data['rawJson'] ?? null,
            $data['invoiceCounter'] ?? null,
            $data['invoiceCounterExtension'] ?? null,
            $clientId,
            $invoice,
            Receipt::SOURCE_PFR,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $data */
    private static function source(array $data): string
    {
        return ($data['source'] ?? Receipt::SOURCE_QR) === Receipt::SOURCE_PFR ? Receipt::SOURCE_PFR : Receipt::SOURCE_QR;
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
            (string) ($r['source'] ?? Receipt::SOURCE_QR),
            $opt($r['invoice_counter'] ?? null),
            $opt($r['invoice_counter_extension'] ?? null),
        );
    }
}
