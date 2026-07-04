<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/23 12:22:13
 */

namespace Weline\Framework\Cache\test;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Manager\ObjectManager;

use function PHPUnit\Framework\assertTrue;

class CacheTest extends \Weline\Framework\UnitTest\TestCore
{
    public function testMemcachedCache()
    {
        if (class_exists(\Memcached::class)) {
            /**@var \Weline\Framework\Cache\Driver\Memcached $cache */
            $cache     = ObjectManager::makeWithoutFactory(CacheFactory::class)->create('memcached');
            $cache_key = 'test_memcached_object_cache';
            $cache->set($cache_key, new DataObject(['test_memcached_object_cache' => 'ok']));
            /**@var DataObject $data */
            $data = $cache->get($cache_key);
            assertTrue($data->getData('test_memcached_object_cache') === 'ok', 'Memcached:缓存对象成功！');
        } else {
            assertTrue(true, 'Memcached:缓存对象跳过！');
        }
    }
}
