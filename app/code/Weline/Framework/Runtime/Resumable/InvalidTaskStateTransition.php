<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

final class InvalidTaskStateTransition extends \LogicException
{
    public function __construct(
        public readonly ResumableTaskStatus $from,
        public readonly ResumableTaskStatus $to,
    ) {
        parent::__construct("Resumable task cannot transition from {$from->value} to {$to->value}.");
    }
}
