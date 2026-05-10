<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Subscription\Service\SubscriptionListPageDataService;

class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Subscription::templates/Frontend/Subscription/SubscriptionList/index.phtml';

    protected ?string $layoutType = 'subscription';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionListPageDataService $pageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->redirect('customer/account/login');
            return '';
        }

        $page = max(1, (int) ($this->request->getParam('page') ?? 1));
        $status = trim((string) ($this->request->getParam('status') ?? ''));

        foreach ($this->pageDataService->build($customerId, $page, 10, $status) as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('title', __('My Subscriptions'));
        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
