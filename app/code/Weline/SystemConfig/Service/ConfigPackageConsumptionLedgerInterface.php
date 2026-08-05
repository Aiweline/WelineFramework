<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

/**
 * package_uuid 一次性消费账本（CAS）。
 */
interface ConfigPackageConsumptionLedgerInterface
{
    /**
     * @param array{
     *   package_uuid:string,
     *   recipient_kid:string,
     *   scope_key:string,
     *   source_instance?:string,
     *   filename?:string,
     *   payload_hash?:string
     * } $row
     *
     * @throws \RuntimeException config_envelope_package_replayed when already consumed
     */
    public function claim(array $row): void;

    public function markApplied(string $packageUuid): void;

    public function markFailed(string $packageUuid): void;

    public function isConsumed(string $packageUuid): bool;
}
