<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * 管理员新增优惠小计项草稿（金额为优惠绝对值，单位分）。
 */
final class AdjustmentLineDraft
{
    public function __construct(
        public readonly string $label,
        public readonly int $discountAmountMinor,
        public readonly string $note = '',
    ) {
    }
}
