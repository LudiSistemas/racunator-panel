<?php
declare(strict_types=1);

namespace RacunatorPanel\Kernel;

/** Renderovanje PHP šablona iz src/Views. U šablonu su dostupne promenljive iz $vars i funkcija e() za escape. */
final class View
{
    public function __construct(private readonly string $dir = __DIR__ . '/../Views')
    {
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Šablon ne postoji: $template");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }
}

/** Escape za HTML (UTF-8). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
