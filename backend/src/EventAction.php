<?php

declare(strict_types=1);

namespace App;

final class EventAction
{
    public const TYPE_TEXT = 'text';
    public const TYPE_SURVEY = 'survey';
    public const TYPE_VIDEO = 'video';

    public const TYPE_ID_TEXT = 1;
    public const TYPE_ID_SURVEY = 2;
    public const TYPE_ID_VIDEO = 3;

    /** @param array<string, mixed> $row */
    public static function typeCodeFromRow(array $row): string
    {
        $code = (string) ($row['action_type_code'] ?? '');
        if ($code !== '') {
            return self::normalizeTypeCode($code);
        }
        $id = (int) ($row['action_type_id'] ?? self::TYPE_ID_TEXT);

        return self::codeFromTypeId($id);
    }

    public static function codeFromTypeId(int $id): string
    {
        return match ($id) {
            self::TYPE_ID_SURVEY => self::TYPE_SURVEY,
            self::TYPE_ID_VIDEO => self::TYPE_VIDEO,
            default => self::TYPE_TEXT,
        };
    }

    public static function typeIdFromCode(string $code): int
    {
        return match (self::normalizeTypeCode($code)) {
            self::TYPE_SURVEY => self::TYPE_ID_SURVEY,
            self::TYPE_VIDEO => self::TYPE_ID_VIDEO,
            default => self::TYPE_ID_TEXT,
        };
    }

    public static function normalizeTypeCode(string $code): string
    {
        $c = strtolower(trim($code));

        return match ($c) {
            self::TYPE_SURVEY => self::TYPE_SURVEY,
            self::TYPE_VIDEO => self::TYPE_VIDEO,
            default => self::TYPE_TEXT,
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   type_id: int,
     *   type_code: string,
     *   text_widget_id: ?int,
     *   survey_widget_id: ?int,
     *   video_widget_id: ?int
     * }|null
     */
    public static function parseEventLink(array $input, string $eventKey): ?array
    {
        $typeCode = self::normalizeTypeCode((string) ($input['action_type'] ?? self::TYPE_TEXT));
        if ($eventKey === '_standard') {
            $typeCode = self::TYPE_TEXT;
        }
        $widgetId = isset($input['widget_id']) ? (int) $input['widget_id'] : 0;
        if ($widgetId < 1) {
            return null;
        }

        return [
            'type_id' => self::typeIdFromCode($typeCode),
            'type_code' => $typeCode,
            'text_widget_id' => $typeCode === self::TYPE_TEXT ? $widgetId : null,
            'survey_widget_id' => $typeCode === self::TYPE_SURVEY ? $widgetId : null,
            'video_widget_id' => $typeCode === self::TYPE_VIDEO ? $widgetId : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toApiPayload(array $row): array
    {
        $type = self::typeCodeFromRow($row);
        $base = [
            'action_type' => $type,
            'action_type_label' => (string) ($row['action_type_label'] ?? $type),
        ];

        if ($type === self::TYPE_SURVEY) {
            $base['widget_id'] = (int) ($row['survey_widget_id'] ?? 0);
            $base['widget_name'] = (string) ($row['survey_widget_name'] ?? '');
            $base['survey_title'] = (string) ($row['survey_title'] ?? '');
            $base['survey_description'] = $row['survey_description'] !== null
                ? (string) $row['survey_description'] : null;
        } elseif ($type === self::TYPE_VIDEO) {
            $base['widget_id'] = (int) ($row['video_widget_id'] ?? 0);
            $base['widget_name'] = (string) ($row['video_widget_name'] ?? '');
            $base['media_id'] = isset($row['video_media_id']) ? (int) $row['video_media_id'] : null;
            $base['video_link_url'] = $row['video_link_url'] !== null && $row['video_link_url'] !== ''
                ? (string) $row['video_link_url'] : null;
        } else {
            $base['widget_id'] = (int) ($row['text_widget_id'] ?? 0);
            $base['widget_name'] = (string) ($row['text_widget_name'] ?? '');
            $base['phrase'] = (string) ($row['text_body'] ?? '');
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $contentRow joined widget content
     * @return array<string, mixed>
     */
    public static function toWidgetPayload(string $type, array $contentRow, ?string $videoPlayUrl = null): array
    {
        if ($type === self::TYPE_SURVEY) {
            $payload = [
                'type' => self::TYPE_SURVEY,
                'survey_title' => (string) ($contentRow['title'] ?? ''),
            ];
            $desc = $contentRow['description'] ?? null;
            if ($desc !== null && (string) $desc !== '') {
                $payload['survey_description'] = (string) $desc;
            }
            $dismissMs = $contentRow['dismiss_after_ms'] ?? null;
            if ($dismissMs !== null && (int) $dismissMs > 0) {
                $payload['dismiss_after_ms'] = (int) $dismissMs;
            }

            return $payload;
        }
        if ($type === self::TYPE_VIDEO) {
            $payload = ['type' => self::TYPE_VIDEO];
            if ($videoPlayUrl !== null && $videoPlayUrl !== '') {
                $payload['video_url'] = $videoPlayUrl;
            }
            $link = $contentRow['link_url'] ?? null;
            if ($link !== null && (string) $link !== '') {
                $payload['video_link_url'] = (string) $link;
            }
            $durationMs = $contentRow['duration_ms'] ?? null;
            if ($durationMs !== null && (int) $durationMs > 0) {
                $payload['duration_ms'] = (int) $durationMs;
            } else {
                $payload['duration_unknown'] = true;
            }
            $leaveMode = (string) ($contentRow['leave_mode'] ?? 'video_end');
            $payload['leave_mode'] = $leaveMode === 'timer' ? 'timer' : 'video_end';
            $leaveTimerMs = $contentRow['leave_timer_ms'] ?? null;
            if ($payload['leave_mode'] === 'timer' && $leaveTimerMs !== null && (int) $leaveTimerMs > 0) {
                $payload['leave_timer_ms'] = (int) $leaveTimerMs;
            }

            return $payload;
        }

        return [
            'type' => self::TYPE_TEXT,
            'phrase' => (string) ($contentRow['body'] ?? ''),
        ];
    }

    public static function isValidHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
