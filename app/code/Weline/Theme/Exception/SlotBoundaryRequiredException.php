<?php

declare(strict_types=1);

namespace Weline\Theme\Exception;

use Weline\Framework\App\Exception;

/**
 * Raised when layout HTML contains data-wslot but no compile-time boundary markers.
 */
final class SlotBoundaryRequiredException extends Exception
{
}
