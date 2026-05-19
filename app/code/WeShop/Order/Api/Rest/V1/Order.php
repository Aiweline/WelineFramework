<?php

declare(strict_types=1);

namespace WeShop\Order\Api\Rest\V1;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Model\Order as OrderModel;
use WeShop\Order\Service\OrderDetailPageDataService;
use WeShop\Order\Service\OrderListPageDataService;
use WeShop\Order\Service\OrderService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class Order extends FrontendRestController
{
    public function __construct(
        private ?CustomerContextInterface $customerContext = null,
        private ?OrderService $orderService = null,
        private ?OrderListPageDataService $orderListPageDataService = null,
        private ?OrderDetailPageDataService $orderDetailPageDataService = null
    ) {
    }

    public function getList(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('请先登录'),
                'data' => ['orders' => []],
            ]);
        }

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('成功'),
            'data' => $this->getOrderListPageDataService()->build(
                $customerId,
                max(1, (int) ($this->request->getParam('page') ?? 1)),
                max(1, (int) ($this->request->getParam('page_size') ?? 20))
            ),
        ]);
    }

    public function getDetail(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('请先登录'),
                'data' => ['order' => null, 'items' => []],
            ]);
        }

        try {
            $data = $this->getOrderDetailPageDataService()->build(
                $customerId,
                (int) ($this->request->getParam('id', 0) ?? 0)
            );
        } catch (\RuntimeException $exception) {
            return $this->fetchJson([
                'code' => 404,
                'msg' => $exception->getMessage(),
                'data' => ['order' => null, 'items' => []],
            ]);
        }

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('成功'),
            'data' => $data,
        ]);
    }

    public function getUnpaidCount(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('请先登录'),
                'data' => ['count' => 0],
            ]);
        }

        $count = $this->getOrderService()->getUnpaidOrderCount($customerId);

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('成功'),
            'data' => [
                'count' => $count,
                'has_unpaid' => $count > 0,
            ],
        ]);
    }

    public function getUnpaidList(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('请先登录'),
                'data' => ['orders' => []],
            ]);
        }

        $orders = $this->getOrderService()->getUnpaidOrders($customerId);

        $orderList = [];
        foreach ($orders as $order) {
            $orderList[] = [
                'order_id' => $order['order_id'] ?? $order[OrderModel::schema_fields_ID] ?? 0,
                'increment_id' => $order['increment_id'] ?? $order[OrderModel::schema_fields_increment_id] ?? '',
                'total' => $order['total'] ?? $order[OrderModel::schema_fields_total] ?? 0,
                'created_at' => $order['created_at'] ?? $order[OrderModel::schema_fields_created_at] ?? '',
            ];
        }

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('成功'),
            'data' => [
                'orders' => $orderList,
                'count' => count($orderList),
            ],
        ]);
    }

    protected function fetchJson(array $data): string
    {
        $response = $this->request->getResponse();
        $httpCode = match ((int) ($data['code'] ?? 200)) {
            401 => 401,
            404 => 404,
            403 => 403,
            default => 200,
        };
        $response->setHttpResponseCode($httpCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function getOrderService(): OrderService
    {
        return $this->orderService ??= ObjectManager::getInstance(OrderService::class);
    }

    private function getOrderListPageDataService(): OrderListPageDataService
    {
        return $this->orderListPageDataService ??= ObjectManager::getInstance(OrderListPageDataService::class);
    }

    private function getOrderDetailPageDataService(): OrderDetailPageDataService
    {
        return $this->orderDetailPageDataService ??= ObjectManager::getInstance(OrderDetailPageDataService::class);
    }
}
