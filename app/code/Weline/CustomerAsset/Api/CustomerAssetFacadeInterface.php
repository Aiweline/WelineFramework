<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Api;

/**
 * Server-side CustomerAsset boundary.
 *
 * Payment, Order and browser account UI are not part of P4D-001.
 */
interface CustomerAssetFacadeInterface
{
    /**
     * @param array{
     *   customer_id:string|int,
     *   website_id:int,
     *   asset_code:string,
     *   namespace?:string,
     *   amount_minor:int,
     *   event_id:string
     * } $request
     * @return array<string, mixed>
     */
    public function credit(array $request): array;

    /**
     * @param array{
     *   customer_id:string|int,
     *   website_id:int,
     *   asset_code:string,
     *   namespace?:string,
     *   amount_minor:int,
     *   event_id:string
     * } $request
     * @return array<string, mixed>
     */
    public function reserve(array $request): array;

    /** @return array<string, mixed> */
    public function release(string $reservationId, string $eventId): array;

    /** @return array<string, mixed> */
    public function commit(string $reservationId, string $eventId): array;

    /**
     * Return part or all of an already committed reservation.
     *
     * This is settlement of an existing obligation and remains available when
     * new asset tender is disabled.
     *
     * @return array<string, mixed>
     */
    public function returnCommitted(
        string $reservationId,
        int $amountMinor,
        string $eventId,
    ): array;

    /** @return array<string, mixed> */
    public function getBalance(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = 'live',
    ): array;

    /** @return list<array<string, mixed>> */
    public function listAccounts(
        string|int $customerId,
        int $websiteId,
        string $namespace = 'live',
        int $limit = 100,
    ): array;

    /** @return list<array<string, mixed>> */
    public function listLedger(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = 'live',
        int $limit = 100,
    ): array;

    /** @return array<string, mixed> */
    public function getReservation(string $reservationId): array;
}
