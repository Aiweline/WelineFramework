<?php

declare(strict_types=1);

namespace Weline\FakeData\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;
use Weline\FakeData\Service\FakeDataImportService;
use Weline\FakeData\Service\FakeDataProviderPlanner;
use Weline\FakeData\Service\FakeDataProviderRegistry;
use Weline\FakeData\Service\FakeDataRecordService;

final class FakeDataImportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ImportProvider::$calls = [];
    }

    public function testDryRunBuildsPlanWithoutCallingProviders(): void
    {
        $service = $this->createService([
            'catalog' => new ImportProvider('catalog', 'Catalog_Module', 100),
            'product' => new ImportProvider('product', 'Product_Module', 200, ['catalog']),
        ]);

        $report = $service->execute(['provider' => 'product', 'dry-run' => true]);

        self::assertTrue($report['dry_run']);
        self::assertSame(['catalog', 'product'], array_column($report['providers'], 'code'));
        self::assertSame([], ImportProvider::$calls);
    }

    public function testResetCleansInReverseOrderAndSeedsInForwardOrder(): void
    {
        $service = $this->createService([
            'catalog' => new ImportProvider('catalog', 'Catalog_Module', 100),
            'product' => new ImportProvider('product', 'Product_Module', 200, ['catalog']),
        ]);

        $service->execute(['provider' => 'product', 'reset' => true]);

        self::assertSame([
            'cleanup:product',
            'cleanup:catalog',
            'seed:catalog',
            'seed:product',
        ], ImportProvider::$calls);
    }

    private function createService(array $providers): FakeDataImportService
    {
        return new FakeDataImportService(
            new ImportRegistry($providers),
            new FakeDataProviderPlanner(),
            (new \ReflectionClass(FakeDataRecordService::class))->newInstanceWithoutConstructor()
        );
    }
}

final class ImportRegistry extends FakeDataProviderRegistry
{
    public function __construct(
        private readonly array $providers,
    ) {
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getWarnings(): array
    {
        return [];
    }
}

final class ImportProvider implements FakeDataProviderInterface
{
    /** @var array<int, string> */
    public static array $calls = [];

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
        self::$calls[] = 'seed:' . $this->code;
        return (new FakeDataResult())->addCreated();
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        self::$calls[] = 'cleanup:' . $this->code;
        return (new FakeDataResult())->addDeleted();
    }
}

