<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;

/**
 * Podešavanja: naziv knjigovodstva, javni URL i verzija (prikaz), promena lozinke, dvofaktorska prijava (TOTP)
 * i prekidač API-ja za knjigovođu (faza A: samo čuvanje podešavanja i ključa; sam API dolazi kasnije).
 *
 * Ključevi u settings: `accountant_name`, `accountant_api_enabled` ('1'|'0'), `accountant_api_slug`,
 * `accountant_api_key_prefix`, `accountant_api_key_hash` (SHA-256 hex, kao kod korisnika), `accountant_api_enabled_at` (UTC).
 */
final class SettingsController extends BaseController
{
    private const TOTP_ISSUER = 'Računator panel';
    private const NAME_MAX = 150;

    public function index(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        return $this->settingsPage($this->accountantName(), null);
    }

    public function save(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request, true)) {
            return $deny;
        }
        $name = $this->field($request, 'naziv');
        $error = null;
        if ($name === '') {
            $error = 'Unesite naziv knjigovodstva — prikazuje se korisnicima u aplikaciji i na kartici sa QR kodom.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $error = 'Naziv može imati najviše ' . self::NAME_MAX . ' znakova.';
        } elseif ($this->hasControlChars($name)) {
            $error = 'Naziv sadrži nedozvoljene znakove.';
        }
        if ($error !== null) {
            return $this->settingsPage($name, $error, 422);
        }
        $this->app->settings->set('accountant_name', $name);
        $this->audit($request, 'settings.update', 'settings', null, 'accountant_name');
        $this->flash()->success('Naziv knjigovodstva je sačuvan.');
        return $this->redirect('/podesavanja');
    }

    public function passwordForm(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        return $this->passwordPage([]);
    }

    public function changePassword(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request)) {
            return $deny;
        }
        $user = $this->user();
        $current = $this->rawField($request, 'trenutna');
        $new = $this->rawField($request, 'lozinka');
        $errors = [];
        if ($current === '' || !$this->services->verifyPassword($current, $user->passwordHash)) {
            $errors['trenutna'] = 'Trenutna lozinka nije tačna.';
        }
        $newError = UsersController::passwordError($new, $this->rawField($request, 'lozinka_ponovo'));
        if ($newError !== null) {
            $errors['lozinka'] = $newError;
        } elseif ($errors === [] && hash_equals($current, $new)) {
            $errors['lozinka'] = 'Nova lozinka mora biti drugačija od trenutne.';
        }
        if ($errors !== []) {
            return $this->passwordPage($errors, 422);
        }
        $this->app->users->updatePassword($user->id, $this->services->hashPassword($new));
        $this->audit($request, 'user.password_change', 'user', $user->id);
        $this->flash()->success('Lozinka je promenjena.');
        return $this->redirect('/podesavanja');
    }

    public function twoFactor(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        return $this->twoFactorPage(null);
    }

    public function twoFactorSave(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request)) {
            return $deny;
        }
        $user = $this->user();
        $action = $this->field($request, 'radnja');

        if ($action === 'ukljuci') {
            if ($user->totpSecret !== null) {
                return $this->redirect('/podesavanja/2fa');
            }
            $secret = $this->panelSession()->get('totp_pending');
            if (!is_string($secret) || $secret === '') {
                $this->flash()->error('Postupak je istekao. Skenirajte novi QR kod i pokušajte ponovo.');
                return $this->redirect('/podesavanja/2fa');
            }
            $code = (string) preg_replace('/\D+/', '', $this->field($request, 'kod'));
            if (strlen($code) !== 6 || !$this->services->totpVerify($secret, $code)) {
                return $this->twoFactorPage('Kod nije tačan. Proverite da je vreme na telefonu tačno i unesite novi kod.', 422);
            }
            $this->app->users->updateTotpSecret($user->id, $secret);
            $this->panelSession()->forget('totp_pending');
            $this->audit($request, 'user.2fa_enable', 'user', $user->id);
            $this->flash()->success('Dvofaktorska prijava je uključena. Pri sledećoj prijavi unesite i kod iz aplikacije.');
            return $this->redirect('/podesavanja/2fa');
        }

        if ($action === 'iskljuci') {
            if ($user->totpSecret === null) {
                return $this->redirect('/podesavanja/2fa');
            }
            $password = $this->rawField($request, 'lozinka');
            if ($password === '' || !$this->services->verifyPassword($password, $user->passwordHash)) {
                return $this->twoFactorPage('Lozinka nije tačna — dvofaktorska prijava nije isključena.', 422);
            }
            $this->app->users->updateTotpSecret($user->id, null);
            $this->audit($request, 'user.2fa_disable', 'user', $user->id);
            $this->flash()->success('Dvofaktorska prijava je isključena.');
            return $this->redirect('/podesavanja/2fa');
        }

        return $this->redirect('/podesavanja/2fa');
    }

    public function api(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireAdmin()) {
            return $deny;
        }
        return $this->apiPage(null);
    }

    public function apiSave(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request, true)) {
            return $deny;
        }
        $settings = $this->app->settings;
        $enabled = $settings->get('accountant_api_enabled') === '1';
        $action = $this->field($request, 'radnja');

        if ($action === 'ukljuci') {
            if ($enabled) {
                $this->flash()->error('API za knjigovođu je već uključen.');
                return $this->redirect('/podesavanja/api');
            }
            // Svako uključivanje daje novu nasumičnu adresu i nov ključ.
            $settings->set('accountant_api_slug', $this->services->slug());
            $key = $this->issueApiKey();
            $settings->set('accountant_api_enabled', '1');
            $settings->set('accountant_api_enabled_at', gmdate('Y-m-d H:i:s'));
            $this->audit($request, 'api.enable', 'settings', null);
            return $this->apiPage($key);
        }

        if ($action === 'novi-kljuc') {
            if (!$enabled) {
                return $this->redirect('/podesavanja/api');
            }
            if (!$this->panelSession()->consumeNonce('api-rotate', $this->field($request, '_nonce'))) {
                $this->flash()->error('Zahtev za nov ključ je već obrađen.');
                return $this->redirect('/podesavanja/api');
            }
            $key = $this->issueApiKey();
            $this->audit($request, 'api.rotate_key', 'settings', null);
            return $this->apiPage($key);
        }

        if ($action === 'iskljuci') {
            if ($enabled) {
                $settings->set('accountant_api_enabled', '0');
                $settings->delete('accountant_api_key_hash');
                $settings->delete('accountant_api_key_prefix');
                $this->audit($request, 'api.disable', 'settings', null);
            }
            $this->flash()->success('API za knjigovođu je isključen; dosadašnji ključ više ne važi.');
            return $this->redirect('/podesavanja/api');
        }

        return $this->redirect('/podesavanja/api');
    }

    private function issueApiKey(): string
    {
        $key = $this->services->apiKey();
        $this->app->settings->set('accountant_api_key_prefix', $this->services->keyPrefix($key));
        $this->app->settings->set('accountant_api_key_hash', $this->services->keyHash($key));
        return $key;
    }

    private function settingsPage(string $name, ?string $error, int $status = 200): Response
    {
        return $this->page('podesavanja/index', [
            'title' => 'Podešavanja',
            'nav' => 'podesavanja',
            'name' => $name,
            'error' => $error,
            'publicUrl' => $this->app->config->publicUrl(),
            'appVersion' => $this->app->version,
            'apiEnabled' => $this->app->settings->get('accountant_api_enabled') === '1',
            'twoFactorOn' => $this->user()->totpSecret !== null,
        ], $status);
    }

    /** @param array<string,string> $errors */
    private function passwordPage(array $errors, int $status = 200): Response
    {
        return $this->page('podesavanja/lozinka', [
            'title' => 'Promena lozinke',
            'nav' => 'podesavanja',
            'errors' => $errors,
            'passwordMin' => UsersController::PASSWORD_MIN,
        ], $status);
    }

    private function twoFactorPage(?string $error, int $status = 200): Response
    {
        $user = $this->user();
        $vars = [
            'title' => 'Dvofaktorska prijava',
            'nav' => 'podesavanja',
            'enabled' => $user->totpSecret !== null,
            'error' => $error,
            'secret' => null,
            'otpUri' => null,
            'withQr' => false,
        ];
        if ($user->totpSecret === null) {
            $secret = $this->panelSession()->get('totp_pending');
            if (!is_string($secret) || $secret === '') {
                $secret = $this->services->totpSecret();
                $this->panelSession()->set('totp_pending', $secret);
            }
            $vars['secret'] = $secret;
            $vars['otpUri'] = $this->services->totpUri($secret, $user->username, self::TOTP_ISSUER);
            $vars['withQr'] = true;
        }
        return $this->page('podesavanja/2fa', $vars, $status, ['Cache-Control' => 'no-store, private']);
    }

    private function apiPage(?string $newKey): Response
    {
        $settings = $this->app->settings;
        $enabled = $settings->get('accountant_api_enabled') === '1';
        $slug = (string) $settings->get('accountant_api_slug', '');
        return $this->page('podesavanja/api', [
            'title' => 'API za knjigovođu',
            'nav' => 'podesavanja',
            'enabled' => $enabled,
            'baseUrl' => $enabled && $slug !== '' ? $this->app->config->publicUrl() . '/api/' . $slug . '/v1' : null,
            'keyPrefix' => (string) $settings->get('accountant_api_key_prefix', ''),
            'enabledAt' => $settings->get('accountant_api_enabled_at'),
            'newKey' => $newKey,
            'rotateNonce' => $enabled ? $this->panelSession()->issueNonce('api-rotate') : '',
        ], 200, $newKey !== null ? ['Cache-Control' => 'no-store, private'] : []);
    }
}
