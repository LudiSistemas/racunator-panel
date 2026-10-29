<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Auth\SessionGuard;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;

/** Prijava (korisničko ime, lozinka i — ako je nalog uključio 2FA — kod iz aplikacije) i odjava. */
final class AuthController extends BaseController
{
    public function showLogin(Request $request, array $params = []): Response
    {
        if ($this->currentUser() !== null) {
            return $this->redirect('/');
        }
        return $this->loginPage('', null);
    }

    public function login(Request $request, array $params = []): Response
    {
        if ($deny = $this->checkCsrf($request)) {
            return $deny;
        }
        $username = $this->field($request, 'korisnicko_ime');
        $password = $this->rawField($request, 'lozinka');
        $code = (string) preg_replace('/\D+/', '', $this->field($request, 'kod'));
        if ($username === '' || $password === '') {
            return $this->loginPage($username, 'Unesite korisničko ime i lozinku.', 422);
        }
        if (mb_strlen($username) > 64 || strlen($password) > 1024) {
            return $this->loginPage('', 'Pogrešno korisničko ime ili lozinka.', 422);
        }
        // Ograničenje broja pokušaja, provera lozinke i TOTP-a su u jezgru (Guard).
        $error = $this->app->guard->login($request, $username, $password, $code === '' ? null : $code);
        if ($error !== null) {
            // Nalog ima 2FA: prikazujemo (otvaramo) polje za kod.
            $needsCode = $code !== '' || $error === SessionGuard::NEEDS_TOTP || $error === SessionGuard::BAD_TOTP;
            return $this->loginPage($username, $error, 422, $needsCode);
        }
        // Upis u dnevnik (login) radi jezgro u Guard::login.
        return $this->redirect('/');
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

    private function loginPage(string $username, ?string $error, int $status = 200, bool $needsCode = false): Response
    {
        return $this->page('prijava', [
            'title' => 'Prijava',
            'username' => $username,
            'error' => $error,
            'needsCode' => $needsCode,
        ], $status, ['Cache-Control' => 'no-store, private']);
    }
}
