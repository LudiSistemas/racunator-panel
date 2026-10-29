<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Services\HttpClient;

/** Lažni HTTP klijent: odgovori po URL-u; beleži pozive. Vrednost može biti callable(string $url): array. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url:string,headers:array}> */
    public array $calls = [];

    /** @param array<string,array{status:int,body:string}|callable|\Throwable> $routes */
    public function __construct(public array $routes = [])
    {
    }

    public function get(string $url, array $headers = []): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        $r = $this->routes[$url] ?? null;
        if ($r === null) {
            throw new \RuntimeException('Mrežna greška (lažna): nema rute ' . $url);
        }
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return is_callable($r) ? $r($url) : $r;
    }
}
