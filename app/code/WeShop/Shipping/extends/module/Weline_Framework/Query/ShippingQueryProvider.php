<?php

declare(strict_types=1);

namespace WeShop\Shipping\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Shipping\Service\ShippingService;

class ShippingQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ShippingService $shippingService
    ) {
    }

    public function getProviderName(): string
    {
        return 'shipping';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCheckoutShippingMethods' => $this->shippingService->getCheckoutShippingMethods($params),
            'getAvailableShippingMethods' => $this->shippingService->getAvailableShippingMethods($params),
            'calculateShipping' => $this->shippingService->calculateShipping(
                \is_array($params['shipping_data'] ?? null) ? $params['shipping_data'] : [],
                (string) ($params['shipping_method'] ?? '')
            ),
            'calculate' => $this->calculate($params),
            default => throw new \InvalidArgumentException(
                (string) __('Shipping query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    private function calculate(array $params): array
    {
        $shippingMethod = trim((string)($params['shipping_method'] ?? ''));
        if ($shippingMethod === '') {
            return [
                'success' => false,
                'message' => (string)__('Shipping method is required.'),
            ];
        }

        $context = \is_array($params['shipping_data'] ?? null) ? $params['shipping_data'] : $params;
        $context['shipping_method'] = $shippingMethod;
        $fee = $this->shippingService->calculateShipping($context, $shippingMethod);

        return [
            'success' => true,
            'message' => (string)__('Shipping fee calculated successfully.'),
            'data' => [
                'shipping_method' => $shippingMethod,
                'shipping_fee' => $fee,
                'formatted_fee' => sprintf('%.2f', $fee),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'shipping',
            'name' => __('Shipping Query'),
            'description' => __('Provides checkout shipping method data and shipping calculation access.'),
            'module' => 'WeShop_Shipping',
            'operations' => [
                ['name' => 'getCheckoutShippingMethods', 'description' => __('Get enabled shipping methods for checkout.')],
                ['name' => 'getAvailableShippingMethods', 'description' => __('Get available shipping methods as code-label map.')],
                ['name' => 'calculateShipping', 'description' => __('Calculate shipping fee for a shipping method.')],
                [
                    'name' => 'calculate',
                    'description' => __('Calculate frontend shipping fee for the selected method.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 5,
                    'params' => [
                        'shipping_method' => ['type' => 'string', 'required' => true, 'max_length' => 80],
                        'shipping_data' => ['type' => 'map', 'required' => false, 'max_keys' => 50],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Calculate shipping fee',
                ],
            ],
        ];
    }
}

