<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;

/** Dnevnik radnji: poslednjih 200 zapisa (ko je šta uradio i kada). */
final class AuditController extends BaseController
{
    private const LIMIT = 200;

    public function index(Request $request, array $params = []): Response
    {
        if ($deny = $this->requireUser()) {
            return $deny;
        }
        return $this->page('dnevnik', [
            'title' => 'Dnevnik radnji',
            'nav' => 'dnevnik',
            'entries' => $this->app->audit->recent(self::LIMIT),
            'limit' => self::LIMIT,
        ]);
    }
}
