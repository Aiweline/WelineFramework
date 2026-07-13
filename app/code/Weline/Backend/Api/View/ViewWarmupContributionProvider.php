<?php

declare(strict_types=1);

namespace Weline\Backend\Api\View;

use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            templates: [
                'Weline_Backend::templates/public/head.phtml',
                'Weline_Backend::templates/public/footer.phtml',
            ],
            tagTemplates: [
                'blocks' => [
                    'Weline_Backend::header/base.phtml',
                    'Weline_Backend::footer/base.phtml',
                    'Weline_Backend::version.phtml',
                    'Weline_Backend::system/notification.phtml',
                ],
            ],
            staticFiles: [
                'app/code/Weline/Backend/view/statics/base/weline.modules.js',
                'app/code/Weline/Backend/view/statics/backend/weline.modules.js',
                'app/code/Weline/Backend/view/statics/js/url-backend.js',
                'app/code/Weline/Backend/view/statics/js/cookie.js',
            ],
        );
    }
}
