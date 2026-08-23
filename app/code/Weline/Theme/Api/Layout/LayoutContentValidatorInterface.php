<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/** Extension point for modules that own typed nodes embedded in Theme layout data. */
interface LayoutContentValidatorInterface
{
    /**
     * @param array<string,mixed> $layoutData
     * @param array<string,mixed> $context Explicit request/job context; implementations must not read prior-request state.
     */
    public function validate(array $layoutData, array $context): void;
}
