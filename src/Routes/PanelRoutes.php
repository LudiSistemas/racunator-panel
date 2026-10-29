<?php
declare(strict_types=1);

namespace RacunatorPanel\Routes;

use RacunatorPanel\App;
use RacunatorPanel\Controllers\Panel\AuditController;
use RacunatorPanel\Controllers\Panel\AuthController;
use RacunatorPanel\Controllers\Panel\ClientsController;
use RacunatorPanel\Controllers\Panel\DashboardController;
use RacunatorPanel\Controllers\Panel\PanelServices;
use RacunatorPanel\Controllers\Panel\ReceiptsController;
use RacunatorPanel\Controllers\Panel\SettingsController;
use RacunatorPanel\Controllers\Panel\TermsController;
use RacunatorPanel\Controllers\Panel\UsersController;

/** Rute ekrana knjigovođe. Redosled je bitan: `/korisnici/novi` mora biti pre `/korisnici/{id}`. */
final class PanelRoutes
{
    /** @param PanelServices|null $services zamena servisa jezgra (testovi); podrazumevano prave klase jezgra */
    public static function register(App $app, ?PanelServices $services = null): void
    {
        $services ??= new PanelServices();
        $r = $app->router;

        $auth = new AuthController($app, $services);
        $r->get('/prijava', $auth->showLogin(...));
        $r->post('/prijava', $auth->login(...));
        $r->post('/odjava', $auth->logout(...));

        $terms = new TermsController($app, $services);
        $r->get('/uslovi', $terms->show(...));
        $r->post('/uslovi', $terms->accept(...));

        $dashboard = new DashboardController($app, $services);
        $r->get('/', $dashboard->index(...));

        $clients = new ClientsController($app, $services);
        $receipts = new ReceiptsController($app, $services);
        $r->get('/korisnici', $clients->index(...));
        $r->get('/korisnici/novi', $clients->createForm(...));
        $r->post('/korisnici/novi', $clients->create(...));
        $r->get('/korisnici/{id}/racuni', $receipts->index(...));
        $r->get('/korisnici/{id}/racuni.csv', $receipts->csv(...));
        $r->post('/korisnici/{id}/novi-kljuc', $clients->rotateKey(...));
        $r->post('/korisnici/{id}/deaktiviraj', $clients->deactivate(...));
        $r->post('/korisnici/{id}/aktiviraj', $clients->activate(...));
        $r->get('/korisnici/{id}', $clients->show(...));
        $r->post('/korisnici/{id}', $clients->update(...));

        $users = new UsersController($app, $services);
        $r->get('/nalozi', $users->index(...));
        $r->get('/nalozi/novi', $users->createForm(...));
        $r->post('/nalozi/novi', $users->create(...));
        $r->post('/nalozi/{id}/deaktiviraj', $users->deactivate(...));
        $r->post('/nalozi/{id}/aktiviraj', $users->activate(...));
        $r->post('/nalozi/{id}/lozinka', $users->setPassword(...));
        $r->post('/nalozi/{id}/resetuj-2fa', $users->resetTwoFactor(...));

        $settings = new SettingsController($app, $services);
        $r->get('/podesavanja', $settings->index(...));
        $r->post('/podesavanja', $settings->save(...));
        $r->get('/podesavanja/lozinka', $settings->passwordForm(...));
        $r->post('/podesavanja/lozinka', $settings->changePassword(...));
        $r->get('/podesavanja/2fa', $settings->twoFactor(...));
        $r->post('/podesavanja/2fa', $settings->twoFactorSave(...));
        $r->post('/podesavanja/odjava-svuda', $settings->logoutEverywhere(...));
        $r->get('/podesavanja/api', $settings->api(...));
        $r->post('/podesavanja/api', $settings->apiSave(...));
        $r->get('/podesavanja/api/openapi.yaml', $settings->openapi(...));

        $audit = new AuditController($app, $services);
        $r->get('/dnevnik', $audit->index(...));
    }
}
