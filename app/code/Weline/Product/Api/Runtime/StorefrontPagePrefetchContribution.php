<?php

declare(strict_types=1);

namespace Weline\Product\Api\Runtime;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontPagePrefetchContributionInterface;
use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Framework\View\Template;

/**
 * Product-owned page prefetch contribution.
 *
 * Historical request-path shelf SSR prime (projectHtmlByProductId before layout)
 * was removed after cold wls_tpl_route evidence (2026-10-16): prime paid ~27
 * card_fragment MISS while layout reused only ~3 HIT for the same product ids
 * (flag/identity drift). Worker {@see \Weline\Product\Service\StorefrontHotCacheBagSeeder}
 * remains the HotCache warm path; request layout owns on-demand card SSR.
 *
 * Owning: Weline_Product. Do not re-enable request SSR prime without cold
 * HIT-rate proof (prime MISS → layout HIT for the same logicalKey).
 */
final class StorefrontPagePrefetchContribution implements StorefrontPagePrefetchContributionInterface
{
    public const LATCH_KEY = 'product.homepage.shelf_card_html.page_prefetch.primed.v1';

    public function primeBeforeLayoutFetch(?Template $template = null, string ...$templateRefs): int
    {
        if (!RequestContext::isInitialized()) {
            return 0;
        }
        if (RequestContext::get(self::LATCH_KEY) === true) {
            return 0;
        }
        if (!$this->looksLikeHomepage(...$templateRefs)) {
            return 0;
        }

        RequestContext::set(self::LATCH_KEY, true);
        TemplateRenderRouteTrace::mark('card_prime', 'disabled_low_reuse', [
            'reason' => 'cold_route_prime_miss_layout_miss',
        ]);

        return 0;
    }

    public function prefetchInlineWidgetAssets(array $specs): int
    {
        return 0;
    }

    private function looksLikeHomepage(string ...$templateRefs): bool
    {
        foreach ($templateRefs as $ref) {
            $lower = \strtolower(\trim($ref));
            if ($lower === '') {
                continue;
            }
            if (\str_contains($lower, 'homepage')
                || \str_contains($lower, 'layout-homepage')
                || \str_contains($lower, '/layouts/homepage/')
            ) {
                return true;
            }
        }

        return false;
    }
}
