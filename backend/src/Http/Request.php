<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @param array<string, mixed> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public mixed $body = null,
        /** @var array<string, mixed> */
        public array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $headers,
            null,
        );
    }

    public function header(string $name): ?string
    {
        $key = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
        return $this->headers[$key] ?? null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization');
        if ($h === null || !str_starts_with($h, 'Bearer ')) {
            return null;
        }
        return trim(substr($h, 7));
    }
}
