<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Compile-time slot boundary comments for string-based slot fill/extract.
 *
 * DEV final HTML keeps markers; PROD strips them before response/FPC.
 */
final class SlotBoundaryMarkers
{
    public const OPEN_PREFIX = '<!--@weline-slot:';
    public const CLOSE_PREFIX = '<!--@/weline-slot:';

    public static function open(string $slotId): string
    {
        $slotId = self::normalizeId($slotId);

        return self::OPEN_PREFIX . $slotId . '-->';
    }

    public static function close(string $slotId): string
    {
        $slotId = self::normalizeId($slotId);

        return self::CLOSE_PREFIX . $slotId . '-->';
    }

    public static function hasMarkers(string $html): bool
    {
        return $html !== '' && str_contains($html, self::OPEN_PREFIX);
    }

    /**
     * Remove boundary comments from final HTML (PROD only).
     */
    public static function strip(string $html): string
    {
        if ($html === '' || !self::hasMarkers($html)) {
            return $html;
        }

        $stripped = (string) preg_replace(
            '/<!--@(?:\/)?weline-slot:[\w.-]+-->\s*/',
            '',
            $html,
        );

        return $stripped === '' ? $html : $stripped;
    }

    private static function normalizeId(string $slotId): string
    {
        $slotId = trim($slotId);
        if ($slotId === '' || !preg_match('/^[\w.-]+$/', $slotId)) {
            throw new \InvalidArgumentException('Invalid slot id for boundary marker: ' . $slotId);
        }

        return $slotId;
    }
}
