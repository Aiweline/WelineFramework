<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\UnitTest\TestCore;
use Weline\Seo\Service\FeedRegistryService;

/**
 * FeedRegistryService 服务测试
 * 
 * @package Weline_Seo
 */
class FeedRegistryServiceTest extends TestCore
{
    /**
     * 测试服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(FeedRegistryService::class);
        $this->assertInstanceOf(FeedRegistryService::class, $service);
    }

    /**
     * 测试获取 Providers
     */
    public function testGetProviders(): void
    {
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(FeedRegistryService::class);
        $providers = $service->getProviders();
        
        $this->assertIsArray($providers);
    }

    /**
     * 测试按主体类型获取 Providers
     */
    public function testGetProvidersBySubjectType(): void
    {
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(FeedRegistryService::class);
        $providers = $service->getProvidersBySubjectType('store');
        
        $this->assertIsArray($providers);
    }
}

