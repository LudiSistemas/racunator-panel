<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Auth\SessionStore;

/**
 * Sesijski podaci panela (ključ `_panel`): jednokratni brojevi formi (zaštita od ponovnog slanja forme osvežavanjem
 * stranice, npr. dvostrukog kreiranja korisnika) i privremena TOTP tajna dok se 2FA ne potvrdi.
 * API ključevi se NE čuvaju u sesiji.
 */
final class PanelSession
{
    private const KEY = '_panel';
    private const MAX_NONCES = 10;

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function get(string $key): mixed
    {
        $bag = $this->bag();
        return $bag[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $bag = $this->bag();
        $bag[$key] = $value;
        $this->session->set(self::KEY, $bag);
    }

    public function forget(string $key): void
    {
        $bag = $this->bag();
        if (array_key_exists($key, $bag)) {
            unset($bag[$key]);
            $this->session->set(self::KEY, $bag);
        }
    }

    /** Novi jednokratni broj za formu `$form` (čuva se najviše 10 po formi — više otvorenih kartica). */
    public function issueNonce(string $form): string
    {
        $nonce = bin2hex(random_bytes(16));
        $list = $this->get('nonce.' . $form);
        $list = is_array($list) ? $list : [];
        $list[] = $nonce;
        $this->set('nonce.' . $form, array_slice($list, -self::MAX_NONCES));
        return $nonce;
    }

    /** True ako je broj važeći; tada se troši i ne može se ponovo upotrebiti. */
    public function consumeNonce(string $form, string $nonce): bool
    {
        $list = $this->get('nonce.' . $form);
        if (!is_array($list) || $nonce === '') {
            return false;
        }
        foreach ($list as $i => $known) {
            if (is_string($known) && hash_equals($known, $nonce)) {
                unset($list[$i]);
                $this->set('nonce.' . $form, array_values($list));
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function bag(): array
    {
        $bag = $this->session->get(self::KEY, []);
        return is_array($bag) ? $bag : [];
    }
}
