<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\B2bCustomer;

use WeShop\B2B\Service\B2bCustomerService;
use WeShop\B2B\Service\PaymentTermService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::b2b_customer_profile', 'B2B Enterprise Customers', 'mdi mdi-account-group-outline', 'Manage B2B enterprise customers', 'Weline_Backend::customer_group')]
class Index extends BaseController
{
    public function __construct(
        private readonly B2bCustomerService $b2bCustomerService,
        private readonly PaymentTermService $paymentTermService
    ) {
    }

    #[Acl('WeShop_B2B::b2b_customer_profile_index', 'View B2B enterprise customers', 'mdi mdi-account-search-outline', 'View B2B enterprise customer management page')]
    public function index(): string
    {
        $this->paymentTermService->ensureDefaultTerms();
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'company_name' => (string) $this->request->getParam('company_name', ''),
            'customer_id' => (int) $this->request->getParam('customer_id', 0),
        ];

        $list = $this->b2bCustomerService->getProfileList($page, $pageSize, $filters);

        $this->assign([
            'title' => (string) __('B2B Enterprise Customers'),
            'profileSaveUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/b2b-customer/save'),
            'profileIndexUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/b2b-customer'),
            'items' => $list['items'],
            'pagination' => $list['pagination'],
            'total' => $list['total'],
            'filters' => $filters,
            'paymentTerms' => $this->paymentTermService->listActiveTerms(),
        ]);

        return (string) $this->fetchBase('WeShop_B2B::backend/templates/b2b-customer/index.phtml');
    }
}
