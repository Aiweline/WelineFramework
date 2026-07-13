<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Deliberately indistinguishable task/owner/lease denial.
 *
 * Transport adapters map this exception to a uniform not-found response so a
 * caller cannot use a task identifier to discover another principal's work.
 */
final class ResumableTaskAccessDeniedException extends \RuntimeException
{
}
