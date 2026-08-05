<?php

declare(strict_types=1);

namespace Weline\Order\Api;

use Weline\Order\Api\Data\OrderPaidContext;

/**
 * Post-payment extension point for P2F（does not implement refund/invoice money logic）.
 */
interface OrderPostPaymentHookInterface
{
    /**
     * Invoked after an Order reaches paid（or equivalent）from Payment.
     */
    public function afterOrderPaid(OrderPaidContext $context): void;
}
