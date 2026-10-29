<?php
declare(strict_types=1);

namespace RacunatorPanel\Install;

/**
 * Pristup okruženju za provere (verzija PHP-a, ekstenzije, ini, disk, mreža). Odvojeno od Checks da bi testovi
 * mogli da podmetnu lažne vrednosti nasleđivanjem.
 */
class SystemProbe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function ini(string $name): string
    {
        return (string) ini_get($name);
    }

    public function functionUsable(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array($name, $disabled, true);
    }

    public function isWritable(string $path): bool
    {
        return is_dir($path) ? is_writable($path) : is_writable(dirname($path));
    }

    public function freeSpace(string $path): ?int
    {
        if (!$this->functionUsable('disk_free_space')) {
            return null;
        }
        $v = @disk_free_space($path);
        return $v === false ? null : (int) $v;
    }

    /** Upis probnog fajla (prava pisanja u praksi, ne samo po is_writable). */
    public function canCreateFile(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $f = $dir . '/.proba-' . bin2hex(random_bytes(4));
        if (@file_put_contents($f, 'x') !== 1) {
            return false;
        }
        @unlink($f);
        return true;
    }

    /**
     * Upiše probne fajlove (nasumičan sadržaj) u $dir i zatraži ih preko $baseUrl. Vraća listu fajlova koji su dostupni
     * (status 200 sa istim sadržajem), praznu listu kad nijedan nije, ili null kad poziv samom sebi nije moguć.
     * @param list<string> $names relativne putanje u $dir
     * @return list<string>|null
     */
    public function webExposed(string $dir, string $baseUrl, array $names): ?array
    {
        $client = new \RacunatorPanel\Services\NativeHttpClient(8, 4096);
        $exposed = [];
        $answered = false;
        $written = [];
        try {
            foreach ($names as $name) {
                $file = $dir . '/' . $name;
                if (!is_dir(dirname($file)) || @file_put_contents($file, $token = bin2hex(random_bytes(12))) === false) {
                    continue;
                }
                $written[] = $file;
                try {
                    $res = $client->get(rtrim($baseUrl, '/') . '/' . $name, ['User-Agent' => 'racunator-panel-instaler']);
                } catch (\Throwable) {
                    continue;
                }
                $answered = true;
                if ($res['status'] === 200 && trim($res['body']) === $token) {
                    $exposed[] = basename($name);
                }
            }
        } finally {
            foreach ($written as $f) {
                @unlink($f);
            }
        }
        return $answered ? $exposed : null;
    }

    /**
     * Test mod_rewrite-a: upiše probni direktorijum sa .htaccess pravilom i proba ga preko HTTP-a na sopstvenoj adresi.
     * Vraća true (radi), false (server odgovara, ali pravilo ne radi) ili null (test nije moguć: nema odlaznih poziva,
     * nema adrese, hosting blokira poziv samom sebi).
     */
    public function modRewriteWorks(string $installDir, ?string $installUrl): ?bool
    {
        if ($installUrl === null || $installUrl === '') {
            return null;
        }
        if ($this->extensionLoaded('apache2handler') || function_exists('apache_get_modules')) {
            $mods = function_exists('apache_get_modules') ? apache_get_modules() : [];
            if ($mods !== [] && !in_array('mod_rewrite', $mods, true)) {
                return false;
            }
        }
        $dir = $installDir . '/rw-proba-' . bin2hex(random_bytes(4));
        $token = bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0755)) {
            return null;
        }
        try {
            file_put_contents($dir . '/.htaccess', "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^proba$ token.txt [L]\n</IfModule>\n");
            file_put_contents($dir . '/token.txt', $token);
            $client = new \RacunatorPanel\Services\NativeHttpClient(8, 4096);
            try {
                $res = $client->get(rtrim($installUrl, '/') . '/' . basename($dir) . '/proba', ['User-Agent' => 'racunator-panel-instaler']);
            } catch (\Throwable) {
                return null;
            }
            if ($res['status'] === 200 && trim($res['body']) === $token) {
                return true;
            }
            // Ako ni sam probni fajl nije dostupan, ne znamo ništa o mod_rewrite-u (npr. zaštita hostinga).
            try {
                $direct = $client->get(rtrim($installUrl, '/') . '/' . basename($dir) . '/token.txt', ['User-Agent' => 'racunator-panel-instaler']);
            } catch (\Throwable) {
                return null;
            }
            return trim($direct['body']) === $token ? false : null;
        } finally {
            @unlink($dir . '/.htaccess');
            @unlink($dir . '/token.txt');
            @rmdir($dir);
        }
    }
}
