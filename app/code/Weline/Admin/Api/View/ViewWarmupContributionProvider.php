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
                'Weline_Admin::templates/Login/fast.phtml',
                'Weline_Admin::templates/Login/index.phtml',
                'Weline_Admin::templates/Login/head.phtml',
                'Weline_Admin::templates/Login/footer.phtml',
                'Weline_Admin::templates/common/head.phtml',
                'Weline_Admin::templates/common/footer.phtml',
                'Weline_Admin::templates/common/left-sidebar.phtml',
                'Weline_Admin::templates/common/right-sidebar.phtml',
                'Weline_Admin::templates/common/page/loading.phtml',
            ],
            staticFiles: [
                'app/code/Weline/Admin/view/statics/assets/css/bootstrap.min.css',
                'app/code/Weline/Admin/view/statics/assets/css/bootstrap-dark.min.css',
                'app/code/Weline/Admin/view/statics/assets/css/icons.min.css',
                'app/code/Weline/Admin/view/statics/assets/css/app.min.css',
                'app/code/Weline/Admin/view/statics/assets/css/app-dark.min.css',
                'app/code/Weline/Admin/view/statics/assets/libs/bootstrap/js/bootstrap.bundle.min.js',
                'app/code/Weline/Admin/view/statics/assets/libs/jquery/jquery.min.js',
                'app/code/Weline/Admin/view/statics/assets/libs/metismenu/metisMenu.min.js',
                'app/code/Weline/Admin/view/statics/assets/libs/simplebar/simplebar.min.js',
                'app/code/Weline/Admin/view/statics/assets/libs/node-waves/waves.min.js',
                'app/code/Weline/Admin/view/statics/assets/js/app.js',
            ],
            hookNames: [
                'Weline_Admin::backend::partials::login::providers',
            ],
        );
    }
}
