<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Runtime;

use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\RequestResetterInterface;

/**
 * Per-request isolation for Framework request-scoped state.
 *
 * RequestContext::init() already clears storage at every request start (first
 * line of defence); registering this capability via ModuleRequestResetterRegistry
 * is the second line required by the performance review (§7), so request-scoped
 * FPC memos cannot survive a worker boundary even if init() were skipped for a
 * request or storage were captured elsewhere.
 *
 * Deliberately clears ONLY request-scoped memos — never
 * FullPageCacheCoordinator::clearProcessCache(), which owns cross-request
 * payload/receipt caches and would destroy shared warm caches if run per request.
 */
final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        $failures = [];

        try {
            FullPageCacheCoordinator::clearRequestMemos();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'fpc_request_memos', $throwable);
        }

        if ($failures !== []) {
            throw new RequestResetException('framework_request_resetter', $failures);
        }
    }
}
