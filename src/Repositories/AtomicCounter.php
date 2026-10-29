<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

/**
 * Dodatna mogućnost skladišta podešavanja: brojač koji samo raste, upisan atomično (bez trke dva istovremena zahteva).
 * Koristi se za poslednji iskorišćen TOTP korak (isti kod ne sme da prođe dva puta ni kad stignu dva zahteva odjednom).
 */
interface AtomicCounter
{
    /** Upisuje $value pod $key samo ako je trenutna vrednost manja (ili je nema). True = upisano. */
    public function advanceCounter(string $key, int $value): bool;
}
