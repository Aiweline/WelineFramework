<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

/**
 * First-class template render modes for ViewEngine.
 *
 * - stream: include into an already-open capture frame (no nested begin/end)
 * - capture: outer begin/end once; return string; child partials default to stream
 * - include: caller already resolved a compiled path; setData + include only
 */
enum RenderMode: string
{
    case Stream = 'stream';
    case Capture = 'capture';
    case Include = 'include';
}
