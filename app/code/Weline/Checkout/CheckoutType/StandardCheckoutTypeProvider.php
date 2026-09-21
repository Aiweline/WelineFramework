<?php

declare(strict_types=1);

namespace Weline\Checkout\CheckoutType;

use Weline\Checkout\Api\CheckoutTypeProviderInterface;

/**
 * 支付域内置：传统/零售结账（cart_type=toc）。
 */
final class StandardCheckoutTypeProvider implements CheckoutTypeProviderInterface
{
    public const CODE = 'standard';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('零售');
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function isAvailable(array $context = []): bool
    {
        return true;
    }

    public function getCartTypeCode(): string
    {
        return 'toc';
    }

    public function getCapabilities(): array
    {
        return [
            'needs_shipping_address' => true,
            'needs_shipping_method' => true,
        ];
    }
}
