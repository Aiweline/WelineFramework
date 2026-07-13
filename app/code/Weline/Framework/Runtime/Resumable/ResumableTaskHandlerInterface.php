<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Registered business handler. Browser input selects its type code, never a
 * PHP class name.
 */
interface ResumableTaskHandlerInterface
{
    public function typeCode(): string;

    /**
     * @param array<string|int, mixed> $input
     */
    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult;
}
