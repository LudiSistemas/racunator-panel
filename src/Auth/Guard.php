<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;

/**
 * Ugovor između jezgra (implementacija: SessionGuard) i panela (kontroleri). Panel kontroleri ne diraju $_SESSION direktno.
 */
interface Guard
{
    /** Prijavljen nalog ili null. */
    public function user(): ?User;
    /** Prijava korisničkim imenom i lozinkom (+ TOTP kod ako je nalog uključio 2FA). Vraća grešku kao string ili null. */
    public function login(Request $request, string $username, string $password, ?string $totpCode): ?string;
    public function logout(): void;
    /** CSRF token za forme (skriveno polje `_token`). */
    public function csrfToken(): string;
    /** Proverava `_token` iz forme; false = odbiti zahtev (403). */
    public function checkCsrf(Request $request): bool;
}
