<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Service;

use Weline\Framework\Runtime\RequestContext;

/**
 * Ensure at most one StoreMusic float markup per HTTP request
 * (layout widget + base::body-end Hook must not double-render).
 */
final class StoreMusicRenderGate
{
    private const MEMO_KEY = 'weline.store_music.render_claimed';

    public static function claim(): bool
    {
        if (!RequestContext::isInitialized()) {
            if (!empty($GLOBALS['__weline_store_music_render_claimed'])) {
                return false;
            }
            $GLOBALS['__weline_store_music_render_claimed'] = true;

            return true;
        }

        if (RequestContext::get(self::MEMO_KEY, false)) {
            return false;
        }
        RequestContext::set(self::MEMO_KEY, true);

        return true;
    }

    /**
     * Drop a prior claim so a discarded SSR pass (e.g. ThemePreview Content
     * build() whose HTML is thrown away in editor_mode) cannot starve the
     * real LayoutSlotRenderer / body-end Hook pass with empty markup.
     */
    public static function reset(): void
    {
        $GLOBALS['__weline_store_music_render_claimed'] = false;
        if (RequestContext::isInitialized()) {
            RequestContext::set(self::MEMO_KEY, false);
        }
    }
}
