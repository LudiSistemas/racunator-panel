<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Services\Terms;

/**
 * Uslovi korišćenja u panelu. Svaki prijavljen nalog vidi sažetak, link na ceo tekst i kada su uslovi prihvaćeni.
 * Administrator koji nije prihvatio važeću verziju (`Terms::VERSION`) ovde dolazi preusmeren iz BaseController-a i ne
 * može dalje dok ne označi „Prihvatam uslove korišćenja" (provera na serveru); prihvatanje se beleži u settings i dnevnik.
 */
final class TermsController extends BaseController
{
    public function show(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser(false)) {
            return $deny;
        }
        return $this->termsPage(null);
    }

    public function accept(Request $request, array $params = []): Response
    {
        $deny = $this->requireAdmin(false) ?? $this->checkCsrf($request);
        if ($deny !== null) {
            return $deny;
        }
        if (!Terms::needsAcceptance($this->app->settings)) {
            return $this->redirect('/');
        }
        if ($this->field($request, 'prihvatam_uslove') !== 'da') {
            return $this->termsPage('Da biste nastavili rad u panelu, označite „Prihvatam uslove korišćenja".', 422);
        }
        Terms::accept($this->app->settings, $this->app->audit, $this->user()->id, $request->ip);
        $this->flash()->success('Uslovi korišćenja (verzija ' . Terms::VERSION . ') su prihvaćeni.');
        return $this->redirect('/');
    }

    private function termsPage(?string $error, int $status = 200): Response
    {
        $settings = $this->app->settings;
        $user = $this->user();
        return $this->page('uslovi', [
            'title' => 'Uslovi korišćenja',
            'termsVersion' => Terms::VERSION,
            'date' => Terms::DATE,
            'termsUrl' => Terms::URL,
            'summary' => Terms::summary(),
            'mustAccept' => $user->isAdmin() && Terms::needsAcceptance($settings),
            'pendingForAdmin' => !$user->isAdmin() && Terms::needsAcceptance($settings),
            'acceptedVersion' => Terms::acceptedVersion($settings),
            'acceptedAt' => $settings->get(Terms::SETTING_AT),
            'error' => $error,
        ], $status, ['Cache-Control' => 'no-store, private']);
    }
}
