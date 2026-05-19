<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventAction;
use App\EventLandPosition;
use App\Database;
use App\WidgetStats;
use App\Http\Request;
use App\Http\Response;
use App\Util\HostNormalizer;
use PDO;
use PDOException;

final class WidgetController
{
    /** Показ ~25 с + пауза до авто ~10 с; вкладка в фоне сильно режет таймеры — запас 2–3 мин. */
    private const STALE_EXECUTION_SECONDS = 180;

    public const REASON_FAIRY_BUSY = 'fairy_busy';
    public const REASON_EVENT_LOCKED = 'event_locked_other_fairy';
    public const REASON_NOT_ASSIGNED = 'event_not_assigned';
    public const REASON_NOT_FOUND = 'event_not_found';
    public const REASON_STANDARD_DISABLED = 'standard_not_enabled';
    public const REASON_ALL_FAIRIES_BUSY = 'all_fairies_busy';

    /** Зарезервированный ключ: то же событие, что и остальные; авто после загрузки, если назначено фее. */
    private const STANDARD_EVENT_KEY = '_standard';

    public function __construct(
        private readonly Database $db,
        private readonly string $appUrl,
    ) {
    }

    public function serve(Request $request): Response
    {
        $token = trim((string) ($request->query['token'] ?? ''));
        if ($token === '') {
            return Response::text('console.error("widget: missing token");', 400, [
                'Content-Type' => 'application/javascript; charset=utf-8',
            ]);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::text(
                'console.error("widget: invalid token or host mismatch");',
                403,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            );
        }

        $apiBase = $this->appUrl;
        $appId = (int) $appRow['application_id'];
        $chk = $this->db->pdo()->prepare(
            'SELECT 1 FROM widget_fairies f
             INNER JOIN fairy_events fe ON fe.fairy_id = f.id
             INNER JOIN widget_events we ON we.id = fe.widget_event_id AND we.event_key = ?
             WHERE f.application_id = ? LIMIT 1',
        );
        $chk->execute([self::STANDARD_EVENT_KEY, $appId]);
        $autoStandardWelcome = (bool) $chk->fetchColumn();
        $js = $this->buildWidgetJs($apiBase, $token, $autoStandardWelcome);
        return Response::text($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function eventBegin(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $eventKey = trim((string) ($b['event_key'] ?? ''));
        if ($token === '') {
            return Response::json(['error' => 'validation', 'message' => 'token обязателен'], 422);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $appId = (int) $appRow['application_id'];

        if ($eventKey === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $eventKey)) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(
                ['error' => 'validation', 'message' => 'session_key: 16–64 символа [a-zA-Z0-9_-]'],
                422,
            );
        }

        $pageUrl = trim((string) ($b['page_url'] ?? ''));

        return $this->beginEventForApplication($appId, $eventKey, $sessionKey, $token, $pageUrl);
    }

    public function surveyRate(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        $rating = isset($b['rating']) ? (int) $b['rating'] : 0;
        if ($token === '' || $executionId < 1 || $rating < 1 || $rating > 5) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(['error' => 'validation'], 422);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $appId = (int) $appRow['application_id'];
        $pageUrl = trim((string) ($b['page_url'] ?? ''));
        if (mb_strlen($pageUrl) > 2048) {
            $pageUrl = mb_substr($pageUrl, 0, 2048);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT x.id, x.widget_event_id, x.completed_at, we.action_type_id, we.survey_widget_id
             FROM widget_event_executions x
             INNER JOIN widget_fairies f ON f.id = x.fairy_id
             INNER JOIN widget_events we ON we.id = x.widget_event_id
             WHERE x.id = ? AND x.session_key = ? AND f.application_id = ? LIMIT 1',
        );
        $st->execute([$executionId, $sessionKey, $appId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['action_type_id'] !== EventAction::TYPE_ID_SURVEY) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ($row['completed_at'] !== null) {
            return Response::json(['ok' => true]);
        }
        $widgetEventId = (int) $row['widget_event_id'];
        $surveyWidgetId = (int) ($row['survey_widget_id'] ?? 0);
        $pdo->prepare(
            'INSERT INTO widget_survey_ratings
             (application_id, widget_event_id, survey_widget_id, execution_id, session_key, rating, page_url)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), page_url = VALUES(page_url),
             survey_widget_id = VALUES(survey_widget_id)',
        )->execute([
            $appId,
            $widgetEventId,
            $surveyWidgetId > 0 ? $surveyWidgetId : null,
            $executionId,
            $sessionKey,
            $rating,
            $pageUrl !== '' ? $pageUrl : null,
        ]);
        $this->completeExecutionById($pdo, $executionId, $sessionKey, $token);

        return Response::json(['ok' => true]);
    }

    public function videoDismiss(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        if ($token === '' || $executionId < 1) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(['error' => 'validation'], 422);
        }
        if ($this->resolveApplicationForEmbed($request, $token) === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        WidgetStats::recordVideoDismiss($pdo, $executionId);
        $this->completeExecutionById($pdo, $executionId, $sessionKey, $token);

        return Response::json(['ok' => true]);
    }

    public function surveyDismiss(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        if ($token === '' || $executionId < 1) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(['error' => 'validation'], 422);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $appId = (int) $appRow['application_id'];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT x.completed_at, we.id AS widget_event_id, we.survey_widget_id
             FROM widget_event_executions x
             INNER JOIN widget_fairies f ON f.id = x.fairy_id
             INNER JOIN widget_events we ON we.id = x.widget_event_id
             WHERE x.id = ? AND x.session_key = ? AND f.application_id = ? AND we.action_type_id = ?
             LIMIT 1',
        );
        $st->execute([$executionId, $sessionKey, $appId, EventAction::TYPE_ID_SURVEY]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $surveyWidgetId = (int) ($row['survey_widget_id'] ?? 0);
        if ($surveyWidgetId > 0 && $row['completed_at'] === null) {
            WidgetStats::recordSurveyCancel(
                $pdo,
                $appId,
                $surveyWidgetId,
                (int) $row['widget_event_id'],
                $executionId,
                $sessionKey,
            );
        }
        $this->completeExecutionById($pdo, $executionId, $sessionKey, $token);

        return Response::json(['ok' => true]);
    }

    public function videoProgress(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        $watchMs = isset($b['watch_duration_ms']) ? (int) $b['watch_duration_ms'] : 0;
        $completedFull = !empty($b['completed_full']);
        if ($token === '' || $executionId < 1) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(['error' => 'validation'], 422);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT x.id FROM widget_event_executions x
             INNER JOIN widget_fairies f ON f.id = x.fairy_id
             INNER JOIN widget_events we ON we.id = x.widget_event_id
             WHERE x.id = ? AND x.session_key = ? AND f.application_id = ? AND we.action_type_id = ?
             LIMIT 1',
        );
        $st->execute([$executionId, $sessionKey, (int) $appRow['application_id'], EventAction::TYPE_ID_VIDEO]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        WidgetStats::recordVideoProgress($pdo, $executionId, $watchMs, $completedFull);

        return Response::json(['ok' => true]);
    }

    public function videoLinkClick(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        if ($token === '' || $executionId < 1) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(['error' => 'validation'], 422);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT x.id FROM widget_event_executions x
             INNER JOIN widget_fairies f ON f.id = x.fairy_id
             WHERE x.id = ? AND x.session_key = ? AND f.application_id = ? LIMIT 1',
        );
        $st->execute([$executionId, $sessionKey, (int) $appRow['application_id']]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        WidgetStats::recordVideoLinkClick($pdo, $executionId);

        return Response::json(['ok' => true]);
    }

    public function eventComplete(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        if ($token === '' || $executionId < 1) {
            return Response::json(['error' => 'validation', 'message' => 'token и execution_id обязательны'], 422);
        }
        $sessionKey = $this->parseSessionKey($b);
        if ($sessionKey === null) {
            return Response::json(
                ['error' => 'validation', 'message' => 'session_key: 16–64 символа [a-zA-Z0-9_-]'],
                422,
            );
        }
        if ($this->resolveApplicationForEmbed($request, $token) === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'SELECT x.id AS ex_id, x.fairy_id, x.completed_at
                 FROM widget_event_executions x
                 INNER JOIN widget_fairies f ON f.id = x.fairy_id
                 INNER JOIN widget_applications a ON a.id = f.application_id
                 WHERE x.id = ? AND x.session_key = ? AND a.widget_token = ? AND a.status = ?
                 FOR UPDATE',
            );
            $st->execute([$executionId, $sessionKey, $token, 'approved']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();

                return Response::json(['error' => 'not_found'], 404);
            }
            $fairyId = (int) $row['fairy_id'];
            if ($row['completed_at'] !== null) {
                $pdo->rollBack();

                return Response::json(['ok' => true]);
            }
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([$executionId]);
            $pdo->prepare(
                'UPDATE widget_fairies SET current_execution_id = NULL WHERE id = ? AND current_execution_id = ?',
            )->execute([$fairyId, $executionId]);
            $pdo->commit();
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return Response::json(['error' => 'server'], 500);
        }

        return Response::json(['ok' => true]);
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

        return [
            'application_id' => (int) $row['application_id'],
            'site_url' => (string) $row['site_url'],
        ];
    }

