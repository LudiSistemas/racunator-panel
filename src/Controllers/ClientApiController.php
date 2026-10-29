<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers;

use RacunatorPanel\Auth\ApiDailyLimiter;
use RacunatorPanel\Auth\ApiRateLimiter;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Models\Client;
use RacunatorPanel\Models\Receipt;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\ClientRepository;
use RacunatorPanel\Repositories\ReceiptRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Services\KeyGenerator;

/**
 * API za aplikaciju Računator (v1, docs/api.md):
 *   GET  /u/{slug}/ping      — provera uparivanja;
 *   POST /u/{slug}/receipts  — prijem računa (do 200 po zahtevu, telo do 1 MB).
 * Autentifikacija: `Authorization: Bearer <ključ>`. Pogrešan ključ i nepostojeći/neaktivan slug daju isti 401.
 * Ograničenja: 60 zahteva u minutu po ključu (ApiRateLimiter) i dnevni broj prihvaćenih računa po korisniku
 * (settings `api_daily_limit`, podrazumevano 2000; 0 = bez dnevnog limita). Prelazak 50 % i dostizanje dnevnog limita
 * se jednom dnevno upisuju u dnevnik radnji (`api.daily_warning`, `api.daily_limit`).
 * Polje `source` (`qr` podrazumevano | `pfr`): račun unet po PFR broju ima link tačno https://suf.purs.gov.rs/verify;
 * kad isti račun kasnije stigne iz QR koda sa pravim linkom, zapis se nadograđuje i računa kao prihvaćen.
 */
final class ClientApiController
{
    public const API_VERSION = '1';
    public const MAX_BODY = 1048576;
    public const MAX_RECEIPTS = 200;
    public const MAX_STRING = 200;
    public const MAX_URL = 2048;
    private const MAX_RAW_JSON = 8192;

