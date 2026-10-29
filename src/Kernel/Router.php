<?php
declare(strict_types=1);

namespace RacunatorPanel\Kernel;

/**
 * Mali ruter. Šablon `/u/{slug}/receipts` daje parametre u `$params`; handler je callable(Request, array $params): Response.
 * Middleware su callable(Request, callable $next): Response, izvršavaju se redom pre handlera.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,names:list<string>,handler:callable}> */
    private array $routes = [];
    /** @var list<callable> */
    private array $middleware = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '/?$#',
            'names' => $names,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void { $this->add('POST', $pattern, $handler); }

    public function use(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function dispatch(Request $request): Response
    {
        $core = function (Request $req): Response {
            $methodMatched = false;
            foreach ($this->routes as $route) {
                if (!preg_match($route['regex'], $req->path, $m)) {
                    continue;
                }
                if ($route['method'] !== $req->method) {
                    $methodMatched = true;
                    continue;
                }
                array_shift($m);
                $params = array_combine($route['names'], array_map('rawurldecode', $m)) ?: [];
                return ($route['handler'])($req, $params);
            }
            return $methodMatched
                ? Response::text('Metoda nije dozvoljena', 405)
                : Response::text('Stranica nije pronađena', 404);
        };
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, callable $mw): callable => static fn (Request $r): Response => $mw($r, $next),
            $core,
        );
        return $pipeline($request);
    }
}
