<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Backend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Backend\RMA\Index;
use WeShop\RMA\Model\Rma;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

final class IndexTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(Rma::class);
        parent::tearDown();
    }

    public function testIndexReturnsRmaList(): void
    {
        $rmaModel = $this->createListModel([['rma_id' => 1]], '<nav>1</nav>');
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(static fn (string $key, mixed $value) => $controller);
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock());

        self::assertSame('page', $controller->index());
    }

    public function testIndexWithStatusFilter(): void
    {
        $rmaModel = $this->createListModel([], '<nav>1</nav>');
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(static fn (string $key, mixed $value) => $controller);
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'status' => 'pending',
        ]));

        self::assertSame('page', $controller->index());
    }

    public function testIndexWithOrderIdFilter(): void
    {
        $rmaModel = $this->createListModel([], '<nav>1</nav>');
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(static fn (string $key, mixed $value) => $controller);
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'order_id' => 123,
        ]));

        self::assertSame('page', $controller->index());
    }

    public function testIndexAssignsCorrectData(): void
    {
        $rmaModel = $this->createListModel([['rma_id' => 1]], '<nav>1</nav>');
        ObjectManager::setInstance(Rma::class, $rmaModel);

        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignedData = [];
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignedData, $controller) {
                $assignedData[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock());

        $controller->index();

        self::assertIsArray($assignedData['rmas'] ?? null);
        self::assertIsString($assignedData['pagination'] ?? null);
        self::assertIsArray($assignedData['filters'] ?? null);
    }

    private function createListModel(array $items, string $pagination): Rma
    {
        return new class($items, $pagination) extends Rma {
            public function __construct(
                private readonly array $fixtureItems,
                private readonly string $fixturePagination
            ) {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                return $this;
            }

            public function order(string $field = '', string $sort = 'DESC'): static
            {
                return $this;
            }

            public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function getItems(): array
            {
                return $this->fixtureItems;
            }

            public function getPagination(string $pagination_style = 'pagination-rounded', string $url_path = '', bool $use_backend_url = false): string
            {
                return $this->fixturePagination;
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
}
