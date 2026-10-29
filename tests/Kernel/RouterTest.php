<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Kernel\Router;

final class RouterTest extends TestCase
{
    public function testParametriIzPutanje(): void
    {
        $router = new Router();
        $router->get('/u/{slug}/ping', static fn (Request $r, array $p): Response => Response::text('slug=' . $p['slug']));
        $res = $router->dispatch(new Request('GET', '/u/abc123/ping'));
        self::assertSame(200, $res->status);
        self::assertSame('slug=abc123', $res->body);
    }

    public function testZavrsnaKosaCrtaIDekodiranje(): void
    {
        $router = new Router();
        $router->get('/korisnici/{id}', static fn (Request $r, array $p): Response => Response::text($p['id']));
        self::assertSame('a b', $router->dispatch(new Request('GET', '/korisnici/a%20b/'))->body);
    }

    public function test404i405(): void
    {
        $router = new Router();
        $router->post('/u/{slug}/receipts', static fn (): Response => Response::text('ok'));
        self::assertSame(404, $router->dispatch(new Request('GET', '/nema'))->status);
        self::assertSame(405, $router->dispatch(new Request('GET', '/u/x/receipts'))->status);
    }

    public function testMiddlewareRedosled(): void
    {
        $router = new Router();
        $router->use(static fn (Request $r, callable $next): Response => $next($r)->withHeader('X-A', '1'));
        $router->use(static fn (Request $r, callable $next): Response => $r->path === '/stop' ? Response::text('stop', 403) : $next($r));
        $router->get('/ok', static fn (): Response => Response::text('ok'));
        $ok = $router->dispatch(new Request('GET', '/ok'));
        self::assertSame('1', $ok->headers['X-A']);
        $stop = $router->dispatch(new Request('GET', '/stop'));
        self::assertSame(403, $stop->status);
        self::assertSame('1', $stop->headers['X-A']);
    }
}
