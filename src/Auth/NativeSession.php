<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/**
 * PHP sesija sa bezbednim kolačićem: HttpOnly, SameSite=Lax, Secure kad je zahtev preko HTTPS-a, putanja = putanja panela.
 * Pokreće se lenjo (tek kad zatreba), da API zahtevi i /health ne prave sesije.
 * Ako je zadat `$savePath` (npr. storage/tmp/sessions), sesije se ne čuvaju u zajedničkom /tmp hostinga.
 */
final class NativeSession implements SessionStore
{
    public const COOKIE = 'racunator_panel';

    private bool $started = false;

    public function __construct(
        private bool $secure = false,
        private readonly string $cookiePath = '/',
        private readonly ?string $savePath = null,
        private readonly int $lifetime = 43200,
    ) {
    }

    /** Poziva App pre obrade zahteva (tek tada se zna da li je HTTPS). */
    public function setSecure(bool $secure): void
    {
        if (!$this->started) {
            $this->secure = $secure;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started && !isset($_COOKIE[self::COOKIE])) {
            return $default; // nema kolačića → nema ni podataka; ne pravimo sesiju bez potrebe
        }
        $this->start();
        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        if (!$this->started && !isset($_COOKIE[self::COOKIE])) {
            return;
        }
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        if (!$this->started && !isset($_COOKIE[self::COOKIE])) {
            return;
        }
        $this->start();
        $_SESSION = [];
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', $this->cookieOptions(time() - 3600));
        }
        session_destroy();
        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Sesija ne može da počne: zaglavlja su već poslata ($file:$line).");
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime);
        if ($this->savePath !== null && (is_dir($this->savePath) || @mkdir($this->savePath, 0700, true)) && is_writable($this->savePath)) {
            session_save_path($this->savePath);
            // Sopstveni folder: neki hostinzi čiste samo podrazumevani, pa uključujemo i PHP-ovo čišćenje.
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        session_name(self::COOKIE);
        $opts = $this->cookieOptions(0);
        unset($opts['expires']);
        $opts['lifetime'] = 0;
        session_set_cookie_params($opts);
        session_start();
        $this->started = true;
    }

    /** @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
