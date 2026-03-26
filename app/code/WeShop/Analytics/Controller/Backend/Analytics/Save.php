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
            $payload = [
                'provider' => $provider,
                'enabled' => $this->request->getParam('enabled', 0),
            ];

            $definitions = $this->analyticsConfigService->getProviderDefinitions();
            $fields = is_array($definitions[$provider]['fields'] ?? null) ? $definitions[$provider]['fields'] : [];
            foreach ($fields as $field) {
                $fieldName = trim((string) ($field['name'] ?? ''));
                if ($fieldName === '') {
                    continue;
                }

                $payload[$fieldName] = $this->request->getParam($fieldName, '');
            }

            $this->analyticsConfigService->saveProviderConfig($payload);

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
