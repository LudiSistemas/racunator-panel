<?php
declare(strict_types=1);

namespace RacunatorPanel\Auth;

use RacunatorPanel\Kernel\Response;

/**
 * Provere pristupa za panel kontrolere. Vraćaju Response koji treba odmah vratiti, ili null ako je pristup dozvoljen:
 *   if ($r = Authorization::requireUser($app->guard)) { return $r; }
 * Osnovnu putanju (npr. `/panel`) za preusmerenje postavlja App::boot iz `public_url`.
 */
final class Authorization
{
    private static string $basePath = '';

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /** Neprijavljen → 303 na /prijava. */
    public static function requireUser(Guard $guard): ?Response
    {
        return $guard->user() === null ? Response::redirect(self::$basePath . '/prijava') : null;
    }

    /** Neprijavljen → /prijava; prijavljen bez uloge admin → 403. */
    public static function requireAdmin(Guard $guard): ?Response
    {
        $user = $guard->user();
        if ($user === null) {
            return Response::redirect(self::$basePath . '/prijava');
        }
        if (!$user->isAdmin()) {
            return Response::html(
                '<!doctype html><html lang="sr"><meta charset="utf-8"><title>Zabranjen pristup</title>'
                . '<h1>Zabranjen pristup</h1><p>Za ovu stranicu je potrebna uloga administratora.</p></html>',
                403,
            );
        }
        return null;
    }
}
