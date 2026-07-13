<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Asset;

use Weline\Theme\Service\ThemeStaticAssetPublisher;

final class StaticAssetPublisher implements StaticAssetPublisherInterface
{
    public function __construct(private readonly ThemeStaticAssetPublisher $publisher)
    {
    }

    public function publishForRequestPath(string $requestPath): ?string
    {
        return $this->publisher->publishForRequestPath($requestPath);
    }
}
