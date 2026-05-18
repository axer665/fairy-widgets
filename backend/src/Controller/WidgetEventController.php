<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\EventAction;
use App\EventLandPosition;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class WidgetEventController
{
    private const EVENT_SELECT = 'SELECT we.id, we.event_key, we.phrase, we.action_type_id,
            we.survey_title, we.video_media_id, we.video_link_url,
            we.pos_h_edge, we.pos_v_edge, we.pos_unit, we.pos_x, we.pos_y,
            we.created_at, we.updated_at,
            wat.code AS action_type_code, wat.label AS action_type_label
         FROM widget_events we
         INNER JOIN widget_action_types wat ON wat.id = we.action_type_id';

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
        $this->ensureStandardWelcomeEvent($appId);
        $st = $this->db->pdo()->prepare(
            self::EVENT_SELECT . ' WHERE we.application_id = ? ORDER BY we.id ASC',
        );
        $st->execute([$appId]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = $this->mapEventRow($r);
        }

        return Response::json(['events' => $rows]);
    }

    public function listActionTypes(Request $request): Response
    {
        $st = $this->db->pdo()->query(
            'SELECT code, label FROM widget_action_types ORDER BY sort_order ASC',
        );
        $types = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[] = [
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
            ];
        }

        return Response::json(['action_types' => $types]);
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
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $eventKey)) {
            return Response::json(
                ['error' => 'validation', 'message' => 'event_key: латиница, цифры, _-, до 64'],
                422,
            );
        }
        $parsed = EventAction::parseEventInput($b, $eventKey);
        if ($parsed === null) {
            return Response::json(
                ['error' => 'validation', 'message' => $this->validationMessage($b, $eventKey)],
                422,
            );
        }
        if (!$this->validateVideoMedia($appId, $parsed['video_media_id'])) {
            return Response::json(['error' => 'validation', 'message' => 'Видео не найдено в контенте заявки'], 422);
        }
        $positionInput = null;
        if (array_key_exists('position', $b)) {
            if (!is_array($b['position'])) {
                return Response::json(['error' => 'validation', 'message' => 'position: объект'], 422);
            }
            $positionInput = EventLandPosition::parse($b['position']);
            if ($positionInput === null) {
                return Response::json(
                    [
                        'error' => 'validation',
                        'message' => 'position: horizontal left|right, vertical top|bottom, unit px|percent, x и y ≥ 0 (до 100 для %)',
                    ],
                    422,
                );
            }
        }
        $pdo = $this->db->pdo();
        $existSt = $pdo->prepare(
            'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $existSt->execute([$appId, $eventKey]);
        $existingId = $existSt->fetchColumn();
        if ($existingId !== false) {
            $this->updateEventRow($pdo, (int) $existingId, $parsed, $positionInput);
        } else {
            $this->insertEventRow($pdo, $appId, $eventKey, $parsed, $positionInput);
        }
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $st = $pdo->prepare(
                'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
            );
            $st->execute([$appId, $eventKey]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $id = $row ? (int) $row['id'] : 0;
        }
        $pdo->prepare(
            'INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id) SELECT id, ? FROM widget_fairies WHERE application_id = ?',
        )->execute([$id, $appId]);

        return Response::json(['ok' => true, 'id' => $id, 'event_key' => $eventKey], 201);
    }

    public function delete(Request $request): Response
    {
        $appId = $this->appId($request);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $eventId = $this->eventId($request);
        if ($eventId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT id, event_key FROM widget_events WHERE id = ? AND application_id = ? LIMIT 1',
        );
        $st->execute([$eventId, $appId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ((string) $row['event_key'] === '_standard') {
            return Response::json(
                ['error' => 'validation', 'message' => 'Системное событие _standard нельзя удалить'],
                422,
            );
        }
        $pdo->prepare('DELETE FROM widget_events WHERE id = ? AND application_id = ?')->execute([$eventId, $appId]);

        return Response::json(['ok' => true]);
    }

    /** @param array<string, mixed> $row */
    public function mapEventRow(array $row, ?string $videoPlayUrl = null): array
    {
        $out = [
            'id' => (int) $row['id'],
            'event_key' => (string) $row['event_key'],
            'position' => EventLandPosition::fromDbRow($row),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        return array_merge($out, EventAction::toApiPayload($row, $videoPlayUrl));
    }

    /** @param array{type_id: int, phrase: string, survey_title: ?string, video_media_id: ?int, video_link_url: ?string} $parsed */
    private function insertEventRow(PDO $pdo, int $appId, string $eventKey, array $parsed, ?array $positionInput): void
    {
        $pos = $positionInput ?? EventLandPosition::defaults();
        $dbPos = EventLandPosition::toDbParams($pos);
        $pdo->prepare(
            'INSERT INTO widget_events (
                application_id, event_key, phrase, action_type_id, survey_title, video_media_id, video_link_url,
                pos_h_edge, pos_v_edge, pos_unit, pos_x, pos_y
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        )->execute([
            $appId,
            $eventKey,
            $parsed['phrase'],
            $parsed['type_id'],
            $parsed['survey_title'],
            $parsed['video_media_id'],
            $parsed['video_link_url'],
            $dbPos['pos_h_edge'],
            $dbPos['pos_v_edge'],
            $dbPos['pos_unit'],
            $dbPos['pos_x'],
            $dbPos['pos_y'],
        ]);
    }

    /** @param array{type_id: int, phrase: string, survey_title: ?string, video_media_id: ?int, video_link_url: ?string} $parsed */
    private function updateEventRow(PDO $pdo, int $id, array $parsed, ?array $positionInput): void
    {
        if ($positionInput !== null) {
            $dbPos = EventLandPosition::toDbParams($positionInput);
            $pdo->prepare(
                'UPDATE widget_events SET phrase = ?, action_type_id = ?, survey_title = ?, video_media_id = ?,
                 video_link_url = ?, pos_h_edge = ?, pos_v_edge = ?, pos_unit = ?, pos_x = ?, pos_y = ?,
                 updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([
                $parsed['phrase'],
                $parsed['type_id'],
                $parsed['survey_title'],
                $parsed['video_media_id'],
                $parsed['video_link_url'],
                $dbPos['pos_h_edge'],
                $dbPos['pos_v_edge'],
                $dbPos['pos_unit'],
                $dbPos['pos_x'],
                $dbPos['pos_y'],
                $id,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE widget_events SET phrase = ?, action_type_id = ?, survey_title = ?, video_media_id = ?,
                 video_link_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([
                $parsed['phrase'],
                $parsed['type_id'],
                $parsed['survey_title'],
                $parsed['video_media_id'],
                $parsed['video_link_url'],
                $id,
            ]);
        }
    }

    private function validateVideoMedia(int $appId, ?int $mediaId): bool
    {
        if ($mediaId === null) {
            return true;
        }
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_media_assets WHERE id = ? AND application_id = ? LIMIT 1',
        );
        $st->execute([$mediaId, $appId]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $b */
    private function validationMessage(array $b, string $eventKey): string
    {
        $type = EventAction::normalizeTypeCode((string) ($b['action_type'] ?? EventAction::TYPE_TEXT));
        if ($eventKey === '_standard') {
            return 'Для _standard нужен непустой phrase (текст)';
        }

        return match ($type) {
            EventAction::TYPE_SURVEY => 'Для опроса укажите survey_title (до 512 символов)',
            EventAction::TYPE_VIDEO => 'Для видео выберите video_media_id из вкладки «Контент»; video_link_url — опционально (https)',
            default => 'Для текста укажите phrase',
        };
    }

    private function appId(Request $request): int
    {
        return (int) (($request->attributes['route_params']['id'] ?? 0));
    }

    private function eventId(Request $request): int
    {
        return (int) (($request->attributes['route_params']['eventId'] ?? 0));
    }

    private function ensureStandardWelcomeEvent(int $applicationId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO widget_events (application_id, event_key, phrase, action_type_id) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE id = id',
        )->execute([$applicationId, '_standard', 'Привет! Я фея виджета.', EventAction::TYPE_ID_TEXT]);
        $pdo->prepare(
            'INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id)
             SELECT f.id, we.id
             FROM widget_fairies f
             INNER JOIN widget_events we ON we.application_id = f.application_id AND we.event_key = ?
             WHERE f.application_id = ? AND f.standard_behavior = 1',
        )->execute(['_standard', $applicationId]);
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
