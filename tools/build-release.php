<?php
declare(strict_types=1);

/*
 * Pravi sve fajlove izdanja u build/:
 *   racunator-panel-<verzija>.zip   — paket za ažuriranje iz panela
 *   racunator-panel-install.php     — samoraspakujući install fajl (isti ZIP + PHP stub)
 *   SHA256SUMS                      — kontrolni zbirovi oba fajla
 *   CHANGELOG-<verzija>.md          — odeljak CHANGELOG.md za ovu verziju (telo GitHub Release-a)
 * Potpis se dodaje posebno: php tools/sign-release.php (privatni ključ iz env RELEASE_SIGNING_KEY).
 *   php tools/build-release.php [--out=build] [--expect-version=1.2.3]
 */

require __DIR__ . '/lib/Package.php';

use RacunatorPanel\Tools\Package;

$root = dirname(__DIR__);
$out = $root . '/build';
$expect = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
        $out = str_starts_with($out, '/') ? $out : getcwd() . '/' . $out;
    } elseif (str_starts_with($a, '--expect-version=')) {
        $expect = ltrim(substr($a, 17), 'v');
    }
}
try {
    $version = Package::version($root);
    if ($expect !== null && $expect !== $version) {
        throw new \RuntimeException('Tag (' . $expect . ') ne odgovara src/version.php (' . $version . ').');
    }
    $zip = Package::buildZip($root, $out . '/racunator-panel-' . $version . '.zip');
    $inst = Package::buildInstaller($root, $zip['file'], $out . '/' . Package::INSTALLER_NAME);
    Package::writeSums([$zip['file'], $inst['file']], $out . '/SHA256SUMS');

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
    printf("Izdanje %s napravljeno u %s\n%s", $version, $out, file_get_contents($out . '/SHA256SUMS'));
} catch (\Throwable $e) {
    fwrite(STDERR, 'GREŠKA: ' . $e->getMessage() . "\n");
    exit(1);
}