    /** @param array<string, mixed> $b */
    private function parseSessionKey(array $b): ?string
    {
        $s = trim((string) ($b['session_key'] ?? ''));
        if ($s === '' || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $s)) {
            return null;
        }

        return $s;
    }

    private function firstFairyIdForLog(PDO $pdo, int $appId): int
    {
        $st = $pdo->prepare(
            'SELECT id FROM widget_fairies WHERE application_id = ? ORDER BY id ASC LIMIT 1',
        );
        $st->execute([$appId]);
        $id = $st->fetchColumn();

        return $id !== false ? (int) $id : 0;
    }

    private function beginEventForApplication(
        int $appId,
        string $eventKey,
        string $sessionKey,
        string $widgetToken,
        string $pageUrl = '',
    ): Response {
        $pdo = $this->db->pdo();
        $logFairy = $this->firstFairyIdForLog($pdo, $appId);
        if ($logFairy < 1) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $st = $pdo->prepare(
            'SELECT we.id, we.action_type_id, we.text_widget_id, we.survey_widget_id, we.video_widget_id,
                    we.pos_h_edge, we.pos_v_edge, we.pos_unit, we.pos_x, we.pos_y,
                    wat.code AS action_type_code, wat.label AS action_type_label
             FROM widget_events we
             INNER JOIN widget_action_types wat ON wat.id = we.action_type_id
             WHERE we.application_id = ? AND we.event_key = ? LIMIT 1',
        );
        $st->execute([$appId, $eventKey]);
        $ev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ev) {
            $this->insertFailure(
                $pdo,
                $appId,
                $logFairy,
                null,
                $eventKey,
                self::REASON_NOT_FOUND,
                'Событие с таким ключом не найдено',
                null,
            );

            return Response::json(['error' => 'conflict', 'reason' => self::REASON_NOT_FOUND], 409);
        }
        $widgetEventId = (int) $ev['id'];
        $landPosition = EventLandPosition::fromDbRow($ev);
        $type = EventAction::typeCodeFromRow($ev);
        $content = $this->loadContentForEvent($pdo, $appId, $type, $ev);
        if ($content === null) {
            $this->insertFailure(
                $pdo,
                $appId,
                $logFairy,
                $widgetEventId,
                $eventKey,
                self::REASON_NOT_FOUND,
                'Виджет не настроен для события',
                null,
            );

            return Response::json(['error' => 'conflict', 'reason' => self::REASON_NOT_FOUND], 409);
        }
        $videoUrl = null;
        if ($type === EventAction::TYPE_VIDEO) {
            $mediaId = (int) ($content['media_id'] ?? 0);
            if ($mediaId > 0) {
                $videoUrl = $this->widgetMediaPlayUrl($mediaId, $widgetToken);
            }
        }
        $action = EventAction::toWidgetPayload($type, $content, $videoUrl);
        $cst = $pdo->prepare(
            'SELECT f.id FROM widget_fairies f
             INNER JOIN fairy_events fe ON fe.fairy_id = f.id AND fe.widget_event_id = ?
             WHERE f.application_id = ?
             ORDER BY f.id ASC',
        );
        $cst->execute([$widgetEventId, $appId]);
        /** @var list<int|string> $fairyIds */
        $fairyIds = $cst->fetchAll(PDO::FETCH_COLUMN);
        if ($fairyIds === []) {
            $this->insertFailure(
                $pdo,
                $appId,
                $logFairy,
                $widgetEventId,
                $eventKey,
                self::REASON_NOT_ASSIGNED,
                'Событие не назначено ни одной фее',
                null,
            );

            return Response::json(['error' => 'conflict', 'reason' => self::REASON_NOT_ASSIGNED], 409);
        }

        $lockName = 'wevt_' . $widgetEventId . '_' . md5($sessionKey);
        $execId = 0;
        try {
            $pdo->beginTransaction();
            $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 8)')->fetchColumn();
            if ((int) $lk !== 1) {
                $pdo->rollBack();

                return Response::json(['error' => 'server', 'message' => 'lock'], 503);
            }
            try {
                $this->closeStaleOpenExecutionsForWidgetEvent($pdo, $widgetEventId);
                $ost = $pdo->prepare(
                    'SELECT id, fairy_id FROM widget_event_executions
                     WHERE widget_event_id = ? AND session_key = ? AND completed_at IS NULL LIMIT 1 FOR UPDATE',
                );
                $ost->execute([$widgetEventId, $sessionKey]);
                $other = $ost->fetch(PDO::FETCH_ASSOC);
                if ($other) {
                    $otherFairyId = (int) $other['fairy_id'];
                    $bk = $this->blockerEventKey($pdo, (int) $other['id']);
                    $logFairyId = $otherFairyId;
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                    $pdo->commit();
                    $this->insertFailure(
                        $pdo,
                        $appId,
                        $logFairyId,
                        $widgetEventId,
                        $eventKey,
                        self::REASON_EVENT_LOCKED,
                        'Это событие уже выполняется в этой сессии браузера (вкладка)',
                        [
                            'blocker_execution_id' => (int) $other['id'],
                            'blocker_fairy_id' => $otherFairyId,
                            'blocker_widget_event_id' => $widgetEventId,
                            'blocker_event_key' => $bk,
                        ],
                    );

                    return Response::json(
                        ['error' => 'conflict', 'reason' => self::REASON_EVENT_LOCKED],
                        409,
                    );
                }
                foreach ($fairyIds as $fidRaw) {
                    $fairyId = (int) $fidRaw;
                    $this->releaseStaleExecution($pdo, $fairyId);
                    $fst = $pdo->prepare(
                        'SELECT id FROM widget_fairies WHERE id = ? FOR UPDATE',
                    );
                    $fst->execute([$fairyId]);
                    if (!$fst->fetch(PDO::FETCH_ASSOC)) {
                        continue;
                    }
                    $busy = $pdo->prepare(
                        'SELECT id FROM widget_event_executions
                         WHERE fairy_id = ? AND session_key = ? AND completed_at IS NULL LIMIT 1 FOR UPDATE',
                    );
                    $busy->execute([$fairyId, $sessionKey]);
                    if ($busy->fetch(PDO::FETCH_ASSOC)) {
                        continue;
                    }
                    $pdo->prepare(
                        'INSERT INTO widget_event_executions (fairy_id, widget_event_id, kind, session_key) VALUES (?,?,?,?)',
                    )->execute([$fairyId, $widgetEventId, 'event', $sessionKey]);
                    $execId = (int) $pdo->lastInsertId();
                    WidgetStats::recordImpression(
                        $pdo,
                        $appId,
                        $widgetEventId,
                        $execId,
                        $sessionKey,
                        $ev,
                        $pageUrl !== '' ? $pageUrl : null,
                    );
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                    $pdo->commit();

                    return Response::json([
                        'execution_id' => $execId,
                        'position' => $landPosition,
                        'action' => $action,
                    ]);
                }
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                $pdo->commit();
                $this->insertFailure(
                    $pdo,
                    $appId,
                    (int) $fairyIds[0],
                    $widgetEventId,
                    $eventKey,
                    self::REASON_ALL_FAIRIES_BUSY,
                    'В этой сессии все подходящие феи уже заняты показом (или нет свободной для этой сессии)',
                    null,
                );

                return Response::json(['error' => 'conflict', 'reason' => self::REASON_ALL_FAIRIES_BUSY], 409);
            } catch (PDOException $e) {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                throw $e;
            }
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return Response::json(['error' => 'server'], 500);
        }

        return Response::json(['error' => 'server'], 500);
    }

    private function blockerEventKey(PDO $pdo, int $executionId): ?string
    {
        $st = $pdo->prepare(
            'SELECT e.event_key FROM widget_event_executions x
             LEFT JOIN widget_events e ON e.id = x.widget_event_id
             WHERE x.id = ? LIMIT 1',
        );
        $st->execute([$executionId]);
        $k = $st->fetchColumn();

        return $k !== false ? (string) $k : null;
    }

    private function logFailureFromFairyBusy(
        PDO $pdo,
        int $appId,
        int $fairyId,
        string $attemptedKey,
        int $blockerExecutionId,
        ?int $attemptedWidgetEventId = null,
    ): void {
        $bst = $pdo->prepare(
            'SELECT fairy_id, widget_event_id FROM widget_event_executions WHERE id = ? LIMIT 1',
        );
        $bst->execute([$blockerExecutionId]);
        $b = $bst->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return;
        }
        $bk = $this->blockerEventKey($pdo, $blockerExecutionId);
        $detail = ($bk === self::STANDARD_EVENT_KEY)
            ? 'Фея выполняла стандартное приветствие'
            : ('Фея выполняла событие' . ($bk !== null && $bk !== '' ? ' «' . $bk . '»' : ''));
        $this->insertFailure(
            $pdo,
            $appId,
            $fairyId,
            $attemptedWidgetEventId,
            $attemptedKey !== '' ? $attemptedKey : '(стандартное)',
            self::REASON_FAIRY_BUSY,
            $detail,
            [
                'blocker_execution_id' => $blockerExecutionId,
                'blocker_fairy_id' => (int) $b['fairy_id'],
                'blocker_widget_event_id' => $b['widget_event_id'] !== null ? (int) $b['widget_event_id'] : null,
                'blocker_event_key' => $bk,
            ],
        );
    }

    /** @param array<string, mixed>|null $blocker */
    private function insertFailure(
        PDO $pdo,
        int $appId,
        int $fairyId,
        ?int $widgetEventId,
        string $eventKey,
        string $reasonCode,
        string $detail,
        ?array $blocker,
    ): void {
        $blocker = $blocker ?? [];
        $ins = $pdo->prepare(
            'INSERT INTO widget_event_failures (
                application_id, fairy_id, widget_event_id, event_key, reason_code, detail,
                blocker_execution_id, blocker_fairy_id, blocker_widget_event_id, blocker_event_key
            ) VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $ins->execute([
            $appId,
            $fairyId,
            $widgetEventId,
            $eventKey,
            $reasonCode,
            $detail,
            $blocker['blocker_execution_id'] ?? null,
            $blocker['blocker_fairy_id'] ?? null,
            $blocker['blocker_widget_event_id'] ?? null,
            $blocker['blocker_event_key'] ?? null,
        ]);
    }

    /** Закрывает «забытые» выполнения по этому событию (вкладка закрыта без event-complete), чтобы не блокировать ключ. */
    private function closeStaleOpenExecutionsForWidgetEvent(PDO $pdo, int $widgetEventId): void
    {
        $st = $pdo->prepare(
            'SELECT id, fairy_id, started_at FROM widget_event_executions
             WHERE widget_event_id = ? AND completed_at IS NULL FOR UPDATE',
        );
        $st->execute([$widgetEventId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $now = time();
        foreach ($rows as $row) {
            $started = strtotime((string) $row['started_at']);
            if ($started === false || ($now - $started) <= self::STALE_EXECUTION_SECONDS) {
                continue;
            }
            $eid = (int) $row['id'];
            $fid = (int) $row['fairy_id'];
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ? AND completed_at IS NULL',
            )->execute([$eid]);
            $pdo->prepare(
                'UPDATE widget_fairies SET current_execution_id = NULL WHERE id = ? AND current_execution_id = ?',
            )->execute([$fid, $eid]);
        }
    }

    /** Протухшие открытые выполнения по фее (любая сессия) + сброс устаревшего current_execution_id. */
    private function releaseStaleExecution(PDO $pdo, int $fairyId): void
    {
        $pdo->prepare('SELECT id FROM widget_fairies WHERE id = ? FOR UPDATE')->execute([$fairyId]);
        $st = $pdo->prepare(
            'SELECT id, started_at FROM widget_event_executions
             WHERE fairy_id = ? AND completed_at IS NULL FOR UPDATE',
        );
        $st->execute([$fairyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $now = time();
        foreach ($rows as $row) {
            $started = strtotime((string) $row['started_at']);
            if ($started === false || ($now - $started) <= self::STALE_EXECUTION_SECONDS) {
                continue;
            }
            $eid = (int) $row['id'];
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ? AND completed_at IS NULL',
            )->execute([$eid]);
        }
        $pdo->prepare(
            'UPDATE widget_fairies f SET f.current_execution_id = NULL
             WHERE f.id = ?
             AND f.current_execution_id IS NOT NULL
             AND NOT EXISTS (
               SELECT 1 FROM widget_event_executions x
               WHERE x.id = f.current_execution_id AND x.completed_at IS NULL
             )',
        )->execute([$fairyId]);
    }

    /**
     * @param array<string, mixed> $ev
     * @return array<string, mixed>|null
     */
    private function loadContentForEvent(PDO $pdo, int $appId, string $type, array $ev): ?array
    {
        if ($type === EventAction::TYPE_TEXT) {
            $id = (int) ($ev['text_widget_id'] ?? 0);
            if ($id < 1) {
                return null;
            }
            $st = $pdo->prepare(
                'SELECT body FROM widget_text_widgets WHERE id = ? AND application_id = ? LIMIT 1',
            );
            $st->execute([$id, $appId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ? ['body' => (string) $row['body']] : null;
        }
        if ($type === EventAction::TYPE_SURVEY) {
            $id = (int) ($ev['survey_widget_id'] ?? 0);
            if ($id < 1) {
                return null;
            }
            $st = $pdo->prepare(
                'SELECT title, description, dismiss_after_ms FROM widget_survey_widgets
                 WHERE id = ? AND application_id = ? LIMIT 1',
            );
            $st->execute([$id, $appId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ? [
                'title' => (string) $row['title'],
                'description' => $row['description'],
                'dismiss_after_ms' => $row['dismiss_after_ms'],
            ] : null;
        }
        if ($type === EventAction::TYPE_VIDEO) {
            $id = (int) ($ev['video_widget_id'] ?? 0);
            if ($id < 1) {
                return null;
            }
            $st = $pdo->prepare(
                'SELECT vw.media_id, vw.link_url, vw.leave_mode, vw.leave_timer_ms, ma.duration_ms
                 FROM widget_video_widgets vw
                 INNER JOIN widget_media_assets ma ON ma.id = vw.media_id
                 WHERE vw.id = ? AND vw.application_id = ? LIMIT 1',
            );
            $st->execute([$id, $appId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ? [
                'media_id' => (int) $row['media_id'],
                'link_url' => $row['link_url'],
                'leave_mode' => (string) ($row['leave_mode'] ?? 'video_end'),
                'leave_timer_ms' => $row['leave_timer_ms'],
                'duration_ms' => $row['duration_ms'],
            ] : null;
        }

        return null;
    }

    private function widgetMediaPlayUrl(int $mediaId, string $widgetToken): string
    {
        return rtrim($this->appUrl, '/') . '/widget/media/' . $mediaId . '?token=' . rawurlencode($widgetToken);
    }

    private function completeExecutionById(PDO $pdo, int $executionId, string $sessionKey, string $token): void
    {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'SELECT x.id AS ex_id, x.fairy_id, x.completed_at
                 FROM widget_event_executions x
                 INNER JOIN widget_fairies f ON f.id = x.fairy_id
                 INNER JOIN widget_applications a ON a.id = f.application_id
                 WHERE x.id = ? AND x.session_key = ? AND a.widget_token = ? AND a.status = ?
                 FOR UPDATE',
            );
            $st->execute([$executionId, $sessionKey, $token, 'approved']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['completed_at'] !== null) {
                $pdo->rollBack();

                return;
            }
            $fairyId = (int) $row['fairy_id'];
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([$executionId]);
            $pdo->prepare(
                'UPDATE widget_fairies SET current_execution_id = NULL WHERE id = ? AND current_execution_id = ?',
            )->execute([$fairyId, $executionId]);
            $pdo->commit();
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function buildWidgetJs(
        string $apiBase,
        string $widgetToken,
        bool $autoStandardWelcome,
    ): string {
        $path = dirname(__DIR__, 2) . '/resources/widget-runtime.js';
        if (!is_readable($path)) {
            return 'console.error("widget: runtime missing");';
        }
        $js = (string) file_get_contents($path);
        $replacements = [
            '{{API}}' => addslashes($apiBase),
            '{{TOKEN}}' => addslashes($widgetToken),
            '{{AUTO_STANDARD}}' => $autoStandardWelcome ? 'true' : 'false',
            '{{VERSION}}' => '11',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $js);
    }
}
