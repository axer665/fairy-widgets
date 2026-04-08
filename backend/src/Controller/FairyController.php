<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class FairyController
{
    public function __construct(
        private readonly Database $db,
    ) {
    }

    public function listByApplication(Request $request): Response
    {
        $appId = (int) (($request->attributes['route_params'] ?? [])['id'] ?? 0);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        $stdSt = $pdo->prepare(
            'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $stdSt->execute([$appId, '_standard']);
        $stdCol = $stdSt->fetchColumn();
        $stdEventId = $stdCol !== false ? (int) $stdCol : 0;
        $st = $pdo->prepare(
            'SELECT id, application_id, name, created_at FROM widget_fairies WHERE application_id = ? ORDER BY id ASC',
        );
        $st->execute([$appId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $fid = (int) $r['id'];
            $r['id'] = $fid;
            $r['application_id'] = (int) $r['application_id'];
            $es = $pdo->prepare(
                'SELECT widget_event_id FROM fairy_events WHERE fairy_id = ? ORDER BY widget_event_id ASC',
            );
            $es->execute([$fid]);
            $assigned = array_map('intval', $es->fetchAll(PDO::FETCH_COLUMN));
            $r['standard_behavior'] = $stdEventId > 0 && in_array($stdEventId, $assigned, true);
            $r['assigned_event_ids'] = $assigned;
        }
        unset($r);
        return Response::json(['fairies' => $rows]);
    }

    public function create(Request $request): Response
    {
        $appId = (int) (($request->attributes['route_params'] ?? [])['id'] ?? 0);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApprovedApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $b = $request->body;
        $name = is_array($b) ? trim((string) ($b['name'] ?? '')) : '';
        if ($name === '') {
            $name = 'Фея';
        }
        if (mb_strlen($name) > 128) {
            return Response::json(['error' => 'validation', 'message' => 'Имя до 128 символов'], 422);
        }
        $pdo = $this->db->pdo();
        $tokRow = $pdo->prepare(
            'SELECT widget_token FROM widget_applications WHERE id = ? AND status = ? LIMIT 1',
        );
        $tokRow->execute([$appId, 'approved']);
        $wt = $tokRow->fetchColumn();
        if ($wt === false || $wt === null || $wt === '') {
            return Response::json(['error' => 'invalid_state', 'message' => 'Нет токена заявки'], 409);
        }
        $pdo->prepare(
            'INSERT INTO widget_fairies (application_id, name, standard_behavior) VALUES (?,?,0)',
        )->execute([$appId, $name]);
        $fairyId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id)
             SELECT ?, id FROM widget_events WHERE application_id = ?',
        )->execute([$fairyId, $appId]);
        return Response::json([
            'id' => $fairyId,
            'name' => $name,
        ], 201);
    }

    public function update(Request $request): Response
    {
        $id = (int) (($request->attributes['route_params'] ?? [])['id'] ?? 0);
        if ($id < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        $fairy = $this->fairyOwnedByUser($id, $uid);
        if ($fairy === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $pdo = $this->db->pdo();
        if (array_key_exists('name', $b)) {
            $name = trim((string) $b['name']);
            if ($name === '') {
                return Response::json(['error' => 'validation', 'message' => 'Имя не пустое'], 422);
            }
            if (mb_strlen($name) > 128) {
                return Response::json(['error' => 'validation'], 422);
            }
            $pdo->prepare('UPDATE widget_fairies SET name = ? WHERE id = ?')->execute([$name, $id]);
        }
        if (array_key_exists('standard_behavior', $b)) {
            $sb = $b['standard_behavior'];
            $val = is_bool($sb) ? $sb : (bool) (int) $sb;
            $appIdForFairy = (int) $fairy['application_id'];
            $pdo->prepare(
                'INSERT INTO widget_events (application_id, event_key, phrase) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE id = id',
            )->execute([$appIdForFairy, '_standard', 'Привет! Я фея виджета.']);
            $wst = $pdo->prepare(
                'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
            );
            $wst->execute([$appIdForFairy, '_standard']);
            $weId = (int) $wst->fetchColumn();
            if ($weId > 0) {
                if ($val) {
                    $pdo->prepare(
                        'INSERT IGNORE INTO fairy_events (fairy_id, widget_event_id) VALUES (?,?)',
                    )->execute([$id, $weId]);
                } else {
                    $pdo->prepare(
                        'DELETE FROM fairy_events WHERE fairy_id = ? AND widget_event_id = ?',
                    )->execute([$id, $weId]);
                }
            }
            $pdo->prepare('UPDATE widget_fairies SET standard_behavior = ? WHERE id = ?')->execute([(int) $val, $id]);
        }
        $stdSt = $pdo->prepare(
            'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $stdSt->execute([(int) $fairy['application_id'], '_standard']);
        $stdCol = $stdSt->fetchColumn();
        $stdEventId = $stdCol !== false ? (int) $stdCol : 0;
        $st = $pdo->prepare(
            'SELECT id, name FROM widget_fairies WHERE id = ? LIMIT 1',
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $as = $pdo->prepare(
            'SELECT widget_event_id FROM fairy_events WHERE fairy_id = ? AND widget_event_id = ? LIMIT 1',
        );
        $as->execute([$id, $stdEventId > 0 ? $stdEventId : 0]);
        $hasStd = $stdEventId > 0 && (bool) $as->fetchColumn();

        return Response::json([
            'ok' => true,
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'standard_behavior' => $hasStd,
        ]);
    }

    public function putAssignments(Request $request): Response
    {
        $id = (int) (($request->attributes['route_params'] ?? [])['id'] ?? 0);
        if ($id < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        $fairy = $this->fairyOwnedByUser($id, $uid);
        if ($fairy === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $b = $request->body;
        if (!is_array($b) || !isset($b['event_ids']) || !is_array($b['event_ids'])) {
            return Response::json(['error' => 'validation', 'message' => 'event_ids: массив id событий'], 422);
        }
        $appId = (int) $fairy['application_id'];
        $ids = array_values(array_unique(array_map('intval', $b['event_ids'])));
        $ids = array_values(array_filter($ids, static fn (int $x): bool => $x > 0));
        $pdo = $this->db->pdo();
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $appId;
            $st = $pdo->prepare(
                "SELECT id FROM widget_events WHERE id IN ($placeholders) AND application_id = ?",
            );
            $st->execute($params);
            $valid = $st->fetchAll(PDO::FETCH_COLUMN);
            $valid = array_map('intval', $valid);
            sort($valid);
            $idsSorted = $ids;
            sort($idsSorted);
            if ($valid !== $idsSorted) {
                return Response::json(['error' => 'validation', 'message' => 'Недопустимые event_ids'], 422);
            }
        }
        $pdo->prepare('DELETE FROM fairy_events WHERE fairy_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO fairy_events (fairy_id, widget_event_id) VALUES (?,?)');
        foreach ($ids as $eid) {
            $ins->execute([$id, $eid]);
        }
        $stdSt = $pdo->prepare(
            'SELECT id FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $stdSt->execute([$appId, '_standard']);
        $stdCol = $stdSt->fetchColumn();
        $stdEventId = $stdCol !== false ? (int) $stdCol : 0;
        $pdo->prepare('UPDATE widget_fairies SET standard_behavior = ? WHERE id = ?')->execute([
            $stdEventId > 0 && in_array($stdEventId, $ids, true) ? 1 : 0,
            $id,
        ]);

        return Response::json(['ok' => true, 'event_ids' => $ids]);
    }

    public function listFailures(Request $request): Response
    {
        $appId = (int) (($request->attributes['route_params'] ?? [])['id'] ?? 0);
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 50)));
        $st = $this->db->pdo()->prepare(
            'SELECT f.id, f.fairy_id, f.widget_event_id, f.event_key, f.reason_code, f.detail,
                    f.blocker_execution_id, f.blocker_fairy_id, f.blocker_widget_event_id, f.blocker_event_key,
                    f.created_at,
                    wf.name AS fairy_name
             FROM widget_event_failures f
             JOIN widget_fairies wf ON wf.id = f.fairy_id
             WHERE f.application_id = ?
             ORDER BY f.id DESC
             LIMIT ' . $limit,
        );
        $st->execute([$appId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['fairy_id'] = (int) $r['fairy_id'];
            $r['widget_event_id'] = $r['widget_event_id'] !== null ? (int) $r['widget_event_id'] : null;
            $r['blocker_execution_id'] = $r['blocker_execution_id'] !== null ? (int) $r['blocker_execution_id'] : null;
            $r['blocker_fairy_id'] = $r['blocker_fairy_id'] !== null ? (int) $r['blocker_fairy_id'] : null;
            $r['blocker_widget_event_id'] = $r['blocker_widget_event_id'] !== null ? (int) $r['blocker_widget_event_id'] : null;
        }
        unset($r);

        return Response::json(['failures' => $rows]);
    }

    private function ownsApplication(int $applicationId, int $userId): bool
    {
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_applications WHERE id = ? AND user_id = ? LIMIT 1',
        );
        $st->execute([$applicationId, $userId]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }

    private function ownsApprovedApplication(int $applicationId, int $userId): bool
    {
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_applications WHERE id = ? AND user_id = ? AND status = ? LIMIT 1',
        );
        $st->execute([$applicationId, $userId, 'approved']);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function fairyOwnedByUser(int $fairyId, int $userId): ?array
    {
        $st = $this->db->pdo()->prepare(
            'SELECT f.id, f.application_id FROM widget_fairies f
             INNER JOIN widget_applications a ON a.id = f.application_id
             WHERE f.id = ? AND a.user_id = ? LIMIT 1',
        );
        $st->execute([$fairyId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
