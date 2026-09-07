<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\CommerceOrderTypeInterface;

/** Built-in retail order type — always registered; no B2B dependency. */
final class TocCommerceOrderType implements CommerceOrderTypeInterface
{
    public const CODE = 'toc';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('零售');
    }

    public function getBadgeTone(): string
    {
        return 'muted';
    }

    public function requiresCustomerLogin(): bool
    {
        return false;
    }

    public function disablesStorefrontDiscounts(): bool
    {
        return false;
    }
}
