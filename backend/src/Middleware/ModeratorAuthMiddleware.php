<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Jwt;

final class ModeratorAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $jwtSecret,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $payload = Jwt::decode($token, $this->jwtSecret);
        if ($payload === null || ($payload['role'] ?? '') !== 'moderator') {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $request->attributes['user_id'] = (int) $payload['sub'];
        return $next($request);
    }
}
