<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\View\Template;

/**
 * Owning-module page-level HotCache/asset prefetch contributions.
 *
 * Register as `storefront.page_prefetch.<Module>` in etc/module.php.
 * Framework Schedule / HotCachePagePrefetch only resolve this contract —
 * no Theme/Websites FQCN soft-pulls.
 */
interface StorefrontPagePrefetchContributionInterface
{
    public const CAPABILITY_PREFIX = 'storefront.page_prefetch.';

    /**
     * Path/meta-style primes that must not call getFetchFile / resolveThemeFile.
     *
     * @return int logical keys submitted to prefetchPolicy (0 = noop)
     */
    public function primeBeforeLayoutFetch(?Template $template = null, string ...$templateRefs): int;

    /**
     * @param list<array{type:string,name:string,code:string,module:string}> $specs
     * @return int logical keys submitted for widget asset prefetch
     */
    public function prefetchInlineWidgetAssets(array $specs): int;
}
