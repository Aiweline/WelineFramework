<?php

declare(strict_types=1);

namespace WeShop\Compare\Controller\Frontend\Compare;

use WeShop\Compare\Service\ComparePageDataService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ComparePageDataService $comparePageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        foreach ($this->comparePageDataService->build($customerId) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
