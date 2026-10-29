<?php
declare(strict_types=1);

namespace RacunatorPanel\Install;

/** Mali pomoćnici za HTML čarobnjaka (bez zavisnosti od public/assets i šablona panela). */
final class Ui
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Šalje celu stranicu. $steps: lista [ključ, naziv] za traku koraka, $active: aktivni ključ. */
    public static function page(string $title, string $body, array $steps = [], string $active = '', int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        }
        $nav = '';
        if ($steps !== []) {
            $nav .= '<ol class="steps">';
            $passed = true;
            foreach ($steps as [$key, $label]) {
                $cls = $key === $active ? 'active' : ($passed ? 'done' : '');
                if ($key === $active) {
                    $passed = false;
                }
                $nav .= '<li class="' . $cls . '">' . self::e($label) . '</li>';
            }
            $nav .= '</ol>';
        }
        echo '<!doctype html><html lang="sr-Latn"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::e($title) . ' · Računator panel</title><link rel="stylesheet" href="style.css">'
            . '<script src="meter.js" defer></script></head><body><main class="wrap">'
            . '<header class="head"><span class="logo">Računator panel</span><span class="sub">instalacija i ažuriranje</span></header>'
            . $nav . '<h1>' . self::e($title) . '</h1>' . $body
            . '<footer class="foot">Uputstvo: docs/instalacija.md i docs/azuriranje.md u folderu panela.</footer></main></body></html>';
    }

    public static function alert(string $type, string $html): string
    {
        return '<div class="alert alert-' . self::e($type) . '">' . $html . '</div>';
    }

    /** @param list<string> $errors */
    public static function errors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }
        $li = '';
        foreach ($errors as $err) {
            $li .= '<li>' . self::e($err) . '</li>';
        }
        return self::alert('greska', '<ul>' . $li . '</ul>');
    }

    public static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    public static function input(string $name, string $label, string $value = '', string $type = 'text', string $hint = '', array $attrs = []): string
    {
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . self::e($k) . ($v === true ? '' : '="' . self::e($v) . '"');
        }
        return '<label class="field"><span>' . self::e($label) . '</span><input type="' . self::e($type) . '" name="' . self::e($name)
            . '" id="f-' . self::e($name) . '" value="' . self::e($value) . '"' . $extra . '>'
            . ($hint !== '' ? '<small>' . self::e($hint) . '</small>' : '') . '</label>';
    }

    /** @param list<array{key:string,label:string,status:string,message:string,hint:string}> $results */
    public static function checks(array $results): string
    {
        $icons = [Checks::OK => '✔', Checks::WARN => '!', Checks::FAIL => '✖'];
        $html = '<ul class="checks">';
        foreach ($results as $r) {
            $html .= '<li class="check check-' . self::e($r['status']) . '"><span class="icon" aria-hidden="true">' . $icons[$r['status']] . '</span>'
                . '<div><strong>' . self::e($r['label']) . '</strong> <span class="status">(' . self::e($r['status'] === Checks::OK ? 'u redu' : $r['status']) . ')</span>'
                . '<p>' . self::e($r['message']) . '</p>'
                . ($r['hint'] !== '' && $r['status'] !== Checks::OK ? '<p class="hint">Šta tražiti od hostinga: ' . self::e($r['hint']) . '</p>' : '')
                . '</div></li>';
        }
        return $html . '</ul>';
    }

    /** @param list<array{at:string,step:string,status:string,message:string}> $steps */
    public static function updateSteps(array $steps): string
    {
        $html = '<table class="log"><thead><tr><th>Vreme</th><th>Korak</th><th>Opis</th></tr></thead><tbody>';
        foreach ($steps as $s) {
            $html .= '<tr class="log-' . self::e($s['status']) . '"><td>' . self::e(substr($s['at'], 11, 8)) . '</td><td>'
                . self::e($s['step']) . '</td><td>' . self::e($s['message']) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }
}
