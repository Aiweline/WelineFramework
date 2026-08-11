<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Establishes the first host gateway from a verified project release package. */
interface GatewayStartupBootstrapperInterface
{
    /**
     * @param array<string,mixed> $observedStatus
     * @return array<string,mixed>
     */
    public function bootstrap(
        array $observedStatus,
        float $deadlineMonotonic,
    ): array;
}
