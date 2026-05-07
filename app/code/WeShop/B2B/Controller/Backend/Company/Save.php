<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Company;

use WeShop\B2B\Service\CompanyService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::company_management_actions', 'B2B company actions', 'mdi mdi-domain-edit', 'Create and update B2B companies', 'WeShop_B2B::company_management')]
class Save extends BaseController
{
    public function __construct(
        private readonly CompanyService $companyService
    ) {
    }

    #[Acl('WeShop_B2B::company_management_save_post', 'Save B2B company', 'mdi mdi-content-save', 'Save B2B company data')]
    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->request->getUrlBuilder()->getBackendUrl('*/backend/company'));

        try {
            $company = $this->companyService->saveCompany([
                'company_id' => $this->request->getParam('company_id', 0),
                'name' => $this->request->getParam('name', ''),
                'email' => $this->request->getParam('email', ''),
                'tax_id' => $this->request->getParam('tax_id', ''),
                'phone' => $this->request->getParam('phone', ''),
                'address' => $this->request->getParam('address', ''),
                'status' => $this->request->getParam('status', CompanyService::STATUS_ACTIVE),
            ]);

            $this->getMessageManager()->addSuccess(__('Company profile saved.'));
            $this->redirect($this->request->getUrlBuilder()->getBackendUrl('*/backend/company', ['id' => $company->getId()]));
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Company profile save failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    #[Acl('WeShop_B2B::company_management_save_index', 'Open B2B company save route', 'mdi mdi-content-save-outline', 'Open B2B company save route')]
    public function index(): string
    {
        return $this->post();
    }
}
