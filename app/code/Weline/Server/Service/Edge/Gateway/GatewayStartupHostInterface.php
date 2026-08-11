<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Read-only host discovery used before a project chooses its public edge. */
interface GatewayStartupHostInterface
{
    /** @return array<string,mixed> */
    public function status(
        float $transientRetrySeconds = 0.0,
        ?float $deadlineMonotonic = null,
    ): array;

    /** @return array<string,mixed> */
    public function prepare(
        ?array $observedStatus = null,
        ?float $deadlineMonotonic = null,
    ): array;
}
