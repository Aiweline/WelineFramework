<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Controller\Frontend\Promotion;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Controller\Frontend\Promotion\Index;
use WeShop\Promotion\Service\PromotionPageDataService;
use Weline\Framework\Http\Request;

class IndexTest extends TestCase
{
    public function testLayoutTypeIsPromotion(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new Index($this->createMock(PromotionPageDataService::class));
        $this->assertSame('promotion', $property->getValue($controller));
    }

    public function testDealsAssignsDataAndFetchesPage(): void
    {
        $pageData = $this->createMock(PromotionPageDataService::class);
        $pageData->expects($this->once())
            ->method('build')
            ->with('deals', 1, 24)
            ->willReturn([
                'items' => [['product_id' => 1]],
                'total' => 1,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['page', null, 1],
        ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$pageData])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->exactly(3))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('page', $controller->deals());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
