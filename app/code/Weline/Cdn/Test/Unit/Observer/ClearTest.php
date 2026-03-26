<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Observer\Clear;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Event\Event;

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

    public function testObserverInstantiation(): void
    {
        $this->assertInstanceOf(Clear::class, $this->observer);
    }

    public function testExecuteDomainEmpty(): void
    {
        $event = new Event('Weline_Cdn::clear', []);
        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testExecuteSuccess(): void
    {
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->with('example.com', 'everything', [])
            ->willReturn(['success' => true, 'message' => 'ok']);

        $event = new Event('Weline_Cdn::clear', [
            'domain' => 'example.com',
            'mode' => 'everything',
        ]);

        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['message']);
    }

    public function testExecuteDefaultMode(): void
    {
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->with('example.com', 'everything', [])
            ->willReturn(['success' => true, 'message' => 'ok']);

        $event = new Event('Weline_Cdn::clear', ['domain' => 'example.com']);
        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertTrue($result['success']);
    }

    public function testExecuteException(): void
    {
        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->willThrowException(new \Exception('clear failed'));

        $event = new Event('Weline_Cdn::clear', [
            'domain' => 'example.com',
            'mode' => 'everything',
        ]);

        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('clear failed', $result['message']);
    }

    public function testExecutePurgeUrls(): void
    {
        $payload = [
            'domain' => 'example.com',
            'mode' => 'urls',
            'urls' => ['url1', 'url2'],
        ];

        $this->cachePurger->expects($this->once())
            ->method('purge')
            ->with('example.com', 'urls', ['urls' => ['url1', 'url2']])
            ->willReturn(['success' => true, 'message' => 'ok']);

        $event = new Event('Weline_Cdn::clear', $payload);
        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertTrue($result['success']);
    }
}
