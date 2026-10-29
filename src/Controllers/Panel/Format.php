<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

/** Formatiranje za prikaz u panelu i u CSV-u (srpski zapis brojeva i datuma). */
final class Format
{
    /** Vremenska zona za prikaz vremena koja jezgro čuva u UTC-u (primljeno, poslednje slanje, dnevnik). */
    public const DISPLAY_TZ = 'Europe/Belgrade';

    private const MONTHS = [
        1 => 'januar', 2 => 'februar', 3 => 'mart', 4 => 'april', 5 => 'maj', 6 => 'jun',
        7 => 'jul', 8 => 'avgust', 9 => 'septembar', 10 => 'oktobar', 11 => 'novembar', 12 => 'decembar',
    ];

    private const INVOICE_TYPES = [
        'Normal' => 'Promet', 'Proforma' => 'Predračun', 'Copy' => 'Kopija', 'Training' => 'Obuka', 'Advance' => 'Avans',
    ];

    private const TRANSACTION_TYPES = ['Sale' => 'prodaja', 'Refund' => 'refundacija'];

    private const AUDIT_ACTIONS = [
        'login' => 'Prijava',
        'logout' => 'Odjava',
        'client.create' => 'Dodat korisnik',
        'client.update' => 'Izmenjen korisnik',
        'client.rotate_key' => 'Nov ključ korisnika',
        'client.deactivate' => 'Deaktiviran korisnik',
        'client.activate' => 'Aktiviran korisnik',
        'receipts.export' => 'Izvoz računa (CSV)',
        'user.create' => 'Dodat nalog',
        'user.deactivate' => 'Deaktiviran nalog',
        'user.activate' => 'Aktiviran nalog',
        'user.password_reset' => 'Postavljena nova lozinka naloga',
        'user.password_change' => 'Promenjena sopstvena lozinka',
        'user.2fa_enable' => 'Uključena dvofaktorska prijava',
        'user.2fa_disable' => 'Isključena dvofaktorska prijava',
        'settings.update' => 'Izmenjena podešavanja',
        'api.enable' => 'Uključen API za knjigovođu',
        'api.disable' => 'Isključen API za knjigovođu',
        'api.rotate_key' => 'Nov ključ API-ja za knjigovođu',
        'update.run' => 'Ažuriranje panela',
        'update.revert' => 'Povratak na prethodnu verziju',
    ];

    /** Iznos u parama → „1.234,56" (sa tačkom za hiljade) ili „1234,56" (za CSV). */
    public static function amount(int $minor, bool $thousands = true): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = $minor < 0 ? -$minor : $minor;
        $whole = intdiv($abs, 100);
        $fraction = $abs % 100;
        $wholeText = $thousands ? number_format($whole, 0, ',', '.') : (string) $whole;
        return $sign . $wholeText . ',' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /** PFR vreme računa (lokalno, ISO 8601) → „24.10.2026. 21:42". */
    public static function sdcDateTime(string $iso, string $format = 'd.m.Y. H:i'): string
    {
        try {
            return (new \DateTimeImmutable($iso))->format($format);
        } catch (\Exception) {
            return $iso;
        }
    }

    /** Vreme sačuvano u UTC-u → lokalno vreme za prikaz; null → crtica. */
    public static function utc(?string $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(self::DISPLAY_TZ))
                ->format('d.m.Y. H:i');
        } catch (\Exception) {
            return $value;
        }
    }

    /** „2026-10" → „oktobar 2026". */
    public static function month(string $yearMonth): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m)) {
            return $yearMonth;
        }
        $index = (int) $m[2];
        return (self::MONTHS[$index] ?? $m[2]) . ' ' . $m[1];
    }

    /** Ispravan mesec oblika YYYY-MM. */
    public static function isMonth(string $value): bool
    {
        return (bool) preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/', $value);
    }

    /** Vrsta računa i transakcije, npr. „Promet – prodaja". */
    public static function receiptKind(?string $invoiceType, ?string $transactionType): string
    {
        $parts = [];
        if ($invoiceType !== null && $invoiceType !== '') {
            $parts[] = self::INVOICE_TYPES[$invoiceType] ?? $invoiceType;
        }
        if ($transactionType !== null && $transactionType !== '') {
            $parts[] = self::TRANSACTION_TYPES[$transactionType] ?? $transactionType;
        }
        return $parts === [] ? '—' : implode(' – ', $parts);
    }

    public static function invoiceType(?string $type): string
    {
        return $type === null || $type === '' ? '' : (self::INVOICE_TYPES[$type] ?? $type);
    }

    public static function transactionType(?string $type): string
    {
        return $type === null || $type === '' ? '' : (self::TRANSACTION_TYPES[$type] ?? $type);
    }

    public static function auditAction(string $action): string
    {
        return self::AUDIT_ACTIONS[$action] ?? $action;
    }

    /** Uloga naloga za prikaz. */
    public static function role(string $role): string
    {
        return match ($role) {
            'admin' => 'Administrator',
            'zaposleni' => 'Zaposleni',
            default => $role,
        };
    }

    /** Da li je link zaista ka sajtu Poreske uprave (SUF) — drugi linkovi se ne prikazuju kao dugme. */
    public static function isSufUrl(string $url): bool
    {
        return str_starts_with($url, 'https://suf.purs.gov.rs/');
    }

    /** TOTP tajna u grupama od po četiri znaka radi lakšeg prepisivanja. */
    public static function groupSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
