<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

use Weline\Framework\Runtime\FiberOutputBuffer;

/**
 * Thin facade over process-level Fiber OB capture state.
 * Process OB strategy stays in FiberOutputBuffer; this class only exposes
 * engine-facing predicates used by ViewEngine / HookRuntime.
 */
final class CaptureSession
{
    public static function hasActive(): bool
    {
        return FiberOutputBuffer::hasActiveCapture();
    }
}
