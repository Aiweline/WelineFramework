<?php

declare(strict_types=1);

namespace Weline\Product\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

/**
 * Publishes bounded anonymous catalog surfaces for the WLS startup warmup.
 */
final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            fpcPaths: [
                '/en_US/products',
                '/zh_Hans_CN/products',
                '/ar_SA/products',
            ],
        );
    }
}
