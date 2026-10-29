<?php
declare(strict_types=1);

// PSR-4 autoloader bez Composer-a (na hostingu nema vendor/). Namespace RacunatorPanel\ → src/.
spl_autoload_register(static function (string $class): void {
    $prefix = 'RacunatorPanel\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
