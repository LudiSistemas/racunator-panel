<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Models\Client;

interface ClientRepository
{
    public function findById(int $id): ?Client;
    public function findBySlug(string $slug): ?Client;
    /** @return list<Client> */
    public function all(): array;
    public function create(string $name, string $slug, string $apiKeyPrefix, string $apiKeyHash, ?string $note): Client;
    public function rotateKey(int $id, string $apiKeyPrefix, string $apiKeyHash): void;
    public function setActive(int $id, bool $active): void;
    public function touchSeen(int $id): void;
    public function update(int $id, string $name, ?string $note): void;
}
