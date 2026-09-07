<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * Gates price-changing OrderCalculators by commerce order_type.
 * ToB (`disablesStorefrontDiscounts`) never runs Marketing/deal calculators.
 */
final class OrderCalculatorGate
{
    public function __construct(
        private readonly ?CommerceOrderTypeRegistry $registry = null,
    ) {
    }

    public static function forTesting(?CommerceOrderTypeRegistry $registry = null): self
    {
        return new self($registry ?? CommerceOrderTypeRegistry::forTesting());
    }

    public function allowsPriceChangingCalculators(string $orderType): bool
    {
        $code = strtolower(trim($orderType));
        if ($code === '') {
            $code = CommerceOrderTypeRegistry::CODE_TOC;
        }
        $registry = $this->registry ?? CommerceOrderTypeRegistry::forTesting();
        if (!$registry->has($code)) {
            return $code === CommerceOrderTypeRegistry::CODE_TOC;
        }

        return !$registry->require($code)->disablesStorefrontDiscounts();
    }
}
