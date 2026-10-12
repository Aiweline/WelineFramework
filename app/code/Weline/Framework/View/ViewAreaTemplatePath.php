<?php
declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * 按请求区在 templates/ 下一层挂 frontend|backend。
 * 只管区层命名与拼接；短路径业务结构不管。
 */
final class ViewAreaTemplatePath
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';

    public static function normalizeArea(?string $area): string
    {
        $area = strtolower(trim((string)$area));

        return $area === self::AREA_BACKEND ? self::AREA_BACKEND : self::AREA_FRONTEND;
    }

    /**
     * 剥掉 controller_name 前导区段 Backend|Frontend（大小写不敏感）。
     * 例：Backend/Log → Log；frontend/account → account。
     */
    public static function stripLeadingAreaSegment(string $controllerName): string
    {
        $normalized = str_replace('\\', '/', trim($controllerName));
        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            return '';
        }
        if (preg_match('#^(backend|frontend)(?:/|$)#i', $normalized, $m) === 1) {
            return ltrim(substr($normalized, strlen($m[1])), '/');
        }

        return $normalized;
    }

    /**
     * @return non-empty-string templates/{area}/{relative}
     */
    public static function joinTemplates(string $area, string $relative): string
    {
        $area = self::normalizeArea($area);
        $relative = str_replace('\\', '/', ltrim($relative, '/'));

        return $relative === ''
            ? 'templates/' . $area
            : 'templates/' . $area . '/' . $relative;
    }

    /**
     * @return non-empty-string themes/{area}/{relative}
     */
    public static function joinThemes(string $area, string $relative): string
    {
        $area = self::normalizeArea($area);
        $relative = str_replace('\\', '/', ltrim($relative, '/'));

        return $relative === ''
            ? 'themes/' . $area
            : 'themes/' . $area . '/' . $relative;
    }
}
