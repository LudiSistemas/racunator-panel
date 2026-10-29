<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers;

use RacunatorPanel\App;
use RacunatorPanel\Controllers\Panel\BaseController;
use RacunatorPanel\Controllers\Panel\PanelServices;
use RacunatorPanel\Install\Updater;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Services\Backup;
use RacunatorPanel\Services\HttpClient;
use RacunatorPanel\Services\NativeHttpClient;
use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Services\ReleaseInfo;

/**
 * Stranica Ažuriranje (plan §5a): trenutna verzija, provera nove, ceo changelog, ažuriranje tek posle potvrde,
 * povratak iz rezervne kopije, lista kopija za preuzimanje. Sve je dostupno samo administratoru.
 */
final class UpdateController extends BaseController
{
    private ?HttpClient $http;

    public function __construct(App $app, PanelServices $services, ?HttpClient $http = null, private readonly ?Updater $updater = null)
    {
        parent::__construct($app, $services);
        $this->http = $http;
    }

    public function index(Request $request): Response
    {
        if (($deny = $this->requireAdmin()) !== null) {
            return $deny;
        }
        $checker = $this->checker();
        $error = null;
        try {
            $latest = $checker->latest(false);
        } catch (\Throwable $e) {
            $latest = $checker->cached();
            $error = $e->getMessage();
        }
        $updater = $this->updater();
        $available = $latest !== null && ReleaseChecker::isNewer($latest->version, $this->currentVersion()) ? $latest : null;
        return $this->page('azuriranje/index', [
            'title' => 'Ažuriranje',
            'nav' => 'azuriranje',
            'current' => $this->currentVersion(),
            'latest' => $latest,
            'available' => $available,
            'checkedAt' => $checker->lastCheckedAt(),
            'checkError' => $error,
            'backups' => $this->backupList($updater),
            'last' => $updater->lastResult(),
            'problems' => $updater->preflight($available?->zipSize, true),
            'stuckMaintenance' => $this->stuckMaintenance(),
            'manualPackage' => $this->manualPackage(),
            'signatureCheck' => $updater->canVerifySignatures(),
        ]);
    }

    /** „Proveri sada" — zaobilazi keš (najviše jednom u minutu). */
    public function checkNow(Request $request): Response
    {
        if (($deny = $this->guardPost($request, true)) !== null) {
            return $deny;
        }
        try {
            $r = $this->checker()->available(true);
            $this->flash()->success($r !== null ? 'Dostupna je verzija ' . $r->version . '.' : 'Imate najnoviju verziju.');
        } catch (\Throwable $e) {
            $this->flash()->error('Provera nije uspela: ' . $e->getMessage());
        }
        return $this->redirect('/azuriranje');
    }

    /** Dijalog potvrde (zasebna stranica, radi i bez JavaScript-a). Ništa se ne preuzima. */
    public function confirmUpdate(Request $request): Response
    {
        if (($deny = $this->requireAdmin()) !== null) {
            return $deny;
        }
        $release = $this->availableRelease();
        if ($release === null) {
            $this->flash()->error('Nema nove verzije za instalaciju.');
            return $this->redirect('/azuriranje');
        }
        return $this->page('azuriranje/potvrda', [
            'title' => 'Potvrda ažuriranja',
            'nav' => 'azuriranje',
            'current' => $this->currentVersion(),
            'release' => $release,
            'problems' => $this->updater()->preflight($release->zipSize, true),
            'signatureCheck' => $this->updater()->canVerifySignatures(),
        ]);
    }

    public function runUpdate(Request $request): Response
    {
        if (($deny = $this->guardPost($request, true)) !== null) {
            return $deny;
        }
        $release = $this->availableRelease();
        if ($release === null || $this->field($request, 'verzija') !== $release->version) {
            $this->flash()->error('Verzija za ažuriranje se promenila ili više nije dostupna. Proverite ponovo.');
            return $this->redirect('/azuriranje');
        }
        if ($this->field($request, 'potvrda') !== 'da') {
            $this->flash()->error('Ažuriranje nije pokrenuto: potrebno je potvrditi.');
            return $this->redirect('/azuriranje/pokreni');
        }
        $result = $this->updater()->updateFromRelease($release, $this->user()->id, $request->ip);
        // Rezultat se prikazuje posle preusmerenja — tada stranicu već crta (nova ili vraćena) verzija koda.
        $result['ok']
            ? $this->flash()->success('Panel je ažuriran na verziju ' . $release->version . '.')
            : $this->flash()->error('Ažuriranje nije uspelo: ' . (string) $result['error']);
        return $this->redirect('/azuriranje#poslednje');
    }

