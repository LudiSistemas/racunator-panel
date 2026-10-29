<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/**
 * HTTP klijent bez zavisnosti: curl ako postoji, inače PHP strimovi (zahteva allow_url_fopen).
 * Proverava TLS sertifikate, prati najviše 5 preusmerenja i dozvoljava samo http/https.
 */
final class NativeHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $maxBytes = 64 * 1024 * 1024,
    ) {
    }

    /** Da li hosting uopšte dozvoljava odlazne HTTP pozive iz PHP-a. */
    public static function available(): bool
    {
        return self::curlUsable() || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    public function get(string $url, array $headers = []): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new \RuntimeException('Dozvoljene su samo http i https adrese.');
        }
        if (self::curlUsable()) {
            return $this->viaCurl($url, $headers);
        }
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->viaStream($url, $headers);
        }
        throw new \RuntimeException('Hosting ne dozvoljava odlazne HTTP pozive (nema curl, allow_url_fopen je isključen).');
    }

    private static function curlUsable(): bool
    {
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('curl_exec', $disabled, true) && !in_array('curl_init', $disabled, true);
    }

    /** @param array<string,string> $headers */
    private function viaCurl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init nije uspeo.');
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $body = '';
        $tooBig = false;
        $max = $this->maxBytes;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooBig = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            throw new \RuntimeException('Odgovor je veći od dozvoljenog.');
        }
        if ($ok === false) {
            throw new \RuntimeException('Mrežna greška: ' . $error);
        }
        return ['status' => $status, 'body' => $body];
    }

    /** @param array<string,string> $headers */
    private function viaStream(string $url, array $headers): array
    {
        $lines = '';
        foreach ($headers as $k => $v) {
            $lines .= $k . ': ' . $v . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $lines,
                'timeout' => $this->timeout,
                'follow_location' => 1,
                'max_redirects' => 6,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            $err = error_get_last();
            throw new \RuntimeException('Mrežna greška: ' . ($err['message'] ?? 'nepoznata'));
        }
        $body = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $this->maxBytes) {
                fclose($fp);
                throw new \RuntimeException('Odgovor je veći od dozvoljenog.');
            }
        }
        $meta = stream_get_meta_data($fp);
        fclose($fp);
        $status = 0;
        // Poslednji statusni red (posle preusmerenja) određuje status.
        foreach ((array) ($meta['wrapper_data'] ?? []) as $h) {
            if (is_string($h) && preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => $body];
    }
}
