<?php
declare(strict_types=1);

namespace RacunatorPanel\Controllers\Panel;

use RacunatorPanel\Auth\SessionStore;

/**
 * Kratke poruke (uspeh/greška) koje preživljavaju jedno preusmerenje. Čuvaju se u sesiji pod ključem `_flash`
 * (kroz SessionStore jezgra — u radu je to `$_SESSION['_flash']`); prikazuje ih `layout.php` i brišu se pri čitanju.
 */
final class Flash
{
    public const KEY = '_flash';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function success(string $message): void
    {
        $this->add('uspeh', $message);
    }

    public function error(string $message): void
    {
        $this->add('greska', $message);
    }

    public function add(string $type, string $message): void
    {
        $messages = $this->session->get(self::KEY, []);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['type' => $type, 'message' => $message];
        $this->session->set(self::KEY, $messages);
    }

    /** Vraća i briše sve poruke. @return list<array{type:string,message:string}> */
    public function take(): array
    {
        $messages = $this->session->get(self::KEY);
        if (!is_array($messages)) {
            return [];
        }
        $this->session->remove(self::KEY);
        $out = [];
        foreach ($messages as $m) {
            if (is_array($m) && is_string($m['type'] ?? null) && is_string($m['message'] ?? null)) {
                $out[] = ['type' => $m['type'], 'message' => $m['message']];
            }
        }
        return $out;
    }
}