    public function confirmRevert(Request $request): Response
    {
        if (($deny = $this->requireAdmin()) !== null) {
            return $deny;
        }
        $name = $this->queryParam($request, 'kopija');
        $meta = $this->updater()->backups()->find($name);
        if ($meta === null) {
            return $this->notFound('Rezervna kopija ne postoji.');
        }
        return $this->page('azuriranje/vrati', [
            'title' => 'Vraćanje prethodne verzije',
            'nav' => 'azuriranje',
            'current' => $this->currentVersion(),
            'backup' => $meta,
        ]);
    }

    public function runRevert(Request $request): Response
    {
        if (($deny = $this->guardPost($request, true)) !== null) {
            return $deny;
        }
        $name = $this->field($request, 'kopija');
        if (!Backup::validName($name) || $this->field($request, 'potvrda') !== 'da') {
            $this->flash()->error('Povratak nije pokrenut: izaberite kopiju i potvrdite.');
            return $this->redirect('/azuriranje');
        }
        $result = $this->updater()->revert($name, $this->user()->id, $request->ip);
        $result['ok']
            ? $this->flash()->success('Vraćena je verzija ' . (string) $result['to'] . ' iz kopije ' . $name . '.')
            : $this->flash()->error('Povratak nije uspeo: ' . (string) $result['error']);
        return $this->redirect('/azuriranje#poslednje');
    }

    /** Uklanja zaostalu oznaku održavanja (npr. posle prekinutog procesa) kad ažuriranje nije u toku. */
    public function clearMaintenance(Request $request): Response
    {
        if (($deny = $this->guardPost($request, true)) !== null) {
            return $deny;
        }
        if ($this->stuckMaintenance()) {
            foreach ($this->app->maintenanceFlags() as $flag) {
                @unlink($flag);
            }
            $this->audit($request, 'update.maintenance_cleared');
            $this->flash()->success('Režim održavanja je isključen.');
        }
        return $this->redirect('/azuriranje');
    }

    public function download(Request $request, array $params): Response
    {
        if (($deny = $this->requireAdmin()) !== null) {
            return $deny;
        }
        $name = (string) ($params['name'] ?? '');
        $file = (string) ($params['file'] ?? '');
        $path = $this->updater()->backups()->filePath($name, $file);
        if ($path === null) {
            return $this->notFound('Fajl rezervne kopije ne postoji.');
        }
        $this->audit($request, 'backup.download', null, null, $name . '/' . $file);
        $type = str_ends_with($file, '.zip') ? 'application/zip' : (str_ends_with($file, '.sql') ? 'application/sql' : 'application/octet-stream');
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="racunator-panel-' . $name . '-' . $file . '"',
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function currentVersion(): string
    {
        return $this->app->settings->get('version') ?? $this->app->version;
    }

    private function checker(): ReleaseChecker
    {
        return new ReleaseChecker($this->http ?? new NativeHttpClient(15), $this->app->settings, $this->currentVersion());
    }

    private function availableRelease(): ?ReleaseInfo
    {
        $r = $this->checker()->cached();
        return $r !== null && ReleaseChecker::isNewer($r->version, $this->currentVersion()) ? $r : null;
    }

    private function updater(): Updater
    {
        if ($this->updater !== null) {
            return $this->updater;
        }
        require_once $this->app->root . '/install/bootstrap.php';
        return new Updater(
            $this->app->root,
            $this->app->db,
            $this->app->settings,
            $this->app->audit,
            $this->http ?? new NativeHttpClient(180),
            $this->app->config->publicUrl(),
            $this->app->config->storagePath(),
            null,
            (string) $this->app->config->get('app_key', ''),
        );
    }

    /** @return list<array<string,mixed>> */
    private function backupList(Updater $updater): array
    {
        try {
            return is_dir($updater->backups()->dir()) ? $updater->backups()->list() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Oznaka održavanja postoji, a nijedan proces ne drži bravu ažuriranja. */
    private function stuckMaintenance(): bool
    {
        $exists = false;
        foreach ($this->app->maintenanceFlags() as $flag) {
            $exists = $exists || is_file($flag);
        }
        if (!$exists) {
            return false;
        }
        $fp = @fopen($this->app->config->storagePath() . '/' . Updater::LOCK, 'c');
        if ($fp === false) {
            return true;
        }
        $free = flock($fp, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $free;
    }

    /** Paket koji je install fajl raspakovao nad postojećom instalacijom (čeka potvrdu u install/). */
    private function manualPackage(): ?array
    {
        $f = $this->app->config->storagePath() . '/tmp/manual-update.json';
        $data = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($data) && isset($data['version']) ? $data : null;
    }
}
