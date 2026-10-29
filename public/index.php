<?php
declare(strict_types=1);

/*
 * Front controller. Svi zahtevi (osim postojećih fajlova u assets/) dolaze ovde preko public/.htaccess.
 * Greške: posetilac vidi kratku stranicu na srpskom, detalji idu u storage/logs/error.log (bez argumenata funkcija,
 * da u log ne dospeju ključevi ili lozinke).
 */

use RacunatorPanel\App;
use RacunatorPanel\Kernel\Request;

// Ugrađeni PHP server (`php -S … public/index.php`): postojeće fajlove iz assets/ služi direktno.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (str_starts_with(realpath($file) ?: '', __DIR__ . '/assets/') && is_file($file) && !preg_match('/\.ph(p\d?|tml|ar)$/i', $file)) {
        return false;
    }
}

$root = dirname(__DIR__);

// Authorization zaglavlje: na CGI/FPM hostinzima stiže kao REDIRECT_HTTP_AUTHORIZATION (posle .htaccess pravila).
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
            }
        }
    }
}

// Ceo repo u web root-u (koreni .htaccess prosleđuje u public/): osnovna putanja je folder iznad public/.
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$uriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (str_ends_with($scriptDir, '/public') && $uriPath !== $scriptDir && !str_starts_with($uriPath, $scriptDir . '/')) {
    $_SERVER['SCRIPT_NAME'] = substr($scriptDir, 0, -strlen('/public')) . '/index.php';
}

require $root . '/src/autoload.php';

try {
    if (!is_file($root . '/config.php')) {
        racunator_panel_error_page(503, 'Panel nije instaliran', 'Panel još nije podešen. Pokrenite instalaciju (racunator-panel-install.php).');
        return;
    }
    // Preveliko telo se odbija pre čitanja u memoriju (Request::fromGlobals čita najviše MAX_BODY + 1 bajt i kad
    // Content-Length nije poslat, npr. kod chunked prenosa).
    $declaredLength = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
    if (ctype_digit($declaredLength) && (strlen($declaredLength) > 12 || (int) $declaredLength > Request::MAX_BODY)) {
        App::payloadTooLarge()->send();
        return;
    }
    $app = App::boot($root);
    $app->handle(Request::fromGlobals($app->trustedProxies()))->send();
} catch (\Throwable $e) {
    racunator_panel_log_error($root, $e);
    racunator_panel_error_page(500, 'Greška na serveru', 'Došlo je do greške. Pokušajte ponovo, a ako se ponovi, obratite se administratoru panela.');
}

function racunator_panel_log_error(string $root, \Throwable $e): void
{
    $dir = $root . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $lines = [];
    for ($t = $e; $t !== null; $t = $t->getPrevious()) {
        $lines[] = sprintf('%s: %s u %s:%d', $t::class, $t->getMessage(), $t->getFile(), $t->getLine());
        foreach ($t->getTrace() as $i => $frame) {
            $lines[] = sprintf('  #%d %s:%s %s%s%s()', $i, $frame['file'] ?? '?', $frame['line'] ?? '?',
                $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
        }
    }
    $entry = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . ($_SERVER['REQUEST_METHOD'] ?? '-') . ' '
        . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) . "\n" . implode("\n", $lines) . "\n";
    if (@file_put_contents($dir . '/error.log', $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log('Računator panel: ' . $e->getMessage());
    }
}

function racunator_panel_error_page(int $status, string $title, string $message): void
{
    if (headers_sent()) {
        return;
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="sr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<title>' . $esc($title) . '</title></head><body><h1>' . $esc($title) . '</h1><p>' . $esc($message) . '</p></body></html>';
}
