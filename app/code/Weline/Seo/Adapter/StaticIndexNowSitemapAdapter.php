<?php

declare(strict_types=1);

namespace Weline\Seo\Adapter;

class StaticIndexNowSitemapAdapter extends IndexNowSitemapAdapter
{
    public function __construct(
        private readonly string $platformCode,
        private readonly string $platformName,
        private readonly string $platformColor,
        private readonly string $indexNowEndpoint
    ) {
    }

    public function getPlatformCode(): string
    {
        return $this->platformCode;
    }

    public function getPlatformName(): string
    {
        return $this->platformName;
    }

    public function getPlatformColor(): string
    {
        return $this->platformColor;
    }

    protected function getDefaultIndexNowEndpoint(): string
    {
        return $this->indexNowEndpoint;
    }
}
