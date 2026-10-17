<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * How much request projection a post-response task retains until drain.
 *
 * - NONE: closure carries all dependencies; no fiber/context snapshot.
 * - SCOPE: ScopeEnvelope + lang/currency + area/origin pins for SWR re-render
 *   (no $_POST/FILES / full static bag / HeaderCollector).
 * - FULL: shared WlsFiberContext snapshot per request id (default for unknown callers;
 *   post-response capture skips HeaderCollector — drain uses restore(false)).
 */
enum PostResponseCaptureMode: string
{
    case None = 'none';
    case Scope = 'scope';
    case Full = 'full';
}
