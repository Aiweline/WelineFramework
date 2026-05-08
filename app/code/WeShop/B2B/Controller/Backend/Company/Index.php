<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Company;

use WeShop\B2B\Service\CompanyAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::company_management', 'B2B Companies', 'mdi mdi-domain', 'Manage B2B companies', 'Weline_Backend::customer_group')]
class Index extends BaseController
{
    public function __construct(
        private readonly CompanyAdminPageDataService $companyAdminPageDataService
    ) {
    }

    #[Acl('WeShop_B2B::company_management_index', 'View B2B companies', 'mdi mdi-domain-search', 'View B2B company management page')]
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);
        $filters = [
            'company_id' => $this->request->getParam('company_id', ''),
            'name' => $this->request->getParam('name', ''),
            'email' => $this->request->getParam('email', ''),
            'status' => $this->request->getParam('status', ''),
        ];

        $this->assign(array_merge(
            [
                'title' => (string) __('B2B Company Management'),
                'companyIndexUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/company'),
                'companySaveUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/company/save'),
            ],
            $this->companyAdminPageDataService->getPageData($page, $pageSize, $filters, $editingId)
        ));

        return (string) $this->fetchBase('WeShop_B2B::backend/templates/company/index.phtml');
    }
}
