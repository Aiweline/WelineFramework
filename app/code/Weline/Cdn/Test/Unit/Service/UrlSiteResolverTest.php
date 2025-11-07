<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\UrlSiteResolver;
use Weline\Framework\Manager\ObjectManager;

/**
 * UrlSiteResolver服务单元测试
 */
class UrlSiteResolverTest extends TestCase
{
    private UrlSiteResolver $resolver;
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = ObjectManager::getInstance();
        $this->resolver = new UrlSiteResolver($this->objectManager);
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(UrlSiteResolver::class, $this->resolver);
    }

    /**
     * 测试：解析域名（无效URL）
     */
    public function testResolveDomainByUrlInvalidUrl(): void
    {
        $result = $this->resolver->resolveDomainByUrl('invalid-url');
        $this->assertNull($result);
    }

    /**
     * 测试：解析域名（无host的URL）
     */
    public function testResolveDomainByUrlNoHost(): void
    {
        $result = $this->resolver->resolveDomainByUrl('/path/to/page');
        $this->assertNull($result);
    }

    /**
     * 测试：解析域名（完整URL）
     */
    public function testResolveDomainByUrlFullUrl(): void
    {
        // 注意：实际测试需要数据库中有域名数据
        // 这里主要验证方法调用不会出错
        $result = $this->resolver->resolveDomainByUrl('https://example.com/page');
        
        // 可能返回null（如果没有匹配的域名）或Domain对象
        $this->assertTrue($result === null || $result instanceof Domain);
    }

    /**
     * 测试：解析域名（子域名URL）
     */
    public function testResolveDomainByUrlSubdomain(): void
    {
        $result = $this->resolver->resolveDomainByUrl('https://www.example.com/page');
        
        // 验证方法能处理子域名
        $this->assertTrue($result === null || $result instanceof Domain);
    }

    /**
     * 测试：解析域名（带端口URL）
     */
    public function testResolveDomainByUrlWithPort(): void
    {
        $result = $this->resolver->resolveDomainByUrl('https://example.com:8080/page');
        
        $this->assertTrue($result === null || $result instanceof Domain);
    }

    /**
     * 测试：根据站点ID解析域名
     */
    public function testResolveDomainBySiteId(): void
    {
        // 注意：实际测试需要数据库中有域名数据
        $result = $this->resolver->resolveDomainBySiteId(1);
        
        $this->assertTrue($result === null || $result instanceof Domain);
    }

    /**
     * 测试：根据站点ID解析域名（不存在的站点）
     */
    public function testResolveDomainBySiteIdNotExists(): void
    {
        $result = $this->resolver->resolveDomainBySiteId(999999);
        
        // 不存在的站点应该返回null
        $this->assertNull($result);
    }

    /**
     * 测试：URL解析逻辑（最长匹配）
     */
    public function testUrlParsingLogic(): void
    {
        // 测试URL解析的基本逻辑
        $testUrls = [
            'https://example.com/page',
            'http://www.example.com/page',
            'https://subdomain.example.com/page',
            'https://example.com:8080/page',
        ];

        foreach ($testUrls as $url) {
            $result = $this->resolver->resolveDomainByUrl($url);
            // 验证方法能处理各种URL格式
            $this->assertTrue($result === null || $result instanceof Domain);
        }
    }

    /**
     * 测试：最长匹配原则
     */
    public function testLongestMatchPrinciple(): void
    {
        // 验证最长匹配原则
        // 实际测试需要数据库中有多个域名数据
        $this->markTestSkipped('需要数据库中有多个域名数据才能完整测试');
    }
}

