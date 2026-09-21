<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Checkout\CheckoutType;

use Weline\Checkout\Api\CheckoutTypeProviderInterface;

/** B2B wholesale checkout type — registered only when Weline_B2B is present. */
final class TobCheckoutTypeProvider implements CheckoutTypeProviderInterface
{
    public const CODE = 'tob';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('批发');
    }

    public function getSortOrder(): int
    {
        return 20;
    }

    public function isAvailable(array $context = []): bool
    {
        return true;
    }

    public function getCartTypeCode(): string
    {
        return 'tob';
    }

    public function getCapabilities(): array
    {
        return [
            'needs_shipping_address' => true,
            'needs_shipping_method' => true,
            'label_hint' => 'wholesale',
        ];
    }
}
