<?php

declare(strict_types=1);

namespace Weline\Queue\Api;

/**
 * Public consumer contract for queue handlers.
 *
 * The legacy Weline\Queue\QueueInterface remains supported by the runtime for
 * third-party compatibility, but new consumers should implement this contract.
 */
interface QueueConsumerInterface
{
    public function name(): string;

    /**
     * Queue type attribute descriptors. The runtime validates their concrete
     * representation while keeping it out of this public signature.
     *
     * @return array<int, mixed>
     */
    public function attributes(): array;

    public function tip(): string;

    public function execute(QueueTaskContextInterface $queue): string;

    public function validate(QueueTaskContextInterface $queue): bool;
}
