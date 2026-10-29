<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

use RacunatorPanel\Repositories\SettingsRepository;

/**
 * Provera novog izdanja na GitHub Releases. Šalje samo GET sa User-Agent zaglavljem (bez ikakvih podataka instalacije).
 * Rezultat se kešira u settings (`last_update_check`, `latest_release_json`) najviše jednom u 12 h; „Proveri sada" može
 * da zaobiđe keš, ali najviše jednom u minutu (GitHub dozvoljava 60 anonimnih zahteva na sat po IP adresi).
 */
final class ReleaseChecker
{
    public const REPO = 'LudiSistemas/racunator-panel';
    public const API = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
    /** Dozvoljeni početak adrese paketa (sprečava preuzimanje sa tuđe adrese i kad bi neko podmetnuo odgovor API-ja). */
    public const DOWNLOAD_PREFIX = 'https://github.com/' . self::REPO . '/releases/download/';
    /** Hostovi sa kojih se preuzimaju fajlovi izdanja (github.com preusmerava na CDN za fajlove). */
    public const DOWNLOAD_HOSTS = ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];

    /**
     * Javni Ed25519 ključ za proveru potpisa paketa (base64, 32 bajta). Pravi par: `php tools/generate-signing-key.php`.
     * Bez ispravnog ključa (ili bez ekstenzije sodium) ažuriranje preko mreže je isključeno.
     */
    public const PUBLIC_KEY = 'gsy5vNw/tHlWrGRbnlfJzLqAW4okmmoDjU2um5jgkQc=';

    public const CACHE_SECONDS = 43200;
    public const FORCE_MIN_SECONDS = 60;

    /** @var \Closure():int */
    private \Closure $clock;

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsRepository $settings,
        private readonly string $currentVersion,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** `v1.2.3` ili `1.2.3` (uz opcioni sufiks `-rc.1`) → `1.2.3`; inače null. */
    public static function versionFromTag(string $tag): ?string
    {
        $v = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
        return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $v) === 1 ? $v : null;
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        return version_compare($candidate, $current, '>');
    }

    /**
     * Adresa fajla izdanja je zvanična: samo https, host sa liste, bez korisnika/lozinke/porta/upita/fragmenta, bez „..",
     * kodiranih tačaka i kosih crta; na github.com putanja mora biti /<REPO>/releases/download/<tag>/<fajl>.
     */
    public static function isOfficialDownloadUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1 || preg_match('/%(2e|2f|5c|00)/i', $url) === 1) {
            return false;
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || !isset($p['host'], $p['path'])
            || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment'])) {
            return false;
        }
        $host = strtolower($p['host']);
        if (!in_array($host, self::DOWNLOAD_HOSTS, true)) {
            return false;
        }
        $path = $p['path'];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '.' || $seg === '..') {
                return false;
            }
        }
        if ($host === 'github.com') {
            $prefix = '/' . self::REPO . '/releases/download/';
            return str_starts_with($path, $prefix) && preg_match('#^[^/]+/[^/]+$#', substr($path, strlen($prefix))) === 1;
        }
        return true;
    }

    /** Javni ključ (podrazumevano PUBLIC_KEY) je ispravan base64 od 32 bajta, a ne placeholder. */
    public static function publicKeyConfigured(?string $publicKeyB64 = null): bool
    {
        $raw = base64_decode($publicKeyB64 ?? self::PUBLIC_KEY, true);
        return $raw !== false && strlen($raw) === 32;
    }

    /** Vreme poslednje provere (unix) ili null. */
    public function lastCheckedAt(): ?int
    {
        $v = $this->settings->get('last_update_check');
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }

    /** Poslednje poznato izdanje iz keša, bez mrežnog poziva. */
    public function cached(): ?ReleaseInfo
    {
        $json = $this->settings->get('latest_release_json');
        if ($json === null || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? ReleaseInfo::fromArray($data) : null;
    }

    /**
     * Najnovije izdanje (iz keša ako je mlađi od 12 h). `$force` zaobilazi keš (ograničeno na jednom u minutu).
     * Baca \RuntimeException kad provera ne uspe (poruka je za prikaz korisniku).
     */
    public function latest(bool $force = false): ?ReleaseInfo
    {
        $now = ($this->clock)();
        $last = $this->lastCheckedAt();
        $age = $last === null ? PHP_INT_MAX : $now - $last;
        if ($age < self::CACHE_SECONDS && (!$force || $age < self::FORCE_MIN_SECONDS)) {
            return $this->cached();
        }
        $res = $this->http->get(self::API, [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'racunator-panel/' . $this->currentVersion,
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);
        // Beleži vreme i kad je odgovor greška, da panel ne bi zasipao GitHub zahtevima.
        $this->settings->set('last_update_check', (string) $now);
        if ($res['status'] === 404) {
            // Još nema nijednog izdanja.
            $this->settings->set('latest_release_json', '');
            return null;
        }
        if ($res['status'] !== 200) {
            throw new \RuntimeException('GitHub je odgovorio statusom ' . $res['status'] . '. Pokušajte kasnije.');
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Odgovor GitHub-a nije ispravan JSON.');
        }
        $release = ReleaseInfo::fromGithub($json);
        if ($release === null) {
            throw new \RuntimeException('Poslednje izdanje nema ispravnu oznaku verzije.');
        }
        $this->settings->set('latest_release_json', json_encode($release->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $release;
    }

    /** Novo izdanje ako postoji i novije je od trenutne verzije, inače null. */
    public function available(bool $force = false): ?ReleaseInfo
    {
        $r = $this->latest($force);
        return $r !== null && self::isNewer($r->version, $this->currentVersion) ? $r : null;
    }

    /**
     * Provera Ed25519 potpisa (`.sig` = base64 potpisa). Vraća true/false, ili null kad provera nije moguća
     * (nema sodium ekstenzije ili javni ključ nije postavljen).
     */
    public static function verifySignature(string $data, string $sigFileContents, ?string $publicKeyB64 = null): ?bool
    {
        $publicKeyB64 ??= self::PUBLIC_KEY;
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $pk = base64_decode($publicKeyB64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }
        $sig = base64_decode(trim($sigFileContents), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $data, $pk);
    }

    /** SHA-256 za dati fajl iz sadržaja SHA256SUMS (`<hex>  <ime>` ili `<hex> *<ime>`). */
    public static function sumFor(string $sums, string $fileName): ?string
    {
        foreach (preg_split('/\r?\n/', $sums) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m) && trim($m[2]) === $fileName) {
                return strtolower($m[1]);
            }
        }
        return null;
    }
}
