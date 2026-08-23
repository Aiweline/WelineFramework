<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Disk;

/**
 * Theme-level disk Meta key helpers (整盘).
 */
final class ThemeDiskKeys
{
    public const PANEL_COLOR = 'color';
    public const PANEL_TYPOGRAPHY = 'typography';
    public const PANEL_SPACING = 'spacing';
    public const PANEL_BORDERS = 'borders';
    public const PANEL_SHADOWS = 'shadows';
    public const PANEL_AUTH = 'auth';

    public static function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    public static function normalizeScope(string $scope): string
    {
        $scope = trim($scope);

        return $scope !== '' ? $scope : 'default';
    }

    public static function normalizePanel(string $panel): string
    {
        $panel = strtolower(trim($panel));

        return preg_replace('/[^a-z0-9_-]+/', '', $panel) ?: 'color';
    }

    public static function activeIdentify(string $area, string $panel): string
    {
        $area = self::normalizeArea($area);
        $panel = self::normalizePanel($panel);

        return "theme.{$area}.disk_active.{$panel}.value";
    }

    public static function customIdentify(string $area, string $panel, string $diskKey): string
    {
        $area = self::normalizeArea($area);
        $panel = self::normalizePanel($panel);
        $diskKey = self::normalizeDiskKey($diskKey);

        return "theme.{$area}.disk_custom.{$panel}.{$diskKey}.value";
    }

    public static function bundleIdentify(string $area, string $scope = 'default'): string
    {
        $area = self::normalizeArea($area);
        $scope = self::normalizeScope($scope);

        return "theme.{$area}.disk_bundle.{$scope}.value";
    }

    public static function normalizeDiskKey(string $diskKey): string
    {
        $diskKey = strtolower(trim($diskKey));
        $diskKey = preg_replace('/[^a-z0-9_-]+/', '-', $diskKey) ?: 'disk';

        return trim($diskKey, '-') ?: 'disk';
    }

    public static function parseActiveRef(string $ref): array
    {
        $ref = trim($ref);
        if (str_starts_with($ref, 'custom:')) {
            return ['kind' => 'custom', 'key' => self::normalizeDiskKey(substr($ref, 7))];
        }
        if (str_starts_with($ref, 'file:')) {
            return ['kind' => 'file', 'key' => ltrim(substr($ref, 5), '_')];
        }
        if ($ref !== '') {
            return ['kind' => 'file', 'key' => ltrim($ref, '_')];
        }

        return ['kind' => '', 'key' => ''];
    }

    public static function fileRef(string $key): string
    {
        return 'file:' . ltrim($key, '_');
    }

    public static function customRef(string $diskKey): string
    {
        return 'custom:' . self::normalizeDiskKey($diskKey);
    }
}
