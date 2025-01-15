<?php

namespace Gvanda\Product\Cache;

use Weline\Framework\Cache\CacheFactory;

class FrontendCacheFactory extends CacheFactory
{
    public function __construct($identity = 'product_frontend_cache', $tip = '产品后台缓存', $permanently = false)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
