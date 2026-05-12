<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Subscription\Service\SubscriptionDetailPageDataService;

class View extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Subscription::templates/Frontend/Subscription/View/index.phtml';

    protected ?string $layoutType = 'subscription';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionDetailPageDataService $pageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->redirect('customer/account/login');
            return '';
        }

        $subscriptionId = (int) ($this->request->getParam('id') ?? 0);
        if ($subscriptionId <= 0) {
            $this->getMessageManager()->addError(__('Subscription ID is required.'));
            $this->redirect('subscription');
            return '';
        }

        try {
            foreach ($this->pageDataService->build($customerId, $subscriptionId) as $key => $value) {
                $this->assign($key, $value);
            }
        } catch (\RuntimeException $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            $this->redirect('subscription');
            return '';
        }

        $this->assign('title', __('订阅服务详情'));
        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
