<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Install\SystemProbe;

require_once __DIR__ . '/../../install/bootstrap.php';

final class FakeProbe extends SystemProbe
{
    public string $php = '8.2.10';
    /** @var array<string,bool> */
    public array $ext = ['pdo' => true, 'pdo_mysql' => true, 'pdo_sqlite' => true, 'openssl' => true, 'mbstring' => true,
        'json' => true, 'session' => true, 'zip' => true, 'sodium' => true, 'curl' => true];
    /** @var array<string,string> */
    public array $iniValues = ['memory_limit' => '128M', 'allow_url_fopen' => '1', 'open_basedir' => ''];
    public bool $writable = true;
    public ?int $free = 1024 * 1024 * 1024;
    public ?bool $rewrite = true;
    /** @var list<string>|null rezultat webExposed (null = poziv nije moguć) */
    public ?array $exposed = [];
    /** @var list<array{dir:string,url:string,names:list<string>}> */
    public array $exposedCalls = [];

    public function phpVersion(): string { return $this->php; }
    public function extensionLoaded(string $name): bool { return $this->ext[$name] ?? false; }
    public function ini(string $name): string { return $this->iniValues[$name] ?? ''; }
    public function functionUsable(string $name): bool { return true; }
    public function isWritable(string $path): bool { return $this->writable; }
    public function canCreateFile(string $dir): bool { return $this->writable; }
    public function freeSpace(string $path): ?int { return $this->free; }
    public function modRewriteWorks(string $installDir, ?string $installUrl): ?bool { return $this->rewrite; }
    public function webExposed(string $dir, string $baseUrl, array $names): ?array
    {
        $this->exposedCalls[] = ['dir' => $dir, 'url' => $baseUrl, 'names' => $names];
        return $this->exposed;
    }
}
