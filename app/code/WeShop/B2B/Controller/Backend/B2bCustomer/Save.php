<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\B2bCustomer;

use WeShop\B2B\Service\B2bCustomerService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::b2b_customer_profile_actions', 'B2B enterprise customer actions', 'mdi mdi-account-edit-outline', 'Create and update B2B enterprise customers', 'WeShop_B2B::b2b_customer_profile')]
class Save extends BaseController
{
    public function __construct(
        private readonly B2bCustomerService $b2bCustomerService
    ) {
    }

    #[Acl('WeShop_B2B::b2b_customer_profile_save_post', 'Save B2B enterprise customer', 'mdi mdi-content-save', 'Save B2B enterprise customer data')]
    public function post(): string
    {
        $back = (string) $this->request->getParam('back_url', $this->request->getUrlBuilder()->getBackendUrl('*/backend/b2b-customer'));
        try {
            $this->b2bCustomerService->saveProfile([
                'customer_id' => (int) $this->request->getParam('customer_id', 0),
                'company_name' => (string) $this->request->getParam('company_name', ''),
                'company_reg_no' => (string) $this->request->getParam('company_reg_no', ''),
                'business_license' => (string) $this->request->getParam('business_license', ''),
                'tax_id' => (string) $this->request->getParam('tax_id', ''),
                'credit_level' => (string) $this->request->getParam('credit_level', ''),
                'credit_limit' => (float) $this->request->getParam('credit_limit', 0),
                'payment_term_id' => (int) $this->request->getParam('payment_term_id', 0),
                'status' => (int) $this->request->getParam('status', 1),
                'company_id' => (int) $this->request->getParam('company_id', 0),
            ]);
            $this->getMessageManager()->addSuccess((string) __('B2B customer profile saved.'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage() ?: (string) __('Save failed.'));
        }

        $this->redirect($back);

        return '';
    }
}
