<?php
declare(strict_types=1);

/*
 * Pravi fajlove izdanja u build/:
 *   racunator-panel-<verzija>.zip   — paket za ažuriranje iz panela
 *   racunator-panel-install.php     — samoraspakujući install fajl (isti ZIP + PHP stub + potpis ZIP-a ako postoji .sig)
 *   SHA256SUMS                      — kontrolni zbirovi ZIP-a i install fajla
 *   CHANGELOG-<verzija>.md          — odeljak CHANGELOG.md za ovu verziju (telo GitHub Release-a)
 *
 *   php tools/build-release.php [--out=build] [--expect-version=1.2.3]
 *       pravi ZIP (zahteva ext-zip), install fajl, SHA256SUMS i changelog; ovo radi posao „build" (bez tajni)
 *   php tools/build-release.php --from-zip [--out=build] [--expect-version=1.2.3]
 *       NE pravi ZIP ponovo: uzima postojeći build/racunator-panel-<verzija>.zip i njegov .sig (napravljen sa
 *       tools/sign-release.php), ugrađuje potpis u install fajl i ponovo piše SHA256SUMS; ne treba ext-zip ni Composer
 *       (posao „sign" u workflow-u izdanja). Bez .sig fajla prekida.
 */

require __DIR__ . '/lib/Package.php';

use RacunatorPanel\Tools\Package;

$root = dirname(__DIR__);
$out = $root . '/build';
$expect = null;
$fromZip = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
        $out = str_starts_with($out, '/') ? $out : getcwd() . '/' . $out;
    } elseif (str_starts_with($a, '--expect-version=')) {
        $expect = ltrim(substr($a, 17), 'v');
    } elseif ($a === '--from-zip') {
        $fromZip = true;
    } else {
        fwrite(STDERR, "GREŠKA: nepoznat argument $a\n");
        exit(2);
    }
}
try {
    $version = Package::version($root);
    if ($expect !== null && $expect !== $version) {
        throw new \RuntimeException('Tag (' . $expect . ') ne odgovara src/version.php (' . $version . ').');
    }
    $zipFile = $out . '/racunator-panel-' . $version . '.zip';
    $sig = null;
    if ($fromZip) {
        if (!is_file($zipFile) || !is_file($zipFile . '.sig')) {
            throw new \RuntimeException('Za --from-zip su potrebni ' . $zipFile . ' i ' . $zipFile . '.sig.');
        }
        $sig = (string) file_get_contents($zipFile . '.sig');
    } else {
        Package::buildZip($root, $zipFile);
        if (is_file($zipFile . '.sig')) {
            @unlink($zipFile . '.sig'); // stari potpis ne odgovara novom ZIP-u
        }
    }
    $inst = Package::buildInstaller($root, $zipFile, $out . '/' . Package::INSTALLER_NAME, $sig);
    Package::writeSums([$zipFile, $inst['file']], $out . '/SHA256SUMS');

    // Odeljak changelog-a: „## <verzija>" (ili „## [<verzija>]") do sledećeg „## ".
    $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
    $section = '';
    if (preg_match('/^##\s*\[?v?' . preg_quote($version, '/') . '\]?[^\n]*\n(.*?)(?=^##\s|\z)/ms', $changelog, $m)) {
        $section = trim($m[1]);
    }
    if ($section === '') {
        fwrite(STDERR, "UPOZORENJE: CHANGELOG.md nema odeljak „## $version\".\n");
        $section = 'Verzija ' . $version . '.';
    }
    file_put_contents($out . '/CHANGELOG-' . $version . '.md', $section . "\n");
    printf("Izdanje %s napravljeno u %s (install fajl %s)\n%s", $version, $out, $inst['signed'] ? 'potpisan' : 'bez potpisa',
        file_get_contents($out . '/SHA256SUMS'));
} catch (\Throwable $e) {
    fwrite(STDERR, 'GREŠKA: ' . $e->getMessage() . "\n");
    exit(1);
}
