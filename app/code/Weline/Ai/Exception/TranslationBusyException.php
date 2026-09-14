<?php

declare(strict_types=1);

namespace Weline\Ai\Exception;

/**
 * Expected single-flight contention on a translation lane.
 *
 * Extends RuntimeException (not Framework App Exception) so constructing it
 * does not write exception.log — callers treat BUSY as control flow for the
 * next cron round, not as an unhandled failure storm.
 */
class TranslationBusyException extends \RuntimeException
{
}
