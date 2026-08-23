<?php

declare(strict_types=1);

namespace Weline\FileManager\Api;

/** FileManager-owned validation boundary; Theme integration is supplied by an optional adapter. */
interface LayoutContentValidatorInterface
{
    /**
     * @param array<string,mixed> $layoutData
     * @param array<string,mixed> $context
     */
    public function validate(array $layoutData, array $context): void;
}
