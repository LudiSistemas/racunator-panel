<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/** CSRF token u sesiji (jedan po sesiji, menja se pri prijavi). Poređenje u konstantnom vremenu. */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }
        return $token;
    }

    public function check(?string $token): bool
    {
        $expected = $this->session->get(self::KEY);
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public function rotate(): void
    {
        $this->session->set(self::KEY, bin2hex(random_bytes(32)));
    }
}
