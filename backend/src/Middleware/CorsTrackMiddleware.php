<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;

/**
 * CORS для запросов виджета с чужих сайтов.
 */
final class CorsTrackMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->method === 'OPTIONS') {
            return new Response(204, '', $this->corsHeaders($request));
        }
        $response = $next($request);
        $headers = array_merge($response->headers, $this->corsHeaders($request));
        return new Response($response->status, $response->body, $headers);
    }

    /** @return array<string, string> */
    private function corsHeaders(Request $request): array
    {
        $origin = $request->header('Origin') ?? '*';
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
