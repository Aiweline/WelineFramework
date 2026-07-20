<?php
declare(strict_types=1);

namespace Weline\Api\Api\Framework;

/**
 * Frontend REST registration point for framework-managed SSE streams.
 *
 * API route discovery intentionally scans the Api directory.  Keeping this
 * wrapper here makes /api/framework/stream resolve through the REST router,
 * rather than being accidentally registered as a PC controller route.
 */
class Stream extends \Weline\Framework\Controller\Api\Stream
{
}
