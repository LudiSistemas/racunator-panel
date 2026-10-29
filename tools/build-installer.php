<?php
declare(strict_types=1);

/*
 * Pravi samoraspakujući install fajl: build/racunator-panel-install.php (i ZIP build/racunator-panel-<verzija>.zip).
 *   php tools/build-installer.php [--out=build]
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
    $inst = Package::buildInstaller($root, $zip['file'], $out . '/' . Package::INSTALLER_NAME);
    printf("Verzija %s\nZIP:       %s (%d fajlova, %d B, sha256 %s)\nInstaller: %s (%d B, sha256 %s)\n",
        $version, $zip['file'], $zip['count'], $zip['size'], $zip['sha256'], $inst['file'], $inst['size'], $inst['sha256']);
} catch (\Throwable $e) {
    fwrite(STDERR, 'GREŠKA: ' . $e->getMessage() . "\n");
    exit(1);
}
