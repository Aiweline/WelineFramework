<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Marker: ObjectManager keeps one process-wide instance even inside WLS Fibers.
 *
 * Use for cache coordinators and other request-stateless services whose durable
 * data lives in process bags or the Memory Service. Request-varying state must
 * go through {@see RequestContext}, never mutable fields on the shared instance
 * that assume a single concurrent request.
 *
 * Fiber-local request context objects implement {@see RequestLocalInterface}
 * instead (default for unmarked classes remains fiber-local for safety).
 */
interface ProcessSharedInterface
{
}
