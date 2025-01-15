<?php

namespace WeShop\Product\Cache;

use Weline\Framework\Cache\CacheFactory;

class BackendCacheFactory extends CacheFactory
{
    public function __construct($identity = 'product_backend_cache', $tip = '产品后台缓存', $permanently = false)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
