<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\CacheFactory;

class Cache
{
    /**
     * @var CacheFactory
     */
    private CacheFactory $cacheManager;

    public function __construct(
        CacheFactory $cacheManager
    )
    {
        $this->cacheManager = $cacheManager;
    }

    public function cache(string $driver = ''): CachePoolInterface
    {
        return $this->cacheManager->create($driver);
    }
}
