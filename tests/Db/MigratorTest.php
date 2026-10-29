<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Db;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Db\Connection;
use RacunatorPanel\Db\Migrator;

final class MigratorTest extends TestCase
{
    private function pdo(): \PDO
    {
        return Connection::open(['driver' => 'sqlite', 'dsn' => 'sqlite::memory:']);
    }

    public function testPrimenaIIdempotentnost(): void
    {
        $pdo = $this->pdo();
        $m = new Migrator($pdo);
        self::assertSame(0, $m->currentVersion());
        self::assertSame(['0001_init', '0002_users_session_version', '0003_login_limits_api_daily'], $m->migrate());
        self::assertSame(3, $m->currentVersion());
        self::assertSame([], $m->migrate());
        self::assertSame([], (new Migrator($pdo))->migrate());
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn());

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['users', 'clients', 'receipts', 'settings', 'audit_log', 'login_attempts', 'api_rate', 'api_daily', 'schema_version'] as $t) {
            self::assertContains($t, $tables);
        }
        $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'dflt_value', 'name');
        self::assertArrayHasKey('session_version', $columns);
        self::assertSame('1', (string) $columns['session_version']);
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('login_attempts_user_at_idx', $indexes);
    }

    public function testNadogradnjaPostojeceBazeSaNalozima(): void
    {
        $dir = sys_get_temp_dir() . '/rp-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy(dirname(__DIR__, 2) . '/src/Db/migrations/0001_init.php', $dir . '/0001_init.php');
        $pdo = $this->pdo();
        (new Migrator($pdo, $dir))->migrate();
        $pdo->exec("INSERT INTO users (username, password_hash, role, created_at) VALUES ('ana', 'h', 'admin', '2026-01-01 00:00:00')");
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);

        self::assertSame(['0002_users_session_version', '0003_login_limits_api_daily'], (new Migrator($pdo))->migrate());
        self::assertSame(1, (int) $pdo->query("SELECT session_version FROM users WHERE username = 'ana'")->fetchColumn());
    }

    public function testJedinstvenostRacunaPoKorisniku(): void
    {
        $pdo = $this->pdo();
        (new Migrator($pdo))->migrate();
        $pdo->exec("INSERT INTO clients (name, slug, api_key_prefix, api_key_hash, created_at) VALUES ('A', 'a', 'p', 'h', '2026-01-01 00:00:00')");
        $insert = "INSERT INTO receipts (client_id, invoice_number, verification_url, sdc_datetime, total_amount_minor, received_at)"
            . " VALUES (1, 'X', 'u', '2026-10-01T00:00:00', 1, '2026-10-01 00:00:00')";
        $pdo->exec($insert);
        try {
            $pdo->exec($insert);
            self::fail('Očekivano kršenje jedinstvenosti');
        } catch (\PDOException $e) {
            self::assertTrue(Connection::isUniqueViolation($e));
        }
    }

    public function testStraniKljuceviUkljuceni(): void
    {
        $pdo = $this->pdo();
        (new Migrator($pdo))->migrate();
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO receipts (client_id, invoice_number, verification_url, sdc_datetime, total_amount_minor, received_at)"
            . " VALUES (99, 'X', 'u', '2026-10-01T00:00:00', 1, '2026-10-01 00:00:00')");
    }

    public function testNovaMigracijaIzFoldera(): void
    {
        $dir = sys_get_temp_dir() . '/rp-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy(dirname(__DIR__, 2) . '/src/Db/migrations/0001_init.php', $dir . '/0001_init.php');
        file_put_contents($dir . '/0002_dodatak.php', "<?php return ['mysql' => [], 'sqlite' => ['CREATE TABLE t2 (id INTEGER)']];");
        file_put_contents($dir . '/0003_pukne.php', "<?php return ['mysql' => [], 'sqlite' => ['CREATE TABLE t3 (id INTEGER)', 'NIJE SQL']];");
        $pdo = $this->pdo();
        $m = new Migrator($pdo, $dir);
        try {
            $m->migrate();
            self::fail('Očekivana greška u 0003');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0003_pukne', $e->getMessage());
        }
        // 0001 i 0002 su primenjene, 0003 je vraćena u celosti (transakcija).
        self::assertSame(2, $m->currentVersion());
        self::assertSame(2, $m->latestVersion() - 1);
        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name = 't3'")->fetchAll());
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testNepodrzanDrajver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Connection::open(['driver' => 'pgsql', 'dsn' => 'pgsql:host=x']);
    }

    public function testDriverIPutanja(): void
    {
        $pdo = Connection::open(['driver' => 'sqlite', 'path' => ':memory:']);
        self::assertSame('sqlite', Connection::driver($pdo));
        self::assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }
}
