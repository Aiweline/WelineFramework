<?php

declare(strict_types=1);

namespace Weline\Order\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            tagTemplates: [
                'hooks' => [
                    'Weline_Order::hooks/account.sidebar.group.commerce.phtml',
                    'Weline_Order::hooks/account.sidebar.content.phtml',
                ],
            ],
        );
    }
}
