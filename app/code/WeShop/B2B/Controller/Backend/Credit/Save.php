<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Credit;

use WeShop\B2B\Service\CreditService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::b2b_credit_actions', 'B2B credit actions', 'mdi mdi-credit-card-edit-outline', 'Update B2B credit lines', 'WeShop_B2B::b2b_credit')]
class Save extends BaseController
{
    public function __construct(
        private readonly CreditService $creditService
    ) {
    }

    #[Acl('WeShop_B2B::b2b_credit_save_post', 'Save B2B credit line', 'mdi mdi-content-save', 'Save B2B credit line data')]
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
