<?php

declare(strict_types=1);

/*
 * Shopify订单管理缓存类
 * 用于缓存Shopify相关的数据，如店铺信息、API响应等
 */

namespace FlashForge\ShopifyOrderManager\Cache;

use Weline\Framework\Cache\CacheFactory;

class ShopifyCache extends CacheFactory
{
    private const CACHE_IDENTITY = 'shopify_order_manager';
    private const CACHE_TIP = 'Shopify订单管理缓存';
        
    /**
     * 构造函数
     * 
     * @param string $identity 缓存标识
     * @param string $tip 缓存说明
     * @param bool $permanently 是否持久缓存
     */
    public function __construct(string $identity = self::CACHE_IDENTITY, string $tip = self::CACHE_TIP, bool $permanently = false)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
