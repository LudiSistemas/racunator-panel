<?php
declare(strict_types=1);

namespace RacunatorPanel\Models;

/**
 * Korisnik aplikacije (firma / preduzetnik) kog knjigovođa vodi. `slug` je 32 hex znaka i deo je endpointa
 * `/u/{slug}/receipts`; API ključ se čuva samo heširan (SHA-256 hex) uz prefiks (prvih 8 znakova) za pretragu.
 */
final class Client
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $apiKeyPrefix,
        public readonly string $apiKeyHash,
        public readonly bool $active,
        public readonly ?string $note,
        public readonly string $createdAt,
        public readonly ?string $lastSeenAt,
    ) {
    }
}
