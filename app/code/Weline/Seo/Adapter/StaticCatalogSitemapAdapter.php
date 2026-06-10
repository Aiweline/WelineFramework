<?php

declare(strict_types=1);

namespace Weline\Seo\Adapter;

class StaticCatalogSitemapAdapter extends AbstractSitemapPlatformAdapter
{
    public function __construct(
        private readonly string $platformCode,
        private readonly string $platformName,
        private readonly string $platformColor = '#6c757d',
        private readonly int $maxUrls = 50000,
        private readonly int $maxSize = 52428800
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

    public function getMaxUrlsPerFile(): int
    {
        return $this->maxUrls;
    }

    public function getMaxFileSizeBytes(): int
    {
        return $this->maxSize;
    }
}
