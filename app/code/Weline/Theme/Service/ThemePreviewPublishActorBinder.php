<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Backend\Api\Auth\BackendUserContext;

/**
 * Request-scoped backend actor for storefront preview publish-and-exit.
 *
 * Live-preview Tokens carry file_access_actor_id from mint-time auth. Sibling
 * storefront hosts do not share backend session cookies, so ThemeAssetEditor
 * must accept this binder only after the Frontend controller validated the Token.
 */
final class ThemePreviewPublishActorBinder
{
    private static ?BackendUserContext $actor = null;

    public static function install(BackendUserContext $actor): void
    {
        if ($actor->getId() < 1 || !$actor->getIsEnabled()) {
            throw new \InvalidArgumentException('theme_preview_publish_actor_invalid');
        }
        self::$actor = $actor;
    }

    public static function current(): ?BackendUserContext
    {
        $actor = self::$actor;
        if ($actor === null) {
            return null;
        }
        if ($actor->getId() < 1 || !$actor->getIsEnabled()) {
            return null;
        }

        return $actor;
    }

    public static function clear(): void
    {
        self::$actor = null;
    }
}
