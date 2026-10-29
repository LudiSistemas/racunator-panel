<?php
declare(strict_types=1);

namespace RacunatorPanel\Models;

/**
 * Račun koji je aplikacija poslala. Jedinstven po (clientId, invoiceNumber). Iznos je u parama (int), vreme ISO 8601
 * (`2026-10-24T21:42:31`, lokalno vreme PFR-a). `verificationUrl` je link ka suf.purs.gov.rs.
 */
final class Receipt
{
    public function __construct(
        public readonly int $id,
        public readonly int $clientId,
        public readonly string $invoiceNumber,
        public readonly string $verificationUrl,
        public readonly string $sdcDateTime,
        public readonly int $totalAmountMinor,
        public readonly ?string $sellerName,
        public readonly ?string $sellerPib,
        public readonly ?string $buyerPib,
        public readonly ?string $invoiceType,
        public readonly ?string $transactionType,
        public readonly string $receivedAt,
    ) {
    }
}
