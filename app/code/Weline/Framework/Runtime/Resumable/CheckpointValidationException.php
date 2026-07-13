<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Raised when data cannot safely be persisted as a resumable-task checkpoint.
 */
final class CheckpointValidationException extends \InvalidArgumentException
{
}
