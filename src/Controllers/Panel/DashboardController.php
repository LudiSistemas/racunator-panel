<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;

/** Kontrolna tabla: korisnici sa brojem računa u tekućem mesecu i vremenom poslednjeg slanja. */
final class DashboardController extends BaseController
{
    public function index(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        $month = (new \DateTimeImmutable('now', new \DateTimeZone(Format::DISPLAY_TZ)))->format('Y-m');
        $counts = $this->app->receipts->countsForMonth($month);
        $clients = $this->app->clients->all();
        usort($clients, static function ($a, $b): int {
            // Aktivni prvi, zatim po poslednjem slanju (najnovije gore), pa po imenu.
            return [$b->active, (string) $b->lastSeenAt] <=> [$a->active, (string) $a->lastSeenAt]
                ?: strcasecmp($a->name, $b->name);
        });
        $total = 0;
        foreach ($clients as $c) {
            $total += $counts[$c->id] ?? 0;
        }
        return $this->page('kontrolna-tabla', [
            'title' => 'Kontrolna tabla',
            'nav' => 'tabla',
            'month' => $month,
            'clients' => $clients,
            'counts' => $counts,
            'totalCount' => $total,
        ]);
    }
}
