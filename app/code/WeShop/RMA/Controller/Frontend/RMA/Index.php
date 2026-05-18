<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Frontend\RMA;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\RMA\Service\RmaPageDataService;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';
    private const CONTENT_TEMPLATE = 'WeShop_RMA::templates/Frontend/RMA/Index/index.phtml';

    protected ?string $layoutType = 'rma';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly RmaPageDataService $rmaPageDataService
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

        $orderId = (int) ($this->request->getParam('order_id') ?? 0);
        $orderIncrementId = trim((string) ($this->request->getParam('order_increment_id') ?? ''));
        foreach ($this->rmaPageDataService->build($customerId, $orderId, $orderIncrementId) as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('title', __('Returns & Exchanges'));
        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
