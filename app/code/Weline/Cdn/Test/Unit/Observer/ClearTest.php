<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Observer\Clear;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Event\Event;

/**
 * Clear观察者单元测试
 */
class ClearTest extends TestCase
{
    private Clear $observer;
    private CachePurger $cachePurger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePurger = $this->createMock(CachePurger::class);
        $this->observer = new Clear($this->cachePurger);
    }

    /**
     * 测试：观察者实例化
     */
    public function testObserverInstantiation(): void
    {
        $this->assertInstanceOf(Clear::class, $this->observer);
    }

    /**
     * 测试：执行清理（域名参数为空）
     */
    public function testExecuteDomainEmpty(): void
    {
        $event = new Event('Weline_Cdn::clear', []);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('域名参数不能为空', $result['message']);
    }

    /**
     * 测试：执行清理（成功场景）
     */
    public function testExecuteSuccess(): void
    {
        $eventData = [
            'domain' => 'example.com',
            'mode' => 'everything',
            'data' => []
        ];
        $event = new Event('Weline_Cdn::clear', $eventData);
        
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->with('example.com', 'everything', [])
            ->willReturn(['success' => true, 'message' => '清理成功']);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('清理成功', $result['message']);
    }

    /**
     * 测试：执行清理（默认模式）
     */
    public function testExecuteDefaultMode(): void
    {
        $eventData = [
            'domain' => 'example.com'
        ];
        $event = new Event('Weline_Cdn::clear', $eventData);
        
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->with('example.com', 'everything', [])
            ->willReturn(['success' => true, 'message' => '清理成功']);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertTrue($result['success']);
    }

    /**
     * 测试：执行清理（异常处理）
     */
    public function testExecuteException(): void
    {
        $eventData = [
            'domain' => 'example.com',
            'mode' => 'everything'
        ];
        $event = new Event('Weline_Cdn::clear', $eventData);
        
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->willThrowException(new \Exception('清理失败'));
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('清理失败', $result['message']);
    }

    /**
     * 测试：执行清理（按URL清理）
     */
    public function testExecutePurgeUrls(): void
    {
        $eventData = [
            'domain' => 'example.com',
            'mode' => 'urls',
            'data' => ['urls' => ['url1', 'url2']]
        ];
        $event = new Event('Weline_Cdn::clear', $eventData);
        
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->with('example.com', 'urls', ['urls' => ['url1', 'url2']])
            ->willReturn(['success' => true, 'message' => '清理成功']);
        
        $this->observer->execute($event);
        
        $result = $event->getData('result');
        $this->assertTrue($result['success']);
    }
}

