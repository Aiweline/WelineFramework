<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Api\View\ComponentMetaReaderInterface;
use Weline\Theme\Helper\ComponentMetaParser;

final class ComponentMetaReader implements ComponentMetaReaderInterface
{
    public function parse(string $filePath): array
    {
        return ComponentMetaParser::parse($filePath);
    }
}
