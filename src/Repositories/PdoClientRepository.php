<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Models\Client;

/** Korisnici (firme/preduzetnici) u tabeli `clients`. Ključ se nikad ne čuva u čitljivom obliku. */
final class PdoClientRepository implements ClientRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findById(int $id): ?Client
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::map($row) : null;
    }

    public function findBySlug(string $slug): ?Client
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $slug)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? self::map($row) : null;
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM clients ORDER BY name, id')->fetchAll();
        return array_map([self::class, 'map'], $rows);
    }

    public function create(string $name, string $slug, string $apiKeyPrefix, string $apiKeyHash, ?string $note): Client
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Naziv korisnika je obavezan.');
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $slug)) {
            throw new \InvalidArgumentException('Slug mora biti 32 heksadecimalna znaka.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO clients (name, slug, api_key_prefix, api_key_hash, active, note, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([$name, $slug, $apiKeyPrefix, $apiKeyHash, self::cleanNote($note), gmdate('Y-m-d H:i:s')]);
        $client = $this->findById((int) $this->pdo->lastInsertId());
        if ($client === null) {
            throw new \RuntimeException('Korisnik nije upisan.');
        }
        return $client;
    }

    public function rotateKey(int $id, string $apiKeyPrefix, string $apiKeyHash): void
    {
        $this->pdo->prepare('UPDATE clients SET api_key_prefix = ?, api_key_hash = ? WHERE id = ?')
            ->execute([$apiKeyPrefix, $apiKeyHash, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE clients SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public function touchSeen(int $id): void
    {
        $this->pdo->prepare('UPDATE clients SET last_seen_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    public function update(int $id, string $name, ?string $note): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Naziv korisnika je obavezan.');
        }
        $this->pdo->prepare('UPDATE clients SET name = ?, note = ? WHERE id = ?')->execute([$name, self::cleanNote($note), $id]);
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);
        return $note === '' ? null : $note;
    }

    /** @param array<string,mixed> $r */
    private static function map(array $r): Client
    {
        return new Client(
            (int) $r['id'],
            (string) $r['name'],
            (string) $r['slug'],
            (string) $r['api_key_prefix'],
            (string) $r['api_key_hash'],
            (bool) (int) $r['active'],
            $r['note'] !== null ? (string) $r['note'] : null,
            (string) $r['created_at'],
            $r['last_seen_at'] !== null ? (string) $r['last_seen_at'] : null,
        );
    }
}
