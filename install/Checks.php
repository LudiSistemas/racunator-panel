<?php
declare(strict_types=1);

namespace RacunatorPanel\Install;

/**
 * Provere hostinga pre instalacije (i pre ažuriranja). Svaka stavka: zeleno (ok), žuto (upozorenje — radi, ali nešto
 * nedostaje ili nije moglo da se proveri) ili crveno (greska — instalacija ne može). Uz svaku je objašnjenje i šta
 * tražiti od hostinga.
 */
final class Checks
{
    public const OK = 'ok';
    public const WARN = 'upozorenje';
    public const FAIL = 'greska';

    public const MIN_PHP = '8.1.0';
    public const MIN_MEMORY = 64 * 1024 * 1024;
    public const MIN_FREE = 50 * 1024 * 1024;

    private readonly SystemProbe $probe;

    /**
     * @param string $root koren instalacije
     * @param ?string $installUrl adresa direktorijuma install/ (za test mod_rewrite-a), null u CLI režimu
     */
    public function __construct(
        private readonly string $root,
        private readonly ?string $installUrl = null,
        private readonly bool $https = false,
        ?SystemProbe $probe = null,
    ) {
        $this->probe = $probe ?? new SystemProbe();
    }

    /** @return list<array{key:string,label:string,status:string,message:string,hint:string}> */
    public function run(): array
    {
        $p = $this->probe;
        $r = [];

        $php = $p->phpVersion();
        $r[] = version_compare($php, self::MIN_PHP, '>=')
            ? $this->item('php', 'PHP verzija', self::OK, 'PHP ' . $php . '.')
            : $this->item('php', 'PHP verzija', self::FAIL, 'PHP ' . $php . ' je prestar; potreban je 8.1 ili noviji.',
                'U kontrolnom panelu hostinga (cPanel → „Select PHP Version" / „MultiPHP Manager") izaberite PHP 8.1 ili noviji za ovaj domen.');

        $pdo = $p->extensionLoaded('pdo');
        $mysql = $pdo && $p->extensionLoaded('pdo_mysql');
        $sqlite = $pdo && $p->extensionLoaded('pdo_sqlite');
        if ($mysql && $sqlite) {
            $r[] = $this->item('pdo', 'Baza (PDO)', self::OK, 'Dostupni su MySQL/MariaDB i SQLite.');
        } elseif ($mysql || $sqlite) {
            $r[] = $this->item('pdo', 'Baza (PDO)', self::OK, 'Dostupan je ' . ($mysql ? 'MySQL/MariaDB' : 'SQLite') . ' (drugi drajver nije uključen).',
                $mysql ? '' : 'Ako želite MySQL, zatražite ekstenziju pdo_mysql.');
        } else {
            $r[] = $this->item('pdo', 'Baza (PDO)', self::FAIL, 'Nema PDO drajvera za MySQL ni za SQLite.',
                'Zatražite od hostinga da uključi PHP ekstenzije „pdo" i „pdo_mysql" (ili „pdo_sqlite").');
        }

        foreach ([
            'openssl' => 'Potrebna za bezbedne nasumične ključeve i HTTPS pozive.',
            'mbstring' => 'Potrebna za rad sa tekstom na srpskom (UTF-8).',
            'json' => 'Potrebna za API i podešavanja.',
            'session' => 'Potrebna za prijavu u panel.',
        ] as $ext => $why) {
            $r[] = $p->extensionLoaded($ext)
                ? $this->item('ext_' . $ext, 'Ekstenzija ' . $ext, self::OK, 'Uključena.')
                : $this->item('ext_' . $ext, 'Ekstenzija ' . $ext, self::FAIL, 'Nije uključena. ' . $why,
                    'Zatražite od hostinga da uključi PHP ekstenziju „' . $ext . '" (često je to kvačica u „Select PHP Version → Extensions").');
        }

        $r[] = $p->extensionLoaded('zip')
            ? $this->item('ext_zip', 'Ekstenzija zip', self::OK, 'Uključena (potrebna za ažuriranje i rezervne kopije).')
            : $this->item('ext_zip', 'Ekstenzija zip', self::WARN, 'Nije uključena. Instalacija radi, ali ažuriranje iz panela i rezervne kopije neće raditi.',
                'Zatražite od hostinga PHP ekstenziju „zip".');

        $r[] = $p->extensionLoaded('sodium')
            ? $this->item('ext_sodium', 'Ekstenzija sodium', self::OK, 'Uključena (provera digitalnog potpisa paketa pri ažuriranju).')
            : $this->item('ext_sodium', 'Ekstenzija sodium', self::WARN, 'Nije uključena. Pri ažuriranju se proverava samo SHA-256, bez digitalnog potpisa.',
                'Zatražite od hostinga PHP ekstenziju „sodium".');

        $curl = $p->extensionLoaded('curl') && $p->functionUsable('curl_exec');
        $fopen = filter_var($p->ini('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
        $r[] = ($curl || $fopen)
            ? $this->item('http', 'Odlazni HTTP pozivi', self::OK, $curl ? 'Dostupan curl.' : 'Dostupan allow_url_fopen.')
            : $this->item('http', 'Odlazni HTTP pozivi', self::WARN, 'Nema curl-a ni allow_url_fopen. Panel neće moći sam da proveri i preuzme novu verziju; ažuriranje ide ručno (install fajl preko FTP-a).',
                'Zatražite ekstenziju „curl" ili uključen „allow_url_fopen".');

        $mem = self::bytes($p->ini('memory_limit'));
        $r[] = ($mem === -1 || $mem >= self::MIN_MEMORY)
            ? $this->item('memory', 'Memorija (memory_limit)', self::OK, $mem === -1 ? 'Bez ograničenja.' : self::human($mem) . '.')
            : $this->item('memory', 'Memorija (memory_limit)', self::FAIL, 'Samo ' . self::human($mem) . '; potrebno je najmanje 64 MB.',
                'Povećajte „memory_limit" na 128M (cPanel → „Select PHP Version → Options" ili fajl .user.ini).');

        $rootOk = $p->isWritable($this->root) && $p->canCreateFile($this->root);
        $r[] = $rootOk
            ? $this->item('write_root', 'Pisanje u folder instalacije', self::OK, 'PHP može da piše u ' . $this->root . '.')
            : $this->item('write_root', 'Pisanje u folder instalacije', self::FAIL, 'PHP ne može da piše u ' . $this->root . '.',
                'Postavite prava foldera na 755 (vlasnik je vaš nalog) ili zatražite od hostinga da PHP radi pod vašim korisnikom (PHP-FPM/suPHP).');

        $storage = $this->root . '/storage';
        $storageOk = is_dir($storage) ? ($p->isWritable($storage) && $p->canCreateFile($storage)) : $rootOk;
        $r[] = $storageOk
            ? $this->item('write_storage', 'Pisanje u storage/', self::OK, 'Folder za bazu, kopije i dnevnike je upisiv.')
            : $this->item('write_storage', 'Pisanje u storage/', self::FAIL, 'PHP ne može da piše u storage/.',
                'Postavite prava foldera storage/ na 755 ili 775 za PHP korisnika.');

        $free = $p->freeSpace($this->root);
        if ($free === null) {
            $r[] = $this->item('disk', 'Slobodan prostor', self::WARN, 'Hosting ne dozvoljava proveru slobodnog prostora.',
                'Proverite u kontrolnom panelu da imate bar 50 MB slobodno (rezervne kopije zauzimaju dodatni prostor).');
        } else {
            $r[] = $free >= self::MIN_FREE
                ? $this->item('disk', 'Slobodan prostor', self::OK, self::human($free) . ' slobodno.')
                : $this->item('disk', 'Slobodan prostor', self::FAIL, 'Samo ' . self::human($free) . ' slobodno; potrebno je najmanje 50 MB.',
                    'Oslobodite prostor ili zatražite veći paket od hostinga.');
        }

        $r[] = $this->https
            ? $this->item('https', 'HTTPS', self::OK, 'Stranica je otvorena preko HTTPS-a.')
            : $this->item('https', 'HTTPS', self::WARN, 'Stranica nije otvorena preko HTTPS-a. Aplikacija Računator šalje račune samo na HTTPS adresu.',
                'Uključite besplatan SSL sertifikat (cPanel → „SSL/TLS Status" ili „Let\'s Encrypt") i otvorite instalaciju preko https://.');

        $rw = $p->modRewriteWorks($this->root . '/install', $this->installUrl);
        if ($rw === true) {
            $r[] = $this->item('rewrite', 'mod_rewrite (.htaccess)', self::OK, 'Pravila iz .htaccess rade.');
        } elseif ($rw === false) {
            $r[] = $this->item('rewrite', 'mod_rewrite (.htaccess)', self::FAIL, 'Server ne primenjuje pravila iz .htaccess (mod_rewrite isključen ili AllowOverride None). Panel bez toga ne radi.',
                'Zatražite od hostinga: „uključite mod_rewrite i AllowOverride All za moj folder". Na nginx-u vidite docs/instalacija.md.');
        } else {
            $r[] = $this->item('rewrite', 'mod_rewrite (.htaccess)', self::WARN, 'Test nije bio moguć (hosting ne dozvoljava poziv samom sebi ili nema odlaznih poziva). Biće provereno na kraju instalacije preko /health.',
                'Ako posle instalacije panel daje grešku 404 ili 500, zatražite od hostinga mod_rewrite i AllowOverride All.');
        }

        $basedir = $p->ini('open_basedir');
        if ($basedir !== '') {
            $r[] = $this->item('open_basedir', 'open_basedir', self::OK, 'Uključeno (' . $basedir . '). Panel koristi samo svoj folder, pa ovo ne smeta.',
                'Ako se jave greške „open_basedir restriction", tražite da folder instalacije bude u dozvoljenim putanjama.');
        }

        return $r;
    }

    /** Najgori status u listi. */
    public static function overall(array $results): string
    {
        $status = self::OK;
        foreach ($results as $r) {
            if ($r['status'] === self::FAIL) {
                return self::FAIL;
            }
            if ($r['status'] === self::WARN) {
                $status = self::WARN;
            }
        }
        return $status;
    }

    /** „128M", „1G", „-1" → bajtovi (-1 = bez ograničenja). */
    public static function bytes(string $value): int
    {
        $v = trim($value);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
        }
        return number_format(max(0, $bytes) / 1024, 0, ',', '.') . ' KB';
    }

    /** @return array{key:string,label:string,status:string,message:string,hint:string} */
    private function item(string $key, string $label, string $status, string $message, string $hint = ''): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'hint' => $hint];
    }
}
