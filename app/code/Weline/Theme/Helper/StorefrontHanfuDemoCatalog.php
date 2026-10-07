<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Theme\Service\Storefront\StorefrontRenderContextBag;

/**
 * Hanfu shell demos (hero slides, category tiles, videos, hot words) are for known
 * Hanfu storefront website codes only. New sites (grocery, daocharms, …) must not
 * inherit Theme-module Hanfu placeholder catalog.
 */
final class StorefrontHanfuDemoCatalog
{
    /** @var list<string> */
    private const ALLOWED_WEBSITE_CODES = [
        'default',
        'hanfu',
        'changanhanfu',
    ];

    public static function allowed(?string $websiteCode = null): bool
    {
        $code = \strtolower(\trim($websiteCode ?? self::resolveWebsiteCode()));

        return \in_array($code, self::ALLOWED_WEBSITE_CODES, true);
    }

    private static function resolveWebsiteCode(): string
    {
        $fromBag = StorefrontRenderContextBag::websiteCode();
        if ($fromBag !== null && $fromBag !== '') {
            return \strtolower(\trim($fromBag));
        }
        try {
            if (\class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
                $code = \strtolower(\trim(
                    (string)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteCode()
                ));
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (\Throwable) {
        }

        return 'default';
    }
}
