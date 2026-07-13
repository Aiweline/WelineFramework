<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

final class TaskStopRequestedException extends \RuntimeException
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $reason = '',
    ) {
        parent::__construct('Resumable task stop requested' . ($reason === '' ? '.' : ': ' . $reason));
    }
}
