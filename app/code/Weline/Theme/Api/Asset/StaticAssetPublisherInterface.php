<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Asset;

interface StaticAssetPublisherInterface
{
    public function publishForRequestPath(string $requestPath): ?string;
}
