<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            tagTemplates: [
                'hooks' => [
                    'Weline_Shipping::hooks/account.sidebar.phtml',
                    'Weline_Shipping::hooks/account.sidebar.content.phtml',
                ],
            ],
        );
    }
}
