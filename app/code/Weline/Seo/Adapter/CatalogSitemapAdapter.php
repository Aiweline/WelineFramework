<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

abstract class CatalogSitemapAdapter extends AbstractSitemapPlatformAdapter
{
    protected const PLATFORM_CODE = '';
    protected const PLATFORM_NAME = '';
    protected const PLATFORM_COLOR = '#6c757d';
    protected const MAX_URLS = 50000;
    protected const MAX_SIZE = 52428800;

    public function getPlatformCode(): string
    {
        return static::PLATFORM_CODE;
    }

    public function getPlatformName(): string
    {
        return static::PLATFORM_NAME;
    }

    public function getPlatformColor(): string
    {
        return static::PLATFORM_COLOR;
    }

    public function getMaxUrlsPerFile(): int
    {
        return static::MAX_URLS;
    }

    public function getMaxFileSizeBytes(): int
    {
        return static::MAX_SIZE;
    }
}
