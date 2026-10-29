<?php
// RACUNATOR_PANEL_INSTALLER — samoraspakujući install fajl Računator panela, verzija @@VERSION@@.
//
// Otvorite ovaj fajl u pregledaču (https://vas-domen.rs/folder/racunator-panel-install.php) ili pokrenite
// `php racunator-panel-install.php`. Fajl sadrži ceo panel (ZIP u base64 posle __halt_compiler) i:
//   1) proverava verziju PHP-a i celovitost paketa (SHA-256),
//   2) raspakuje panel pored sebe (prazan folder = nova instalacija; folder sa config.php = ručno ažuriranje u releases/<verzija>/),
//   3) proverava SHA-256 svakog fajla iz MANIFEST.sha256,
//   4) vodi na čarobnjak install/.
// Pri ručnom ažuriranju paket u releases/<verzija>/ nema aktivan .htaccess (koreni je htaccess.dist), releases/ dobija
// zabranu pristupa preko veba, a ZIP sa ugrađenim potpisom (RPI_SIGNATURE) ide u storage/tmp/manual-update/ — instalirani
// panel proverava potpis svojim javnim ključem pre zamene fajlova.
// Ekstenzija zip NIJE potrebna za raspakivanje: ako je nema, koristi se ugrađeni čitač ZIP-a (dovoljan je zlib).
// Kod je namerno pisan sintaksom koju razume i stari PHP, da bi na prestarom PHP-u prikazao poruku umesto prazne stranice.

define('RPI_VERSION', '@@VERSION@@');
define('RPI_SHA256', '@@SHA256@@');
define('RPI_SIGNATURE', '@@SIGNATURE@@');
define('RPI_DENY_HTACCESS', "# Računator panel — privremeni ili stari fajlovi programa; nikad nisu dostupni preko veba.\n"
    . "Options -Indexes\n"
    . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
    . "# <If> se primenjuje posle .htaccess-a poddirektorijuma, pa ni njihovo „Require all granted\" ne poništava zabranu.\n"
    . "<IfModule mod_authz_core.c>\n    <If \"true\">\n        Require all denied\n    </If>\n</IfModule>\n");
define('RPI_MIN_PHP', '8.1.0');

function rpi_cli()
{
    return PHP_SAPI === 'cli';
}

function rpi_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function rpi_page($title, $html, $status)
{
    if (rpi_cli()) {
        $text = trim(html_entity_decode(strip_tags(str_replace(array('</p>', '<li>', '<br>'), array("\n", "\n - ", "\n"), $html)), ENT_QUOTES, 'UTF-8'));
        fwrite($status >= 400 ? STDERR : STDOUT, $title . "\n" . $text . "\n");
        exit($status >= 400 ? 1 : 0);
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
    }
    echo '<!doctype html><html lang="sr-Latn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . rpi_e($title) . '</title></head>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#1d2330">'
        . '<p style="color:#1f6f5c;font-weight:700">Računator panel ' . rpi_e(RPI_VERSION) . ' — instalacija</p>'
        . '<h1>' . rpi_e($title) . '</h1>' . $html . '</body></html>';
    exit;
}

function rpi_fail($title, $html)
{
    rpi_page($title, $html, 500);
}

/** Ime iz ZIP-a je bezbedno (bez apsolutnih putanja, „..", obrnutih kosih crta). */
function rpi_safe_name($name)
{
    if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || preg_match('/[\x00-\x1F]/', $name) || preg_match('#^[A-Za-z]:#', $name)) {
        return false;
    }
    foreach (explode('/', rtrim($name, '/')) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    return true;
}

/**
 * Ugrađeni čitač ZIP-a (bez ext-zip): čita centralni direktorijum, podržava metode 0 (stored) i 8 (deflate), proverava CRC32.
 * Vraća niz ime => sadržaj (direktorijumi imaju null).
 */
