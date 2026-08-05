<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Order\Model\Order;
use Weline\Order\Model\RefundCase;
use Weline\Order\Service\OrderRefundCoordinator;

/**
 * 顾客退款状态只读投影。
 *
 * 退款申请、渠道回写和测试造数均不得经匿名浏览器资源执行。
 */
final class OrderRefundQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly OrderRefundCoordinator $coordinator,
        private readonly SessionFactory $sessionFactory,
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function getProviderName(): string
    {
        return 'refund';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'customerView' => $this->customerView($params),
            default => throw new \InvalidArgumentException(
                (string)__('退款接口不支持该操作：%{1}', [$operation]),
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function customerView(array $params): array
    {
        $refundCaseUuid = trim((string)($params['refund_case_uuid'] ?? ''));
        if ($refundCaseUuid === '') {
            return ['success' => false, 'error_code' => 'refund_case_uuid_required'];
        }
        $customerId = $this->authenticatedCustomerId();
        if ($customerId <= 0) {
            return ['success' => false, 'error_code' => 'refund_customer_login_required'];
        }
        $case = $this->newModel(RefundCase::class)
            ->where(RefundCase::schema_fields_REFUND_CASE_UUID, $refundCaseUuid)
            ->find()
            ->fetch();
        if (!$case instanceof RefundCase || !$case->getId()) {
            return ['success' => false, 'error_code' => 'refund_case_not_found'];
        }
        $order = $this->newModel(Order::class)
            ->where(
                Order::schema_fields_ORDER_UUID,
                (string)$case->getData(RefundCase::schema_fields_ORDER_UUID),
            )
            ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
            ->find()
            ->fetch();
        if (!$order instanceof Order || !$order->getId()) {
            return ['success' => false, 'error_code' => 'refund_case_not_found'];
        }
        $view = $this->coordinator->customerView($refundCaseUuid);
        $payment = $this->coordinator->getPayment($refundCaseUuid);

        return [
            'success' => $view !== '',
            'refund_case_uuid' => $refundCaseUuid,
            'customer_view' => $view,
            'channel_status' => (string)($payment['channel_status'] ?? ''),
            'payment_status' => (string)($payment['status'] ?? ''),
        ];
    }

    private function authenticatedCustomerId(): int
    {
        $session = $this->sessionFactory->createFrontendSession();
        if (!$session->isLoggedIn()) {
            return 0;
        }

        return (int)($session->getUserId() ?? $session->getUser()?->getId() ?? 0);
    }

    /**
     * @template T of \Weline\Framework\Database\Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): \Weline\Framework\Database\Model
    {
        return $this->objectManager->getInstance($class, [], false);
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_Order',
            'summary' => 'Authenticated customer refund status projection',
            'operations' => [[
                'name' => 'customerView',
                'frontend' => true,
                'auth' => 'customer',
                'mode' => 'read',
                'graph' => false,
                'cost' => 1,
                'params' => [
                    'refund_case_uuid' => [
                        'type' => 'string',
                        'required' => true,
                        'max_length' => 36,
                    ],
                ],
                'returns' => ['type' => 'array'],
                'summary' => 'Customer-owned refund status (processing/succeeded/failed)',
            ]],
        ];
    }
}
