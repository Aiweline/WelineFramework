<?php

namespace Shopify\Reviews\Cache;

use Weline\Framework\Cache\CacheFactory;

class Cache extends CacheFactory
{
    function __construct(string $identity = 'reviews_cache', string $tip = 'Shopify评论缓存！', bool $permanently = false)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}