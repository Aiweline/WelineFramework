<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\RequestContext;

/**
 * Request/Fiber-scoped policy used by renderers that make a response private.
 *
 * The marker deliberately lives in RequestContext so WLS workers never retain
 * one request's cache decision for the next request.
 */
final class SharedResponseCachePolicy
{
    public const REQUEST_FORBIDDEN_KEY = 'framework.response.shared_cache_forbidden';

    public static function forbid(string $reason): void
    {
        $reasons = RequestContext::get(self::REQUEST_FORBIDDEN_KEY, []);
        $reasons = is_array($reasons) ? $reasons : [];
        $reason = trim($reason);
        if ($reason !== '') {
            $reasons[$reason] = true;
        }
        RequestContext::set(self::REQUEST_FORBIDDEN_KEY, $reasons);
        HeaderCollector::getInstance()
            ->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')
            ->setHeader('Pragma', 'no-cache');
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
}
