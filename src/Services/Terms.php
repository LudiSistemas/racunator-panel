<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\SettingsRepository;

/**
 * Uslovi korišćenja (pun tekst: docs/uslovi-koriscenja.md i https://sistema.sh/uslovi).
 * Prihvatanje se beleži u settings (`terms_accepted_version`, `terms_accepted_at` u UTC-u) i u dnevnik radnji
 * (`terms.accept`). Kad se tekst suštinski promeni, VERSION se povećava: administrator posle ažuriranja pri prvoj
 * prijavi mora ponovo da prihvati uslove (provera u BaseController-u; ostali nalozi nisu blokirani).
 */
final class Terms
{
    public const VERSION = 1;
    public const DATE = '2026-10-29';
    public const URL = 'https://sistema.sh/uslovi';
    public const DOC = 'docs/uslovi-koriscenja.md';
    public const SETTING_VERSION = 'terms_accepted_version';
    public const SETTING_AT = 'terms_accepted_at';
    public const AUDIT_ACTION = 'terms.accept';

    /**
     * Kratak sažetak (čarobnjak za instalaciju i stranica uslova u panelu).
     * @return list<string>
     */
    public static function summary(): array
    {
        return [
            'Računator panel je besplatan softver otvorenog koda (licenca MIT) i daje se „kakav jeste“, bez ikakvih garancija.',
            'Račune koje klijenti pošalju proveravate sami. Jedini merodavan izvor podataka o fiskalnom računu je zvanični portal Poreske uprave (suf.purs.gov.rs).',
            'Računator nije povezan sa Poreskom upravom i ne daje poreske, knjigovodstvene ni pravne savete.',
            'Za server, bezbednost, rezervne kopije i podatke svojih klijenata odgovorni ste vi; mi nemamo pristup panelu ni računima.',
            'U najvećoj meri koju zakon dozvoljava, autor ne odgovara ni za kakvu štetu nastalu korišćenjem panela (uključujući gubitak podataka i poreske kazne).',
        ];
    }

    /** Prihvaćena verzija uslova (0 = nikad prihvaćeni, npr. instalacija starija od uslova). */
    public static function acceptedVersion(SettingsRepository $settings): int
    {
        $v = (string) $settings->get(self::SETTING_VERSION, '');
        return ctype_digit($v) ? (int) $v : 0;
    }

    /** Važeća verzija uslova još nije prihvaćena. */
    public static function needsAcceptance(SettingsRepository $settings): bool
    {
        return self::acceptedVersion($settings) < self::VERSION;
    }

    /** Beleži prihvatanje važeće verzije (settings + dnevnik radnji). */
    public static function accept(SettingsRepository $settings, AuditLogRepository $audit, ?int $userId, string $ip): void
    {
        $settings->set(self::SETTING_VERSION, (string) self::VERSION);
        $settings->set(self::SETTING_AT, gmdate('Y-m-d H:i:s'));
        $audit->log($userId, self::AUDIT_ACTION, null, null, $ip, json_encode(['version' => self::VERSION]) ?: null);
    }
}
