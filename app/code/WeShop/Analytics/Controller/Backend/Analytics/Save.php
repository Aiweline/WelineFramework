<?php

declare(strict_types=1);

namespace WeShop\Analytics\Controller\Backend\Analytics;

use WeShop\Analytics\Service\AnalyticsConfigService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly AnalyticsConfigService $analyticsConfigService
    ) {
    }

    public function post(): string
    {
        $provider = (string) $this->request->getParam('provider', AnalyticsConfigService::PROVIDER_GOOGLE);
        $redirectUrl = $this->_url->getBackendUrl('*/backend/analytics', ['provider' => $provider]);

        try {
            $this->analyticsConfigService->saveProviderConfig([
                'provider' => $provider,
                'enabled' => $this->request->getParam('enabled', 0),
                'measurement_id' => $this->request->getParam('measurement_id', ''),
                'api_secret' => $this->request->getParam('api_secret', ''),
                'pixel_id' => $this->request->getParam('pixel_id', ''),
                'access_token' => $this->request->getParam('access_token', ''),
                'test_event_code' => $this->request->getParam('test_event_code', ''),
            ]);

            $this->getMessageManager()->addSuccess(__('Analytics provider config saved.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Analytics provider config save failed.'));
        }

        $this->redirect($redirectUrl);

        return '';
    }

    public function index(): string
    {
        return $this->post();
    }
}
