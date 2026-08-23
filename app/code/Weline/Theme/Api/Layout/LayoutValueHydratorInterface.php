<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/** Optional runtime resolver for typed values stored inside Theme layout data. */
interface LayoutValueHydratorInterface
{
    /** @param array<string,mixed> $node */
    public function supports(array $node): bool;

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context Explicit request/job context.
     */
    public function hydrate(array $node, array $context): HydratedLayoutValue;
}
