<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/**
 * Produces compact database identities without replacing the readable columns.
 *
 * All callers must first construct LayoutIdentity so locale and identity fields
 * have one canonical representation before hashing.
 */
final class LayoutIdentityHasher
{
    public static function base(int $themeId, string $pageType, LayoutIdentity $identity): string
    {
        return self::parts([
            (string)$themeId,
            $pageType,
            $identity->layoutOption,
            $identity->scope,
            $identity->localeCode,
            $identity->targetType,
            (string)$identity->targetId,
        ]);
    }

    public static function node(
        int $themeId,
        string $pageType,
        LayoutIdentity $identity,
        string $status,
        string $nodeUid,
    ): string {
        return self::parts([
            self::base($themeId, $pageType, $identity),
            $status,
            $nodeUid,
        ]);
    }

    public static function virtual(
        int $themeId,
        string $area,
        string $layoutType,
        LayoutIdentity $identity,
    ): string {
        return self::parts([
            (string)$themeId,
            $area,
            $layoutType,
            $identity->layoutOption,
            $identity->scope,
            $identity->localeCode,
            $identity->targetType,
            (string)$identity->targetId,
        ]);
    }

    public static function injection(
        int $themeId,
        string $componentArea,
        string $pageType,
        LayoutIdentity $identity,
        string $injectionKey,
    ): string {
        return self::parts([
            (string)$themeId,
            $componentArea,
            $pageType,
            $identity->layoutOption,
            $identity->scope,
            $identity->localeCode,
            $identity->targetType,
            (string)$identity->targetId,
            $injectionKey,
        ]);
    }

    /** @param list<string> $parts */
    private static function parts(array $parts): string
    {
        return hash('sha256', implode("\0", $parts));
    }
}
