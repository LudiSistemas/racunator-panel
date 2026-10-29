<?php
declare(strict_types=1);

namespace RacunatorPanel\Kernel;

use RacunatorPanel\Auth\IpAddress;

/** HTTP zahtev (nepromenljiv). Pravi se iz superglobala u public/index.php ili ručno u testovima. */
final class Request
{
    /** Najveće telo zahteva koje se čita u memoriju (1 MB); veće telo se čita skraćeno na MAX_BODY + 1 bajt. */
    public const MAX_BODY = 1048576;

    /** @param array<string,string> $headers mala slova u ključevima */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $form = [],
        public readonly ?string $rawBody = null,
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $ip = '0.0.0.0',
        public readonly bool $https = false,
    ) {
    }

    /**
     * @param list<string> $trustedProxies CIDR opsezi pouzdanih proksija (config `trusted_proxies`). Samo kad zahtev stiže
     *   sa takve adrese, IP klijenta se uzima iz `X-Forwarded-For`, a HTTPS iz `X-Forwarded-Proto`; inače REMOTE_ADDR i HTTPS.
     */
    public static function fromGlobals(array $trustedProxies = []): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        if ($trustedProxies !== [] && IpAddress::inAny($ip, $trustedProxies)) {
            $ip = self::forwardedClient($headers['x-forwarded-for'] ?? '', $trustedProxies) ?? $ip;
            $proto = strtolower(trim(explode(',', $headers['x-forwarded-proto'] ?? '')[0]));
            $https = $https || $proto === 'https';
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($path, '/'),
            $_GET,
            $_POST,
            file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1) ?: null,
            $headers,
            $_COOKIE,
            $ip,
            $https,
        );
    }

    /**
     * Adresa klijenta iz `X-Forwarded-For` (zdesna nalevo, preskačući pouzdane proksije): prva adresa koja nije pouzdan
     * proksi. Neispravan zapis prekida lanac (null = zadržati REMOTE_ADDR).
     * @param list<string> $trustedProxies
     */
    public static function forwardedClient(string $forwardedFor, array $trustedProxies): ?string
    {
        $chain = array_values(array_filter(array_map('trim', explode(',', $forwardedFor)), static fn (string $v): bool => $v !== ''));
        $client = null;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!IpAddress::isValid($chain[$i])) {
                break;
            }
            $client = $chain[$i];
            if (!IpAddress::inAny($client, $trustedProxies)) {
                break;
            }
        }
        return $client;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Vrednost iz `Authorization: Bearer <token>` ili null. */
    public function bearerToken(): ?string
    {
        $h = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }

    /** JSON telo kao niz; null ako nije JSON objekat. */
    public function json(): ?array
    {
        if ($this->rawBody === null || $this->rawBody === '') {
            return null;
        }
        $data = json_decode($this->rawBody, true, 64);
        return is_array($data) ? $data : null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
