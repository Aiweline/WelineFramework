<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

class AccountOrdersListContextService
{
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function build(int $customerId, array $params = []): array
    {
        $statusFilterOptions = $this->orderService->getAvailableStatuses();
        $requestedOrderStatus = trim((string)($params['order_status'] ?? ''));
        $activeOrderStatus = $requestedOrderStatus !== '' && isset($statusFilterOptions[$requestedOrderStatus])
            ? $requestedOrderStatus
            : '';
        $orderFilters = $activeOrderStatus !== '' ? ['status' => $activeOrderStatus] : [];

        $currentPage = max(1, (int)($params['order_page'] ?? 1));
        $requestedPageSize = (int)($params['order_page_size'] ?? self::DEFAULT_PAGE_SIZE);
        $pageSize = min(self::MAX_PAGE_SIZE, max(self::DEFAULT_PAGE_SIZE, $requestedPageSize));

        $result = $this->orderService->getCustomerOrders($customerId, $currentPage, $pageSize, $orderFilters);
        $orders = (array)($result['items'] ?? []);
        $totalOrders = max(0, (int)($result['total'] ?? count($orders)));
        $pagination = (array)($result['pagination'] ?? []);
        $totalPages = max(1, (int)($pagination['total_pages'] ?? (int)ceil($totalOrders / $pageSize)));

        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
            $result = $this->orderService->getCustomerOrders($customerId, $currentPage, $pageSize, $orderFilters);
            $orders = (array)($result['items'] ?? []);
            $totalOrders = max(0, (int)($result['total'] ?? count($orders)));
            $pagination = (array)($result['pagination'] ?? []);
            $totalPages = max(1, (int)($pagination['total_pages'] ?? (int)ceil($totalOrders / $pageSize)));
        }

        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

        $buildPageUrl = static function (int $page, ?string $orderStatus = null) use ($pageSize, $activeOrderStatus): string {
            $query = [
                'order_page' => max(1, $page),
                'order_page_size' => $pageSize,
            ];
            $orderStatus = $orderStatus ?? $activeOrderStatus;
            if ($orderStatus !== '') {
                $query['order_status'] = $orderStatus;
            }

            return '/customer/account/index?' . http_build_query($query) . '#orders';
        };

        $buildReturnsUrl = static function (
            int $orderId,
            string $orderIncrementId,
            string $returnAnchor,
            int $orderPage,
            int $orderPageSize,
        ) use ($buildPageUrl): string {
            $context = [
                'order_id' => $orderId,
            ];
            if ($orderIncrementId !== '') {
                $context['order_increment_id'] = $orderIncrementId;
            }
            if ($returnAnchor !== '') {
                $context['return_anchor'] = $returnAnchor;
            }
            $context['return_url'] = $buildPageUrl($orderPage);

            return '/customer/account/index?' . http_build_query($context) . '#returns';
        };

        return [
            'customerId' => $customerId,
            'orderService' => $this->orderService,
            'statusFilterOptions' => $statusFilterOptions,
            'activeOrderStatus' => $activeOrderStatus,
            'currentPage' => $currentPage,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'totalOrders' => $totalOrders,
            'orders' => $orders,
            'escape' => $escape,
            'buildPageUrl' => $buildPageUrl,
            'buildReturnsUrl' => $buildReturnsUrl,
            'statusText' => [
                OrderService::STATUS_PENDING => (string) __('待处理'),
                OrderService::STATUS_PROCESSING => (string) __('处理中'),
                OrderService::STATUS_PAID => (string) __('已支付'),
                OrderService::STATUS_FULFILLED => (string) __('已发货'),
                OrderService::STATUS_COMPLETED => (string) __('已完成'),
                OrderService::STATUS_CANCELLED => (string) __('已取消'),
                OrderService::STATUS_REFUNDED => (string) __('已退款'),
            ],
        ];
    }
}
