<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

use RacunatorPanel\Models\Client;
use RacunatorPanel\Models\Receipt;

/**
 * JSON prikaz modela za API za knjigovođu — jedino mesto gde se određuje oblik računa i korisnika u odgovorima
 * (novo polje računa se dodaje samo ovde).
 * Vremena: `sdcDateTime` je lokalno (zidno) vreme PFR-a `YYYY-MM-DDTHH:MM:SS`, kao u aplikaciji; `receivedAt`,
 * `createdAt` i `lastSeenAt` su UTC u obliku ISO 8601 sa `Z` (npr. `2026-10-24T19:45:02Z`).
 */
final class ReceiptJson
{
    /** @return array<string,mixed> */
    public static function fromModel(Receipt $r): array
    {
        return [
            'id' => $r->id,
            'clientId' => $r->clientId,
            'invoiceNumber' => $r->invoiceNumber,
            'verificationUrl' => $r->verificationUrl,
            'sdcDateTime' => $r->sdcDateTime,
            'totalAmountMinor' => $r->totalAmountMinor,
            'sellerName' => $r->sellerName,
            'sellerPib' => $r->sellerPib,
            'buyerPib' => $r->buyerPib,
            'invoiceType' => $r->invoiceType,
            'transactionType' => $r->transactionType,
            'receivedAt' => self::utc($r->receivedAt),
        ];
    }

    /** @return array<string,mixed> */
    public static function client(Client $c, int $receiptsCount): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'active' => $c->active,
            'createdAt' => self::utc($c->createdAt),
            'lastSeenAt' => self::utc($c->lastSeenAt),
            'receiptsCount' => $receiptsCount,
        ];
    }

    /** UTC vreme iz baze (`YYYY-MM-DD HH:MM:SS`) → ISO 8601 sa `Z`; null ostaje null. */
    public static function utc(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return $value;
        }
    }
}
