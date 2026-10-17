<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

/**
 * Named RequestContext / Template data-bag keys for the ViewEngine runtime.
 * Dictionary push/pop and activeSourceOrigin remain on Template; this class
 * documents the cross-cutting keys engines and helpers must share.
 */
final class RenderContext
{
    /** Force getHook to return a string even under an open capture (string concat consumers). */
    public const REQUIRE_STRING_HOOK = 'view.hook.require_string_result';

    /** Request-scoped override for {@see EngineFlags}. */
    public const ENGINE_V2_FLAG = EngineFlags::REQUEST_KEY;

    /** RequestContext memo prefix for getFetchFile (Template owns the map). */
    public const FETCH_FILE_MEMO_PREFIX = 'view.fetch_file:';

    /** RequestContext memo prefix for hook contributor file lists. */
    public const HOOK_FILES_MEMO_PREFIX = 'view.hook.files:';
}
