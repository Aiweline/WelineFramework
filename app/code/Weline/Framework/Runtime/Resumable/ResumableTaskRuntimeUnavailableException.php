<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * The resumable runtime cannot safely serve a request at the moment.
 *
 * This is intentionally distinct from an access denial: callers may retry a
 * temporary runtime outage, but must receive no task-existence information.
 */
final class ResumableTaskRuntimeUnavailableException extends \RuntimeException
{
}
