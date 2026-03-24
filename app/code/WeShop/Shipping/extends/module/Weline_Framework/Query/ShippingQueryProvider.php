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
            default => throw new \InvalidArgumentException(
                (string) __('Shipping query provider does not support operation: %{1}', [$operation])
            ),
        };
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
            ],
        ];
    }
}

