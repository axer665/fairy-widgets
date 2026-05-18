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
    private const EVENT_SELECT = 'SELECT we.id, we.event_key, we.action_type_id,
            we.text_widget_id, we.survey_widget_id, we.video_widget_id,
            we.pos_h_edge, we.pos_v_edge, we.pos_unit, we.pos_x, we.pos_y,
            we.created_at, we.updated_at,
            wat.code AS action_type_code, wat.label AS action_type_label,
            tw.name AS text_widget_name, tw.body AS text_body,
            sw.name AS survey_widget_name, sw.title AS survey_title, sw.description AS survey_description,
            vw.name AS video_widget_name, vw.media_id AS video_media_id, vw.link_url AS video_link_url
         FROM widget_events we
         INNER JOIN widget_action_types wat ON wat.id = we.action_type_id
         LEFT JOIN widget_text_widgets tw ON tw.id = we.text_widget_id
         LEFT JOIN widget_survey_widgets sw ON sw.id = we.survey_widget_id
         LEFT JOIN widget_video_widgets vw ON vw.id = we.video_widget_id';

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
        $parsed = EventAction::parseEventLink($b, $eventKey);
        if ($parsed === null || !$this->validateWidgetLink($appId, $parsed)) {
            return Response::json(
                ['error' => 'validation', 'message' => $this->validationMessage($b, $eventKey)],
                422,
            );
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
    public function mapEventRow(array $row): array
    {
        $out = [
            'id' => (int) $row['id'],
            'event_key' => (string) $row['event_key'],
            'position' => EventLandPosition::fromDbRow($row),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        return array_merge($out, EventAction::toApiPayload($row));
    }

    /**
     * @param array{
     *   type_id: int,
     *   type_code: string,
     *   text_widget_id: ?int,
     *   survey_widget_id: ?int,
     *   video_widget_id: ?int
     * } $parsed
     */
    private function insertEventRow(PDO $pdo, int $appId, string $eventKey, array $parsed, ?array $positionInput): void
    {
        $pos = $positionInput ?? EventLandPosition::defaults();
        $dbPos = EventLandPosition::toDbParams($pos);
        $pdo->prepare(
            'INSERT INTO widget_events (
                application_id, event_key, action_type_id,
                text_widget_id, survey_widget_id, video_widget_id,
                pos_h_edge, pos_v_edge, pos_unit, pos_x, pos_y
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        )->execute([
            $appId,
            $eventKey,
            $parsed['type_id'],
            $parsed['text_widget_id'],
            $parsed['survey_widget_id'],
            $parsed['video_widget_id'],
            $dbPos['pos_h_edge'],
            $dbPos['pos_v_edge'],
            $dbPos['pos_unit'],
            $dbPos['pos_x'],
            $dbPos['pos_y'],
        ]);
    }

    /** @param array{type_id: int, type_code: string, text_widget_id: ?int, survey_widget_id: ?int, video_widget_id: ?int} $parsed */
    private function updateEventRow(PDO $pdo, int $id, array $parsed, ?array $positionInput): void
    {
        if ($positionInput !== null) {
            $dbPos = EventLandPosition::toDbParams($positionInput);
            $pdo->prepare(
                'UPDATE widget_events SET action_type_id = ?, text_widget_id = ?, survey_widget_id = ?,
                 video_widget_id = ?, pos_h_edge = ?, pos_v_edge = ?, pos_unit = ?, pos_x = ?, pos_y = ?,
                 updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([
                $parsed['type_id'],
                $parsed['text_widget_id'],
                $parsed['survey_widget_id'],
                $parsed['video_widget_id'],
                $dbPos['pos_h_edge'],
                $dbPos['pos_v_edge'],
                $dbPos['pos_unit'],
                $dbPos['pos_x'],
                $dbPos['pos_y'],
                $id,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE widget_events SET action_type_id = ?, text_widget_id = ?, survey_widget_id = ?,
                 video_widget_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([
                $parsed['type_id'],
                $parsed['text_widget_id'],
                $parsed['survey_widget_id'],
                $parsed['video_widget_id'],
                $id,
            ]);
        }
    }

    /**
     * @param array{
     *   type_id: int,
     *   type_code: string,
     *   text_widget_id: ?int,
     *   survey_widget_id: ?int,
     *   video_widget_id: ?int
     * } $parsed
     */
    private function validateWidgetLink(int $appId, array $parsed): bool
    {
        $pdo = $this->db->pdo();
        if ($parsed['text_widget_id'] !== null) {
            $st = $pdo->prepare('SELECT id FROM widget_text_widgets WHERE id = ? AND application_id = ? LIMIT 1');
            $st->execute([$parsed['text_widget_id'], $appId]);

            return (bool) $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($parsed['survey_widget_id'] !== null) {
            $st = $pdo->prepare('SELECT id FROM widget_survey_widgets WHERE id = ? AND application_id = ? LIMIT 1');
            $st->execute([$parsed['survey_widget_id'], $appId]);

            return (bool) $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($parsed['video_widget_id'] !== null) {
            $st = $pdo->prepare('SELECT id FROM widget_video_widgets WHERE id = ? AND application_id = ? LIMIT 1');
            $st->execute([$parsed['video_widget_id'], $appId]);

            return (bool) $st->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    /** @param array<string, mixed> $b */
    private function validationMessage(array $b, string $eventKey): string
    {
        $type = EventAction::normalizeTypeCode((string) ($b['action_type'] ?? EventAction::TYPE_TEXT));
        if ($eventKey === '_standard') {
            return 'Для _standard выберите текстовый виджет (widget_id)';
        }

        return match ($type) {
            EventAction::TYPE_SURVEY => 'Выберите виджет опроса (widget_id) во вкладке «Опросы»',
            EventAction::TYPE_VIDEO => 'Выберите видео-виджет (widget_id) во вкладке «Видео»',
            default => 'Выберите текстовый виджет (widget_id) во вкладке «Текст»',
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
        $twSt = $pdo->prepare(
            'SELECT id FROM widget_text_widgets WHERE application_id = ? AND name = ? LIMIT 1',
        );
        $twSt->execute([$applicationId, 'Приветствие']);
        $twId = $twSt->fetchColumn();
        if ($twId === false) {
            $pdo->prepare(
                'INSERT INTO widget_text_widgets (application_id, name, body) VALUES (?,?,?)',
            )->execute([$applicationId, 'Приветствие', 'Привет! Я фея виджета.']);
            $twId = (int) $pdo->lastInsertId();
        } else {
            $twId = (int) $twId;
        }
        $pdo->prepare(
            'INSERT INTO widget_events (application_id, event_key, action_type_id, text_widget_id)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE text_widget_id = VALUES(text_widget_id), action_type_id = VALUES(action_type_id)',
        )->execute([$applicationId, '_standard', EventAction::TYPE_ID_TEXT, $twId]);
        $st = $pdo->prepare(
            'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $st->execute([$applicationId, '_standard']);
        $weId = (int) $st->fetchColumn();
        if ($weId > 0) {
            $pdo->prepare(
                'INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id)
                 SELECT f.id, ?
                 FROM widget_fairies f
                 WHERE f.application_id = ? AND f.standard_behavior = 1',
            )->execute([$weId, $applicationId]);
        }
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