function rpi_minizip($data)
{
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) {
        throw new Exception('ZIP nema kraj centralnog direktorijuma.');
    }
    $e = unpack('vdisk/vcddisk/ventries/vtotal/Vsize/Voffset', substr($data, $eocd + 4, 16));
    if ($e['total'] === 0xFFFF || $e['offset'] === 0xFFFFFFFF) {
        throw new Exception('ZIP64 nije podržan.');
    }
    $pos = $e['offset'];
    $out = array();
    for ($i = 0; $i < $e['total']; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") {
            throw new Exception('Oštećen centralni direktorijum ZIP-a.');
        }
        $c = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($data, $pos + 4, 42));
        $name = substr($data, $pos + 46, $c['nlen']);
        $pos += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        if ($c['flags'] & 1) {
            throw new Exception('Šifrovan ZIP nije podržan.');
        }
        if (substr($name, -1) === '/') {
            $out[$name] = null;
            continue;
        }
        $lh = $c['offset'];
        if (substr($data, $lh, 4) !== "PK\x03\x04") {
            throw new Exception('Oštećen lokalni zapis ZIP-a: ' . $name);
        }
        $l = unpack('vnlen/velen', substr($data, $lh + 26, 4));
        $raw = substr($data, $lh + 30 + $l['nlen'] + $l['elen'], $c['csize']);
        if ($c['method'] === 0) {
            $content = $raw;
        } elseif ($c['method'] === 8) {
            if (!function_exists('gzinflate')) {
                throw new Exception('PHP nema ni zip ni zlib ekstenziju — zatražite od hostinga ekstenziju „zip".');
            }
            $content = gzinflate($raw);
            if ($content === false) {
                throw new Exception('Ne mogu da raspakujem ' . $name);
            }
        } else {
            throw new Exception('Nepodržan način kompresije u ZIP-u: ' . $c['method']);
        }
        if (sprintf('%u', crc32($content)) !== sprintf('%u', $c['crc']) || strlen($content) !== $c['usize']) {
            throw new Exception('CRC32 se ne slaže: ' . $name);
        }
        $out[$name] = $content;
    }
    return $out;
}

/** Svi unosi ZIP-a kao ime => sadržaj (preko ext-zip ako postoji, inače ugrađenim čitačem). */
function rpi_entries($zipData, $tmpDir)
{
    if (class_exists('ZipArchive') && !getenv('RPI_FORCE_MINIZIP')) {
        $tmp = $tmpDir . '/rpi-' . bin2hex(random_bytes(6)) . '.zip';
        if (@file_put_contents($tmp, $zipData) !== false) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) {
                $out = array();
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    $out[$name] = substr($name, -1) === '/' ? null : $zip->getFromIndex($i);
                }
                $zip->close();
                @unlink($tmp);
                return $out;
            }
            @unlink($tmp);
        }
    }
    return rpi_minizip($zipData);
}

function rpi_mkdir($dir)
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new Exception('Ne mogu da napravim folder ' . $dir . ' (prava pisanja).');
    }
}

/** Upis fajla koji od prvog trenutka ima prava 0600. */
function rpi_write_private($file, $content)
{
    $old = umask(0077);
    $ok = @file_put_contents($file, $content);
    umask($old);
    @chmod($file, 0600);
    return $ok === strlen($content);
}

function rpi_rrmdir($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $e) {
        if ($e !== '.' && $e !== '..') {
            rpi_rrmdir($path . '/' . $e);
        }
    }
    @rmdir($path);
}

// ---------------------------------------------------------------------------------------------------------------------

if (version_compare(PHP_VERSION, RPI_MIN_PHP, '<')) {
    rpi_fail('PHP je prestar', '<p>Računator panel zahteva PHP ' . RPI_MIN_PHP . ' ili noviji, a ovaj hosting koristi <strong>' . rpi_e(PHP_VERSION) . '</strong>.</p>'
        . '<p>U kontrolnom panelu hostinga (cPanel → „Select PHP Version" ili „MultiPHP Manager") izaberite PHP 8.1 ili noviji za ovaj domen, pa osvežite stranicu.</p>');
}

