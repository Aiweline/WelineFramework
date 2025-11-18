<?php

namespace Weline\FileManager\Cache;

use Weline\Framework\Cache\CacheFactory;

class FileManagerCacheFactory extends CacheFactory
{
    public function __construct(string $identity = 'file-manager', string $tip = '文件管理器缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }

}
