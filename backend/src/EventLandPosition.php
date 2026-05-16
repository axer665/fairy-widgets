<?php

declare(strict_types=1);

namespace App;

/**
 * Точка приземления феи: отступы от выбранных краёв viewport (px или %).
 */
final class EventLandPosition
{
    public const H_LEFT = 'left';
    public const H_RIGHT = 'right';
    public const V_TOP = 'top';
    public const V_BOTTOM = 'bottom';
    public const UNIT_PX = 'px';
    public const UNIT_PERCENT = 'percent';

    public const DEFAULT_H = self::H_RIGHT;
    public const DEFAULT_V = self::V_BOTTOM;
    public const DEFAULT_UNIT = self::UNIT_PX;
    public const DEFAULT_X = 150.0;
    public const DEFAULT_Y = 130.0;

    /** @return array{horizontal: string, vertical: string, unit: string, x: float, y: float} */
    public static function defaults(): array
    {
        return [
            'horizontal' => self::DEFAULT_H,
            'vertical' => self::DEFAULT_V,
            'unit' => self::DEFAULT_UNIT,
            'x' => self::DEFAULT_X,
            'y' => self::DEFAULT_Y,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{horizontal: string, vertical: string, unit: string, x: float, y: float}
     */
    public static function fromDbRow(array $row): array
    {
        return [
            'horizontal' => self::normalizeHorizontal((string) ($row['pos_h_edge'] ?? self::DEFAULT_H)),
            'vertical' => self::normalizeVertical((string) ($row['pos_v_edge'] ?? self::DEFAULT_V)),
            'unit' => self::normalizeUnit((string) ($row['pos_unit'] ?? self::DEFAULT_UNIT)),
            'x' => (float) ($row['pos_x'] ?? self::DEFAULT_X),
            'y' => (float) ($row['pos_y'] ?? self::DEFAULT_Y),
        ];
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array{horizontal: string, vertical: string, unit: string, x: float, y: float}|null
     */
    public static function parse(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $h = self::normalizeHorizontal((string) ($input['horizontal'] ?? $input['h_edge'] ?? ''));
        $v = self::normalizeVertical((string) ($input['vertical'] ?? $input['v_edge'] ?? ''));
        $unit = self::normalizeUnit((string) ($input['unit'] ?? ''));
        if (!array_key_exists('x', $input) || !array_key_exists('y', $input)) {
            return null;
        }
        if (!is_numeric($input['x']) || !is_numeric($input['y'])) {
            return null;
        }
        $x = (float) $input['x'];
        $y = (float) $input['y'];
        if ($x < 0 || $y < 0) {
            return null;
        }
        if ($unit === self::UNIT_PERCENT && ($x > 100 || $y > 100)) {
            return null;
        }
        if ($unit === self::UNIT_PX && ($x > 10000 || $y > 10000)) {
            return null;
        }

        return [
            'horizontal' => $h,
            'vertical' => $v,
            'unit' => $unit,
            'x' => $x,
            'y' => $y,
        ];
    }

    /** @param array{horizontal: string, vertical: string, unit: string, x: float, y: float} $pos */
    public static function toDbParams(array $pos): array
    {
        return [
            'pos_h_edge' => $pos['horizontal'],
            'pos_v_edge' => $pos['vertical'],
            'pos_unit' => $pos['unit'],
            'pos_x' => $pos['x'],
            'pos_y' => $pos['y'],
        ];
    }

    private static function normalizeHorizontal(string $v): string
    {
        return $v === self::H_LEFT ? self::H_LEFT : self::H_RIGHT;
    }

    private static function normalizeVertical(string $v): string
    {
        return $v === self::V_TOP ? self::V_TOP : self::V_BOTTOM;
    }

    private static function normalizeUnit(string $v): string
    {
        return $v === self::UNIT_PERCENT ? self::UNIT_PERCENT : self::UNIT_PX;
    }
}
