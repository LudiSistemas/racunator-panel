<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Kernel\Request;

/**
 * Proširenje ugovora `Guard` (koji ostaje nepromenjen): prijava u dva koraka (lozinka, pa kod za 2FA) i osvežavanje
 * sesije posle promene `session_version` tekućeg naloga. Implementacija: SessionGuard; panel kontroleri ga koriste
 * kad `$app->guard` implementira ovaj interfejs.
 */
interface AccountGuard extends Guard
{
    /** Rok (sekunde) za unos koda posle prvog koraka prijave. */
    public const PENDING_TTL = 300;

    /** Korisničko ime za koje prijava čeka kod (drugi korak), ili null ako ništa ne čeka (ili je rok istekao). */
    public function pendingLogin(): ?string;

    /** Drugi korak prijave: kod iz aplikacije za autentifikaciju. Vraća poruku o grešci ili null (prijavljen). */
    public function completeLogin(Request $request, string $code): ?string;

    /** Odustajanje od započete prijave (dugme „Nazad"). */
    public function cancelPendingLogin(): void;

    /**
     * Posle povećanja `session_version` tekućeg naloga (promena lozinke, 2FA, „Odjavi me sa svih uređaja"):
     * tekuća sesija preuzima novu verziju i dobija nov ID, a sve ostale sesije tog naloga prestaju da važe.
     */
    public function refreshSession(): void;
}
