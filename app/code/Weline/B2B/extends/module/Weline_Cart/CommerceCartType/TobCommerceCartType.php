<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Cart\CommerceCartType;

use Weline\Cart\Api\CommerceCartTypeInterface;

/** B2B wholesale cart selling type — registered only when Weline_B2B is present. */
final class TobCommerceCartType implements CommerceCartTypeInterface
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

    public function getBadgeTone(): string
    {
        return 'warning';
    }

    public function requiresCustomerLogin(): bool
    {
        return true;
    }

    public function disablesStorefrontDiscounts(): bool
    {
        return true;
    }
}
