<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Service\AccountDashboardDataService;
use WeShop\Frontend\Controller\BaseController;

class Index extends BaseController
{
    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly AccountDashboardDataService $accountDashboardDataService
    ) {
    }

    public function index(): string
    {
        $authUser = $this->customerContext->getAuthUser();
        if (!$authUser || !$authUser->getId()) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));

            return $this->redirect('weshop/customer/account/login');
        }

        foreach ($this->accountDashboardDataService->build($authUser, $this->customerContext->getProfile()) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
