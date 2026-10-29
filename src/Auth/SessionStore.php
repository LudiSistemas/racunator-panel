<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/** Apstrakcija sesije (prava: NativeSession, za testove: ArraySession). */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): void;
    /** Nov ID sesije uz zadržavanje podataka (posle prijave). */
    public function regenerate(): void;
    /** Briše sve podatke i kolačić sesije. */
    public function destroy(): void;
}
