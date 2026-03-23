<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Frontend\B2B;

use WeShop\B2B\Service\CompanyPageDataService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CompanyPageDataService $companyPageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('Please log in to view your corporate programs.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $contactEmail = trim((string) ($this->customerContext->getEmail() ?? ''));
        foreach ($this->companyPageDataService->build($customerId, $contactEmail) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
