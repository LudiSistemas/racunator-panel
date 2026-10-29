<?php
declare(strict_types=1);

namespace RacunatorPanel\Kernel;

/** HTTP zahtev (nepromenljiv). Pravi se iz superglobala u public/index.php ili ručno u testovima. */
final class Request
{
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

    public static function fromGlobals(): self
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
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($headers['x-forwarded-proto'] ?? '') === 'https');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($path, '/'),
            $_GET,
            $_POST,
            file_get_contents('php://input') ?: null,
            $headers,
            $_COOKIE,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $https,
        );
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
