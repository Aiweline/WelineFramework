<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderDetailPageDataService;
use Weline\Framework\Manager\ObjectManager;

class View extends BaseController
{
    protected const CONTENT_TEMPLATE = 'WeShop_Order::templates/Frontend/Order/View/index.phtml';

    protected ?string $layoutType = 'order';

    public function __construct(
        private ?CustomerContextInterface $customerContext = null,
        private ?OrderDetailPageDataService $orderDetailPageDataService = null
    ) {
    }

    public function index(): string
    {
        $customerId = $this->getCustomerContext()->getUserId();
        if (!$customerId) {
            $this->redirect('customer/account/login');
            return '';
        }

        $pageData = $this->getOrderDetailPageDataService()->build(
            (int) $customerId,
            (int) ($this->request->getParam('id') ?? 0)
        );
        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('page_title', (string) __('Order Details'));

        return $this->renderPage();
    }

    protected function renderPage(): string
    {
        return $this->fetchTemplateWithEvents(self::CONTENT_TEMPLATE);
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function getOrderDetailPageDataService(): OrderDetailPageDataService
    {
        return $this->orderDetailPageDataService ??= ObjectManager::getInstance(OrderDetailPageDataService::class);
    }
}
