<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class WidgetEventController
{
    public function __construct(
        private readonly Database $db,
    ) {
    }

    public function list(Request $request): Response
    {
        $appId = $this->appId($request);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT id, event_key, phrase, created_at, updated_at FROM widget_events
             WHERE application_id = ? ORDER BY id ASC',
        );
        $st->execute([$appId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        unset($r);
        return Response::json(['events' => $rows]);
    }

    public function create(Request $request): Response
    {
        $appId = $this->appId($request);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $eventKey = trim((string) ($b['event_key'] ?? ''));
        $phrase = trim((string) ($b['phrase'] ?? ''));
        if ($phrase === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $eventKey)) {
            return Response::json(
                ['error' => 'validation', 'message' => 'event_key: латиница, цифры, _-, до 64; phrase не пустой'],
                422,
            );
        }
        if (mb_strlen($phrase) > 2000) {
            return Response::json(['error' => 'validation', 'message' => 'phrase слишком длинный'], 422);
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO widget_events (application_id, event_key, phrase) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE phrase = VALUES(phrase), updated_at = CURRENT_TIMESTAMP',
        )->execute([$appId, $eventKey, $phrase]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $st = $pdo->prepare(
                'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
            );
            $st->execute([$appId, $eventKey]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $id = $row ? (int) $row['id'] : 0;
        }
        return Response::json(['ok' => true, 'id' => $id, 'event_key' => $eventKey], 201);
    }

    private function appId(Request $request): int
    {
        $params = $request->attributes['route_params'] ?? [];

        return (int) ($params['id'] ?? 0);
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
