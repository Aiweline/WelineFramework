<?php
declare(strict_types=1);

namespace WeShop\RMA\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Model\Rma;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class RmaQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly OrderService $orderService,
        private readonly RmaService $rmaService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'rma';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'create' => $this->create($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported RMA provider operation: %{1}', $operation)
            ),
        };
    }

    private function create(array $params): array
    {
        $customerId = $this->getCustomerId();
        $returnUrl = $this->normalizeReturnUrl((string)($params['return_url'] ?? ''));
        if ($customerId <= 0) {
            return $this->loginRequired($returnUrl);
        }

        $orderIncrementId = trim((string)($params['order_increment_id'] ?? ''));
        $returnAnchor = trim((string)($params['return_anchor'] ?? ''));
        $orderId = $this->resolveOrderId((int)($params['order_id'] ?? 0), $orderIncrementId);
        $reason = trim((string)($params['reason'] ?? ''));
        $type = trim((string)($params['type'] ?? 'return'));
        $description = trim((string)($params['description'] ?? ''));

        if ($orderId <= 0 || $reason === '') {
            return [
                'success' => false,
                'message' => (string)__('Order and reason are required.'),
            ];
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order || (int)($order->getData(Order::schema_fields_customer_id) ?? 0) !== $customerId) {
            return [
                'success' => false,
                'message' => (string)__('You do not have access to this order.'),
            ];
        }

        $rma = $this->rmaService->createRma([
            Rma::schema_fields_ORDER_ID => $orderId,
            Rma::schema_fields_CUSTOMER_ID => $customerId,
            Rma::schema_fields_REASON => $reason,
            Rma::schema_fields_DESCRIPTION => $this->buildDescription($type, $description),
            Rma::schema_fields_STATUS => RmaService::STATUS_PENDING,
        ]);

        $resolvedOrderIncrementId = $orderIncrementId !== ''
            ? $orderIncrementId
            : (string)($order->getData(Order::schema_fields_increment_id) ?? '');
        $createdAt = (string)($rma->getData(Rma::schema_fields_CREATED_AT) ?? date('Y-m-d H:i:s'));
        $orderItems = $this->orderService->getOrderItems($orderId);

        return [
            'success' => true,
            'message' => (string)__('Your return request has been submitted.'),
            'data' => [
                'rma_id' => (int)($rma->getId() ?? 0),
                'order_id' => $orderId,
                'order_increment_id' => $resolvedOrderIncrementId,
                'reason' => $reason,
                'description' => $this->buildDescription($type, $description),
                'status' => RmaService::STATUS_PENDING,
                'status_label' => (string)__('待处理'),
                'created_at' => $createdAt,
                'items' => array_slice($orderItems, 0, 3),
                'item_count' => count($orderItems),
                'redirect_url' => $this->buildReturnsRoute($orderId, $resolvedOrderIncrementId, $returnAnchor, $returnUrl),
            ],
        ];
    }

    private function getCustomerId(): int
    {
        return (int)($this->customerContext->getUserId() ?? 0);
    }

    private function loginRequired(string $returnUrl = ''): array
    {
        $loginUrl = $this->url->getUrl(self::LOGIN_ROUTE);
        if ($returnUrl !== '') {
            $loginUrl .= (str_contains($loginUrl, '?') ? '&' : '?') . 'redirect_url=' . rawurlencode($returnUrl);
        }

        return [
            'success' => false,
            'message' => (string)__('Please log in to continue.'),
            'data' => [
                'redirect_url' => $loginUrl,
            ],
        ];
    }

    private function buildDescription(string $type, string $description): string
    {
        return $type !== '' ? trim('[' . $type . '] ' . $description) : $description;
    }

    private function resolveOrderId(int $orderId, string $orderIncrementId): int
    {
        if ($orderId > 0) {
            return $orderId;
        }

        if ($orderIncrementId === '') {
            return 0;
        }

        $order = $this->orderService->getOrderByIncrementId($orderIncrementId);

        return $order ? (int)($order->getId() ?? 0) : 0;
    }

    private function buildReturnsRoute(int $orderId, string $orderIncrementId, string $returnAnchor, string $returnUrl): string
    {
        $params = [];
        if ($orderId > 0) {
            $params['order_id'] = $orderId;
        }
        if ($orderIncrementId !== '') {
            $params['order_increment_id'] = $orderIncrementId;
        }
        if ($returnAnchor !== '') {
            $params['return_anchor'] = $returnAnchor;
        }
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }

        $query = $params === [] ? '' : '?' . http_build_query($params);

        return '/customer/account/index' . $query . '#returns';
    }

    private function normalizeReturnUrl(string $returnUrl): string
    {
        $normalized = trim($returnUrl);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^https?:\\/\\//i', $normalized) === 1) {
            return '';
        }

        if ($normalized[0] !== '/') {
            $normalized = '/' . $normalized;
        }

        return $normalized;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'rma',
            'name' => __('RMA Query'),
            'description' => __('Provides frontend return and exchange request operations through the worker API.'),
            'module' => 'WeShop_RMA',
            'operations' => [
                [
                    'name' => 'create',
                    'description' => __('Submit a frontend RMA request.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'order_id' => ['type' => 'int', 'required' => false, 'min' => 1],
                        'order_increment_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'type' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                        'reason' => ['type' => 'string', 'required' => true, 'max_length' => 160],
                        'description' => ['type' => 'string', 'required' => false, 'max_length' => 2000],
                        'return_anchor' => ['type' => 'string', 'required' => false, 'max_length' => 160],
                        'return_url' => ['type' => 'string', 'required' => false, 'max_length' => 2000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit RMA request',
                ],
            ],
        ];
    }
}
