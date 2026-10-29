<?php
declare(strict_types=1);

/*
 * Pravi nov Ed25519 par ključeva za potpisivanje izdanja i ispisuje uputstvo.
 *   php tools/generate-signing-key.php                 — ispisuje oba ključa na ekran
 *   php tools/generate-signing-key.php --out <putanja> — privatni ključ (base64) upisuje u fajl (0600), na ekran samo javni
 * Privatni ključ NIKAD ne ide u repo — samo u GitHub secret RELEASE_SIGNING_KEY i u menadžer lozinki održavaoca.
 */

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "GREŠKA: potrebna je PHP ekstenzija sodium.\n");
    exit(1);
}

$out = null;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--out') {
        $out = $args[$i + 1] ?? '';
        $i++;
    } elseif (str_starts_with($args[$i], '--out=')) {
        $out = substr($args[$i], 6);
    } else {
        fwrite(STDERR, "Nepoznat argument: {$args[$i]}\nUpotreba: php tools/generate-signing-key.php [--out <putanja>]\n");
        exit(2);
    }
}
if ($out === '') {
    fwrite(STDERR, "GREŠKA: --out traži putanju fajla.\n");
    exit(2);
}
if ($out !== null && (file_exists($out) || is_link($out))) {
    fwrite(STDERR, "GREŠKA: fajl $out već postoji — neću ga prepisati.\n");
    exit(1);
}

$kp = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($kp));
$public = base64_encode(sodium_crypto_sign_publickey($kp));
sodium_memzero($kp);

if ($out !== null) {
    // Fajl se pravi ekskluzivno (x) sa pravima 0600 pre upisa tajne.
    $old = umask(0077);
    $fp = @fopen($out, 'x');
    umask($old);
    if ($fp === false) {
        fwrite(STDERR, "GREŠKA: ne mogu da napravim $out.\n");
        exit(1);
    }
    chmod($out, 0600);
    $ok = fwrite($fp, $secret . "\n") === strlen($secret) + 1;
    fclose($fp);
    sodium_memzero($secret);
    if (!$ok) {
        @unlink($out);
        fwrite(STDERR, "GREŠKA: upis u $out nije uspeo.\n");
        exit(1);
    }
    echo <<<TXT
Nov par ključeva za potpis izdanja (Ed25519)

JAVNI ključ (ide u kod):
  $public

PRIVATNI ključ je upisan u: $out (prava 0600; na ekranu se ne prikazuje)

Uputstvo:
  1. U src/Services/ReleaseChecker.php zamenite vrednost konstante PUBLIC_KEY javnim ključem iznad.
  2. Privatni ključ postavite kao GitHub secret RELEASE_SIGNING_KEY, npr.:
       gh secret set RELEASE_SIGNING_KEY < $out
     (ili repo → Settings → Secrets and variables → Actions → New repository secret).
  3. Sačuvajte privatni ključ u menadžeru lozinki, pa obrišite fajl $out (bez tog ključa nova izdanja
     ne mogu da se potpišu ključem koji postojeće instalacije prepoznaju).

TXT;
    exit(0);
}

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
