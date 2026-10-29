<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Auth\SessionGuard;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;

/**
 * Prijava u dva koraka i odjava.
 *  1. korak: korisničko ime i lozinka. Nalog bez 2FA se odmah prijavljuje; nalog sa 2FA prelazi na 2. korak (i kad je
 *     lozinka pogrešna — ishod se saopštava tek posle koda, da prvi korak ne otkriva da li je lozinka tačna).
 *  2. korak (`korak=kod`): samo šestocifreni kod; rok 5 minuta. Greška vraća na 1. korak.
 * Ograničenje broja pokušaja, provera lozinke i TOTP-a su u jezgru (Guard / AccountGuard).
 */
final class AuthController extends BaseController
{
    public function showLogin(Request $request, array $params = []): Response
    {
        if ($this->currentUser() !== null) {
            return $this->redirect('/');
        }
        $pending = $this->accountGuard()?->pendingLogin();
        if ($pending !== null) {
            return $this->codePage($pending, null);
        }
        return $this->loginPage('', null);
    }

    public function login(Request $request, array $params = []): Response
    {
        if ($deny = $this->checkCsrf($request)) {
            return $deny;
        }
        $guard = $this->accountGuard();
        if ($this->field($request, 'radnja') === 'nazad') {
            $guard?->cancelPendingLogin();
            return $this->redirect('/prijava');
        }
        if ($this->field($request, 'korak') === 'kod' && $guard !== null) {
            return $this->loginCode($request);
        }

        $username = $this->field($request, 'korisnicko_ime');
        $password = $this->rawField($request, 'lozinka');
        $code = (string) preg_replace('/\D+/', '', $this->field($request, 'kod'));
        if ($username === '' || $password === '') {
            $guard?->cancelPendingLogin();
            return $this->loginPage($username, 'Unesite korisničko ime i lozinku.', 422);
        }
        if (mb_strlen($username) > 64 || strlen($password) > 1024) {
            $guard?->cancelPendingLogin();
            return $this->loginPage('', 'Pogrešno korisničko ime ili lozinka.', 422);
        }
        $error = $this->app->guard->login($request, $username, $password, $code === '' ? null : $code);
        if ($error === null) {
            // Upis u dnevnik (login) radi jezgro u Guard::login.
            return $this->redirect('/');
        }
        if ($error === SessionGuard::NEEDS_TOTP && $guard?->pendingLogin() !== null) {
            return $this->codePage((string) $guard->pendingLogin(), null);
        }
        return $this->loginPage($username, $error, 422);
    }

    public function logout(Request $request, array $params = []): Response
    {
        if ($deny = $this->checkCsrf($request)) {
            return $deny;
        }
        // Upis u dnevnik (logout) radi jezgro u Guard::logout.
        $this->app->guard->logout();
        $this->flash()->success('Odjavljeni ste.');
        return $this->redirect('/prijava');
    }

    private function loginCode(Request $request): Response
    {
        $guard = $this->accountGuard();
        $pending = $guard?->pendingLogin();
        if ($guard === null || $pending === null) {
            return $this->loginPage('', SessionGuard::PENDING_EXPIRED, 422);
        }
        $code = (string) preg_replace('/\D+/', '', $this->field($request, 'kod'));
        if (strlen($code) !== 6) {
            return $this->codePage($pending, 'Unesite šestocifreni kod iz aplikacije za autentifikaciju.', 422);
        }
        $error = $guard->completeLogin($request, $code);
        if ($error === null) {
            return $this->redirect('/');
        }
        return $this->loginPage($pending, $error, 422);
    }

    private function loginPage(string $username, ?string $error, int $status = 200): Response
    {
        return $this->page('prijava', [
            'title' => 'Prijava',
            'step' => 'lozinka',
            'username' => $username,
            'error' => $error,
        ], $status, ['Cache-Control' => 'no-store, private']);
    }

    private function codePage(string $username, ?string $error, int $status = 200): Response
    {
        return $this->page('prijava', [
            'title' => 'Prijava — kod',
            'step' => 'kod',
            'username' => $username,
            'error' => $error,
        ], $status, ['Cache-Control' => 'no-store, private']);
    }
}
