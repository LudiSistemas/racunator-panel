<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\App;
use RacunatorPanel\Auth\AccountGuard;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Models\User;

/**
 * Zajedničke usluge kontrolera panela: provera prijave i uloge, CSRF, adrese sa osnovnom putanjom instalacije,
 * renderovanje stranice u `layout`, dnevnik radnji.
 */
abstract class BaseController
{
    public function __construct(protected readonly App $app, protected readonly PanelServices $services)
    {
    }

    /** Adresa unutar panela sa osnovnom putanjom instalacije (npr. `/panel/korisnici`), preko `App::url`. */
    protected function url(string $path): string
    {
        return $this->app->url($path);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($this->url($path));
    }

    /** Prijavljen i aktivan nalog; deaktiviran nalog se odmah odjavljuje. */
    protected function currentUser(): ?User
    {
        $user = $this->app->guard->user();
        if ($user !== null && !$user->active) {
            $this->app->guard->logout();
            return null;
        }
        return $user;
    }

    /** Guard sa prijavom u dva koraka i osvežavanjem sesije (u radu uvek SessionGuard), ili null. */
    protected function accountGuard(): ?AccountGuard
    {
        return $this->app->guard instanceof AccountGuard ? $this->app->guard : null;
    }

    /**
     * Poništava sve sesije naloga (povećava `session_version`). Ako je to tekući nalog, tekuća sesija ostaje važeća:
     * preuzima novu verziju i dobija nov ID.
     */
    protected function revokeSessions(User $target): void
    {
        $this->app->users->bumpSessionVersion($target->id);
        if ($this->app->guard->user()?->id === $target->id) {
            $this->accountGuard()?->refreshSession();
        }
    }

    /** Prijavljen nalog za ekrane koji su već prošli `requireUser()`. */
    protected function user(): User
    {
        $user = $this->currentUser();
        if ($user === null) {
            throw new \LogicException('Ekran zahteva prijavu.');
        }
        return $user;
    }

    /** null = u redu; inače preusmerenje na prijavu. */
    protected function requireUser(): ?Response
    {
        return $this->currentUser() === null ? $this->redirect('/prijava') : null;
    }

    /** null = u redu; neprijavljen → prijava, zaposleni → 403. */
    protected function requireAdmin(): ?Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirect('/prijava');
        }
        if (!$user->isAdmin()) {
            return $this->error(403, 'Nemate pravo pristupa', 'Ovaj deo panela je dostupan samo administratoru.');
        }
        return null;
    }

    /** null = token ispravan; inače 403. */
    protected function checkCsrf(Request $request): ?Response
    {
        if ($this->app->guard->checkCsrf($request)) {
            return null;
        }
        return $this->error(
            403,
            'Zahtev nije prihvaćen',
            'Sigurnosni token forme nije ispravan ili je sesija istekla. Vratite se, osvežite stranicu i pokušajte ponovo.',
        );
    }

    /** Provera za POST: prijava (ili admin), zatim CSRF. */
    protected function guardPost(Request $request, bool $admin = false): ?Response
    {
        $deny = $admin ? $this->requireAdmin() : $this->requireUser();
        return $deny ?? $this->checkCsrf($request);
    }

    protected function audit(Request $request, string $action, ?string $subjectType = null, ?int $subjectId = null, ?string $details = null): void
    {
        $this->app->audit->log($this->app->guard->user()?->id, $action, $subjectType, $subjectId, $request->ip, $details);
    }

    /** Tekstualno polje forme (bez razmaka na krajevima); niz ili nepostojeće polje daje prazan string. */
    protected function field(Request $request, string $name): string
    {
        $value = $request->form[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** Polje forme bez skraćivanja razmaka (lozinke). */
    protected function rawField(Request $request, string $name): string
    {
        $value = $request->form[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    protected function queryParam(Request $request, string $name): string
    {
        $value = $request->query[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** Numerički ID iz putanje ili null. */
    protected function idParam(array $params, string $name = 'id'): ?int
    {
        $value = $params[$name] ?? '';
        if (!is_string($value) || !preg_match('/^[1-9]\d{0,17}$/', $value)) {
            return null;
        }
        return (int) $value;
    }

    /** Tekst bez kontrolnih znakova (dozvoljeni su prelazi reda samo ako je $multiline). */
    protected function hasControlChars(string $value, bool $multiline = false): bool
    {
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        return preg_match($pattern, $value) !== 0;
    }

    protected function flash(): Flash
    {
        return new Flash($this->app->session);
    }

    protected function panelSession(): PanelSession
    {
        return new PanelSession($this->app->session);
    }

    protected function accountantName(): string
    {
        return (string) $this->app->settings->get('accountant_name', '');
    }

    /**
     * Renderuje šablon u zajednički raspored. Svaki šablon dobija i: $u (putanja → URL), $csrf, $currentUser, $accountantName.
     * @param array<string,mixed> $vars
     * @param array<string,string> $headers
     */
    protected function page(string $template, array $vars = [], int $status = 200, array $headers = []): Response
    {
        $common = [
            'u' => fn (string $path): string => $this->url($path),
            'csrf' => $this->app->guard->csrfToken(),
            'currentUser' => $this->currentUser(),
            'accountantName' => $this->accountantName(),
        ];
        $content = $this->app->view->render($template, $vars + $common);
        $layout = $common + [
            'content' => $content,
            'title' => (string) ($vars['title'] ?? 'Računator panel'),
            'nav' => (string) ($vars['nav'] ?? ''),
            'withQr' => (bool) ($vars['withQr'] ?? false),
            'flash' => $this->flash()->take(),
            'version' => $this->app->version,
        ];
        return Response::html($this->app->view->render('layout', $layout), $status, $headers);
    }

    protected function error(int $status, string $title, string $message): Response
    {
        return $this->page('greska', ['title' => $title, 'message' => $message, 'status' => $status], $status);
    }

    protected function notFound(string $message = 'Tražena stranica ne postoji.'): Response
    {
        return $this->error(404, 'Nije pronađeno', $message);
    }
}
