<?php
declare(strict_types=1);

namespace RacunatorPanel\Routes;

use RacunatorPanel\App;
use RacunatorPanel\Controllers\Panel\PanelServices;
use RacunatorPanel\Controllers\UpdateController;

/** Rute stranice Ažuriranje (samo administrator). Ništa se ne preuzima ni ne menja bez POST-a sa potvrdom. */
final class UpdateRoutes
{
    public static function register(App $app, ?UpdateController $controller = null): void
    {
        $c = $controller ?? new UpdateController($app, new PanelServices());
        $r = $app->router;
        $r->get('/azuriranje', $c->index(...));
        $r->post('/azuriranje', $c->checkNow(...));
        $r->get('/azuriranje/pokreni', $c->confirmUpdate(...));
        $r->post('/azuriranje/pokreni', $c->runUpdate(...));
        $r->get('/azuriranje/vrati', $c->confirmRevert(...));
        $r->post('/azuriranje/vrati', $c->runRevert(...));
        $r->post('/azuriranje/odrzavanje', $c->clearMaintenance(...));
        $r->get('/azuriranje/kopije/{name}/{file}', $c->download(...));
    }
}
