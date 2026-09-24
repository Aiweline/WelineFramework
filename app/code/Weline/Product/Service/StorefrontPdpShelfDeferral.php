<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;

/**
 * PDP cold-path gate: skip SSR card/companion reassembly so shelves emit hydrate
 * shells and fill via BinQuery (`product_storefront` batch ops).
 *
 * Does not remove required slots / default_injection semantics — templates keep DOM.
 * Enabled only when Detail (or peer) calls {@see enableForRequest()}; cart and
 * non-PDP layouts stay synchronous unless the flag is set.
 */
final class StorefrontPdpShelfDeferral
{
    public const BAG_KEY = 'product.pdp_shelf.defer_ssr_cards.v1';

    public static function enableForRequest(): void
    {
        if (!Context::hasCurrent()) {
            return;
        }
        RequestContext::set(self::BAG_KEY, true);
    }

    public static function isEnabled(): bool
    {
        if (!Context::hasCurrent()) {
            return false;
        }

        return RequestContext::get(self::BAG_KEY) === true;
    }

    /**
     * Preview / theme-editor keep synchronous demo cards; storefront PDP cold path defers.
     */
    public static function shouldDeferCardAssembly(bool $isPreviewOrEditor = false): bool
    {
        if ($isPreviewOrEditor) {
            return false;
        }

        return self::isEnabled();
    }
}
