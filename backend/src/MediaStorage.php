<?php

declare(strict_types=1);

namespace App;

final class MediaStorage
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ];

    /** @var array<string, string> */
    private const EXT_BY_MIME = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function applicationDir(int $applicationId): string
    {
        $dir = $this->rootDir . '/' . $applicationId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create media directory');
        }

        return $dir;
    }

    public function absolutePath(int $applicationId, string $storedFilename): string
    {
        return $this->applicationDir($applicationId) . '/' . $storedFilename;
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array{stored_filename: string, mime_type: string, size_bytes: int, original_filename: string}
     */
    public function storeUpload(int $applicationId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Ошибка загрузки файла');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Размер файла: от 1 байта до 10 МБ');
        }
        $mime = $this->detectMime((string) ($file['tmp_name'] ?? ''), (string) ($file['type'] ?? ''));
        if ($mime === null) {
            throw new \InvalidArgumentException('Допустимы видео MP4, WebM или MOV');
        }
        $ext = self::EXT_BY_MIME[$mime];
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $this->absolutePath($applicationId, $stored);
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new \RuntimeException('Не удалось сохранить файл');
        }
        $original = basename((string) ($file['name'] ?? 'video.' . $ext));
        if (mb_strlen($original) > 255) {
            $original = mb_substr($original, 0, 255);
        }

        return [
            'stored_filename' => $stored,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'original_filename' => $original,
        ];
    }

    public function deleteFile(int $applicationId, string $storedFilename): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(mp4|webm|mov)$/', $storedFilename)) {
            return;
        }
        $path = $this->absolutePath($applicationId, $storedFilename);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function detectMime(string $tmpPath, string $clientMime): ?string
    {
        $mime = $clientMime;
        if (is_file($tmpPath) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }
        $mime = strtolower(explode(';', $mime)[0]);

        return in_array($mime, self::ALLOWED_MIME, true) ? $mime : null;
    }
}
