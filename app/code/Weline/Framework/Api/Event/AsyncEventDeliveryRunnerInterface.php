<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Event;

interface AsyncEventDeliveryRunnerInterface
{
    /** @return 'succeeded'|'retry_wait'|'dead'|'noop' */
    public function run(
        int $deliveryId,
        int $attemptNo,
        string $transportHandle,
        string $fenceToken,
    ): string;
}
