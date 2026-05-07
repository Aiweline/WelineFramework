<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Account;

use WeShop\B2B\Service\AccountService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::b2b_account_actions', 'B2B trade account actions', 'mdi mdi-account-edit-outline', 'Create and update B2B trade accounts', 'WeShop_B2B::b2b_account')]
class Save extends BaseController
{
    public function __construct(
        private readonly AccountService $accountService
    ) {
    }

    #[Acl('WeShop_B2B::b2b_account_save_post', 'Save B2B trade account', 'mdi mdi-content-save', 'Save B2B trade account data')]
    public function post(): string
    {
        $back = (string) $this->request->getParam('back_url', $this->request->getUrlBuilder()->getBackendUrl('*/backend/account'));
        try {
            $customerId = (int) $this->request->getParam('customer_id', 0);
            if ($customerId <= 0) {
                throw new \InvalidArgumentException((string) __('Customer ID is required.'));
            }

            $this->accountService->saveAccountSettings($customerId, [
                'payment_term_id' => (int) $this->request->getParam('payment_term_id', 0),
                'credit_period_days' => (int) $this->request->getParam('credit_period_days', 0),
                'auto_approve_limit' => (float) $this->request->getParam('auto_approve_limit', 0),
            ]);
            $this->getMessageManager()->addSuccess((string) __('B2B account settings saved.'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage() ?: (string) __('Save failed.'));
        }

        $this->redirect($back);

        return '';
    }
}
