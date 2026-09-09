<?php

declare(strict_types=1);

namespace Weline\Index\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

/**
 * Non-default locale homepage roots. Default-locale `/` is owned by Framework
 * deferred storefront warmup; prefixed locales need their own FPC keys.
 */
final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            fpcPaths: [
                '/en_US/',
                '/ar_SA/',
            ],
        );
    }
}
