<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Widget\Api\Param\FileImagePreviewResolverInterface;

final class NullFileImagePreviewResolver implements FileImagePreviewResolverInterface
{
    public function resolvePreviewUrl(array $node): string
    {
        return '';
    }
}
