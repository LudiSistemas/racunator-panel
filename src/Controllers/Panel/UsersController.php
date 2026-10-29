<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Models\User;

/** Nalozi knjigovodstva (samo administrator): lista, dodavanje, (de)aktivacija, postavljanje lozinke. */
final class UsersController extends BaseController
{
    public const PASSWORD_MIN = 12;
    private const PASSWORD_MAX = 1024;

    public function index(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireAdmin()) {
            return $deny;
        }
        return $this->listPage([], null);
    }

    public function createForm(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireAdmin()) {
            return $deny;
        }
        return $this->form('', User::ROLE_STAFF, []);
    }

    public function create(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request, true)) {
            return $deny;
        }
        // Jezgro čuva korisnička imena malim slovima.
        $username = strtolower($this->field($request, 'korisnicko_ime'));
        $role = $this->field($request, 'uloga');
        $password = $this->rawField($request, 'lozinka');
        $confirm = $this->rawField($request, 'lozinka_ponovo');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
            $errors['korisnicko_ime'] = 'Korisničko ime: 3 do 64 znaka — slova bez kvačica, cifre, tačka, crtica, donja crta ili @.';
        } elseif ($this->app->users->findByUsername($username) !== null) {
            $errors['korisnicko_ime'] = 'Nalog sa ovim korisničkim imenom već postoji.';
        }
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_STAFF], true)) {
            $errors['uloga'] = 'Izaberite ulogu.';
            $role = User::ROLE_STAFF;
        }
        $passwordError = self::passwordError($password, $confirm);
        if ($passwordError !== null) {
            $errors['lozinka'] = $passwordError;
        }
        if ($errors !== []) {
            return $this->form($username, $role, $errors, 422);
        }

        $user = $this->app->users->create($username, $this->services->hashPassword($password), $role, $this->user()->id);
        $this->audit($request, 'user.create', 'user', $user->id, $user->username . ' (' . $user->role . ')');
        $this->flash()->success('Nalog „' . $user->username . '" je napravljen. Saopštite lozinku zaposlenom lično i preporučite mu da uključi dvofaktorsku prijavu.');
        return $this->redirect('/nalozi');
    }

    public function deactivate(Request $request, array $params = []): Response
    {
        return $this->setActive($request, $params, false);
    }

    public function activate(Request $request, array $params = []): Response
    {
        return $this->setActive($request, $params, true);
    }

    public function setPassword(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request, true)) {
            return $deny;
        }
        $target = $this->findUser($params);
        if ($target === null) {
            return $this->notFound('Nalog ne postoji.');
        }
        $error = self::passwordError($this->rawField($request, 'lozinka'), $this->rawField($request, 'lozinka_ponovo'));
        if ($error !== null) {
            return $this->listPage([$target->id => $error], $target->id, 422);
        }
        $this->app->users->updatePassword($target->id, $this->services->hashPassword($this->rawField($request, 'lozinka')));
        $this->audit($request, 'user.password_reset', 'user', $target->id, $target->username);
        $this->flash()->success('Nova lozinka za nalog „' . $target->username . '" je postavljena.');
        return $this->redirect('/nalozi');
    }

    /** Pravila za lozinku; null = ispravna. */
    public static function passwordError(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            return 'Lozinka mora imati najmanje ' . self::PASSWORD_MIN . ' znakova.';
        }
        if (strlen($password) > self::PASSWORD_MAX) {
            return 'Lozinka je predugačka.';
        }
        if (!hash_equals($password, $confirm)) {
            return 'Lozinke se ne poklapaju.';
        }
        return null;
    }

    private function setActive(Request $request, array $params, bool $active): Response
    {
        if ($deny = $this->guardPost($request, true)) {
            return $deny;
        }
        $target = $this->findUser($params);
        if ($target === null) {
            return $this->notFound('Nalog ne postoji.');
        }
        if (!$active) {
            if ($target->id === $this->user()->id) {
                $this->flash()->error('Ne možete deaktivirati sopstveni nalog.');
                return $this->redirect('/nalozi');
            }
            if ($target->isAdmin() && $target->active && $this->activeAdmins() <= 1) {
                $this->flash()->error('Ovo je jedini aktivan administrator — prvo dodajte ili aktivirajte drugog administratora.');
                return $this->redirect('/nalozi');
            }
        }
        if ($target->active !== $active) {
            $this->app->users->setActive($target->id, $active);
            $this->audit($request, $active ? 'user.activate' : 'user.deactivate', 'user', $target->id, $target->username);
        }
        $this->flash()->success('Nalog „' . $target->username . '" je ' . ($active ? 'aktiviran.' : 'deaktiviran.'));
        return $this->redirect('/nalozi');
    }

    private function activeAdmins(): int
    {
        $count = 0;
        foreach ($this->app->users->all() as $u) {
            if ($u->isAdmin() && $u->active) {
                $count++;
            }
        }
        return $count;
    }

    private function findUser(array $params): ?User
    {
        $id = $this->idParam($params);
        return $id === null ? null : $this->app->users->findById($id);
    }

    /** @param array<int,string> $passwordErrors ID naloga => poruka */
    private function listPage(array $passwordErrors, ?int $openId, int $status = 200): Response
    {
        $users = $this->app->users->all();
        usort($users, static fn (User $a, User $b): int => ($b->active <=> $a->active) ?: strcasecmp($a->username, $b->username));
        return $this->page('nalozi/lista', [
            'title' => 'Nalozi',
            'nav' => 'nalozi',
            'users' => $users,
            'me' => $this->user(),
            'passwordErrors' => $passwordErrors,
            'openId' => $openId,
            'passwordMin' => self::PASSWORD_MIN,
        ], $status);
    }

    /** @param array<string,string> $errors */
    private function form(string $username, string $role, array $errors, int $status = 200): Response
    {
        return $this->page('nalozi/novi', [
            'title' => 'Novi nalog',
            'nav' => 'nalozi',
            'username' => $username,
            'role' => $role,
            'errors' => $errors,
            'passwordMin' => self::PASSWORD_MIN,
        ], $status);
    }
}
