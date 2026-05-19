<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;

final class WidgetTiming
{
    public const VIDEO_LEAVE_VIDEO_END = 'video_end';
    public const VIDEO_LEAVE_TIMER = 'timer';

    public static function parseDurationMsFromUpload(Request $request): ?int
    {
        $raw = $_POST['duration_ms'] ?? null;
        if ($raw === null && is_array($request->body)) {
            $raw = $request->body['duration_ms'] ?? null;
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        $ms = (int) $raw;

        return ($ms >= 1 && $ms <= 86400000) ? $ms : null;
    }

    /**
     * @param array<string, mixed> $b
     * @return array{leave_mode: string, leave_timer_ms: ?int}|null
     */
    public static function parseVideoLeave(array $b): ?array
    {
        $mode = strtolower(trim((string) ($b['leave_mode'] ?? self::VIDEO_LEAVE_VIDEO_END)));
        if ($mode !== self::VIDEO_LEAVE_VIDEO_END && $mode !== self::VIDEO_LEAVE_TIMER) {
            return null;
        }
        $timerMs = null;
        if ($mode === self::VIDEO_LEAVE_TIMER) {
            $sec = isset($b['leave_timer_sec']) ? (int) $b['leave_timer_sec'] : 0;
            if ($sec < 1 || $sec > 86400) {
                return null;
            }
            $timerMs = $sec * 1000;
        }

        return ['leave_mode' => $mode, 'leave_timer_ms' => $timerMs];
    }

    /**
     * @param array<string, mixed> $b
     */
    public static function parseDismissAfterMs(array $b): ?int
    {
        if (!array_key_exists('dismiss_after_sec', $b)) {
            return null;
        }
        $sec = (int) ($b['dismiss_after_sec'] ?? 0);
        if ($sec < 1) {
            return null;
        }
        if ($sec > 86400) {
            $sec = 86400;
        }

        return $sec * 1000;
    }
}