$dir = getenv('RPI_TARGET') && rpi_cli() ? rtrim((string) getenv('RPI_TARGET'), '/') : __DIR__;
$self = basename(__FILE__);
@set_time_limit(300);

$raw = file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__);
$zipData = base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', (string) $raw), true);
if ($zipData === false || !hash_equals(RPI_SHA256, hash('sha256', $zipData))) {
    // Novi install fajl otpremljen preko starog (isto ime, isto vreme izmene): OPcache može da izvrši stari, keširani kod
    // (stari kontrolni zbir) nad novim sadržajem. Jednom poništi keš za ovaj fajl i učitaj ga ponovo.
    if (!rpi_cli() && function_exists('opcache_invalidate') && !isset($_GET['osvezeno'])) {
        @opcache_invalidate(__FILE__, true);
        header('Location: ' . $self . '?osvezeno=1', true, 303);
        exit;
    }
    rpi_fail('Install fajl je oštećen', '<p>Sadržaj fajla se ne slaže sa kontrolnim zbirom. Preuzmite <code>racunator-panel-install.php</code> ponovo sa '
        . 'stranice izdanja na GitHub-u i otpremite ga ponovo (u FTP programu izaberite „binarni" režim prenosa).</p>');
}

if (!is_writable($dir)) {
    rpi_fail('Nema prava pisanja', '<p>PHP ne može da piše u folder <code>' . rpi_e($dir) . '</code>.</p>'
        . '<p>U File Manager-u postavite prava foldera na 755, ili zatražite od hostinga da PHP radi pod vašim korisnikom (PHP-FPM/suPHP).</p>');
}

$update = is_file($dir . '/config.php');
// Folder za podatke: iz config.php postojeće instalacije (može biti van web root-a), inače storage/ pored panela.
$storage = $dir . '/storage';
if ($update) {
    $cfg = @include $dir . '/config.php';
    if (is_array($cfg) && isset($cfg['storage']) && is_string($cfg['storage']) && $cfg['storage'] !== '') {
        $storage = rtrim($cfg['storage'], '/');
    }
}
$tokenFile = $dir . '/storage/tmp/install-token';
if (!$update && is_file($tokenFile) && !rpi_cli()) {
    // Instalaciju je već započeo neko (verovatno vi, u drugom pregledaču). Nov ključ se ne izdaje: inače bi svako ko
    // otvori install fajl pre kraja instalacije mogao da je preuzme.
    rpi_page('Instalacija je već započeta', '<p>Install fajl je već raspakovan i čarobnjak čeka u pregledaču u kom ste ga prvi put otvorili. '
        . 'Nastavite tamo.</p><p>Ako taj pregledač više nemate, otvorite <a href="install/">čarobnjak</a>: on će vam pokazati ime fajla koji treba '
        . 'da napravite preko FTP-a u folderu <code>install/</code> — tako dokazujete da ste vlasnik hostinga i nastavljate instalaciju.</p>', 409);
}
if (!$update) {
    // Nova instalacija: folder mora biti prazan (dozvoljeni su uobičajeni fajlovi hostinga i nastavak prekinutog raspakivanja).
    $allowed = array('.', '..', $self, '.well-known', 'cgi-bin', 'error_log', '.user.ini', 'php.ini', '.DS_Store', '.htaccess', 'index.html', 'default.html');
    $partial = is_file($dir . '/install/index.php') && is_file($dir . '/src/version.php');
    $other = array();
    foreach (scandir($dir) as $e) {
        if (!in_array($e, $allowed, true) && !preg_match('/^racunator-panel-install.*\.php$/', $e)) {
            $other[] = $e;
        }
    }
    if ($other !== array() && !$partial) {
        $list = '';
        foreach (array_slice($other, 0, 12) as $o) {
            $list .= '<li><code>' . rpi_e($o) . '</code></li>';
        }
        rpi_fail('Folder nije prazan', '<p>Panel se instalira u prazan folder, a u <code>' . rpi_e($dir) . '</code> već postoji:</p><ul>' . $list . '</ul>'
            . '<p>Napravite novi folder (npr. <code>public_html/panel/</code>), otpremite install fajl u njega i otvorite ga ponovo.</p>');
    }
    if (is_file($dir . '/.htaccess') && strpos((string) file_get_contents($dir . '/.htaccess'), 'Računator panel') === false) {
        @rename($dir . '/.htaccess', $dir . '/.htaccess.pre-racunator-' . date('Ymd-His'));
    }
}

