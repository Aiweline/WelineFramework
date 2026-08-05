<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Event;

/**
 * Bounded maintenance entry points for an optional async-event transport.
 *
 * Implementations own the durable Delivery/Outbox state. Transport modules
 * receive only aggregate results and never access Framework event models.
 */
interface AsyncEventDeliveryMaintenanceInterface
{
    /** @return array{processed:int,expanded:int,dead:int,retried:int} */
    public function relayOutbox(int $limit = 50): array;

    /** @return array{processed:int,provisioned:int,dispatched:int,failed:int,noop:int} */
    public function reconcileTransport(int $limit = 50): array;

    /** @return array{processed:int,provisioned:int,dispatched:int,failed:int,noop:int} */
    public function provisionDue(int $limit = 50): array;

    /** @return array{processed:int,terminated:int,retry_wait:int,dead:int,unconfirmed:int,noop:int} */
    public function terminateTimedOut(int $limit = 50): array;

    /** @return array{deliveries:int,outboxes:int} */
    public function collectGarbage(int $limit = 50): array;
}
