<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/**
 * Minimalan HTTP klijent (samo GET) za proveru i preuzimanje izdanja i samoproveru `/health`.
 * Implementacija za hosting: NativeHttpClient (curl ili allow_url_fopen); u testovima lažni klijent.
 */
interface HttpClient
{
    /**
     * GET zahtev. Prati preusmerenja. Mrežna greška (nema veze, DNS, TLS) baca \RuntimeException;
     * HTTP greške (404, 500…) se vraćaju kao status.
     *
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers = []): array;
}
