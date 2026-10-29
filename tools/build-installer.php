<?php
declare(strict_types=1);

/*
 * Pravi samoraspakujući install fajl: build/racunator-panel-install.php (i ZIP build/racunator-panel-<verzija>.zip).
 *   php tools/build-installer.php [--out=build]
 * Ako je postavljen env RELEASE_SIGNING_KEY, ZIP se potpisuje (racunator-panel-<verzija>.zip.sig) i potpis se ugrađuje u
 * install fajl — samo takav install fajl prihvata postojeća instalacija za ručno ažuriranje. Bez ključa install fajl je
 * dobar za novu instalaciju i testove.
 */

require __DIR__ . '/lib/Package.php';

use RacunatorPanel\Tools\Package;

$root = dirname(__DIR__);
$out = $root . '/build';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
        $out = str_starts_with($out, '/') ? $out : getcwd() . '/' . $out;
    }
}
try {
    $version = Package::version($root);
    $zip = Package::buildZip($root, $out . '/racunator-panel-' . $version . '.zip');
    $sig = null;
    $key = (string) getenv('RELEASE_SIGNING_KEY');
    if ($key !== '') {
        $secret = Package::secretKey($key);
        $sig = Package::signFile($zip['file'], $secret);
        sodium_memzero($secret);
    } else {
        @unlink($zip['file'] . '.sig');
    }
    $inst = Package::buildInstaller($root, $zip['file'], $out . '/' . Package::INSTALLER_NAME, $sig);
    printf("Verzija %s\nZIP:       %s (%d fajlova, %d B, sha256 %s)\nInstaller: %s (%d B, sha256 %s, %s)\n",
        $version, $zip['file'], $zip['count'], $zip['size'], $zip['sha256'], $inst['file'], $inst['size'], $inst['sha256'],
        $inst['signed'] ? 'potpisan' : 'BEZ potpisa — ručno ažuriranje ga odbija');
} catch (\Throwable $e) {
    fwrite(STDERR, 'GREŠKA: ' . $e->getMessage() . "\n");
    exit(1);
}
