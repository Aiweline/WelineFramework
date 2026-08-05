<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\OrderPaidContext;
use Weline\Order\Api\OrderPostPaymentHookInterface;

/** Default no-op post-payment hook（P2F may replace via extends/DI）. */
final class NoopOrderPostPaymentHook implements OrderPostPaymentHookInterface
{
    public function afterOrderPaid(OrderPaidContext $context): void
    {
        // Intentionally empty — funds/invoice/refund logic stays in Payment/Order services.
    }
}
