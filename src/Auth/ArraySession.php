<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

/** Sesija u memoriji — za testove (jezgro i panel). */
final class ArraySession implements SessionStore
{
    /** @var array<string,mixed> */
    public array $data = [];
    public int $regenerations = 0;
    public bool $destroyed = false;

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
    }
}
