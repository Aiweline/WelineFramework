<?php

declare(strict_types=1);

namespace Weline\Queue\Api;

/**
 * Marks a consumer whose pending auto rows should be drained in-process.
 *
 * Dispatch keeps at most one active Worker for the consumer class; that Worker
 * may claim and finish sibling pending rows after its primary job without
 * spawning additional PHP CLI processes.
 */
interface BatchDrainingQueueConsumerInterface
{
    /**
     * Maximum sibling pending auto rows to drain after the primary job.
     * Values below 1 disable sibling drain (class still stays single-flight).
     */
    public function batchDrainLimit(): int;
}
