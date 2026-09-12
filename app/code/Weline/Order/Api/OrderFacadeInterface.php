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

    /**
     * Attach a storefront customer to guest orders (empty/null customer_id only).
     *
     * @param list<string> $orderUuids
     * @return list<string> Order UUIDs that were attached (or already owned by the same customer)
     *
     * @throws OrderFacadeConflictException
     */
    public function attachCustomerToGuestOrders(int $customerId, array $orderUuids): array;

    /**
     * Merge keys into persisted type_payload (B2B credit / hang projection).
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed> Merged type_payload
     *
     * @throws OrderFacadeConflictException
     */
    public function mergeTypePayload(string $orderUuid, array $patch): array;

    /**
     * Tob hang balance revision: update payable authority (type_payload + money grand_total audit).
     *
     * @param array{
     *   balance_amount_minor:int,
     *   payable_grand_total_minor?:int,
     *   revision_version?:int,
     *   revision_pending?:bool,
     *   audit?:array<string,mixed>
     * } $revision
     * @return array<string,mixed> Merged type_payload
     *
     * @throws OrderFacadeConflictException
     */
    public function reviseTobHangPayable(string $orderUuid, array $revision): array;
}
