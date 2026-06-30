<?php

declare(strict_types=1);

namespace Weline\FakeData\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;
use Weline\FakeData\Service\FakeDataProviderPlanner;

final class FakeDataProviderPlannerTest extends TestCase
{
    public function testProviderSelectionIncludesDependenciesInOrder(): void
    {
        $planner = new FakeDataProviderPlanner();
        $plan = $planner->createPlan([
            'catalog' => new PlannerProvider('catalog', 'Catalog_Module', 100),
            'product' => new PlannerProvider('product', 'Product_Module', 200, ['catalog']),
        ], ['product']);

        self::assertSame(['catalog', 'product'], array_keys($plan));
    }

    public function testModuleSelectionUsesMatchingProviders(): void
    {
        $planner = new FakeDataProviderPlanner();
        $plan = $planner->createPlan([
            'catalog' => new PlannerProvider('catalog', 'Catalog_Module', 100),
            'product' => new PlannerProvider('product', 'Product_Module', 200, ['catalog']),
        ], [], ['Product_Module']);

        self::assertSame(['catalog', 'product'], array_keys($plan));
    }

    public function testCircularDependencyFailsClearly(): void
    {
        $planner = new FakeDataProviderPlanner();

        $this->expectException(\RuntimeException::class);
        $planner->createPlan([
            'a' => new PlannerProvider('a', 'Module_A', 10, ['b']),
            'b' => new PlannerProvider('b', 'Module_B', 20, ['a']),
        ]);
    }
}

final class PlannerProvider implements FakeDataProviderInterface
{
    public function __construct(
        private readonly string $code,
        private readonly string $moduleName,
        private readonly int $sortOrder,
        private readonly array $dependencies = [],
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getLabel(): string
    {
        return $this->code;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function describe(): array
    {
        return [];
    }

    public function seed(FakeDataContext $context): FakeDataResult
    {
        return new FakeDataResult();
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        return new FakeDataResult();
    }
}

