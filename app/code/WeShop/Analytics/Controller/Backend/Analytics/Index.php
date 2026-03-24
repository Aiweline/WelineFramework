<?php

declare(strict_types=1);

namespace WeShop\Analytics\Controller\Backend\Analytics;

use WeShop\Analytics\Service\AnalyticsAdminPageDataService;
use WeShop\Analytics\Service\AnalyticsConfigService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly AnalyticsAdminPageDataService $analyticsAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $provider = (string) $this->request->getParam('provider', AnalyticsConfigService::PROVIDER_GOOGLE);
        $analyticsIndexUrl = $this->_url->getBackendUrl('*/backend/analytics');

        $this->assign(array_merge(
            [
                'title' => (string) __('Analytics Management'),
                'analyticsIndexUrl' => $analyticsIndexUrl,
                'analyticsSaveUrl' => $this->_url->getBackendUrl('*/backend/analytics/save'),
            ],
            $this->analyticsAdminPageDataService->getPageData($provider)
        ));

        return (string) $this->fetchBase('WeShop_Analytics::backend/templates/analytics/index.phtml');
    }
}
