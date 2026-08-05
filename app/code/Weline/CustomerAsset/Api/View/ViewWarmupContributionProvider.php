<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

final class ViewWarmupContributionProvider implements
    ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            tagTemplates: [
                'hooks' => [
                    'Weline_CustomerAsset::hooks/account.sidebar.phtml',
                    'Weline_CustomerAsset::hooks/account.sidebar.content.phtml',
                ],
            ],
        );
    }
}
