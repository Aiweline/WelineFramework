<?php

declare(strict_types=1);

namespace Weline\Admin\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            templates: [
                'Weline_Admin::templates/Login/index.phtml',
                'Weline_Admin::templates/Login/head.phtml',
                'Weline_Admin::templates/common/head.phtml',
                'Weline_Admin::templates/common/left-sidebar.phtml',
            ],
            staticFiles: [],
            hookNames: [
                'Weline_Admin::backend::partials::login::providers',
            ],
        );
    }
}
