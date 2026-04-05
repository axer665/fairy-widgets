<?php

declare(strict_types=1);

namespace App\Util;

final class HostNormalizer
{
    public static function fromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return self::normalizeHost($host);
    }

    public static function fromReferer(?string $referer): ?string
    {
        if ($referer === null || $referer === '') {
            return null;
        }
        $host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return self::normalizeHost($host);
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }
}
