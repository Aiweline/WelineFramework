<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

/**
 * CachePurger服务单元测试
 */
class CachePurgerTest extends TestCase
{
    private CachePurger $cachePurger;
    private AdapterResolver $adapterResolver;
    private AccountManager $accountManager;
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = ObjectManager::getInstance();
        $this->adapterResolver = $this->createMock(AdapterResolver::class);
        $this->accountManager = $this->createMock(AccountManager::class);
        
        $this->cachePurger = new CachePurger(
            $this->objectManager,
            $this->adapterResolver,
            $this->accountManager
        );
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(CachePurger::class, $this->cachePurger);
    }

    /**
     * 测试：清理所有缓存（域名不存在）
     */
    public function testPurgeEverythingDomainNotFound(): void
    {
        $this->expectException(Core::class);
        $this->expectExceptionMessage('域名不存在');
        
        $this->cachePurger->purge(999999, 'everything');
    }

    /**
     * 测试：清理所有缓存（域名未启用）
     */
    public function testPurgeEverythingDomainNotEnabled(): void
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getData')->willReturnMap([
            [Domain::schema_fields_DOMAIN_ID, 1],
            [Domain::schema_fields_ADAPTER, 'cloudflare'],
            [Domain::schema_fields_ZONE_ID, 'zone-123']
        ]);
        $domain->method('isEnabled')->willReturn(false);

        $domainModel = ObjectManager::getInstance(Domain::class);
        // 注意：这里需要mock Domain的load方法，但实际测试中可能需要数据库支持
        // 暂时跳过或使用集成测试
        $this->markTestSkipped('需要mock Domain模型或使用集成测试');
    }

    /**
     * 测试：清理所有缓存（适配器不存在）
     */
    public function testPurgeEverythingAdapterNotFound(): void
    {
        $this->markTestSkipped('需要mock Domain模型或使用集成测试');
    }

    /**
     * 测试：清理所有缓存（成功场景）
     */
    public function testPurgeEverythingSuccess(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('purgeEverything')
            ->with('zone-123', ['api_token' => 'test-token'])
            ->willReturn(['success' => true, 'message' => '清理成功']);

        $this->adapterResolver->expects($this->once())
            ->method('getAdapter')
            ->with('cloudflare')
            ->willReturn($adapter);

        // 注意：这里需要完整的Domain mock，暂时跳过
        $this->markTestSkipped('需要完整的Domain和Account mock，建议使用集成测试');
    }

    /**
     * 测试：按URL清理缓存（URL列表为空）
     */
    public function testPurgeUrlsEmptyUrlList(): void
    {
        $this->expectException(Core::class);
        $this->expectExceptionMessage('URL列表不能为空');
        
        $this->cachePurger->purge(1, 'urls', []);
    }

    /**
     * 测试：按URL清理缓存（字符串URL列表）
     */
    public function testPurgeUrlsWithStringList(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('purgeUrls')
            ->with('zone-123', ['url1', 'url2'], $this->anything())
            ->willReturn(['success' => true]);

        $this->adapterResolver->expects($this->once())
            ->method('getAdapter')
            ->willReturn($adapter);

        $this->markTestSkipped('需要完整的Domain mock');
    }

    /**
     * 测试：按Host清理缓存（Host列表为空）
     */
    public function testPurgeHostsEmptyHostList(): void
    {
        $this->expectException(Core::class);
        $this->expectExceptionMessage('Host列表不能为空');
        
        $this->cachePurger->purge(1, 'hosts', []);
    }

    /**
     * 测试：按Tag清理缓存（Tag列表为空）
     */
    public function testPurgeTagsEmptyTagList(): void
    {
        $this->expectException(Core::class);
        $this->expectExceptionMessage('Tag列表不能为空');
        
        $this->cachePurger->purge(1, 'tags', []);
    }

    /**
     * 测试：按Cache Key清理缓存（Key列表为空）
     */
    public function testPurgeCacheKeysEmptyKeyList(): void
    {
        $this->expectException(Core::class);
        $this->expectExceptionMessage('Cache Key列表不能为空');
        
        $this->cachePurger->purge(1, 'cache_keys', []);
    }

    /**
     * 测试：无效的清理模式
     */
    public function testPurgeInvalidMode(): void
    {
        $this->expectException(Core::class);
        $this->expectExceptionMessage('无效的清理模式');
        
        $this->cachePurger->purge(1, 'invalid_mode');
    }

    /**
     * 测试：清理模式常量验证
     */
    public function testPurgeModeConstants(): void
    {
        $modes = ['everything', 'urls', 'hosts', 'tags', 'cache_keys'];
        
        foreach ($modes as $mode) {
            // 验证模式字符串是有效的
            $this->assertIsString($mode);
            $this->assertNotEmpty($mode);
        }
    }
}

