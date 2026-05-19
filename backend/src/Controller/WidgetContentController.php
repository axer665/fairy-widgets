<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\EventAction;
use App\WidgetTiming;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class WidgetContentController
{
    public function __construct(
        private readonly Database $db,
        private readonly string $appUrl,
    ) {
    }

    public function listText(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $st = $this->db->pdo()->prepare(
            'SELECT tw.id, tw.name, tw.body, tw.created_at, tw.updated_at,
                    (SELECT COUNT(*) FROM widget_text_impressions i WHERE i.text_widget_id = tw.id) AS impressions
             FROM widget_text_widgets tw
             WHERE tw.application_id = ?
             ORDER BY tw.id ASC',
        );
        $st->execute([$appId]);

        return Response::json(['widgets' => $this->mapRows($st, 'text')]);
    }

    public function createText(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $parsed = $this->parseTextBody($request);
        if ($parsed === null) {
            return Response::json(['error' => 'validation', 'message' => 'name и body обязательны'], 422);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO widget_text_widgets (application_id, name, body) VALUES (?,?,?)',
        )->execute([$appId, $parsed['name'], $parsed['body']]);

        return Response::json(['ok' => true, 'id' => (int) $this->db->pdo()->lastInsertId()], 201);
    }

    public function updateText(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $wid = $this->widgetId($request);
        if (!$this->textWidgetOwned($appId, $wid)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $parsed = $this->parseTextUpdate($request);
        if ($parsed === null) {
            return Response::json(['error' => 'validation', 'message' => 'body обязателен (до 2000 символов)'], 422);
        }
        $this->db->pdo()->prepare(
            'UPDATE widget_text_widgets SET body = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        )->execute([$parsed['body'], $wid]);

        return Response::json(['ok' => true]);
    }

    public function deleteText(Request $request): Response
    {
        return $this->deleteWidget($request, EventAction::TYPE_TEXT);
    }

    public function listSurvey(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $st = $this->db->pdo()->prepare(
            'SELECT sw.id, sw.name, sw.title, sw.description, sw.dismiss_after_ms, sw.created_at, sw.updated_at,
                    (SELECT COUNT(*) FROM widget_survey_impressions i WHERE i.survey_widget_id = sw.id) AS impressions,
                    (SELECT COUNT(*) FROM widget_survey_ratings r WHERE r.survey_widget_id = sw.id) AS ratings_count,
                    (SELECT ROUND(AVG(r.rating), 2) FROM widget_survey_ratings r WHERE r.survey_widget_id = sw.id) AS avg_rating,
                    (SELECT COUNT(*) FROM widget_survey_cancellations c WHERE c.survey_widget_id = sw.id) AS cancellations
             FROM widget_survey_widgets sw
             WHERE sw.application_id = ?
             ORDER BY sw.id ASC',
        );
        $st->execute([$appId]);

        return Response::json(['widgets' => $this->mapRows($st, 'survey')]);
    }

    public function createSurvey(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $parsed = $this->parseSurveyBody($request);
        if ($parsed === null) {
            return Response::json(['error' => 'validation', 'message' => 'name и title обязательны'], 422);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO widget_survey_widgets (application_id, name, title, description, dismiss_after_ms)
             VALUES (?,?,?,?,?)',
        )->execute([
            $appId,
            $parsed['name'],
            $parsed['title'],
            $parsed['description'],
            $parsed['dismiss_after_ms'],
        ]);

        return Response::json(['ok' => true, 'id' => (int) $this->db->pdo()->lastInsertId()], 201);
    }

    public function updateSurvey(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $wid = $this->widgetId($request);
        if (!$this->surveyWidgetOwned($appId, $wid)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $parsed = $this->parseSurveyUpdate($request);
        if ($parsed === null) {
            return Response::json(
                ['error' => 'validation', 'message' => 'title обязателен (до 512 символов), description — до 2000'],
                422,
            );
        }
        $this->db->pdo()->prepare(
            'UPDATE widget_survey_widgets SET title = ?, description = ?, dismiss_after_ms = ?,
             updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        )->execute([$parsed['title'], $parsed['description'], $parsed['dismiss_after_ms'], $wid]);

        return Response::json(['ok' => true]);
    }

    public function deleteSurvey(Request $request): Response
    {
        return $this->deleteWidget($request, EventAction::TYPE_SURVEY);
    }

    public function listVideo(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $st = $this->db->pdo()->prepare(
            'SELECT vw.id, vw.name, vw.media_id, vw.link_url, vw.leave_mode, vw.leave_timer_ms,
                    vw.created_at, vw.updated_at,
                    ma.original_filename, ma.mime_type, ma.size_bytes, ma.duration_ms,
                    (SELECT COUNT(*) FROM widget_video_impressions i WHERE i.video_widget_id = vw.id) AS impressions,
                    (SELECT COUNT(*) FROM widget_video_sessions vs WHERE vs.video_widget_id = vw.id AND vs.completed_full = 1) AS completed_full_count,
                    (SELECT COUNT(*) FROM widget_video_sessions vs WHERE vs.video_widget_id = vw.id AND vs.link_clicked = 1) AS link_clicks,
                    (SELECT COUNT(*) FROM widget_video_sessions vs WHERE vs.video_widget_id = vw.id AND vs.dismissed = 1) AS dismissals,
                    (SELECT ROUND(AVG(vs.watch_duration_ms)) FROM widget_video_sessions vs WHERE vs.video_widget_id = vw.id) AS avg_watch_ms
             FROM widget_video_widgets vw
             INNER JOIN widget_media_assets ma ON ma.id = vw.media_id
             WHERE vw.application_id = ?
             ORDER BY vw.id ASC',
        );
        $st->execute([$appId]);
        $token = $this->widgetToken($appId);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = $this->mapVideoRow($r, $token);
        }

        return Response::json(['widgets' => $rows]);
    }

    public function createVideo(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $parsed = $this->parseVideoBody($request);
        if ($parsed === null) {
            return Response::json(['error' => 'validation', 'message' => 'name и media_id обязательны'], 422);
        }
        if (!$this->mediaOwned($appId, $parsed['media_id'])) {
            return Response::json(['error' => 'validation', 'message' => 'Видеофайл не найден'], 422);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO widget_video_widgets (application_id, name, media_id, link_url, leave_mode, leave_timer_ms)
             VALUES (?,?,?,?,?,?)',
        )->execute([
            $appId,
            $parsed['name'],
            $parsed['media_id'],
            $parsed['link_url'],
            $parsed['leave_mode'],
            $parsed['leave_timer_ms'],
        ]);

        return Response::json(['ok' => true, 'id' => (int) $this->db->pdo()->lastInsertId()], 201);
    }

    public function updateVideo(Request $request): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $wid = $this->widgetId($request);
        if (!$this->videoWidgetOwned($appId, $wid)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $parsed = $this->parseVideoUpdate($request);
        if ($parsed === null) {
            return Response::json(
                [
                    'error' => 'validation',
                    'message' => 'link_url: https или пусто; leave_mode: video_end|timer; для timer — leave_timer_sec 1–86400',
                ],
                422,
            );
        }
        $this->db->pdo()->prepare(
            'UPDATE widget_video_widgets SET link_url = ?, leave_mode = ?, leave_timer_ms = ?,
             updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        )->execute([$parsed['link_url'], $parsed['leave_mode'], $parsed['leave_timer_ms'], $wid]);

        return Response::json(['ok' => true]);
    }

    public function deleteVideo(Request $request): Response
    {
        return $this->deleteWidget($request, EventAction::TYPE_VIDEO);
    }

    private function deleteWidget(Request $request, string $type): Response
    {
        $appId = $this->requireApp($request);
        if ($appId instanceof Response) {
            return $appId;
        }
        $wid = $this->widgetId($request);
        $col = match ($type) {
            EventAction::TYPE_SURVEY => 'survey_widget_id',
            EventAction::TYPE_VIDEO => 'video_widget_id',
            default => 'text_widget_id',
        };
        $table = match ($type) {
            EventAction::TYPE_SURVEY => 'widget_survey_widgets',
            EventAction::TYPE_VIDEO => 'widget_video_widgets',
            default => 'widget_text_widgets',
        };
        $pdo = $this->db->pdo();
        if (!$this->widgetOwned($appId, $wid, $type)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $st = $pdo->prepare("SELECT id FROM widget_events WHERE application_id = ? AND {$col} = ? LIMIT 1");
        $st->execute([$appId, $wid]);
        if ($st->fetchColumn() !== false) {
            return Response::json(
                ['error' => 'validation', 'message' => 'Виджет привязан к событию — сначала отвяжите'],
                422,
            );
        }
        $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND application_id = ?")->execute([$wid, $appId]);

        return Response::json(['ok' => true]);
    }

    /** @return list<array<string, mixed>> */
    private function mapRows(\PDOStatement $st, string $kind): array
    {
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'created_at' => $r['created_at'] ?? null,
                'updated_at' => $r['updated_at'] ?? null,
                'stats' => ['impressions' => (int) ($r['impressions'] ?? 0)],
            ];
            if ($kind === 'text') {
                $row['body'] = (string) $r['body'];
            } elseif ($kind === 'survey') {
                $row['title'] = (string) $r['title'];
                $row['description'] = $r['description'] !== null ? (string) $r['description'] : null;
                $row['dismiss_after_ms'] = isset($r['dismiss_after_ms']) && $r['dismiss_after_ms'] !== null
                    ? (int) $r['dismiss_after_ms'] : null;
                $row['stats']['ratings_count'] = (int) ($r['ratings_count'] ?? 0);
                $row['stats']['avg_rating'] = $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null;
                $row['stats']['cancellations'] = (int) ($r['cancellations'] ?? 0);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $r */
    private function mapVideoRow(array $r, ?string $token): array
    {
        $mediaId = (int) $r['media_id'];
        $playUrl = $token !== null && $token !== ''
            ? rtrim($this->appUrl, '/') . '/widget/media/' . $mediaId . '?token=' . rawurlencode($token)
            : null;

        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'media_id' => $mediaId,
            'link_url' => $r['link_url'] !== null && $r['link_url'] !== '' ? (string) $r['link_url'] : null,
            'leave_mode' => (string) ($r['leave_mode'] ?? WidgetTiming::VIDEO_LEAVE_VIDEO_END),
            'leave_timer_ms' => isset($r['leave_timer_ms']) && $r['leave_timer_ms'] !== null
                ? (int) $r['leave_timer_ms'] : null,
            'duration_ms' => isset($r['duration_ms']) && $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
            'original_filename' => (string) $r['original_filename'],
            'mime_type' => (string) $r['mime_type'],
            'size_bytes' => (int) $r['size_bytes'],
            'play_url' => $playUrl,
            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
            'stats' => [
                'impressions' => (int) ($r['impressions'] ?? 0),
                'completed_full_count' => (int) ($r['completed_full_count'] ?? 0),
                'link_clicks' => (int) ($r['link_clicks'] ?? 0),
                'dismissals' => (int) ($r['dismissals'] ?? 0),
                'avg_watch_ms' => $r['avg_watch_ms'] !== null ? (int) $r['avg_watch_ms'] : null,
            ],
        ];
    }

    /** @return array{name: string, body: string}|null */
    private function parseTextBody(Request $request): ?array
    {
        $b = $request->body;
        if (!is_array($b)) {
            return null;
        }
        $name = trim((string) ($b['name'] ?? ''));
        $body = trim((string) ($b['body'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128 || $body === '' || mb_strlen($body) > 2000) {
            return null;
        }

        return ['name' => $name, 'body' => $body];
    }

    /**
     * @return array{
     *   name: string,
     *   title: string,
     *   description: ?string,
     *   dismiss_after_ms: ?int
     * }|null
     */
    private function parseSurveyBody(Request $request): ?array
    {
        $b = $request->body;
        if (!is_array($b)) {
            return null;
        }
        $name = trim((string) ($b['name'] ?? ''));
        $title = trim((string) ($b['title'] ?? ''));
        $desc = trim((string) ($b['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128 || $title === '' || mb_strlen($title) > 512) {
            return null;
        }
        if ($desc !== '' && mb_strlen($desc) > 2000) {
            return null;
        }

        return [
            'name' => $name,
            'title' => $title,
            'description' => $desc !== '' ? $desc : null,
            'dismiss_after_ms' => WidgetTiming::parseDismissAfterMs($b),
        ];
    }

    /** @return array{body: string}|null */
    private function parseTextUpdate(Request $request): ?array
    {
        $b = $request->body;
        if (!is_array($b)) {
            return null;
        }
        $body = trim((string) ($b['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 2000) {
            return null;
        }

        return ['body' => $body];
    }

    /**
     * @return array{title: string, description: ?string, dismiss_after_ms: ?int}|null
     */
    private function parseSurveyUpdate(Request $request): ?array
    {
        $b = $request->body;
        if (!is_array($b)) {
            return null;
        }
        $title = trim((string) ($b['title'] ?? ''));
        $desc = trim((string) ($b['description'] ?? ''));
        if ($title === '' || mb_strlen($title) > 512) {
            return null;
        }
        if ($desc !== '' && mb_strlen($desc) > 2000) {
            return null;
        }

        return [
            'title' => $title,
            'description' => $desc !== '' ? $desc : null,
            'dismiss_after_ms' => WidgetTiming::parseDismissAfterMs($b),
        ];
    }

    /**
     * @return array{
     *   link_url: ?string,
     *   leave_mode: string,
     *   leave_timer_ms: ?int
     * }|null
     */
    private function parseVideoUpdate(Request $request): ?array
    {
        $b = $request->body;
        if (!is_array($b)) {
            return null;
        }
        $link = trim((string) ($b['link_url'] ?? ''));
        if ($link !== '' && !EventAction::isValidHttpUrl($link)) {
            return null;
        }
        $leave = WidgetTiming::parseVideoLeave($b);
        if ($leave === null) {
            return null;
        }

        return [
            'link_url' => $link !== '' ? $link : null,
            'leave_mode' => $leave['leave_mode'],
            'leave_timer_ms' => $leave['leave_timer_ms'],
        ];
    }

    /**
     * @return array{
     *   name: string,
     *   media_id: int,
     *   link_url: ?string,
     *   leave_mode: string,
     *   leave_timer_ms: ?int
     * }|null
     */
    private function parseVideoBody(Request $request): ?array
    {
        $b = $request->body;
        if (!is_array($b)) {
            return null;
        }
        $name = trim((string) ($b['name'] ?? ''));
        $mediaId = isset($b['media_id']) ? (int) $b['media_id'] : 0;
        $link = trim((string) ($b['link_url'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128 || $mediaId < 1) {
            return null;
        }
        if ($link !== '' && !EventAction::isValidHttpUrl($link)) {
            return null;
        }
        $leave = WidgetTiming::parseVideoLeave($b);
        if ($leave === null) {
            return null;
        }

        return [
            'name' => $name,
            'media_id' => $mediaId,
            'link_url' => $link !== '' ? $link : null,
            'leave_mode' => $leave['leave_mode'],
            'leave_timer_ms' => $leave['leave_timer_ms'],
        ];
    }

    private function requireApp(Request $request): int|Response
    {
        $appId = (int) (($request->attributes['route_params']['id'] ?? 0));
        if ($appId < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->ownsApplication($appId, $uid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        return $appId;
    }

    private function widgetId(Request $request): int
    {
        return (int) (($request->attributes['route_params']['widgetId'] ?? 0));
    }

    private function ownsApplication(int $applicationId, int $userId): bool
    {
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_applications WHERE id = ? AND user_id = ? LIMIT 1',
        );
        $st->execute([$applicationId, $userId]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }

    private function textWidgetOwned(int $appId, int $wid): bool
    {
        return $this->widgetOwned($appId, $wid, EventAction::TYPE_TEXT);
    }

    private function surveyWidgetOwned(int $appId, int $wid): bool
    {
        return $this->widgetOwned($appId, $wid, EventAction::TYPE_SURVEY);
    }

    private function videoWidgetOwned(int $appId, int $wid): bool
    {
        return $this->widgetOwned($appId, $wid, EventAction::TYPE_VIDEO);
    }

    private function widgetOwned(int $appId, int $wid, string $type): bool
    {
        $table = match ($type) {
            EventAction::TYPE_SURVEY => 'widget_survey_widgets',
            EventAction::TYPE_VIDEO => 'widget_video_widgets',
            default => 'widget_text_widgets',
        };
        $st = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE id = ? AND application_id = ? LIMIT 1");
        $st->execute([$wid, $appId]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }

    private function mediaOwned(int $appId, int $mediaId): bool
    {
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_media_assets WHERE id = ? AND application_id = ? LIMIT 1',
        );
        $st->execute([$mediaId, $appId]);

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    }

    private function widgetToken(int $appId): ?string
    {
        $st = $this->db->pdo()->prepare(
            'SELECT widget_token FROM widget_applications WHERE id = ? LIMIT 1',
        );
        $st->execute([$appId]);
        $t = $st->fetchColumn();

        return $t !== false && $t !== null ? (string) $t : null;
    }
}
