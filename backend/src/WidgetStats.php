<?php

declare(strict_types=1);

namespace App;

use App\EventAction;
use PDO;

final class WidgetStats
{
    /** @param array<string, mixed> $eventRow */
    public static function recordImpression(
        PDO $pdo,
        int $appId,
        int $widgetEventId,
        int $executionId,
        string $sessionKey,
        array $eventRow,
        ?string $pageUrl,
    ): void {
        $type = EventAction::typeCodeFromRow($eventRow);
        $page = self::trimPageUrl($pageUrl);

        if ($type === EventAction::TYPE_TEXT) {
            $wid = (int) ($eventRow['text_widget_id'] ?? 0);
            if ($wid < 1) {
                return;
            }
            $pdo->prepare(
                'INSERT IGNORE INTO widget_text_impressions
                 (application_id, text_widget_id, widget_event_id, execution_id, session_key, page_url)
                 VALUES (?,?,?,?,?,?)',
            )->execute([$appId, $wid, $widgetEventId, $executionId, $sessionKey, $page]);

            return;
        }
        if ($type === EventAction::TYPE_SURVEY) {
            $wid = (int) ($eventRow['survey_widget_id'] ?? 0);
            if ($wid < 1) {
                return;
            }
            $pdo->prepare(
                'INSERT IGNORE INTO widget_survey_impressions
                 (application_id, survey_widget_id, widget_event_id, execution_id, session_key, page_url)
                 VALUES (?,?,?,?,?,?)',
            )->execute([$appId, $wid, $widgetEventId, $executionId, $sessionKey, $page]);

            return;
        }
        if ($type === EventAction::TYPE_VIDEO) {
            $wid = (int) ($eventRow['video_widget_id'] ?? 0);
            if ($wid < 1) {
                return;
            }
            $pdo->prepare(
                'INSERT IGNORE INTO widget_video_impressions
                 (application_id, video_widget_id, widget_event_id, execution_id, session_key, page_url)
                 VALUES (?,?,?,?,?,?)',
            )->execute([$appId, $wid, $widgetEventId, $executionId, $sessionKey, $page]);
            $pdo->prepare(
                'INSERT IGNORE INTO widget_video_sessions
                 (application_id, video_widget_id, widget_event_id, execution_id, session_key)
                 VALUES (?,?,?,?,?)',
            )->execute([$appId, $wid, $widgetEventId, $executionId, $sessionKey]);
        }
    }

    public static function recordSurveyCancel(
        PDO $pdo,
        int $appId,
        int $surveyWidgetId,
        int $widgetEventId,
        int $executionId,
        string $sessionKey,
    ): void {
        $pdo->prepare(
            'INSERT IGNORE INTO widget_survey_cancellations
             (application_id, survey_widget_id, widget_event_id, execution_id, session_key)
             VALUES (?,?,?,?,?)',
        )->execute([$appId, $surveyWidgetId, $widgetEventId, $executionId, $sessionKey]);
    }

    public static function recordVideoDismiss(PDO $pdo, int $executionId): void
    {
        $pdo->prepare(
            'UPDATE widget_video_sessions SET dismissed = 1 WHERE execution_id = ?',
        )->execute([$executionId]);
    }

    public static function recordVideoProgress(
        PDO $pdo,
        int $executionId,
        int $watchMs,
        bool $completedFull,
    ): void {
        $watchMs = max(0, min($watchMs, 86400000));
        $pdo->prepare(
            'UPDATE widget_video_sessions
             SET watch_duration_ms = GREATEST(watch_duration_ms, ?),
                 completed_full = GREATEST(completed_full, ?)
             WHERE execution_id = ?',
        )->execute([$watchMs, $completedFull ? 1 : 0, $executionId]);
    }

    public static function recordVideoLinkClick(PDO $pdo, int $executionId): void
    {
        $pdo->prepare(
            'UPDATE widget_video_sessions SET link_clicked = 1 WHERE execution_id = ?',
        )->execute([$executionId]);
    }

    private static function trimPageUrl(?string $pageUrl): ?string
    {
        $pageUrl = trim((string) $pageUrl);
        if ($pageUrl === '') {
            return null;
        }
        if (mb_strlen($pageUrl) > 2048) {
            return mb_substr($pageUrl, 0, 2048);
        }

        return $pageUrl;
    }
}
