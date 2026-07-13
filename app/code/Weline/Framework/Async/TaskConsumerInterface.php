<?php

declare(strict_types=1);

namespace Weline\Framework\Async;

/**
 * Runtime-neutral contract implemented by asynchronous task handlers.
 */
interface TaskConsumerInterface
{
    public function name(): string;

    /**
     * @return array<int, mixed>
     */
    public function attributes(): array;

    public function tip(): string;

    public function execute(TaskContextInterface $task): string;

    public function validate(TaskContextInterface $task): bool;
}
