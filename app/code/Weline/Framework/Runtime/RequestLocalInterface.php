<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Marker: ObjectManager keeps a Fiber/request-local instance under WLS.
 *
 * Reserve this for true request context (Request/Response and similar). Fat
 * caches must not live here — use process bags, {@see ProcessSharedInterface},
 * or the Memory Service.
 */
interface RequestLocalInterface
{
}
