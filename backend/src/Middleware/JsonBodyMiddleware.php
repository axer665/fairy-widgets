<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;

final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $ct = $request->header('Content-Type') ?? '';
        if (str_contains(strtolower($ct), 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $data = json_decode($raw, true);
                $request->body = is_array($data) ? $data : null;
            } else {
                $request->body = [];
            }
        }
        return $next($request);
    }
}
