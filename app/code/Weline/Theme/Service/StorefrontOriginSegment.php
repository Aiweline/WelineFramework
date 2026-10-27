<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;

/**
 * Request origin segment for storefront HTML/fragment caches.
 *
 * website_url / base_url can resolve to a different alias than the visitor
 * host (multi-domain stores, local preview shells). Include the raw visitor
 * host as well so a page baked for one origin can never be replayed on another.
 */
final class StorefrontOriginSegment
{
    private const MEMO_KEY = 'theme.storefront_origin_segment.v1';

    public static function get(): string
    {
        if (RequestContext::isInitialized() && RequestContext::has(self::MEMO_KEY)) {
            $memo = RequestContext::get(self::MEMO_KEY);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        }

        try {
            $env = KeyBuilder::environmentContext([], [
                'area' => false,
                'area_route' => false,
                'website' => false,
                'website_url' => true,
                'host' => true,
                'base_url' => true,
                'lang' => false,
                'lang_local' => false,
                'currency' => false,
            ]);
            $joined = \strtolower(\trim((string)($env['host'] ?? '')))
                . '|' . \strtolower(\trim((string)($env['website_url'] ?? '')))
                . '|' . \strtolower(\trim((string)($env['base_url'] ?? '')))
                . '|' . self::visitorHost();
            $segment = $joined === '|||'
                ? 'origin-unknown'
                : 'origin-' . \substr(\sha1($joined), 0, 12);
        } catch (\Throwable) {
            $segment = 'origin-unknown';
        }

        if (RequestContext::isInitialized()) {
            RequestContext::set(self::MEMO_KEY, $segment);
        }

        return $segment;
    }

    private static function visitorHost(): string
    {
        foreach ([
            'http_weline_original_host',
            'server.http_host',
            'HTTP_HOST',
        ] as $key) {
            try {
                $value = \trim((string)WelineEnv::get($key, ''));
                if ($value !== '') {
                    return \strtolower($value);
                }
            } catch (\Throwable) {
            }
        }

        return \strtolower(\trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    }
}
