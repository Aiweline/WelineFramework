<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Company;

use WeShop\B2B\Service\CompanyService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly CompanyService $companyService
    ) {
    }

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

    public function index(): string
    {
        return $this->post();
    }
}
