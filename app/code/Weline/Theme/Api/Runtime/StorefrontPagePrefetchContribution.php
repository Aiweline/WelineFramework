<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Runtime\StorefrontPagePrefetchContributionInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Service\Storefront\StorefrontWidgetRuntimeAssetPrimer;
use Weline\Theme\Service\Storefront\ThemePathResolvePagePrefetch;

/**
 * Theme-owned page prefetch: path.resolve HotCache + inline widget assets.
 */
final class StorefrontPagePrefetchContribution implements StorefrontPagePrefetchContributionInterface
{
    public function __construct(
        private readonly ThemePathResolvePagePrefetch $pathResolve,
        private readonly StorefrontWidgetRuntimeAssetPrimer $assetPrimer,
    ) {
    }

    public function primeBeforeLayoutFetch(?Template $template = null, string ...$templateRefs): int
    {
        return $this->pathResolve->primeBeforeLayoutFetch($template, ...$templateRefs);
    }

    public function prefetchInlineWidgetAssets(array $specs): int
    {
        return $this->assetPrimer->prefetchForInlineSpecs($specs);
    }
}
