<?php

namespace Weline\CKEditorEditorManager\Cache;

use Weline\Framework\Cache\CacheFactory;

class CKEditorEditorManagerCacheFactory extends CacheFactory
{
    public function __construct(string $identity = 'el-finder-file-manager', string $tip = 'Elfinder文件管理器缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
