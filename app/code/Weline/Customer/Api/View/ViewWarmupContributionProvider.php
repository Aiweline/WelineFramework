<?php

declare(strict_types=1);

namespace Weline\Customer\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            templates: [
                'Weline_Customer::templates/frontend/account/sidebar/side.phtml',
                'Weline_Customer::templates/frontend/account/index.phtml',
            ],
            staticFiles: [
                'app/code/Weline/Customer/view/statics/css/account-index.css',
                'app/code/Weline/Customer/view/statics/css/account-sidebar.css',
                'app/code/Weline/Customer/view/statics/js/account-index.js',
            ],
        );
    }
}
