<?php

declare(strict_types=1);

namespace Weline\Ai\Exception;

/**
 * Local/remote AI HTTP transport failure (connect refused, DNS, reset, etc.).
 *
 * Extends RuntimeException so construction does not write exception.log.
 * Callers may still log once via throttled channels.
 */
class AiTransportException extends \RuntimeException
{
}
