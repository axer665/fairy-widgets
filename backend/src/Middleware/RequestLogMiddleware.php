<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;

/** Пример второго middleware на том же маршруте — логирование (заглушка). */
final class RequestLogMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        error_log(sprintf('[api] %s %s', $request->method, $request->path));
        return $next($request);
    }
}
