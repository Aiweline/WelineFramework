<?php

declare(strict_types=1);

namespace Weline\Seo\Adapter;

class YandexSitemapAdapter extends IndexNowSitemapAdapter
{
    protected const PLATFORM_CODE = 'yandex';
    protected const PLATFORM_NAME = 'Yandex';
    protected const PLATFORM_COLOR = '#FF0000';
    protected const INDEXNOW_ENDPOINT = 'https://yandex.com/indexnow';
}
