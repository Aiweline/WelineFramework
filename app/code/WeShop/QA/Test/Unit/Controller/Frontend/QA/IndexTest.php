<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Controller\Frontend\QA;

use PHPUnit\Framework\TestCase;
use WeShop\QA\Controller\Frontend\QA\Index;
use WeShop\QA\Service\QAQuestionPageDataService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testLayoutTypeIsQa(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new Index($this->createMock(QAQuestionPageDataService::class));
        $this->assertEquals('qa', $property->getValue($controller));
    }

    public function testIndexRedirectsWhenProductIdIsMissing(): void
    {
        $pageDataService = $this->createMock(QAQuestionPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['product_id', null, 0],
        ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('redirect')
            ->with('catalog/category');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');
        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsPageDataForProductQaView(): void
    {
        $pageDataService = $this->createMock(QAQuestionPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(88)
            ->willReturn([
                'product_id' => 88,
                'qa_list' => [['question_id' => 1]],
                'question_count' => 1,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['product_id', null, 88],
        ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(4))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('page', $controller->index());
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
