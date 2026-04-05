<?php

declare(strict_types=1);

namespace App;

final class Jwt
{
    public static function encode(array $payload, string $secret, int $ttlSeconds = 86400): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttlSeconds;
        $h = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $p = self::b64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = self::b64url(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        return $h . '.' . $p . '.' . $sig;
    }

    /** @return array<string, mixed>|null */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $sig] = $parts;
        $check = self::b64url(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        if (!hash_equals($check, $sig)) {
            return null;
        }
        $payload = json_decode(self::b64urlDecode($p), true);
        if (!is_array($payload)) {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $b64): string
    {
        $pad = 4 - (strlen($b64) % 4);
        if ($pad < 4) {
            $b64 .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($b64, '-_', '+/'), true) ?: '';
    }
}
