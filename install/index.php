<?php
declare(strict_types=1);

/*
 * Čarobnjak za instalaciju Računator panela i ručno ažuriranje.
 *   - Nema config.php → nova instalacija: provere → baza → javna adresa → prvi nalog → kraj.
 *   - Postoji config.php (install lock) → instalacija je zaključana; jedino je moguće ručno ažuriranje iz paketa koji je
 *     install fajl ostavio u storage/tmp/manual-update/ (posle prijave administratora panela, sa istom zaštitom kao u panelu;
 *     potpis paketa se proverava javnim ključem instalirane verzije).
 *   - Koren nije koren instalacije (kopija programa u releases/ ili _old/) → čarobnjak odbija svaki rad.
 * Novu instalaciju može da vodi samo ko je otvorio install fajl (ključ u adresi), ko napravi fajl za dokaz vlasništva
 * (install/DOZVOLA-<niz>.txt preko FTP-a) ili ko ima SSH (`php install/cli.php dozvola`).
 */

use RacunatorPanel\App;
use RacunatorPanel\Install\Checks;
use RacunatorPanel\Install\Installer;
use RacunatorPanel\Install\Ui;
use RacunatorPanel\Install\Updater;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Models\User;
use RacunatorPanel\Services\AppFiles;
use RacunatorPanel\Services\NativeHttpClient;

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>PHP je prestar</title><h1>PHP je prestar</h1><p>Računator panel zahteva PHP 8.1 ili noviji; '
        . 'na ovom hostingu je ' . htmlspecialchars(PHP_VERSION) . '. U kontrolnom panelu hostinga izaberite noviju verziju PHP-a.</p>';
    return;
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ui.php';

$root = dirname(__DIR__);
$installer = new Installer($root);

// Kopija programa (raspakovan paket u releases/<verzija>/, prethodna verzija u _old/) nije instalacija: ništa ne radi.
$nested = Installer::nestedCopyReason($root);
if ($nested !== null) {
    Ui::page('Stranica nije pronađena', Ui::alert('greska', '<p>' . Ui::e($nested) . ' Čarobnjak radi samo iz foldera install/ u korenu panela.</p>'), [], '', 404);
    return;
}

if ($installer->isInstalled()) {
    racunator_install_update_mode($installer);
    return;
}
racunator_install_wizard($installer);