    private const INVOICE_RE = '/^[A-Z0-9]{8}-[A-Z0-9]{8}-\d{1,10}$/';
    private const URL_PREFIX = 'https://suf.purs.gov.rs/';
    private const DATETIME_RE = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:Z|[+-]\d{2}:?\d{2})?$/';

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ReceiptRepository $receipts,
        private readonly SettingsRepository $settings,
        private readonly ApiRateLimiter $limiter,
        private readonly ?\PDO $pdo = null,
        private readonly ?ApiDailyLimiter $daily = null,
        private readonly ?AuditLogRepository $audit = null,
    ) {
    }

    /** @param array<string,string> $params */
    public function ping(Request $request, array $params): Response
    {
        $client = $this->authenticate($request, (string) ($params['slug'] ?? ''));
        if ($client === null) {
            return self::unauthorized();
        }
        if (($wait = $this->limiter->hit($client->id)) !== null) {
            return self::tooManyRequests($wait);
        }
        $this->clients->touchSeen($client->id);
        return self::json([
            'ok' => true,
            'client' => $client->name,
            'accountant' => (string) $this->settings->get('accountant_name', ''),
            'api' => (int) self::API_VERSION,
        ]);
    }

    /** @param array<string,string> $params */
    public function receipts(Request $request, array $params): Response
    {
        $body = $request->rawBody ?? '';
        $declared = $request->header('content-length');
        if (strlen($body) > self::MAX_BODY || ($declared !== null && ctype_digit($declared) && (int) $declared > self::MAX_BODY)) {
            return self::error(413, 'payload_too_large', 'Telo zahteva je veće od 1 MB. Pošaljite račune u manjim paketima.');
        }
        $client = $this->authenticate($request, (string) ($params['slug'] ?? ''));
        if ($client === null) {
            return self::unauthorized();
        }
        if (($wait = $this->limiter->hit($client->id)) !== null) {
            return self::tooManyRequests($wait);
        }

        $data = $request->json();
        if ($data === null || !array_key_exists('receipts', $data) || !is_array($data['receipts']) || !array_is_list($data['receipts'])) {
            return self::error(400, 'invalid_body', 'Telo mora biti JSON objekat oblika {"receipts":[…]}.');
        }
        $items = $data['receipts'];
        if (count($items) > self::MAX_RECEIPTS) {
            return self::error(413, 'too_many_receipts', 'Najviše ' . self::MAX_RECEIPTS . ' računa po zahtevu.');
        }
        $limit = $this->daily !== null ? $this->dailyLimit() : 0;
        $before = $limit > 0 ? $this->daily->acceptedToday($client->id) : 0;
        if ($limit > 0 && $before >= $limit) {
            $this->noteDaily($request, $client, ApiDailyLimiter::WARN_LIMIT, $before, $limit);
            return $this->dailyLimited($limit, 0);
        }

        $accepted = [];
        $duplicates = [];
        $rejected = [];
        $this->clients->touchSeen($client->id);
        $this->pdo?->beginTransaction();
        try {
            foreach ($items as $item) {
                [$receipt, $reason] = self::validate($item);
                if ($receipt === null) {
                    $rejected[] = ['invoiceNumber' => self::invoiceForReport($item), 'reason' => (string) $reason];
                    continue;
                }
                if ($this->receipts->insertIfNew($client->id, $receipt)) {
                    $accepted[] = $receipt['invoiceNumber'];
                } else {
                    $duplicates[] = $receipt['invoiceNumber'];
                }
            }
            $total = $limit > 0 && $accepted !== [] ? $this->daily->add($client->id, count($accepted)) : $before;
            if ($total > $limit && $limit > 0 && $this->pdo?->inTransaction()) {
                // Ceo zahtev bi prešao dnevni limit: ništa se ne upisuje (ni brojač), aplikacija šalje manje ili sutra.
                $this->pdo->rollBack();
                $this->noteDaily($request, $client, ApiDailyLimiter::WARN_LIMIT, $before, $limit);
                return $this->dailyLimited($limit, max(0, $limit - $before));
            }
            if ($this->pdo?->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo?->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($limit > 0 && $before * 2 < $limit && $total * 2 >= $limit) {
            $this->noteDaily($request, $client, ApiDailyLimiter::WARN_HALF, $total, $limit);
        }
        return self::json(['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected]);
    }

    /** Dnevni limit iz podešavanja (`api_daily_limit`); 0 = bez limita. */
    private function dailyLimit(): int
    {
        $value = trim((string) $this->settings->get('api_daily_limit', (string) ApiDailyLimiter::DEFAULT_LIMIT));
        return ctype_digit($value) ? (int) $value : ApiDailyLimiter::DEFAULT_LIMIT;
    }

    /** Upis u dnevnik radnji, najviše jednom dnevno po nivou (50 % / limit). */
    private function noteDaily(Request $request, Client $client, int $level, int $count, int $limit): void
    {
        if ($this->audit === null || $this->daily === null || !$this->daily->markWarned($client->id, $level)) {
            return;
        }
        $action = $level === ApiDailyLimiter::WARN_LIMIT ? 'api.daily_limit' : 'api.daily_warning';
        $this->audit->log(null, $action, 'client', $client->id, $request->ip, $client->name . ': ' . $count . ' / ' . $limit);
    }

    private function dailyLimited(int $limit, int $remaining): Response
    {
        $wait = $this->daily?->secondsUntilTomorrow() ?? 3600;
        $message = $remaining > 0
            ? 'Zahtev bi prešao dnevni limit od ' . $limit . ' računa. Danas može još ' . $remaining . ' — pošaljite manje računa ili pokušajte sutra.'
            : 'Dostignut je dnevni limit od ' . $limit . ' računa. Pokušajte ponovo sutra.';
        return self::json(['error' => 'daily_limit', 'message' => $message, 'remaining' => $remaining], 429,
            ['Retry-After' => (string) $wait]);
    }

    /**
     * Provera jednog računa. Vraća [podaci za ReceiptRepository::insertIfNew, null] ili [null, razlog odbijanja].
     * @return array{0:?array<string,mixed>,1:?string}
     */
    public static function validate(mixed $item): array
    {
        if (!is_array($item) || array_is_list($item) && $item !== []) {
            return [null, 'račun mora biti JSON objekat'];
        }
        $invoice = $item['invoiceNumber'] ?? null;
        if (!is_string($invoice) || !preg_match(self::INVOICE_RE, $invoice)) {
            return [null, 'invoiceNumber: očekuje se oblik XXXXXXXX-XXXXXXXX-N'];
        }
        // `source` nedostaje (ili je null) → 'qr', kao kod aplikacija pre uvođenja polja.
        $source = $item['source'] ?? Receipt::SOURCE_QR;
        if (!is_string($source) || !in_array($source, [Receipt::SOURCE_QR, Receipt::SOURCE_PFR], true)) {
            return [null, 'source: dozvoljeno je "qr" ili "pfr"'];
        }
        $url = $item['verificationUrl'] ?? null;
        if ($source === Receipt::SOURCE_PFR) {
            // Račun unet po PFR broju nema link iz QR koda — jedini dozvoljeni link je zvanična stranica za proveru.
            if ($url !== Receipt::SUF_VERIFY_URL) {
                return [null, 'verificationUrl: za source "pfr" mora biti tačno ' . Receipt::SUF_VERIFY_URL];
            }
        } elseif (!is_string($url) || !str_starts_with($url, self::URL_PREFIX) || strlen($url) > self::MAX_URL
            || preg_match('/[\s\x00-\x1f\x7f]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [null, 'verificationUrl: mora biti adresa koja počinje sa ' . self::URL_PREFIX];
        }
        $dt = $item['sdcDateTime'] ?? null;
        if (!is_string($dt) || !preg_match(self::DATETIME_RE, $dt, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return [null, 'sdcDateTime: očekuje se ISO 8601, npr. 2026-10-24T21:42:31'];
        }
        $amount = $item['totalAmountMinor'] ?? null;
        if (!is_int($amount) || $amount < 0) {
            return [null, 'totalAmountMinor: očekuje se ceo broj ≥ 0 (iznos u parama)'];
        }
        $out = [
            'invoiceNumber' => $invoice,
            'verificationUrl' => $url,
            // Lokalno vreme PFR-a bez delova sekunde i vremenske zone.
            'sdcDateTime' => sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]),
            'totalAmountMinor' => $amount,
            'source' => $source,
        ];
        foreach (['sellerPib', 'buyerPib'] as $field) {
            $v = $item[$field] ?? null;
            if ($v !== null && (!is_string($v) || !preg_match('/^\d{9}$/', $v))) {
                return [null, $field . ': očekuje se 9 cifara ili null'];
            }
            $out[$field] = $v;
        }
        foreach (['sellerName', 'invoiceType', 'transactionType'] as $field) {
            $v = $item[$field] ?? null;
            if ($v !== null && (!is_string($v) || mb_strlen($v, 'UTF-8') > self::MAX_STRING || preg_match('/[\x00-\x1f\x7f]/', $v))) {
                return [null, $field . ': tekst do ' . self::MAX_STRING . ' znakova ili null'];
            }
            $out[$field] = $v === null ? null : (trim($v) === '' ? null : trim($v));
        }
        $raw = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out['rawJson'] = is_string($raw) && strlen($raw) <= self::MAX_RAW_JSON ? $raw : null;
        return [$out, null];
    }

    /** Ključ iz zaglavlja → korisnik po slug-u → poređenje prefiksa i heša u konstantnom vremenu. */
    private function authenticate(Request $request, string $slug): ?Client
    {
        $token = $request->bearerToken() ?? '';
        $client = preg_match('/^[0-9a-f]{32}$/', $slug) ? $this->clients->findBySlug($slug) : null;
        // Heš se računa uvek, da vreme odgovora ne otkriva da li slug postoji.
        $hash = KeyGenerator::hash($token);
        if ($client === null || !$client->active || !KeyGenerator::looksLikeKey($token)) {
            return null;
        }
        $prefixOk = hash_equals($client->apiKeyPrefix, KeyGenerator::prefix($token));
        $hashOk = hash_equals($client->apiKeyHash, $hash);
        return $prefixOk && $hashOk ? $client : null;
    }

    private static function invoiceForReport(mixed $item): ?string
    {
        $v = is_array($item) ? ($item['invoiceNumber'] ?? null) : null;
        return is_string($v) ? mb_substr($v, 0, 64, 'UTF-8') : null;
    }

    private static function json(array $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers + [
            'X-Racunator-Api' => self::API_VERSION,
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return self::json(['error' => $code, 'message' => $message], $status, $headers);
    }

    private static function unauthorized(): Response
    {
        return self::error(401, 'unauthorized', 'Neispravan ključ ili adresa korisnika.', ['WWW-Authenticate' => 'Bearer']);
    }

    private static function tooManyRequests(int $wait): Response
    {
        return self::error(429, 'rate_limited', 'Previše zahteva. Pokušajte ponovo za ' . $wait . ' s.', ['Retry-After' => (string) $wait]);
    }
}
