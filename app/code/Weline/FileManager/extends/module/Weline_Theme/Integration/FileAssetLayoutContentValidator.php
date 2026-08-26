<?php

declare(strict_types=1);

namespace Weline\FileManager\extends\module\Weline_Theme\Integration;

use Weline\FileManager\Api\LayoutContentValidatorInterface as FileManagerLayoutContentValidatorInterface;
use Weline\Theme\Api\Layout\LayoutContentValidatorInterface;

/** Optional Theme adapter; keeps the FileManager core free of a hard Theme dependency. */
final class FileAssetLayoutContentValidator implements LayoutContentValidatorInterface
{
    public function __construct(
        private readonly FileManagerLayoutContentValidatorInterface $validator,
    ) {
    }

    public function validate(array $layoutData, array $context): void
    {
        $this->validator->validate($layoutData, $context);
    }
}
