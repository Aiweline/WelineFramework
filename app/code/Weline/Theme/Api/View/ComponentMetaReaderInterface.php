<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

interface ComponentMetaReaderInterface
{
    /** @return array<string,mixed> */
    public function parse(string $filePath): array;
}
