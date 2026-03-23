<?php

declare(strict_types=1);

namespace WeShop\Membership\Controller\Frontend\Membership;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Membership\Service\MembershipPageDataService;

class Index extends BaseController
{
    protected ?string $layoutType = 'membership';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly MembershipPageDataService $membershipPageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->redirect('customer/account/login');
            return '';
        }

        foreach ($this->membershipPageDataService->build($customerId) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}