try {
    rpi_mkdir($storage . '/tmp');
    $entries = rpi_entries($zipData, $storage . '/tmp');
    if (!isset($entries['MANIFEST.sha256']) || !isset($entries['src/version.php'])) {
        throw new Exception('Paket nema MANIFEST.sha256 ili src/version.php.');
    }
    $manifest = array();
    foreach (preg_split('/\r?\n/', $entries['MANIFEST.sha256']) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m) || !rpi_safe_name($m[2])) {
            throw new Exception('Neispravan manifest.');
        }
        $manifest[$m[2]] = $m[1];
    }
    foreach ($entries as $name => $content) {
        if (!rpi_safe_name($name)) {
            throw new Exception('Nedozvoljeno ime u paketu: ' . $name);
        }
        if ($content !== null && $name !== 'MANIFEST.sha256' && !isset($manifest[$name])) {
            throw new Exception('Fajl nije u manifestu: ' . $name);
        }
        if ($name === '.htaccess' || $name === 'config.php') {
            throw new Exception('Paket ne sme da sadrži ' . $name . ' u korenu.');
        }
    }

    $target = $update ? $dir . '/releases/' . RPI_VERSION : $dir;
    if ($update) {
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', RPI_VERSION)) {
            throw new Exception('Neispravna verzija paketa.');
        }
        // releases/ nikad nije dostupan preko veba (ni čarobnjak iz raspakovanog paketa).
        rpi_mkdir($dir . '/releases');
        if (@file_put_contents($dir . '/releases/.htaccess', RPI_DENY_HTACCESS) === false) {
            throw new Exception('Ne mogu da upišem releases/.htaccess (prava pisanja).');
        }
        if (!is_file($dir . '/releases/index.html')) {
            @file_put_contents($dir . '/releases/index.html', '');
        }
        rpi_rrmdir($target);
    }
    rpi_mkdir($target);
    foreach ($entries as $name => $content) {
        $path = $target . '/' . rtrim($name, '/');
        if ($content === null) {
            rpi_mkdir($path);
            continue;
        }
        rpi_mkdir(dirname($path));
        if (@file_put_contents($path, $content) !== strlen($content)) {
            throw new Exception('Ne mogu da upišem ' . $name . ' (prostor na disku ili prava pisanja).');
        }
    }
    // Provera svakog fajla na disku prema manifestu.
    foreach ($manifest as $name => $sha) {
        $path = $target . '/' . $name;
        if (!is_file($path) || !hash_equals($sha, hash_file('sha256', $path))) {
            throw new Exception('SHA-256 se ne slaže posle raspakivanja: ' . $name);
        }
    }
    foreach (array('', '/tmp', '/backups', '/logs') as $d) {
        rpi_mkdir($storage . $d);
    }
    if (!is_file($storage . '/.htaccess') && isset($entries['storage/.htaccess'])) {
        file_put_contents($storage . '/.htaccess', $entries['storage/.htaccess']);
    }
    if (!$update && isset($entries['htaccess.dist'])) {
        // Nova instalacija: pravila za koren (prosleđivanje u public/) postaju aktivna tek sada, u korenu.
        if (@file_put_contents($dir . '/.htaccess', $entries['htaccess.dist']) !== strlen($entries['htaccess.dist'])) {
            throw new Exception('Ne mogu da upišem .htaccess u koren (prava pisanja).');
        }
        @unlink($dir . '/htaccess.dist');
    }
    if ($update) {
        // Isti ZIP i njegov potpis čekaju potvrdu administratora u storage/ (zaštićen); panel proverava potpis pre zamene.
        $pending = $storage . '/tmp/manual-update';
        rpi_rrmdir($pending);
        rpi_mkdir($pending);
        @chmod($pending, 0700);
        $zipName = 'racunator-panel-' . RPI_VERSION . '.zip';
        if (!rpi_write_private($pending . '/' . $zipName, $zipData)) {
            throw new Exception('Ne mogu da upišem paket u ' . $pending . ' (prostor na disku ili prava pisanja).');
        }
        $sig = strpos(RPI_SIGNATURE, '@@') === false ? trim(RPI_SIGNATURE) : '';
        if ($sig !== '' && !rpi_write_private($pending . '/' . $zipName . '.sig', $sig . "\n")) {
            throw new Exception('Ne mogu da upišem potpis paketa.');
        }
    }
} catch (Exception $ex) {
    rpi_fail('Raspakivanje nije uspelo', '<p>' . rpi_e($ex->getMessage()) . '</p><p>Ako se ponavlja, preuzmite install fajl ponovo i proverite slobodan prostor na hostingu.</p>');
}

