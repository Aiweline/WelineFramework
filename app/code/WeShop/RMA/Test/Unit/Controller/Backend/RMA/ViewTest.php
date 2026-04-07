<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Backend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Backend\RMA\View;
use WeShop\RMA\Model\Rma;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

final class ViewTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(Rma::class);
        parent::tearDown();
    }

    public function testViewLoadsRmaById(): void
    {
        $rmaModel = $this->createRmaModel([
            'rma_id' => 1,
            'order_id' => 11,
            'customer_id' => 22,
            'reason' => 'Damaged',
            'description' => 'Box crushed',
            'status' => 'pending',
            'created_at' => '2026-04-01 00:00:00',
            'updated_at' => '2026-04-02 00:00:00',
        ]);
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static fn (string $key, mixed $value) => $controller);
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 1]));
        $this->setControllerUrl($controller);

        self::assertSame('page', $controller->index());
    }

    public function testViewLoadsRmaByRmaId(): void
    {
        $rmaModel = $this->createRmaModel([
            'rma_id' => 5,
            'order_id' => 11,
            'customer_id' => 22,
            'reason' => 'Damaged',
            'description' => 'Box crushed',
            'status' => 'pending',
            'created_at' => '2026-04-01 00:00:00',
            'updated_at' => '2026-04-02 00:00:00',
        ]);
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static fn (string $key, mixed $value) => $controller);
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock(['rma_id' => 5]));
        $this->setControllerUrl($controller);

        self::assertSame('page', $controller->index());
    }

    public function testViewRedirectsWhenNoIdProvided(): void
    {
        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects($this->once())->method('redirect')->with('/admin/rma/index')->willReturn('redirected');

        $this->setControllerRequest($controller, $this->createRequestMock());
        $this->setControllerUrl($controller);

        self::assertSame('', $controller->index());
    }

    public function testViewAssignsCorrectData(): void
    {
        $rmaModel = $this->createRmaModel([
            'rma_id' => 1,
            'order_id' => 11,
            'customer_id' => 22,
            'reason' => 'Damaged',
            'description' => 'Box crushed',
            'status' => 'pending',
            'created_at' => '2026-04-01 00:00:00',
            'updated_at' => '2026-04-02 00:00:00',
        ]);
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignedData = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignedData, $controller) {
                $assignedData[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 1]));
        $this->setControllerUrl($controller);

        $controller->index();

        self::assertArrayHasKey('rma', $assignedData);
        self::assertArrayHasKey('rmaIndexUrl', $assignedData);
        self::assertArrayHasKey('rmaApproveUrl', $assignedData);
        self::assertArrayHasKey('rmaRejectUrl', $assignedData);
    }

    private function createRmaModel(array $record): Rma
    {
        return new class($record) extends Rma {
            public function __construct(private readonly array $record)
            {
            }

            public function load(int|string $field_or_pk_value, $value = null): static
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return $this->record['rma_id'] ?? $default;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return $this->record[$key] ?? $index;
            }
        };
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }

    private function setControllerUrl(object $controller): void
    {
        $url = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackendUrl'])
            ->getMock();
        $url->method('getBackendUrl')
            ->willReturnCallback(static fn (string $path): string => match ($path) {
                '*/backend/rma' => '/admin/rma/index',
                '*/backend/rma/approve' => '/admin/rma/approve',
                '*/backend/rma/reject' => '/admin/rma/reject',
                default => '/admin/' . trim($path, '*/'),
            });

        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('_url') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate _url property.');
        }

        $property = $reflection->getProperty('_url');
        $property->setAccessible(true);
        $property->setValue($controller, $url);
    }
}
