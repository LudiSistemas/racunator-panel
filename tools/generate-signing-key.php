<?php
declare(strict_types=1);

/*
 * Pravi nov Ed25519 par ključeva za potpisivanje izdanja i ispisuje uputstvo.
 *   php tools/generate-signing-key.php
 * Privatni ključ NIKAD ne ide u repo — samo u GitHub secret RELEASE_SIGNING_KEY i u menadžer lozinki održavaoca.
 */

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "GREŠKA: potrebna je PHP ekstenzija sodium.\n");
    exit(1);
}
$kp = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($kp));
$public = base64_encode(sodium_crypto_sign_publickey($kp));
echo <<<TXT
Nov par ključeva za potpis izdanja (Ed25519)

JAVNI ključ (ide u kod):
  $public

PRIVATNI ključ (TAJNA — ne komitovati, ne slati e-poštom):
  $secret

Uputstvo:
  1. U src/Services/ReleaseChecker.php zamenite vrednost konstante PUBLIC_KEY javnim ključem iznad.
  2. Na GitHub-u: repo → Settings → Secrets and variables → Actions → New repository secret,
     ime RELEASE_SIGNING_KEY, vrednost = privatni ključ iznad.
  3. Privatni ključ sačuvajte i u menadžeru lozinki (bez njega nova izdanja ne mogu da se potpišu
     ključem koji postojeće instalacije prepoznaju).
  4. Obrišite ovaj ispis iz terminala (npr. `clear`) i istorije.

TXT;