if ($update) {
    $zipName = 'racunator-panel-' . RPI_VERSION . '.zip';
    rpi_write_private($storage . '/tmp/manual-update.json', json_encode(array(
        'version' => RPI_VERSION,
        'dir' => 'releases/' . RPI_VERSION,
        'zip' => 'manual-update/' . $zipName,
        'sig' => is_file($storage . '/tmp/manual-update/' . $zipName . '.sig') ? 'manual-update/' . $zipName . '.sig' : null,
        'zip_sha256' => RPI_SHA256,
        'installer_sha256' => hash_file('sha256', __FILE__),
        'at' => gmdate('c'),
    )));
    // Install fajl više nije potreban: paket je u releases/<verzija>/ i čeka potvrdu administratora.
    if (__DIR__ === $dir) {
        @unlink(__FILE__);
    }
    if (rpi_cli()) {
        rpi_page('Paket je raspakovan', '<p>Verzija ' . RPI_VERSION . ' je u releases/' . RPI_VERSION . '/. Pokrenite: php install/cli.php azuriraj --paket=releases/' . RPI_VERSION . '</p>', 200);
    }
    header('Location: install/', true, 303);
    rpi_page('Paket je raspakovan', '<p>Nastavite na <a href="install/">ručno ažuriranje</a> (potrebna je prijava administratora).</p>', 303);
}

file_put_contents($dir . '/storage/tmp/installer-stub.json', json_encode(array('file' => $self)));
if (rpi_cli()) {
    rpi_page('Panel je raspakovan', '<p>Sledeći korak: php install/cli.php instaliraj --db=sqlite --url=https://domen.rs/panel --admin=ime --naziv="Knjigovodstvo" '
        . '(lozinka u env RACUNATOR_ADMIN_PASSWORD), ili php install/cli.php dozvola (adresa čarobnjaka za pregledač).</p>', 200);
}
if (is_file($tokenFile)) {
    rpi_page('Instalacija je već započeta', '<p>Nastavite u pregledaču u kom ste install fajl prvi put otvorili, ili otvorite <a href="install/">čarobnjak</a> '
        . 'i sledite uputstvo za dokaz vlasništva.</p>', 409);
}
$token = bin2hex(random_bytes(16));
// „x" = samo ako fajl ne postoji: dva istovremena otvaranja install fajla ne mogu oba da dobiju ključ.
$old = umask(0077);
$fp = @fopen($tokenFile, 'x');
umask($old);
if ($fp === false) {
    rpi_page('Instalacija je već započeta', '<p>Nastavite u pregledaču u kom ste install fajl prvi put otvorili, ili otvorite <a href="install/">čarobnjak</a> '
        . 'i sledite uputstvo za dokaz vlasništva.</p>', 409);
}
fwrite($fp, hash('sha256', $token));
fclose($fp);
header('Location: install/?t=' . $token, true, 303);
rpi_page('Panel je raspakovan', '<p>Nastavite na <a href="install/?t=' . rpi_e($token) . '">čarobnjak za instalaciju</a>.</p>', 303);

__halt_compiler();
