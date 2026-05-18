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
     *   phrase: string,
     *   survey_title: ?string,
     *   video_media_id: ?int,
     *   video_link_url: ?string
     * }|null
     */
    public static function parseEventInput(array $input, string $eventKey): ?array
    {
        $typeCode = self::normalizeTypeCode((string) ($input['action_type'] ?? self::TYPE_TEXT));
        if ($eventKey === '_standard') {
            $typeCode = self::TYPE_TEXT;
        }
        $phrase = trim((string) ($input['phrase'] ?? ''));
        $surveyTitle = trim((string) ($input['survey_title'] ?? ''));
        $videoMediaId = isset($input['video_media_id']) ? (int) $input['video_media_id'] : null;
        if ($videoMediaId !== null && $videoMediaId < 1) {
            $videoMediaId = null;
        }
        $videoLinkUrl = trim((string) ($input['video_link_url'] ?? ''));
        if ($videoLinkUrl !== '' && !self::isValidHttpUrl($videoLinkUrl)) {
            return null;
        }
        if ($videoLinkUrl === '') {
            $videoLinkUrl = null;
        }

        return match ($typeCode) {
            self::TYPE_SURVEY => self::parseSurvey($phrase, $surveyTitle),
            self::TYPE_VIDEO => self::parseVideo($phrase, $videoMediaId, $videoLinkUrl),
            default => self::parseText($phrase),
        };
    }

    /** @return array{type_id: int, phrase: string, survey_title: ?string, video_media_id: ?int, video_link_url: ?string}|null */
    private static function parseText(string $phrase): ?array
    {
        if ($phrase === '') {
            return null;
        }
        if (mb_strlen($phrase) > 2000) {
            return null;
        }

        return [
            'type_id' => self::TYPE_ID_TEXT,
            'phrase' => $phrase,
            'survey_title' => null,
            'video_media_id' => null,
            'video_link_url' => null,
        ];
    }

    /** @return array{type_id: int, phrase: string, survey_title: ?string, video_media_id: ?int, video_link_url: ?string}|null */
    private static function parseSurvey(string $phrase, string $surveyTitle): ?array
    {
        if ($surveyTitle === '' || mb_strlen($surveyTitle) > 512) {
            return null;
        }
        if ($phrase !== '' && mb_strlen($phrase) > 2000) {
            return null;
        }

        return [
            'type_id' => self::TYPE_ID_SURVEY,
            'phrase' => $phrase !== '' ? $phrase : $surveyTitle,
            'survey_title' => $surveyTitle,
            'video_media_id' => null,
            'video_link_url' => null,
        ];
    }

    /**
     * @return array{type_id: int, phrase: string, survey_title: ?string, video_media_id: ?int, video_link_url: ?string}|null
     */
    private static function parseVideo(string $phrase, ?int $videoMediaId, ?string $videoLinkUrl): ?array
    {
        if ($videoMediaId === null || $videoMediaId < 1) {
            return null;
        }
        if ($phrase !== '' && mb_strlen($phrase) > 2000) {
            return null;
        }

        return [
            'type_id' => self::TYPE_ID_VIDEO,
            'phrase' => $phrase !== '' ? $phrase : 'Видео',
            'survey_title' => null,
            'video_media_id' => $videoMediaId,
            'video_link_url' => $videoLinkUrl,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toApiPayload(array $row, ?string $videoPlayUrl = null): array
    {
        $type = self::typeCodeFromRow($row);
        $base = [
            'action_type' => $type,
            'action_type_label' => (string) ($row['action_type_label'] ?? $type),
        ];
        if ($type === self::TYPE_SURVEY) {
            $base['survey_title'] = (string) ($row['survey_title'] ?? '');
            $base['phrase'] = (string) ($row['phrase'] ?? '');
        } elseif ($type === self::TYPE_VIDEO) {
            $base['video_media_id'] = isset($row['video_media_id']) ? (int) $row['video_media_id'] : null;
            $base['video_link_url'] = $row['video_link_url'] !== null && $row['video_link_url'] !== ''
                ? (string) $row['video_link_url'] : null;
            $base['phrase'] = (string) ($row['phrase'] ?? 'Видео');
            if ($videoPlayUrl !== null) {
                $base['video_url'] = $videoPlayUrl;
            }
        } else {
            $base['phrase'] = (string) ($row['phrase'] ?? '');
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWidgetPayload(array $row, ?string $videoPlayUrl = null): array
    {
        $type = self::typeCodeFromRow($row);
        if ($type === self::TYPE_SURVEY) {
            return [
                'type' => self::TYPE_SURVEY,
                'survey_title' => (string) ($row['survey_title'] ?? $row['phrase'] ?? ''),
            ];
        }
        if ($type === self::TYPE_VIDEO) {
            $payload = ['type' => self::TYPE_VIDEO];
            if ($videoPlayUrl !== null && $videoPlayUrl !== '') {
                $payload['video_url'] = $videoPlayUrl;
            }
            $link = $row['video_link_url'] ?? null;
            if ($link !== null && (string) $link !== '') {
                $payload['video_link_url'] = (string) $link;
            }

            return $payload;
        }

        return [
            'type' => self::TYPE_TEXT,
            'phrase' => (string) ($row['phrase'] ?? ''),
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
