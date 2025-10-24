<?php

namespace Weline\EditorManager\Cache;

use Weline\Framework\Cache\CacheFactory;

class EditorManagerCacheFactory extends CacheFactory
{
    public function __construct(string $identity = 'editor-manager', string $tip = '编辑器管理器缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }

}
