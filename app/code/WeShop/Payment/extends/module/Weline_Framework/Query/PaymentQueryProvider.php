<?php

declare(strict_types=1);

namespace WeShop\Payment\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Order\Model\Order;
use WeShop\Payment\Service\PaymentService;

class PaymentQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {
    }

    public function getProviderName(): string
    {
        return 'payment';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCheckoutPaymentMethods' => $this->paymentService->getCheckoutPaymentMethods($params),
            'getAvailablePaymentMethods' => $this->paymentService->getAvailablePaymentMethods($params),
            'getPaymentMethod' => $this->paymentService->getPaymentMethod((string) ($params['code'] ?? $params['payment_method'] ?? '')),
            'processPayment' => $this->processPayment($params),
            'queryPaymentStatus' => $this->paymentService->queryPaymentStatus(
                (string) ($params['payment_method'] ?? ''),
                (string) ($params['order_number'] ?? ''),
                \is_array($params['context'] ?? null) ? $params['context'] : []
            ),
            default => throw new \InvalidArgumentException(
                (string) __('Payment query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'payment',
            'name' => __('Payment Query'),
            'description' => __('Provides checkout payment method data and payment processing access.'),
            'module' => 'WeShop_Payment',
            'operations' => [
                ['name' => 'getCheckoutPaymentMethods', 'description' => __('Get enabled payment methods for checkout.')],
                ['name' => 'getAvailablePaymentMethods', 'description' => __('Get all configured payment methods.')],
                ['name' => 'getPaymentMethod', 'description' => __('Get a single payment method definition.')],
                ['name' => 'processPayment', 'description' => __('Process a payment for an order.')],
                ['name' => 'queryPaymentStatus', 'description' => __('Query payment status by order number.')],
            ],
        ];
    }

    protected function processPayment(array $params): array
    {
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            throw new \InvalidArgumentException((string) __('An order instance is required for payment processing.'));
        }

        $paymentMethod = (string) ($params['payment_method'] ?? '');
        $paymentData = $params['payment_data'] ?? [];
        if (!\is_array($paymentData)) {
            $paymentData = [];
        }

        return $this->paymentService->processPayment($order, $paymentMethod, $paymentData);
    }
}
