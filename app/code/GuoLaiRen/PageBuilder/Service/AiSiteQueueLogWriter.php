<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;

/**
 * Queue-side status/log writer.
 *
 * Queue workers should depend on this semantic wrapper instead of wiring
 * themselves to the browser SSE transport. The inherited implementation only
 * persists compact queue/session telemetry, including bounded Stage-1 page and
 * block progress derived from the queue payload.
 */
class AiSiteQueueLogWriter extends QueueDbWriter
{
}
