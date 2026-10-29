<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel\Fake;

use RacunatorPanel\Models\Client;
use RacunatorPanel\Repositories\ClientRepository;

/** Korisnici (firme) u memoriji. */
final class InMemoryClientRepository implements ClientRepository
{
    /** @var array<int,Client> */
    public array $clients = [];
    private int $nextId = 1;

    public function findById(int $id): ?Client
    {
        return $this->clients[$id] ?? null;
    }

    public function findBySlug(string $slug): ?Client
    {
        foreach ($this->clients as $c) {
            if ($c->slug === $slug) {
                return $c;
            }
        }
        return null;
    }

    public function all(): array
    {
        return array_values($this->clients);
    }

    public function create(string $name, string $slug, string $apiKeyPrefix, string $apiKeyHash, ?string $note): Client
    {
        $id = $this->nextId++;
        return $this->clients[$id] = new Client($id, $name, $slug, $apiKeyPrefix, $apiKeyHash, true, $note, gmdate('Y-m-d H:i:s'), null);
    }

    public function rotateKey(int $id, string $apiKeyPrefix, string $apiKeyHash): void
    {
        $this->replace($id, ['apiKeyPrefix' => $apiKeyPrefix, 'apiKeyHash' => $apiKeyHash]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->replace($id, ['active' => $active]);
    }

    public function touchSeen(int $id): void
    {
        $this->replace($id, ['lastSeenAt' => gmdate('Y-m-d H:i:s')]);
    }

    public function update(int $id, string $name, ?string $note): void
    {
        $this->replace($id, ['name' => $name, 'note' => $note]);
    }

    /** @param array<string,mixed> $changes */
    private function replace(int $id, array $changes): void
    {
        $c = $this->clients[$id] ?? null;
        if ($c === null) {
            return;
        }
        $v = $changes + [
            'name' => $c->name, 'apiKeyPrefix' => $c->apiKeyPrefix, 'apiKeyHash' => $c->apiKeyHash, 'active' => $c->active,
            'note' => $c->note, 'lastSeenAt' => $c->lastSeenAt,
        ];
        $this->clients[$id] = new Client($id, $v['name'], $c->slug, $v['apiKeyPrefix'], $v['apiKeyHash'], $v['active'], $v['note'],
            $c->createdAt, $v['lastSeenAt']);
    }
}
