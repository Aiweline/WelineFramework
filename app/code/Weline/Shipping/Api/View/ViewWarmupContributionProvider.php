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
            templates: [
                'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml',
            ],
            tagTemplates: [
                'hooks' => [
                    'Weline_Shipping::hooks/account.sidebar.group.addresses.phtml',
                    'Weline_Shipping::hooks/account.sidebar.content.phtml',
                    'Weline_Shipping::hooks/Weline_Checkout/frontend/widgets/checkout-delivery-context/quick-add.phtml',
                ],
            ],
            staticFiles: [
                'app/code/Weline/Shipping/view/statics/css/widgets/checkout-shipping-address.css',
                'app/code/Weline/Shipping/view/statics/js/widgets/checkout-shipping-address.js',
            ],
        );
    }
}
