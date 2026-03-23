<?php

declare(strict_types=1);

namespace WeShop\Compliance\Controller\Frontend\Compliance;

use WeShop\Compliance\Service\CompliancePageDataService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;

class Consent extends BaseController
{
    protected ?string $layoutType = 'compliance';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CompliancePageDataService $compliancePageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->redirect('customer/account/login');
            return '';
        }

        foreach ($this->compliancePageDataService->buildConsentPage($customerId) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}

