<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Order\CommerceOrderType;

use Weline\Order\Api\CommerceOrderTypeInterface;

/** B2B wholesale order selling type — registered only when Weline_B2B is present. */
final class TobCommerceOrderType implements CommerceOrderTypeInterface
{
    public const CODE = 'tob';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('批发订单');
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
