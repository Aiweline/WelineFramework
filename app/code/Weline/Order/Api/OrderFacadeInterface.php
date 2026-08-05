<?php

declare(strict_types=1);

namespace Weline\Order\Api;

use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\Data\OrderPlan;
use Weline\Order\Api\Data\OrderReadResult;

/**
 * Stable Order boundary（REQ-010 / MOD-P2D-001）.
 * Checkout / Payment / Inventory MUST NOT reference Order Model/Service internals.
 */
interface OrderFacadeInterface
{
    /**
     * Pure compute — no DML, lock, reservation, outbox, or cache write.
     *
     * @throws OrderFacadeConflictException
     */
    public function plan(CreateCheckoutGroupCommand $command): OrderPlan;

    /**
     * Sole writer for new CheckoutGroup + Orders.
     *
     * @throws OrderFacadeConflictException
     */
    public function create(CreateCheckoutGroupCommand $command): CreateCheckoutGroupResult;

    /**
     * Read by Order UUID (not bare display number).
     *
     * @throws OrderFacadeConflictException
     */
    public function get(string $orderUuid): OrderReadResult;

    /**
     * Post-payment notification hook boundary（P2F）.
     *
     * @param array<string, mixed> $context Extension metadata only. The
     *        implementation reloads frozen scope/money/display identity.
     */
    public function notifyOrderPaid(string $orderUuid, array $context = []): void;
}
