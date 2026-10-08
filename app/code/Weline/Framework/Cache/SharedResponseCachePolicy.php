<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/**
 * Request/Fiber-scoped policy used by renderers that make a response private.
 *
 * The marker deliberately lives in RequestContext so WLS workers never retain
 * one request's cache decision for the next request.
 *
 * Browser/shared Cache-Control is owned here. Vendor CDN edge headers must be
 * written by optional listeners of
 * {@see self::EVENT_SHARED_CACHE_FORBIDDEN} (e.g. Weline_Cdn Cloudflare).
 */
final class SharedResponseCachePolicy
{
    public const REQUEST_FORBIDDEN_KEY = 'framework.response.shared_cache_forbidden';

    /** Fired after Cache-Control no-store is applied; payload: reason, reasons, cache_control. */
    public const EVENT_SHARED_CACHE_FORBIDDEN = 'Weline_Framework::response::shared_cache_forbidden';

    private static bool $dispatchingForbidden = false;

    public static function forbid(string $reason): void
    {
        $reasons = RequestContext::get(self::REQUEST_FORBIDDEN_KEY, []);
        $reasons = is_array($reasons) ? $reasons : [];
        $reason = trim($reason);
        if ($reason !== '') {
            $reasons[$reason] = true;
        }
        RequestContext::set(self::REQUEST_FORBIDDEN_KEY, $reasons);
        $cacheControl = 'private, no-store, max-age=0, must-revalidate';
        HeaderCollector::getInstance()
            ->setHeader('Cache-Control', $cacheControl)
            ->setHeader('Pragma', 'no-cache');
        self::dispatchForbidden($reason, $reasons, $cacheControl);
    }

    public static function isForbidden(): bool
    {
        return RequestContext::get(self::REQUEST_FORBIDDEN_KEY, []) !== [];
    }

    /** @return list<string> */
    public static function reasons(): array
    {
        $reasons = RequestContext::get(self::REQUEST_FORBIDDEN_KEY, []);
        return is_array($reasons) ? array_values(array_keys($reasons)) : [];
    }

    /**
     * @param array<string, true> $reasons
     */
    private static function dispatchForbidden(string $reason, array $reasons, string $cacheControl): void
    {
        if (self::$dispatchingForbidden) {
            return;
        }
        self::$dispatchingForbidden = true;
        try {
            $data = [
                'reason' => $reason,
                'reasons' => array_values(array_keys($reasons)),
                'cache_control' => $cacheControl,
            ];
            ObjectManager::getInstance(EventsManager::class)
                ->dispatch(self::EVENT_SHARED_CACHE_FORBIDDEN, $data);
        } catch (\Throwable) {
            // Optional listeners must never break the forbid path.
        } finally {
            self::$dispatchingForbidden = false;
        }
    }
}
