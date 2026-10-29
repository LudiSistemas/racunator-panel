<?php
declare(strict_types=1);

namespace RacunatorPanel\Models;

/**
 * Račun koji je aplikacija poslala. Jedinstven po (clientId, invoiceNumber). Iznos je u parama (int), vreme ISO 8601
 * (`2026-10-24T21:42:31`, lokalno vreme PFR-a). `verificationUrl` je link ka suf.purs.gov.rs.
 * `source`: `qr` — račun iz QR koda (link `…/v/?vl=…` otvara račun sa stavkama); `pfr` — račun unet po PFR broju
 * (link je zvanična stranica `https://suf.purs.gov.rs/verify` na kojoj se ukuca PFR broj).
 */
final class Receipt
{
    public const SOURCE_QR = 'qr';
    public const SOURCE_PFR = 'pfr';
    /** Zvanična stranica za proveru računa po PFR broju. */
    public const SUF_VERIFY_URL = 'https://suf.purs.gov.rs/verify';
    /** Početak pravog linka iz QR koda. */
    public const SUF_QR_URL_PREFIX = 'https://suf.purs.gov.rs/v/?vl=';

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
        public readonly string $source = self::SOURCE_QR,
    ) {
    }

    /** Račun unet po PFR broju (bez QR linka). */
    public function isPfrEntry(): bool
    {
        return $this->source === self::SOURCE_PFR;
    }
}
