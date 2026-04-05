<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Middleware\MiddlewareInterface;

final class Router
{
    /** @var list<array{methods:list<string>,pattern:string,regex:string,paramNames:list<string>,handler:callable,middleware:list<MiddlewareInterface>}> */
    private array $routes = [];

    /**
     * @param list<string>|string $methods
     * @param list<MiddlewareInterface> $middleware
     */
    public function add(array|string $methods, string $pattern, callable $handler, array $middleware = []): void
    {
        $methods = is_array($methods) ? $methods : [$methods];
        [$regex, $paramNames] = self::compilePattern($pattern);
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'pattern' => $pattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if (!in_array($request->method, $route['methods'], true)) {
                continue;
            }
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $params = [];
            foreach ($route['paramNames'] as $i => $name) {
                $params[$name] = $m[$i + 1] ?? null;
            }
            $request->attributes['route_params'] = $params;

            $handler = $route['handler'];
            $stack = $route['middleware'];

            $next = static function (Request $req) use ($handler): Response {
                return $handler($req);
            };
            foreach (array_reverse($stack) as $mw) {
                $inner = $next;
                $next = static function (Request $req) use ($mw, $inner): Response {
                    return $mw->handle($req, $inner);
                };
            }
            return $next($request);
        }

        return Response::json(['error' => 'not_found'], 404);
    }

    /** @return array{0:string,1:list<string>} */
    private static function compilePattern(string $pattern): array
    {
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m) use (&$paramNames): string {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';
        return [$regex, $paramNames];
    }
}
