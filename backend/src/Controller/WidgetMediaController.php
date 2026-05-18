<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\MediaStorage;
use App\Util\HostNormalizer;
use PDO;

final class WidgetMediaController
{
    public function __construct(
        private readonly Database $db,
        private readonly MediaStorage $mediaStorage,
        private readonly string $appUrl,
    ) {
    }

    public function list(Request $request): Response
    {
        $appId = $this->appId($request);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!$this->ownsApplication($appId, (int) ($request->attributes['user_id'] ?? 0))) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT id, original_filename, mime_type, size_bytes, created_at
             FROM widget_media_assets WHERE application_id = ? ORDER BY id DESC',
        );
        $st->execute([$appId]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $items[] = [
                'id' => $id,
                'original_filename' => (string) $row['original_filename'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'created_at' => (string) $row['created_at'],
                'play_url' => $this->cabinetPreviewUrl($appId, $id),
            ];
        }

        return Response::json(['media' => $items]);
    }

    public function upload(Request $request): Response
    {
        $appId = $this->appId($request);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!$this->ownsApplication($appId, (int) ($request->attributes['user_id'] ?? 0))) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return Response::json(['error' => 'validation', 'message' => 'Поле file обязательно'], 422);
        }
        try {
            $meta = $this->mediaStorage->storeUpload($appId, $_FILES['file']);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => 'validation', 'message' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return Response::json(['error' => 'server', 'message' => 'upload_failed'], 500);
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO widget_media_assets (application_id, original_filename, stored_filename, mime_type, size_bytes)
             VALUES (?,?,?,?,?)',
        )->execute([
            $appId,
            $meta['original_filename'],
            $meta['stored_filename'],
            $meta['mime_type'],
            $meta['size_bytes'],
        ]);
        $id = (int) $pdo->lastInsertId();

        return Response::json([
            'ok' => true,
            'id' => $id,
            'original_filename' => $meta['original_filename'],
            'size_bytes' => $meta['size_bytes'],
            'play_url' => $this->cabinetPreviewUrl($appId, $id),
        ], 201);
    }

    public function delete(Request $request): Response
    {
        $appId = $this->appId($request);
        $mediaId = $this->mediaId($request);
        if ($appId < 1 || $mediaId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!$this->ownsApplication($appId, (int) ($request->attributes['user_id'] ?? 0))) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT id, stored_filename FROM widget_media_assets WHERE id = ? AND application_id = ? LIMIT 1',
        );
        $st->execute([$mediaId, $appId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $pdo->prepare('UPDATE widget_events SET video_media_id = NULL WHERE video_media_id = ?')->execute([$mediaId]);
        $pdo->prepare('DELETE FROM widget_media_assets WHERE id = ?')->execute([$mediaId]);
        $this->mediaStorage->deleteFile($appId, (string) $row['stored_filename']);

        return Response::json(['ok' => true]);
    }

    /** Публичная раздача для виджета на сайте клиента (token + referer). */
    public function serveForWidget(Request $request): Response
    {
        $mediaId = (int) (($request->attributes['route_params']['mediaId'] ?? 0));
        $token = trim((string) ($request->query['token'] ?? ''));
        if ($mediaId < 1 || $token === '') {
            return Response::json(['error' => 'not_found'], 404);
        }
        $app = $this->resolveApplicationForEmbed($request, $token);
        if ($app === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $appId = (int) $app['application_id'];

        return $this->streamFile($appId, $mediaId, true);
    }

    /** Превью в кабинете (JWT). */
    public function serveForCabinet(Request $request): Response
    {
        $appId = $this->appId($request);
        $mediaId = $this->mediaId($request);
        if ($appId < 1 || $mediaId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!$this->ownsApplication($appId, (int) ($request->attributes['user_id'] ?? 0))) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        return $this->streamFile($appId, $mediaId, false);
    }

    public function widgetPlayUrl(int $applicationId, int $mediaId, string $widgetToken): string
    {
        return rtrim($this->appUrl, '/') . '/widget/media/' . $mediaId . '?token=' . rawurlencode($widgetToken);
    }

    private function cabinetPreviewUrl(int $applicationId, int $mediaId): string
    {
        return rtrim($this->appUrl, '/') . '/api/applications/' . $applicationId . '/media/' . $mediaId . '/file';
    }

    private function streamFile(int $applicationId, int $mediaId, bool $withCors): Response
    {
        $st = $this->db->pdo()->prepare(
            'SELECT stored_filename, mime_type, size_bytes, original_filename
             FROM widget_media_assets WHERE id = ? AND application_id = ? LIMIT 1',
        );
        $st->execute([$mediaId, $applicationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $path = $this->mediaStorage->absolutePath($applicationId, (string) $row['stored_filename']);
        if (!is_file($path)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $body = (string) file_get_contents($path);
        $headers = [
            'Content-Type' => (string) $row['mime_type'],
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'public, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ];
        if ($withCors) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        return new Response(200, $body, $headers);
    }

    /** @return array{application_id: int, site_url: string}|null */
    private function resolveApplicationForEmbed(Request $request, string $appToken): ?array
    {
        $st = $this->db->pdo()->prepare(
            'SELECT a.id AS application_id, a.site_url, a.status
             FROM widget_applications a
             WHERE a.widget_token = ? AND a.status = ? LIMIT 1',
        );
        $st->execute([$appToken, 'approved']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $expectedHost = HostNormalizer::fromUrl($row['site_url']);
        $refererHost = HostNormalizer::fromReferer($request->header('Referer'));
        if ($refererHost === null && $request->header('Origin') !== null && $request->header('Origin') !== '') {
            $refererHost = HostNormalizer::fromUrl($request->header('Origin'));
        }
        if ($expectedHost === null || $refererHost === null || !hash_equals($expectedHost, $refererHost)) {
            return null;
        }

        return $row;
    }

    private function appId(Request $request): int
    {
        return (int) (($request->attributes['route_params']['id'] ?? 0));
    }

    private function mediaId(Request $request): int
    {
        $params = $request->attributes['route_params'] ?? '';

        return (int) ($params['mediaId'] ?? 0);
    }

    private function ownsApplication(int $applicationId, int $userId): bool
    {
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_applications WHERE id = ? AND user_id = ? LIMIT 1',
        );
        $st->execute([$applicationId, $userId]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }
}
