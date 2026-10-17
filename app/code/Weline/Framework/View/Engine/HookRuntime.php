<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

use Weline\Framework\Runtime\RequestContext;

/**
 * Decides whether getHook should stream into the open capture or capture a string.
 *
 * Hard rule: under an open capture, children default to stream; only explicit
 * require_string / decorate paths nest a string capture.
 */
final class HookRuntime
{
    public static function preferStreamIntoCapture(bool $decorateHookOutput): bool
    {
        if (!CaptureSession::hasActive()) {
            return false;
        }
        if ($decorateHookOutput) {
            return false;
        }
        if (RequestContext::get(RenderContext::REQUIRE_STRING_HOOK)) {
            return false;
        }

        return true;
    }
}