/** Nova instalacija. */
function racunator_install_wizard(Installer $installer): void
{
    $steps = [['provere', 'Provera hostinga'], ['baza', 'Baza'], ['adresa', 'Javna adresa'], ['nalog', 'Prvi nalog'], ['gotovo', 'Kraj']];
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    racunator_install_session($installer, $https);

    // Token iz install fajla: samo ko je pokrenuo install fajl može da završi instalaciju.
    if (isset($_GET['t']) && is_string($_GET['t'])) {
        session_regenerate_id(true);
        $_SESSION['install_token'] = hash('sha256', $_GET['t']);
        header('Location: ./', true, 303);
        return;
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $csrf = (string) $_SESSION['csrf'];
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($post && !hash_equals($csrf, (string) ($_POST['_token'] ?? ''))) {
        Ui::page('Zahtev nije prihvaćen', Ui::alert('greska', '<p>Sigurnosni token nije ispravan ili je sesija istekla. <a href="./">Počnite ponovo</a>.</p>'), [], '', 403);
        return;
    }
    if (!$installer->tokenMatches($_SESSION['install_token'] ?? null)) {
        racunator_install_ownership($installer, $csrf, $post);
        return;
    }
    $step = (string) ($_GET['korak'] ?? 'provere');
    $tok = Ui::hidden('_token', $csrf);
    $installUrl = rtrim(Installer::suggestPublicUrl($_SERVER), '/') . '/install';
    $storage = (string) ($_SESSION['storage'] ?? $installer->bootStorage());

    if ($step === 'baza' && empty($_SESSION['checks_ok'])) {
        $step = 'provere';
    }
    if ($step === 'adresa' && empty($_SESSION['db'])) {
        $step = 'baza';
    }
    if ($step === 'nalog' && empty($_SESSION['public_url'])) {
        $step = empty($_SESSION['db']) ? 'baza' : 'adresa';
    }

    switch ($step) {
        default:
        case 'provere':
            $storageErrors = [];
            if ($post && ($_POST['akcija'] ?? '') === 'storage') {
                try {
                    $storage = $installer->normalizeStorage((string) ($_POST['storage'] ?? ''), (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
                    $_SESSION['storage'] = $storage;
                    unset($_SESSION['db']); // SQLite putanja zavisi od foldera za podatke
                    header('Location: ?korak=provere', true, 303);
                    return;
                } catch (\InvalidArgumentException $e) {
                    $storageErrors[] = $e->getMessage();
                }
            }
            $results = (new Checks($installer->root(), $installUrl, $https, null, $storage))->run();
            $overall = Checks::overall($results);
            $_SESSION['checks_ok'] = $overall !== Checks::FAIL;
            $outside = $storage !== $installer->bootStorage();
            $suggest = racunator_install_storage_suggestion($installer);
            $body = '<p>Proveravamo da li hosting ispunjava uslove. Crvene stavke morate rešiti (najčešće jednim zahtevom podršci hostinga), žute su upozorenja.</p>'
                . Ui::checks($results)
                . '<form method="post" action="?korak=provere" class="card" autocomplete="off">' . $tok . Ui::hidden('akcija', 'storage')
                . '<h2>Folder za podatke</h2><p>Baza (SQLite), rezervne kopije, dnevnici i sesije. Podrazumevano je <code>storage/</code> u folderu panela, '
                . 'zaštićen pravilima iz .htaccess. Bezbednije je folder <strong>van web root-a</strong> (iznad <code>public_html</code>), jer tada ne zavisi od .htaccess-a.</p>'
                . Ui::errors($storageErrors)
                . Ui::input('storage', 'Folder za podatke (apsolutna putanja)', $outside ? $storage : '', 'text',
                    'Prazno = ' . $installer->bootStorage() . ($suggest !== null ? '. Predlog van web root-a: ' . $suggest : ''))
                . '<div class="actions"><button type="submit">Sačuvaj folder za podatke</button></div></form>'
                . '<div class="actions"><a class="btn" href="?korak=provere">Ponovi proveru</a>'
                . ($overall !== Checks::FAIL ? '<a class="btn primary" href="?korak=baza">Dalje: baza</a>' : '<button type="button" disabled>Dalje (prvo rešite crvene stavke)</button>')
                . '</div>';
            Ui::page('Provera hostinga', $body, $steps, 'provere');
            return;

        case 'baza':
            $errors = [];
            $ok = null;
            $in = $_SESSION['db_form'] ?? ['driver' => extension_loaded('pdo_mysql') ? 'mysql' : 'sqlite', 'host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => ''];
            if ($post) {
                $in = [
                    'driver' => ($_POST['driver'] ?? '') === 'sqlite' ? 'sqlite' : 'mysql',
                    'host' => trim((string) ($_POST['host'] ?? '')),
                    'port' => trim((string) ($_POST['port'] ?? '')),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'user' => trim((string) ($_POST['user'] ?? '')),
                ];
                $pass = (string) ($_POST['pass'] ?? '');
                // Prazno polje lozinke = zadrži ranije unetu (lozinka se nikad ne vraća u HTML). U sesiji je lozinka samo
                // šifrovana ključem iz kolačića pregledača, a briše se čim se upiše config.php.
                if ($pass === '' && isset($_SESSION['db_pass_enc'])) {
                    $pass = (string) racunator_install_unseal((string) $_SESSION['db_pass_enc']);
                }
                $_SESSION['db_form'] = $in;
                $_SESSION['db_pass_enc'] = racunator_install_seal($pass);
                try {
                    $db = $installer->dbConfig($in + ['pass' => $pass], $storage);
                    $problem = $installer->testDatabase($db, $storage);
                    if ($problem !== null) {
                        $errors[] = $problem;
                    } else {
                        $ok = 'Veza sa bazom radi' . ($db['driver'] === 'mysql' ? ' i korisnik ima prava za pravljenje tabela.' : ' (SQLite fajl ' . $storage . '/panel.sqlite).');
                        if (($_POST['akcija'] ?? '') === 'dalje') {
                            $_SESSION['db'] = array_merge($db, ['pass' => '']); // lozinka je samo šifrovana (db_pass_enc)
                            header('Location: ?korak=adresa', true, 303);
                            return;
                        }
                    }
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $mysqlOk = extension_loaded('pdo_mysql');
            $sqliteOk = extension_loaded('pdo_sqlite');
            $hasPass = isset($_SESSION['db_pass_enc']) && (string) racunator_install_unseal((string) $_SESSION['db_pass_enc']) !== '';
            $body = '<p>Podatke o bazi (ime, korisnik, lozinka) pravite u kontrolnom panelu hostinga → „MySQL Databases" (ili „MySQL Database Wizard"). '
                . 'Ako hosting nema MySQL, izaberite SQLite.</p>'
                . Ui::errors($errors) . ($ok !== null ? Ui::alert('ok', '<p>' . Ui::e($ok) . '</p>') : '')
                . '<form method="post" action="?korak=baza" class="card" autocomplete="off">' . $tok
                . '<label class="choice"><input type="radio" name="driver" value="mysql"' . ($in['driver'] === 'mysql' ? ' checked' : '') . ($mysqlOk ? '' : ' disabled')
                . '> <strong>MySQL / MariaDB</strong> (preporučeno)' . ($mysqlOk ? '' : ' — nije dostupno na ovom hostingu') . '</label>'
                . Ui::input('host', 'Server baze', (string) $in['host'], 'text', 'Na shared hostingu skoro uvek „localhost".')
                . Ui::input('port', 'Port', (string) $in['port'], 'text', 'Obično 3306.', ['inputmode' => 'numeric'])
                . Ui::input('name', 'Ime baze', (string) $in['name'], 'text', 'Često sa prefiksom naloga, npr. „mojnalog_panel".')
                . Ui::input('user', 'Korisnik baze', (string) $in['user'])
                . Ui::input('pass', 'Lozinka baze', '', 'password', $hasPass ? 'Lozinka je već uneta — ostavite prazno da je zadržite.' : '', ['autocomplete' => 'new-password'])
                . '<label class="choice"><input type="radio" name="driver" value="sqlite"' . ($in['driver'] === 'sqlite' ? ' checked' : '') . ($sqliteOk ? '' : ' disabled')
                . '> <strong>SQLite</strong> — baza u fajlu ' . Ui::e($storage) . '/panel.sqlite (ne treba ništa podešavati)' . ($sqliteOk ? '' : ' — nije dostupno') . '</label>'
                . '<div class="actions"><button type="submit" name="akcija" value="test">Testiraj vezu</button>'
                . '<button type="submit" name="akcija" value="dalje" class="primary">Dalje: javna adresa</button></div></form>';
            Ui::page('Baza', $body, $steps, 'baza');
            return;

        case 'adresa':
            $errors = [];
            $url = (string) ($_SESSION['public_url'] ?? Installer::suggestPublicUrl($_SERVER));
            if ($post) {
                $url = trim((string) ($_POST['public_url'] ?? ''));
                try {
                    $_SESSION['public_url'] = Installer::normalizePublicUrl($url);
                    header('Location: ?korak=nalog', true, 303);
                    return;
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $warn = str_starts_with($url, 'http://')
                ? Ui::alert('upozorenje', '<p>Adresa počinje sa http://. Aplikacija Računator šalje račune samo na <strong>https://</strong> adresu — uključite SSL na hostingu i ovde upišite https://.</p>')
                : '';
            $body = '<p>Ovo je adresa na kojoj se panel otvara i na koju aplikacija Računator šalje račune. Predlog je napravljen iz trenutne adrese; '
                . 'promenite ga samo ako panel otvarate preko drugog domena.</p>' . Ui::errors($errors) . $warn
                . '<form method="post" action="?korak=adresa" class="card">' . $tok
                . Ui::input('public_url', 'Javna adresa panela', $url, 'url', 'Bez kose crte na kraju, npr. https://knjigovodstvo.rs/panel', ['required' => true])
                . '<div class="actions"><a class="btn" href="?korak=baza">Nazad</a><button type="submit" class="primary">Dalje: prvi nalog</button></div></form>';
            Ui::page('Javna adresa', $body, $steps, 'adresa');
            return;

        case 'nalog':
            $errors = [];
            $form = ['admin_username' => '', 'accountant_name' => ''];
            if ($post) {
                $form = [
                    'admin_username' => trim((string) ($_POST['admin_username'] ?? '')),
                    'accountant_name' => trim((string) ($_POST['accountant_name'] ?? '')),
                ];
                $pw = (string) ($_POST['admin_password'] ?? '');
                $errors = Installer::validateAdmin($form['admin_username'], $pw, (string) ($_POST['admin_password2'] ?? ''), $form['accountant_name']);
                if ($errors === []) {
                    try {
                        $db = (array) $_SESSION['db'];
                        $db['pass'] = $db['driver'] === 'mysql' ? (string) racunator_install_unseal((string) ($_SESSION['db_pass_enc'] ?? '')) : '';
                        $result = $installer->install([
                            'db' => $db,
                            'storage' => $storage,
                            'public_url' => $_SESSION['public_url'],
                            'admin_username' => $form['admin_username'],
                            'admin_password' => $pw,
                            'accountant_name' => $form['accountant_name'],
                            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                        ]);
                    } catch (\Throwable $e) {
                        $errors[] = 'Instalacija nije uspela: ' . $e->getMessage();
                    }
                    unset($db);
                    if ($errors === []) {
                        racunator_install_done($installer, $result, $steps);
                        return;
                    }
                }
            }
            $body = '<p>Ovo je nalog administratora (vi). Kasnije u panelu dodajete naloge za zaposlene i, po želji, dvofaktorsku prijavu.</p>'
                . Ui::errors($errors)
                . '<form method="post" action="?korak=nalog" class="card" autocomplete="off">' . $tok
                . Ui::input('accountant_name', 'Naziv knjigovodstva', $form['accountant_name'], 'text', 'Prikazuje se klijentima u aplikaciji posle skeniranja QR koda.', ['required' => true, 'maxlength' => '200'])
                . Ui::input('admin_username', 'Korisničko ime', $form['admin_username'], 'text', 'Slova bez kvačica, brojevi, tačka, crtica.', ['required' => true, 'autocomplete' => 'username'])
                . Ui::input('admin_password', 'Lozinka', '', 'password', '', ['required' => true, 'minlength' => '12', 'autocomplete' => 'new-password'])
                . '<div class="meter" id="meter" data-score="0"><i></i></div><small id="meter-label">najmanje 12 znakova</small>'
                . Ui::input('admin_password2', 'Ponovite lozinku', '', 'password', '', ['required' => true, 'autocomplete' => 'new-password'])
                . '<p class="hint">Savet: koristite frazu od nekoliko reči, npr. „plavi-kofer-sedam-oblaka". Lozinku čuvajte u menadžeru lozinki.</p>'
                . '<div class="actions"><a class="btn" href="?korak=adresa">Nazad</a><button type="submit" class="primary">Instaliraj</button></div></form>';
            Ui::page('Prvi nalog', $body, $steps, 'nalog');
            return;
    }
}

/** Kraj instalacije: provera /health, brisanje install fajla, link na prijavu. */
function racunator_install_done(Installer $installer, array $result, array $steps): void
{
    $appKey = '';
    try {
        $appKey = (string) ($installer->loadConfig()['app_key'] ?? '');
    } catch (\Throwable) {
    }
    $health = Installer::checkHealth(new NativeHttpClient(10, 65536), $result['public_url'], $appKey);
    $stub = $installer->removeStub();
    // Lozinka baze je sada samo u config.php (0600): sesija čarobnjaka i ključ za njeno šifrovanje se brišu.
    $_SESSION = [];
    @session_destroy();
    racunator_install_forget_key();
    $login = $result['public_url'] . '/prijava';
    $body = Ui::alert('ok', '<p><strong>Panel je instaliran</strong> (verzija ' . Ui::e($result['version']) . ').</p>')
        . ($health['ok'] ? Ui::alert('ok', '<p>' . Ui::e($health['message']) . '</p>')
            : Ui::alert('upozorenje', '<p><strong>Samoprovera nije uspela.</strong> ' . Ui::e($health['message']) . '</p><p>Vidi „Tipični problemi" u docs/instalacija.md.</p>'))
        . ($stub !== null ? '<p>Install fajl <code>' . Ui::e($stub) . '</code> je obrisan.</p>'
            : '<p class="hint">Ako je u folderu ostao <code>racunator-panel-install.php</code>, obrišite ga preko FTP-a.</p>')
        . '<div class="card"><h2>Sledeći koraci</h2><ol><li>Prijavite se u panel.</li><li>U Podešavanjima uključite dvofaktorsku prijavu.</li>'
        . '<li>Dodajte prvog korisnika i dajte mu QR kod da ga skenira u aplikaciji Računator.</li></ol>'
        . '<p class="hint">Folder install/ ostaje — koristi se samo za ručno ažuriranje i zaključan je dok postoji config.php.</p>'
        . '<div class="actions"><a class="btn primary" href="' . Ui::e($login) . '">Otvori prijavu</a></div></div>';
    Ui::page('Gotovo', $body, $steps, 'gotovo');
}

/** Sesija čarobnjaka (zasebna od sesije panela), po mogućstvu u storage/tmp; strogi režim (nepoznat ID se ne prihvata). */
function racunator_install_session(Installer $installer, bool $https): void
{
    $dir = $installer->bootStorage() . '/tmp/sessions-install';
    if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    session_name('racunator_install');
    session_set_cookie_params(['lifetime' => 0, 'path' => racunator_install_cookie_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    $GLOBALS['racunator_install_https'] = $https;
    session_start();
}

function racunator_install_cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/') . '/';
}

/** Ključ za šifrovanje lozinke baze u sesiji: samo u kolačiću pregledača (fajl sesije na serveru ga nema). */
function racunator_install_key(): string
{
    $name = 'racunator_install_k';
    $raw = isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? base64_decode(strtr($_COOKIE[$name], '-_', '+/'), true) : false;
    if (!is_string($raw) || strlen($raw) !== 32) {
        $raw = random_bytes(32);
        $_COOKIE[$name] = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        if (!headers_sent()) {
            setcookie($name, $_COOKIE[$name], ['expires' => 0, 'path' => racunator_install_cookie_path(),
                'secure' => (bool) ($GLOBALS['racunator_install_https'] ?? false), 'httponly' => true, 'samesite' => 'Strict']);
        }
    }
    return $raw;
}

function racunator_install_forget_key(): void
{
    if (!headers_sent()) {
        setcookie('racunator_install_k', '', ['expires' => 1, 'path' => racunator_install_cookie_path(), 'httponly' => true, 'samesite' => 'Strict']);
    }
}

function racunator_install_seal(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', racunator_install_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $ct === false ? '' : base64_encode($iv . $tag . $ct);
}

function racunator_install_unseal(string $sealed): ?string
{
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', racunator_install_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/** Predlog foldera za podatke van web root-a (roditelj DOCUMENT_ROOT-a), ako je upisiv. */
function racunator_install_storage_suggestion(Installer $installer): ?string
{
    $doc = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($doc === '' || !is_dir($doc)) {
        return null;
    }
    $parent = dirname((string) (realpath($doc) ?: $doc));
    return $parent !== '/' && @is_writable($parent) ? $parent . '/racunator-podaci' : null;
}

/**
 * Sesija nema ključ install fajla: dokaz vlasništva. Vlasnik preko FTP-a napravi (prazan) fajl install/DOZVOLA-<niz>.txt;
 * niz zna samo ova sesija, pa niko drugi ne može da „preuzme" instalaciju. Posle dokaza čarobnjak izdaje nov ključ.
 */
function racunator_install_ownership(Installer $installer, string $csrf, bool $post): void
{
    $_SESSION['proof_nonce'] ??= bin2hex(random_bytes(16));
    $nonce = (string) $_SESSION['proof_nonce'];
    $file = Installer::proofFileName($nonce);
    $error = null;
    if ($post && ($_POST['akcija'] ?? '') === 'dozvola') {
        if ($installer->consumeProof($nonce)) {
            $token = $installer->issueToken();
            session_regenerate_id(true);
            $_SESSION['install_token'] = hash('sha256', $token);
            unset($_SESSION['proof_nonce']);
            header('Location: ./', true, 303);
            return;
        }
        $error = 'Fajl install/' . $file . ' još ne postoji. Proverite ime (velika i mala slova) i da je u folderu install/.';
    }
    $started = $installer->hasToken();
    $body = Ui::alert('upozorenje', '<p>' . ($started
            ? 'Instalaciju je već započeo install fajl u drugom pregledaču (ili sesija tog pregledača više ne važi).'
            : 'Ovaj čarobnjak nije otvoren preko install fajla.') . ' Da biste nastavili, dokažite da imate pristup fajlovima hostinga.</p>')
        . ($error !== null ? Ui::errors([$error]) : '')
        . '<div class="card"><ol><li>Preko FTP-a ili File Manager-a, u folderu <code>install/</code> panela, napravite prazan fajl sa imenom:'
        . '<p><code style="font-size:1.1em">' . Ui::e($file) . '</code></p></li>'
        . '<li>Kliknite <strong>Proverio sam</strong>. Fajl se briše sam, a ovaj pregledač nastavlja instalaciju.</li></ol>'
        . '<form method="post" action="./">' . Ui::hidden('_token', $csrf) . Ui::hidden('akcija', 'dozvola')
        . '<div class="actions"><button type="submit" class="primary">Proverio sam</button></div></form>'
        . '<p class="hint">Sa SSH pristupom: <code>php install/cli.php dozvola</code> ispisuje adresu čarobnjaka sa ključem.</p></div>';
    Ui::page('Potvrda vlasništva', $body, [], '', 403);
}

/** Postoji config.php: instaler je zaključan; dozvoljeno je samo ručno ažuriranje (admin panela). */
function racunator_install_update_mode(Installer $installer): void
{
    $root = $installer->root();
    try {
        $app = App::boot($root);
    } catch (\Throwable $e) {
        Ui::page('Panel ne može da se pokrene', Ui::alert('greska', '<p>Instalacija postoji (config.php), ali panel ne može da se pokrene: '
            . Ui::e($e->getMessage()) . '</p><p>Ako je ovo posle neuspelog ažuriranja, prethodna verzija je u folderu <code>_old/</code>, a rezervne kopije u '
            . '<code>storage/backups/</code> — vidi „Ručni povratak" u docs/azuriranje.md.</p>'), [], '', 500);
        return;
    }
    if ($app->settings->get('install_lock') !== '1') {
        Ui::page('Instalacija nije završena', Ui::alert('greska', '<p>Postoji config.php, ali baza nema oznaku završene instalacije. '
            . 'Obrišite config.php preko FTP-a i pokrenite instalaciju ponovo (baza mora biti prazna).</p>'), [], '', 409);
        return;
    }
    $request = Request::fromGlobals();
    $storage = $app->config->storagePath();
    $pkg = racunator_install_manual_package($storage);
    $pkgDir = $pkg !== null ? $root . '/' . AppFiles::RELEASES_DIR . '/' . $pkg['version'] : null;
    $flag = is_file($storage . '/maintenance.flag');
    $current = $app->settings->get('version') ?? $app->version;
    $panelUrl = $app->config->publicUrl();

    if ($pkg === null && !$flag) {
        Ui::page('Panel je već instaliran', '<p>Instalacija je zaključana (postoji config.php). Verzija: <strong>' . Ui::e($current) . '</strong>.</p>'
            . '<p>Za ručno ažuriranje otpremite novi <code>racunator-panel-install.php</code> u folder panela i otvorite ga u pregledaču.</p>'
            . '<div class="actions"><a class="btn primary" href="' . Ui::e($panelUrl . '/') . '">Otvori panel</a></div>');
        return;
    }

    $user = $app->guard->user();
    $errors = [];
    $post = $request->isPost();
    if ($post && ($request->form['akcija'] ?? '') === 'prijava') {
        if (!$app->guard->checkCsrf($request)) {
            $errors[] = 'Sigurnosni token nije ispravan — osvežite stranicu.';
        } else {
            $err = $app->guard->login($request, (string) ($request->form['username'] ?? ''), (string) ($request->form['password'] ?? ''),
                isset($request->form['totp']) ? (string) $request->form['totp'] : null);
            if ($err !== null) {
                $errors[] = $err;
            }
            $user = $app->guard->user();
        }
        $post = false;
    }
    if ($user === null || !$user->active || $user->role !== User::ROLE_ADMIN) {
        $body = '<p>Za ručno ažuriranje prijavite se nalogom <strong>administratora</strong> panela.</p>' . Ui::errors($errors)
            . ($user !== null ? Ui::alert('greska', '<p>Prijavljeni nalog nije administrator.</p>') : '')
            . '<form method="post" class="card" autocomplete="off">' . Ui::hidden('_token', $app->guard->csrfToken()) . Ui::hidden('akcija', 'prijava')
            . Ui::input('username', 'Korisničko ime', '', 'text', '', ['required' => true, 'autocomplete' => 'username'])
            . Ui::input('password', 'Lozinka', '', 'password', '', ['required' => true, 'autocomplete' => 'current-password'])
            . Ui::input('totp', 'Kod iz aplikacije za dvofaktorsku prijavu (ako je uključena)', '', 'text', '', ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code'])
            . '<div class="actions"><button type="submit" class="primary">Prijavi se</button></div></form>';
        Ui::page('Ručno ažuriranje', $body, [], '', $errors === [] ? 200 : 401);
        $app->session instanceof \RacunatorPanel\Auth\NativeSession && session_write_close();
        return;
    }

    $updater = new Updater($root, $app->db, $app->settings, $app->audit, new NativeHttpClient(20, 65536), $panelUrl, $app->config->storagePath(),
        null, (string) $app->config->get('app_key', ''));
    if ($post) {
        if (!$app->guard->checkCsrf($request)) {
            Ui::page('Zahtev nije prihvaćen', Ui::alert('greska', '<p>Sigurnosni token nije ispravan — osvežite stranicu.</p>'), [], '', 403);
            return;
        }
        $akcija = (string) ($request->form['akcija'] ?? '');
        if ($akcija === 'odrzavanje' && $flag) {
            @unlink($storage . '/maintenance.flag');
            $app->audit->log($user->id, 'update.maintenance_cleared', null, null, $request->ip);
            header('Location: ./', true, 303);
            return;
        }
        if ($akcija === 'odbaci' && $pkg !== null) {
            Updater::clearManualUpdate($storage, $root);
            $app->audit->log($user->id, 'update.manual_discarded', null, null, $request->ip, json_encode(['version' => $pkg['version']]) ?: null);
            header('Location: ./', true, 303);
            return;
        }
        if ($akcija === 'azuriraj' && $pkg !== null && ($request->form['potvrda'] ?? '') === 'da') {
            $unverified = !$updater->canVerifySignatures() && ($request->form['sha_provereno'] ?? '') === 'da';
            $result = $updater->updateFromPackage($pkg['zip'], $pkg['sig'], $user->id, $request->ip, $unverified, $pkg['version']);
            $body = ($result['ok']
                    ? Ui::alert('ok', '<p><strong>Ažurirano na ' . Ui::e($result['to']) . '.</strong></p>')
                    : Ui::alert($result['rolled_back'] ? 'upozorenje' : 'greska', '<p><strong>Ažuriranje nije uspelo</strong>' . ($result['rolled_back'] ? ' — vraćena je prethodna verzija' : '')
                        . ': ' . Ui::e((string) $result['error']) . '</p>'))
                . Ui::updateSteps($result['steps'])
                . '<div class="actions"><a class="btn primary" href="' . Ui::e($panelUrl . '/azuriranje') . '">Otvori panel</a></div>';
            Ui::page('Rezultat ažuriranja', $body);
            return;
        }
    }

    $body = '';
    if ($flag) {
        $body .= Ui::alert('upozorenje', '<p>Panel je u režimu održavanja (verovatno je prethodno ažuriranje prekinuto).</p>'
            . '<form method="post">' . Ui::hidden('_token', $app->guard->csrfToken()) . Ui::hidden('akcija', 'odrzavanje')
            . '<button type="submit">Isključi režim održavanja</button></form>');
    }
    if ($pkg !== null) {
        $to = $pkg['version'];
        $problems = $updater->preflight(2 * (int) @filesize($pkg['zip']));
        $canVerify = $updater->canVerifySignatures();
        if ($pkg['sig'] === null) {
            $problems[] = 'Install fajl nema digitalni potpis paketa, pa ga panel ne prihvata. Preuzmite racunator-panel-install.php sa zvanične '
                . 'stranice izdanja na GitHub-u (github.com/' . \RacunatorPanel\Services\ReleaseChecker::REPO . '/releases) i otpremite ga ponovo.';
        }
        $body .= '<div class="card"><h2>Ažuriranje ' . Ui::e($current) . ' → ' . Ui::e($to) . '</h2>'
            . '<p>Install fajl je ostavio paket verzije ' . Ui::e($to) . '. Pre zamene fajlova panel proverava <strong>digitalni potpis</strong> paketa '
            . 'svojim javnim ključem i SHA-256 svakog fajla.</p>'
            . '<ol><li>Panel prelazi u režim održavanja.</li><li>Pravi se rezervna kopija fajlova i baze.</li>'
            . '<li>Menjaju se fajlovi programa; config.php, baza, nalozi, ključevi i storage/ ostaju.</li><li>Migracije baze i samoprovera.</li>'
            . '<li>Ako nešto ne uspe — automatski povratak na ' . Ui::e($current) . '.</li></ol><p>Traje obično manje od minuta.</p>';
        $changelog = $pkgDir !== null ? $pkgDir . '/CHANGELOG.md' : '';
        if ($changelog !== '' && is_file($changelog) && !is_link($changelog)) {
            $body .= '<h2>Izmene</h2><div class="card">' . nl2br(Ui::e(mb_substr((string) file_get_contents($changelog), 0, 20000))) . '</div>';
        }
        $shaBox = '';
        if (!$canVerify && $pkg['sig'] !== null) {
            $body .= Ui::alert('upozorenje', '<p><strong>Potpis ne može da se proveri</strong> — hosting nema PHP ekstenziju sodium. Pre potvrde uporedite '
                . 'SHA-256 install fajla sa redom <code>racunator-panel-install.php</code> u fajlu SHA256SUMS na stranici izdanja:</p>'
                . '<p><code>' . Ui::e((string) ($pkg['installer_sha256'] ?? '?')) . '</code></p>'
                . '<p>Ako se ne poklapa do poslednjeg znaka, <strong>ne potvrđujte</strong> i kliknite „Odbaci paket".</p>');
            $shaBox = '<p><label><input type="checkbox" name="sha_provereno" value="da" required> Uporedio sam SHA-256 sa stranicom izdanja i poklapa se.</label></p>';
        }
        if ($problems !== []) {
            $body .= Ui::errors($problems);
        } else {
            $body .= '<form method="post">' . Ui::hidden('_token', $app->guard->csrfToken()) . Ui::hidden('akcija', 'azuriraj')
                . '<p><label><input type="checkbox" name="potvrda" value="da" required> Razumem: biće napravljena rezervna kopija, a panel će kratko biti nedostupan.</label></p>'
                . $shaBox
                . '<div class="actions"><button type="submit" class="primary">Potvrđujem — ažuriraj na ' . Ui::e($to) . '</button></div></form>';
        }
        $body .= '<form method="post">' . Ui::hidden('_token', $app->guard->csrfToken()) . Ui::hidden('akcija', 'odbaci')
            . '<div class="actions"><button type="submit">Odbaci paket</button></div></form>';
        $body .= '</div>';
    }
    Ui::page('Ručno ažuriranje', $body);
}

/**
 * Paket ručnog ažuriranja koji čeka potvrdu (storage/tmp/manual-update.json + ZIP i potpis u storage/tmp/manual-update/).
 * @return array{version:string,zip:string,sig:?string,installer_sha256:?string}|null
 */
function racunator_install_manual_package(string $storage): ?array
{
    $file = $storage . '/tmp/manual-update.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data) || !isset($data['version']) || \RacunatorPanel\Services\ReleaseChecker::versionFromTag((string) $data['version']) !== (string) $data['version']) {
        return null;
    }
    $version = (string) $data['version'];
    $zip = $storage . '/tmp/manual-update/racunator-panel-' . $version . '.zip';
    if (!is_file($zip) || is_link($zip)) {
        return null; // stariji install fajl (bez ZIP-a i potpisa) — ne prihvata se
    }
    $sig = is_file($zip . '.sig') && !is_link($zip . '.sig') ? $zip . '.sig' : null;
    $sha = isset($data['installer_sha256']) && is_string($data['installer_sha256']) && preg_match('/^[0-9a-f]{64}$/', $data['installer_sha256']) === 1
        ? $data['installer_sha256'] : null;
    return ['version' => $version, 'zip' => $zip, 'sig' => $sig, 'installer_sha256' => $sha];
}
