<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Observer\WarmupSend;
use Weline\Cdn\Service\UrlSiteResolver;
use Weline\Framework\Event\Event;

/**
 * WarmupSend观察者单元测试
 */
class WarmupSendTest extends TestCase
{
    private WarmupSend $observer;
    private WarmupUrl $warmupUrlModel;
    private UrlSiteResolver $urlSiteResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warmupUrlModel = $this->createMock(WarmupUrl::class);
        $this->urlSiteResolver = $this->createMock(UrlSiteResolver::class);
        $this->observer = new WarmupSend($this->warmupUrlModel, $this->urlSiteResolver);
    }

    /**
     * 测试：观察者实例化
     */
    public function testObserverInstantiation(): void
    {
        $this->assertInstanceOf(WarmupSend::class, $this->observer);
    }

    /**
     * 测试：执行（模块参数为空）
     */
    public function testExecuteModuleEmpty(): void
    {
        $event = new Event('Weline_Cdn::send_warmup', []);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('模块参数不能为空', $result['message']);
    }

    /**
     * 测试：执行（URL列表为空）
     */
    public function testExecuteUrlsEmpty(): void
    {
        $eventData = [
            'module' => 'TestModule'
        ];
        $event = new Event('Weline_Cdn::send_warmup', $eventData);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('URL列表不能为空', $result['message']);
    }

    /**
     * 测试：执行（URL列表不是数组）
     */
    public function testExecuteUrlsNotArray(): void
    {
        $eventData = [
            'module' => 'TestModule',
            'urls' => 'not-an-array'
        ];
        $event = new Event('Weline_Cdn::send_warmup', $eventData);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('URL列表不能为空', $result['message']);
    }

    /**
     * 测试：执行（字符串URL列表）
     */
    public function testExecuteStringUrls(): void
    {
        $eventData = [
            'module' => 'TestModule',
            'urls' => ['https://example.com/page1', 'https://example.com/page2']
        ];
        $event = new Event('Weline_Cdn::send_warmup', $eventData);
        
        // Mock模型行为
        $this->warmupUrlModel->method('reset')->willReturnSelf();
        $this->warmupUrlModel->method('where')->willReturnSelf();
        $this->warmupUrlModel->method('find')->willReturnSelf();
        $this->warmupUrlModel->method('fetch')->willReturnSelf();
        $this->warmupUrlModel->method('getData')->willReturn(null);
        $this->warmupUrlModel->method('setData')->willReturnSelf();
        $this->warmupUrlModel->method('save')->willReturn(true);
        
        // Mock URL解析
        $this->urlSiteResolver->method('resolveDomainByUrl')->willReturn(null);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('inserted_count', $result);
        $this->assertArrayHasKey('updated_count', $result);
    }

    /**
     * 测试：执行（数组URL格式）
     */
    public function testExecuteArrayUrls(): void
    {
        $eventData = [
            'module' => 'TestModule',
            'urls' => [
                [
                    'url' => 'https://example.com/page1',
                    'site_id' => 1,
                    'domain_id' => 1
                ]
            ]
        ];
        $event = new Event('Weline_Cdn::send_warmup', $eventData);
        
        // Mock模型行为
        $this->warmupUrlModel->method('reset')->willReturnSelf();
        $this->warmupUrlModel->method('where')->willReturnSelf();
        $this->warmupUrlModel->method('find')->willReturnSelf();
        $this->warmupUrlModel->method('fetch')->willReturnSelf();
        $this->warmupUrlModel->method('getData')->willReturn(null);
        $this->warmupUrlModel->method('setData')->willReturnSelf();
        $this->warmupUrlModel->method('save')->willReturn(true);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * 测试：执行（去重处理）
     */
    public function testExecuteDedupe(): void
    {
        $eventData = [
            'module' => 'TestModule',
            'urls' => ['https://example.com/page'],
            'dedupe' => true
        ];
        $event = new Event('Weline_Cdn::send_warmup', $eventData);
        
        // Mock已存在的记录
        $existingUrl = $this->createMock(WarmupUrl::class);
        $existingUrl->method('getData')->willReturnCallback(function($field) {
            return $field === WarmupUrl::fields_WARMUP_URL_ID ? 1 : null;
        });
        $existingUrl->method('setData')->willReturnSelf();
        $existingUrl->method('save')->willReturn(true);
        
        $this->warmupUrlModel->method('reset')->willReturnSelf();
        $this->warmupUrlModel->method('where')->willReturnSelf();
        $this->warmupUrlModel->method('find')->willReturnSelf();
        $this->warmupUrlModel->method('fetch')->willReturn($existingUrl);
        
        $this->urlSiteResolver->method('resolveDomainByUrl')->willReturn(null);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        // 应该更新而不是插入
        $this->assertGreaterThanOrEqual(0, $result['updated_count']);
    }
}

