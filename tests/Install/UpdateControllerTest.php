<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\App;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Controllers\Panel\PanelServices;
use RacunatorPanel\Controllers\UpdateController;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Services\ReleaseChecker;

/** Stranica Ažuriranje: ništa se ne preuzima pre potvrde, samo admin, CSRF, changelog bez HTML-a. */
final class UpdateControllerTest extends InstallTestCase
{
    private App $app;
    private FakeHttpClient $http;
    private UpdateController $c;
    private string $zipUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $i = $this->installation('1.0.0');
        $config = new Config(['public_url' => 'https://knjige.rs/panel', 'storage' => $i['root'] . '/storage', 'app_key' => str_repeat('a', 64)]);
        $this->app = App::create(dirname(__DIR__, 2), $config, $i['pdo'], new ArraySession());
        $base = ReleaseChecker::DOWNLOAD_PREFIX . 'v9.9.0/';
        $this->zipUrl = $base . 'racunator-panel-9.9.0.zip';
        $this->http = new FakeHttpClient([ReleaseChecker::API => ['status' => 200, 'body' => json_encode([
            'tag_name' => 'v9.9.0', 'name' => 'Velika verzija', 'body' => "## Novo\n- <script>alert(1)</script> bezbedno\n- druga stavka",
            'assets' => [
                ['name' => 'racunator-panel-9.9.0.zip', 'browser_download_url' => $this->zipUrl, 'size' => 1000],
                ['name' => 'SHA256SUMS', 'browser_download_url' => $base . 'SHA256SUMS', 'size' => 100],
            ],
        ])], $this->zipUrl => ['status' => 404, 'body' => '']]);
        $updater = new TestUpdater($i['root'], $i['pdo'], $this->app->settings, $this->app->audit, $this->http, 'https://knjige.rs/panel', $i['root'] . '/storage');
        $this->c = new UpdateController($this->app, new PanelServices(), $this->http, $updater);
    }

    private function req(string $method, string $path, array $form = [], array $query = []): Request
    {
        if ($method === 'POST' && !isset($form['_token'])) {
            $form['_token'] = $this->app->guard->csrfToken();
        }
        return new Request($method, $path, $query, $form, null, [], [], '192.0.2.1', true);
    }

    private function login(): void
    {
        $this->assertNull($this->app->guard->login($this->req('POST', '/prijava'), 'admin', 'x', null));
    }

    private function downloads(): int
    {
        return count(array_filter($this->http->calls, fn (array $c): bool => $c['url'] !== ReleaseChecker::API));
    }

    public function testNeprijavljenINijeAdmin(): void
    {
        $this->assertSame(303, $this->c->index($this->req('GET', '/azuriranje'))->status);
        $this->app->users->create('radnik', password_hash('y', PASSWORD_DEFAULT), 'zaposleni', null);
        $this->assertNull($this->app->guard->login($this->req('POST', '/prijava'), 'radnik', 'y', null));
        $this->assertSame(403, $this->c->index($this->req('GET', '/azuriranje'))->status);
        $this->assertSame(403, $this->c->runUpdate($this->req('POST', '/azuriranje/pokreni', ['verzija' => '9.9.0', 'potvrda' => 'da']))->status);
        $this->assertSame(0, $this->downloads());
    }

    public function testNistaSeNePreuzimaPrePotvrde(): void
    {
        $this->login();
        $page = $this->c->index($this->req('GET', '/azuriranje'));
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Dostupna je verzija 9.9.0', $page->body);
        $this->assertStringContainsString('Ažuriraj na 9.9.0', $page->body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
        $this->assertStringNotContainsString('<script>alert(1)', $page->body);
        $this->assertStringContainsString('<br', $page->body, 'prelomi reda iz changelog-a');

        $confirm = $this->c->confirmUpdate($this->req('GET', '/azuriranje/pokreni'));
        $this->assertSame(200, $confirm->status);
        $this->assertStringContainsString('rezervna kopija', $confirm->body);
        $this->assertStringContainsString('name="potvrda"', $confirm->body);

        $noConfirm = $this->c->runUpdate($this->req('POST', '/azuriranje/pokreni', ['verzija' => '9.9.0']));
        $this->assertSame(303, $noConfirm->status);
        $badCsrf = $this->c->runUpdate($this->req('POST', '/azuriranje/pokreni', ['verzija' => '9.9.0', 'potvrda' => 'da', '_token' => 'los']));
        $this->assertSame(403, $badCsrf->status);
        $wrongVersion = $this->c->runUpdate($this->req('POST', '/azuriranje/pokreni', ['verzija' => '9.9.1', 'potvrda' => 'da']));
        $this->assertSame(303, $wrongVersion->status);
        $this->assertSame(0, $this->downloads(), 'pre potvrde nema preuzimanja');
        $this->assertSame('1.0.0', $this->app->settings->get('version'));

        // Tek posle potvrde: preuzimanje (ovde 404 → ažuriranje se prekida bez izmena).
        $run = $this->c->runUpdate($this->req('POST', '/azuriranje/pokreni', ['verzija' => '9.9.0', 'potvrda' => 'da']));
        $this->assertSame(303, $run->status);
        $this->assertSame(1, $this->downloads());
        $this->assertSame('1.0.0', $this->app->settings->get('version'));
        $after = $this->c->index($this->req('GET', '/azuriranje'));
        $this->assertStringContainsString('Nije uspelo', $after->body);
        $this->assertStringContainsString('Vrati ovu verziju (1.0.0)', $after->body, 'rezervna kopija je napravljena pre preuzimanja');
    }

    public function testPotvrdaPovratkaUpozoravaNaGubitakPodataka(): void
    {
        $this->login();
        $meta = (new \RacunatorPanel\Services\Backup(dirname($this->app->config->storagePath()), $this->app->db, $this->app->config->storagePath()))->create('1.0.0');
        $page = $this->c->confirmRevert($this->req('GET', '/azuriranje/vrati', [], ['kopija' => $meta['name']]));
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('izgubljeno', $page->body);
        $this->assertStringContainsString('CSV', $page->body);
        $this->assertSame(404, $this->c->confirmRevert($this->req('GET', '/azuriranje/vrati', [], ['kopija' => '../x']))->status);
        $dl = $this->c->download($this->req('GET', '/x'), ['name' => $meta['name'], 'file' => 'baza.sqlite']);
        $this->assertSame(200, $dl->status);
        $this->assertStringContainsString('attachment', $dl->headers['Content-Disposition']);
        $this->assertSame(404, $this->c->download($this->req('GET', '/x'), ['name' => $meta['name'], 'file' => '../../config.php'])->status);
    }
}
