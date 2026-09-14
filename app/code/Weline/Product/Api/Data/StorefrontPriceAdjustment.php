<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * One catalog/unit-layer price adjustment proposed by a provider module.
 *
 * Checkout cart-rules / coupons stay on Marketing DiscountQuote — do not emit those here.
 */
final readonly class StorefrontPriceAdjustment
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';
    /** Absolute unit price in minor currency units (e.g. B2B list price). */
    public const TYPE_ABSOLUTE_MINOR = 'absolute_minor';

    /** Exclusive unit-price deals compete; strongest savings wins within the group. */
    public const GROUP_UNIT = 'unit';

    public function __construct(
        public string $code,
        public string $sourceModule,
        public string $sourceType,
        public string $sourceId,
        public string $label,
        public string $type,
        public float $value,
        public int $priority = 0,
        public bool $stackable = false,
        public string $exclusiveGroup = self::GROUP_UNIT,
        public string $url = '',
        public string $badge = '',
        // Internal frontend route; empty preserves an explicit provider URL.
        public string $frontendRoute = '',
    ) {
    }

    public function isPercentage(): bool
    {
        return $this->type === self::TYPE_PERCENTAGE;
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function isAbsoluteMinor(): bool
    {
        return $this->type === self::TYPE_ABSOLUTE_MINOR;
    }
}
