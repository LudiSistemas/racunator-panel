<?php
declare(strict_types=1);

/*
 * Ed25519 potpis fajlova izdanja (sodium). Privatni ključ: env RELEASE_SIGNING_KEY (base64; 64 bajta tajnog ključa
 * ili 32 bajta seed-a, kako ga ispisuje tools/generate-signing-key.php). Za svaki fajl pravi <fajl>.sig (base64 potpisa).
 *   RELEASE_SIGNING_KEY=… php tools/sign-release.php build/racunator-panel-1.2.3.zip [još fajlova…]
 * Bez argumenata potpisuje build/racunator-panel-<verzija>.zip. Posle potpisa proverava ga javnim ključem iz
 * src/Services/ReleaseChecker.php — izdanje sa ključem koji panel ne prepoznaje se ne objavljuje.
 * Ne zahteva Composer ni ext-zip (radi i u čistoj slici php:8.3-cli u koraku potpisa workflow-a izdanja).
 */

require __DIR__ . '/lib/Package.php';
require dirname(__DIR__) . '/src/autoload.php';

use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Tools\Package;

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "GREŠKA: potrebna je PHP ekstenzija sodium.\n");
    exit(1);
}
if ((string) getenv('RELEASE_SIGNING_KEY') === '') {
    fwrite(STDERR, "GREŠKA: env RELEASE_SIGNING_KEY nije postavljen (base64 privatnog ključa).\n");
    exit(1);
}
try {
    $secret = Package::secretKey((string) getenv('RELEASE_SIGNING_KEY'));
} catch (\Throwable $e) {
    fwrite(STDERR, 'GREŠKA: RELEASE_SIGNING_KEY: ' . $e->getMessage() . "\n");
    exit(1);
}
$public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
$files = array_slice($argv, 1);
$root = dirname(__DIR__);
if ($files === []) {
    $files = [$root . '/build/racunator-panel-' . Package::version($root) . '.zip'];
}
if (!ReleaseChecker::publicKeyConfigured()) {
    fwrite(STDERR, "GREŠKA: javni ključ u src/Services/ReleaseChecker.php nije postavljen — panel ne bi mogao da proveri potpis.\n");
    exit(1);
}
if (!hash_equals(ReleaseChecker::PUBLIC_KEY, $public)) {
    fwrite(STDERR, "GREŠKA: RELEASE_SIGNING_KEY ne odgovara javnom ključu u src/Services/ReleaseChecker.php.\n");
    exit(1);
}
try {
    foreach ($files as $f) {
        $sig = Package::signFile($f, $secret);
        if (ReleaseChecker::verifySignature((string) file_get_contents($f), $sig, $public) !== true) {
            throw new \RuntimeException('provera potpisa nije prošla za ' . $f);
        }
        echo "Potpisano: $f.sig\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'GREŠKA: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    sodium_memzero($secret);
}
