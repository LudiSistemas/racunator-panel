<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

use RacunatorPanel\Models\Client;

/**
 * Sadržaj QR koda za uparivanje aplikacije (v1, docs/arhitektura.md):
 * {"v":1,"t":"racunator-knjigovodja","endpoint":"https://domen.rs/panel/u/<slug>","name":"Pera DOO","key":"<ključ>","accountant":"Knjigovodstvo XY"}
 */
final class QrPayload
{
    public const VERSION = 1;
    public const TYPE = 'racunator-knjigovodja';

    public static function build(Client $client, string $plainKey, string $publicUrl, string $accountantName): string
    {
        return json_encode([
            'v' => self::VERSION,
            't' => self::TYPE,
            'endpoint' => self::endpoint($client, $publicUrl),
            'name' => $client->name,
            'key' => $plainKey,
            'accountant' => $accountantName,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function endpoint(Client $client, string $publicUrl): string
    {
        return rtrim($publicUrl, '/') . '/u/' . $client->slug;
    }
}
