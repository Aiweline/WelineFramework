<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * Lightweight "is this widget code already owned by a global-region fragment?" check.
 *
 * Unlike {@see GlobalRegion::replacesWidget()} this never bootstraps the render providers:
 * it only scans the fragment directories and reads the switch tree. That keeps it safe to
 * call from the layout-entity renderer, where rendering the fragments would be wrong.
 *
 * Any failure answers "no" — reading the contract must never break a page.
 */
final class GlobalRegionWidgetOwnership
{
    /** @var array<string, bool> */
    private static array $memo = [];

    public static function isReplaced(string $widgetCode): bool
    {
        $widgetCode = \trim($widgetCode);
        if ($widgetCode === '') {
            return false;
        }
        if (\array_key_exists($widgetCode, self::$memo)) {
            return self::$memo[$widgetCode];
        }

        try {
            $root = \defined('BP') ? \rtrim((string)\BP, '/\\') : '';
            if ($root === '' || !\is_dir($root . '/app')) {
                return self::$memo[$widgetCode] = false;
            }
            $switch = GlobalRegionSwitch::fromEnv();
            $scanned = (new GlobalRegionFragmentScanner(GlobalRegionFragmentScanner::defaultRoots($root)))->scan();
            $replaced = false;
            foreach ($scanned['fragments'] as $fragment) {
                if ($fragment->widgetCode === $widgetCode && $switch->allows($fragment)) {
                    $replaced = true;
                    break;
                }
            }
        } catch (\Throwable) {
            return self::$memo[$widgetCode] = false;
        }

        return self::$memo[$widgetCode] = $replaced;
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
