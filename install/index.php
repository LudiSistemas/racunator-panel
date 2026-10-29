<?php
declare(strict_types=1);

/*
 * Čarobnjak za instalaciju Računator panela i ručno ažuriranje.
 *   - Nema config.php → nova instalacija: provere → baza → javna adresa → prvi nalog → kraj.
 *   - Postoji config.php (install lock) → instalacija je zaključana; jedino je moguće ručno ažuriranje iz paketa koji je
 *     install fajl raspakovao u releases/<verzija>/ (posle prijave administratora panela, sa istom zaštitom kao u panelu).
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
        $_SESSION['install_token'] = hash('sha256', $_GET['t']);
        header('Location: ./', true, 303);
        return;
    }
    if (!$installer->tokenMatches($_SESSION['install_token'] ?? null)) {
        Ui::page('Instalacija je zaključana', Ui::alert('greska', '<p>Ovu instalaciju je započeo install fajl, a ova sesija nema njegov ključ. '
            . 'Otvorite ponovo <code>racunator-panel-install.php</code> u pregledaču (ako ga više nema, otpremite ga ponovo) — on će vas vratiti ovde.</p>'), [], '', 403);
        return;
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $csrf = (string) $_SESSION['csrf'];
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($post && !hash_equals($csrf, (string) ($_POST['_token'] ?? ''))) {
        Ui::page('Zahtev nije prihvaćen', Ui::alert('greska', '<p>Sigurnosni token nije ispravan ili je sesija istekla. <a href="./">Počnite ponovo</a>.</p>'), [], '', 403);
        return;
    }
    $step = (string) ($_GET['korak'] ?? 'provere');
    $tok = Ui::hidden('_token', $csrf);
    $installUrl = rtrim(Installer::suggestPublicUrl($_SERVER), '/') . '/install';

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
            $results = (new Checks($installer->root(), $installUrl, $https))->run();
            $overall = Checks::overall($results);
            $_SESSION['checks_ok'] = $overall !== Checks::FAIL;
            $body = '<p>Proveravamo da li hosting ispunjava uslove. Crvene stavke morate rešiti (najčešće jednim zahtevom podršci hostinga), žute su upozorenja.</p>'
                . Ui::checks($results)
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
                // Prazno polje lozinke = zadrži ranije unetu (lozinka se nikad ne vraća u HTML).
                if ($pass === '' && isset($_SESSION['db_pass'])) {
                    $pass = (string) $_SESSION['db_pass'];
                }
                $_SESSION['db_form'] = $in;
                $_SESSION['db_pass'] = $pass;
                try {
                    $db = $installer->dbConfig($in + ['pass' => $pass]);
                    $problem = $installer->testDatabase($db);
                    if ($problem !== null) {
                        $errors[] = $problem;
                    } else {
                        $ok = 'Veza sa bazom radi' . ($db['driver'] === 'mysql' ? ' i korisnik ima prava za pravljenje tabela.' : ' (SQLite fajl storage/panel.sqlite).');
                        if (($_POST['akcija'] ?? '') === 'dalje') {
                            $_SESSION['db'] = $db;
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
            $hasPass = isset($_SESSION['db_pass']) && $_SESSION['db_pass'] !== '';
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
                . '> <strong>SQLite</strong> — baza u fajlu storage/panel.sqlite (ne treba ništa podešavati)' . ($sqliteOk ? '' : ' — nije dostupno') . '</label>'
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
                        $result = $installer->install([
                            'db' => $_SESSION['db'],
                            'public_url' => $_SESSION['public_url'],
                            'admin_username' => $form['admin_username'],
                            'admin_password' => $pw,
                            'accountant_name' => $form['accountant_name'],
                            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                        ]);
                    } catch (\Throwable $e) {
                        $errors[] = 'Instalacija nije uspela: ' . $e->getMessage();
                    }
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
    $health = Installer::checkHealth(new NativeHttpClient(10, 65536), $result['public_url']);
    $stub = $installer->removeStub();
    $_SESSION = [];
    @session_destroy();
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

/** Sesija čarobnjaka (zasebna od sesije panela), po mogućstvu u storage/tmp. */
function racunator_install_session(Installer $installer, bool $https): void
{
    $dir = $installer->storage() . '/tmp/sessions-install';
    if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_name('racunator_install');
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/') . '/';
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
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
    $manual = $installer->storage() . '/tmp/manual-update.json';
    $pkg = is_file($manual) ? json_decode((string) file_get_contents($manual), true) : null;
    $pkgDir = is_array($pkg) && isset($pkg['version']) && \RacunatorPanel\Services\ReleaseChecker::versionFromTag((string) $pkg['version']) !== null
        ? $root . '/' . AppFiles::RELEASES_DIR . '/' . $pkg['version'] : null;
    if ($pkgDir !== null && !is_dir($pkgDir)) {
        $pkgDir = null;
    }
    $flag = is_file($installer->storage() . '/maintenance.flag');
    $current = $app->settings->get('version') ?? $app->version;
    $panelUrl = $app->config->publicUrl();

    if ($pkgDir === null && !$flag) {
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

    $updater = new Updater($root, $app->db, $app->settings, $app->audit, new NativeHttpClient(20, 65536), $panelUrl, $app->config->storagePath());
    if ($post) {
        if (!$app->guard->checkCsrf($request)) {
            Ui::page('Zahtev nije prihvaćen', Ui::alert('greska', '<p>Sigurnosni token nije ispravan — osvežite stranicu.</p>'), [], '', 403);
            return;
        }
        $akcija = (string) ($request->form['akcija'] ?? '');
        if ($akcija === 'odrzavanje' && $flag) {
            @unlink($installer->storage() . '/maintenance.flag');
            $app->audit->log($user->id, 'update.maintenance_cleared', null, null, $request->ip);
            header('Location: ./', true, 303);
            return;
        }
        if ($akcija === 'azuriraj' && $pkgDir !== null && ($request->form['potvrda'] ?? '') === 'da') {
            $result = $updater->updateFromDirectory($pkgDir, $user->id, $request->ip);
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
    if ($pkgDir !== null) {
        $to = AppFiles::versionOf($pkgDir) ?? '?';
        $problems = $updater->preflight(AppFiles::treeSize($pkgDir));
        $body .= '<div class="card"><h2>Ažuriranje ' . Ui::e($current) . ' → ' . Ui::e($to) . '</h2>'
            . '<p>Paket je raspakovan u <code>releases/' . Ui::e($to) . '/</code> i biće proveren fajl po fajl (SHA-256).</p>'
            . '<ol><li>Panel prelazi u režim održavanja.</li><li>Pravi se rezervna kopija fajlova i baze.</li>'
            . '<li>Menjaju se fajlovi programa; config.php, baza, nalozi, ključevi i storage/ ostaju.</li><li>Migracije baze i samoprovera.</li>'
            . '<li>Ako nešto ne uspe — automatski povratak na ' . Ui::e($current) . '.</li></ol><p>Traje obično manje od minuta.</p>';
        $changelog = $pkgDir . '/CHANGELOG.md';
        if (is_file($changelog)) {
            $body .= '<h2>Izmene</h2><div class="card">' . nl2br(Ui::e(mb_substr((string) file_get_contents($changelog), 0, 20000))) . '</div>';
        }
        if ($problems !== []) {
            $body .= Ui::errors($problems);
        } else {
            $body .= '<form method="post">' . Ui::hidden('_token', $app->guard->csrfToken()) . Ui::hidden('akcija', 'azuriraj')
                . '<p><label><input type="checkbox" name="potvrda" value="da" required> Razumem: biće napravljena rezervna kopija, a panel će kratko biti nedostupan.</label></p>'
                . '<div class="actions"><button type="submit" class="primary">Potvrđujem — ažuriraj na ' . Ui::e($to) . '</button></div></form>';
        }
        $body .= '</div>';
    }
    Ui::page('Ručno ažuriranje', $body);
}
