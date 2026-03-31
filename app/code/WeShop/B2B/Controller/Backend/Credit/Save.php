<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Credit;

use WeShop\B2B\Service\CreditService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly CreditService $creditService
    ) {
    }

    public function post(): string
    {
        $back = (string) $this->request->getParam('back_url', $this->request->getUrlBuilder()->getBackendUrl('*/backend/credit'));
        try {
            $customerId = (int) $this->request->getParam('customer_id', 0);
            $limit = (float) $this->request->getParam('credit_limit', 0);
            $level = trim((string) $this->request->getParam('credit_level', ''));
            if ($customerId <= 0) {
                throw new \InvalidArgumentException((string) __('Customer ID is required.'));
            }
            if ($limit < 0) {
                throw new \InvalidArgumentException((string) __('Credit limit must not be negative.'));
            }

            $this->creditService->setCreditLimit($customerId, $limit, $level !== '' ? $level : null);
            $this->getMessageManager()->addSuccess((string) __('B2B credit line saved.'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage() ?: (string) __('Save failed.'));
        }

        $this->redirect($back);

        return '';
    }
}
