<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Models\Client;

/**
 * Korisnici (firme i preduzetnici): lista, dodavanje sa QR-om, detalji, izmena, nov ključ, (de)aktivacija.
 * API ključ se nigde ne čuva u čitljivom obliku — prikazuje se samo u odgovoru na kreiranje ili „Nov ključ".
 */
final class ClientsController extends BaseController
{
    private const NAME_MAX = 150;
    private const NOTE_MAX = 1000;

    public function index(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        $month = (new \DateTimeImmutable('now', new \DateTimeZone(Format::DISPLAY_TZ)))->format('Y-m');
        $clients = $this->app->clients->all();
        usort($clients, static fn (Client $a, Client $b): int => ($b->active <=> $a->active) ?: strcasecmp($a->name, $b->name));
        return $this->page('korisnici/lista', [
            'title' => 'Korisnici',
            'nav' => 'korisnici',
            'clients' => $clients,
            'counts' => $this->app->receipts->countsForMonth($month),
            'month' => $month,
        ]);
    }

    public function createForm(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        return $this->form('', '', []);
    }

    public function create(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request)) {
            return $deny;
        }
        $name = $this->field($request, 'ime');
        $note = $this->field($request, 'napomena');
        $errors = $this->validate($name, $note);
        if ($errors !== []) {
            return $this->form($name, $note, $errors, 422);
        }
        if (!$this->panelSession()->consumeNonce('client-create', $this->field($request, '_nonce'))) {
            // Forma je već poslata (npr. osvežavanje stranice sa QR-om) — ne pravimo drugog korisnika.
            $this->flash()->error('Ova forma je već poslata. Proverite listu korisnika pre nego što pokušate ponovo.');
            return $this->redirect('/korisnici');
        }

        $key = $this->services->apiKey();
        $client = $this->app->clients->create(
            $name,
            $this->services->slug(),
            $this->services->keyPrefix($key),
            $this->services->keyHash($key),
            $note === '' ? null : $note,
        );
        $this->audit($request, 'client.create', 'client', $client->id, $client->name);
        return $this->qrPage($client, $key, 'novi');
    }

    public function show(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        $client = $this->findClient($params);
        if ($client === null) {
            return $this->notFound('Korisnik ne postoji.');
        }
        return $this->details($client, [], null, null);
    }

    public function update(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request)) {
            return $deny;
        }
        $client = $this->findClient($params);
        if ($client === null) {
            return $this->notFound('Korisnik ne postoji.');
        }
        $name = $this->field($request, 'ime');
        $note = $this->field($request, 'napomena');
        $errors = $this->validate($name, $note);
        if ($errors !== []) {
            return $this->details($client, $errors, $name, $note, 422);
        }
        $this->app->clients->update($client->id, $name, $note === '' ? null : $note);
        $this->audit($request, 'client.update', 'client', $client->id, $name);
        $this->flash()->success('Podaci korisnika su sačuvani.');
        return $this->redirect('/korisnici/' . $client->id);
    }

    public function rotateKey(Request $request, array $params = []): Response
    {
        if ($deny = $this->guardPost($request)) {
            return $deny;
        }
        $client = $this->findClient($params);
        if ($client === null) {
            return $this->notFound('Korisnik ne postoji.');
        }
        if (!$this->panelSession()->consumeNonce('client-rotate-' . $client->id, $this->field($request, '_nonce'))) {
            $this->flash()->error('Zahtev za nov ključ je već obrađen. Ako vam treba nov QR kod, kliknite ponovo „Nov ključ".');
            return $this->redirect('/korisnici/' . $client->id);
        }
        $key = $this->services->apiKey();
        $this->app->clients->rotateKey($client->id, $this->services->keyPrefix($key), $this->services->keyHash($key));
        $this->audit($request, 'client.rotate_key', 'client', $client->id, $client->name);
        $fresh = $this->app->clients->findById($client->id) ?? $client;
        return $this->qrPage($fresh, $key, 'novi-kljuc');
    }

    public function deactivate(Request $request, array $params = []): Response
    {
        return $this->setActive($request, $params, false);
    }

    public function activate(Request $request, array $params = []): Response
    {
        return $this->setActive($request, $params, true);
    }

    private function setActive(Request $request, array $params, bool $active): Response
    {
        if ($deny = $this->guardPost($request)) {
            return $deny;
        }
        $client = $this->findClient($params);
        if ($client === null) {
            return $this->notFound('Korisnik ne postoji.');
        }
        if ($client->active !== $active) {
            $this->app->clients->setActive($client->id, $active);
            $this->audit($request, $active ? 'client.activate' : 'client.deactivate', 'client', $client->id, $client->name);
        }
        $this->flash()->success($active
            ? 'Korisnik je aktiviran. Aplikacija ponovo može da šalje račune.'
            : 'Korisnik je deaktiviran. Aplikacija više ne može da šalje račune; primljeni računi ostaju sačuvani.');
        return $this->redirect('/korisnici/' . $client->id);
    }

    private function findClient(array $params): ?Client
    {
        $id = $this->idParam($params);
        return $id === null ? null : $this->app->clients->findById($id);
    }

    /** @return array<string,string> polje => poruka */
    private function validate(string $name, string $note): array
    {
        $errors = [];
        if ($name === '') {
            $errors['ime'] = 'Unesite ime korisnika (naziv firme ili preduzetnika).';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['ime'] = 'Ime može imati najviše ' . self::NAME_MAX . ' znakova.';
        } elseif ($this->hasControlChars($name)) {
            $errors['ime'] = 'Ime sadrži nedozvoljene znakove.';
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            $errors['napomena'] = 'Napomena može imati najviše ' . self::NOTE_MAX . ' znakova.';
        } elseif ($this->hasControlChars($note, true)) {
            $errors['napomena'] = 'Napomena sadrži nedozvoljene znakove.';
        }
        return $errors;
    }

    /** @param array<string,string> $errors */
    private function form(string $name, string $note, array $errors, int $status = 200): Response
    {
        return $this->page('korisnici/novi', [
            'title' => 'Dodaj korisnika',
            'nav' => 'korisnici',
            'name' => $name,
            'note' => $note,
            'errors' => $errors,
            'nonce' => $this->panelSession()->issueNonce('client-create'),
        ], $status);
    }

    /** @param array<string,string> $errors */
    private function details(Client $client, array $errors, ?string $name, ?string $note, int $status = 200): Response
    {
        $summary = $this->app->receipts->monthlySummary($client->id);
        krsort($summary);
        return $this->page('korisnici/detalji', [
            'title' => $client->name,
            'nav' => 'korisnici',
            'client' => $client,
            'endpoint' => $this->app->config->publicUrl() . '/u/' . $client->slug,
            'summary' => $summary,
            'errors' => $errors,
            'name' => $name ?? $client->name,
            'note' => $note ?? (string) $client->note,
            'rotateNonce' => $this->panelSession()->issueNonce('client-rotate-' . $client->id),
        ], $status);
    }

    private function qrPage(Client $client, string $key, string $reason): Response
    {
        $accountant = $this->accountantName();
        $payload = $this->services->qrPayload($client, $key, $this->app->config->publicUrl(), $accountant);
        return $this->page('korisnici/qr', [
            'title' => 'QR kod — ' . $client->name,
            'nav' => 'korisnici',
            'withQr' => true,
            'client' => $client,
            'key' => $key,
            'payload' => $payload,
            'reason' => $reason,
            'endpoint' => $this->app->config->publicUrl() . '/u/' . $client->slug,
        ], 200, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
