<?php
declare(strict_types=1);

/*
 * Ed25519 potpis fajlova izdanja (sodium). Privatni ključ: env RELEASE_SIGNING_KEY (base64; 64 bajta tajnog ključa
 * ili 32 bajta seed-a, kako ga ispisuje tools/generate-signing-key.php). Za svaki fajl pravi <fajl>.sig (base64 potpisa).
 *   RELEASE_SIGNING_KEY=… php tools/sign-release.php build/racunator-panel-1.2.3.zip [još fajlova…]
 * Bez argumenata potpisuje build/racunator-panel-<verzija>.zip. Posle potpisa proverava ga javnim ključem iz
 * src/Services/ReleaseChecker.php (ako je postavljen) — izdanje sa ključem koji panel ne prepoznaje se ne objavljuje.
 */

require __DIR__ . '/lib/Package.php';
require dirname(__DIR__) . '/src/autoload.php';

use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Tools\Package;

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "GREŠKA: potrebna je PHP ekstenzija sodium.\n");
    exit(1);
}
$raw = base64_decode((string) getenv('RELEASE_SIGNING_KEY'), true);
if ($raw === false || $raw === '') {
    fwrite(STDERR, "GREŠKA: env RELEASE_SIGNING_KEY nije postavljen (base64 privatnog ključa).\n");
    exit(1);
}
if (strlen($raw) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
    $raw = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
}
if (strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "GREŠKA: RELEASE_SIGNING_KEY nije Ed25519 ključ (očekuje se 32 ili 64 bajta posle base64).\n");
    exit(1);
}
$public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($raw));
$files = array_slice($argv, 1);
$root = dirname(__DIR__);
if ($files === []) {
    $files = [$root . '/build/racunator-panel-' . Package::version($root) . '.zip'];
}
$strict = ReleaseChecker::publicKeyConfigured();
if (!$strict) {
    fwrite(STDERR, "UPOZORENJE: javni ključ u src/Services/ReleaseChecker.php nije postavljen — panel neće proveravati potpis.\n");
} elseif (!hash_equals(ReleaseChecker::PUBLIC_KEY, $public)) {
    fwrite(STDERR, "GREŠKA: RELEASE_SIGNING_KEY ne odgovara javnom ključu u src/Services/ReleaseChecker.php.\n");
    exit(1);
}
foreach ($files as $f) {
    $data = @file_get_contents($f);
    if ($data === false) {
        fwrite(STDERR, "GREŠKA: ne mogu da pročitam $f\n");
        exit(1);
    }
    $sig = base64_encode(sodium_crypto_sign_detached($data, $raw));
    file_put_contents($f . '.sig', $sig . "\n");
    if (ReleaseChecker::verifySignature($data, $sig, $public) !== true) {
        fwrite(STDERR, "GREŠKA: provera potpisa nije prošla za $f\n");
        exit(1);
    }
    echo "Potpisano: $f.sig\n";
}
sodium_memzero($raw);
