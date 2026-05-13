<?php

declare(strict_types=1);

namespace Weline\FakeData\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;
use Weline\FakeData\Service\FakeDataProviderRegistry;

final class FakeDataProviderRegistryTest extends TestCase
{
    public function testLoadsOnlyFakeDataProviderExtensions(): void
    {
        $registry = new FakeDataProviderRegistry([
            'Test_Module' => [
                [
                    'relative_path' => 'extends/module/Weline_FakeData/Provider/GoodProvider.php',
                    'source_file' => __FILE__,
                    'class_name' => RegistryGoodProvider::class,
                ],
                [
                    'relative_path' => 'extends/module/Weline_Other/Provider/SkippedProvider.php',
                    'source_file' => __FILE__,
                    'class_name' => RegistrySkippedProvider::class,
                ],
                [
                    'relative_path' => 'extends/module/Weline_FakeData/Provider/NonProvider.php',
                    'source_file' => __FILE__,
                    'class_name' => RegistryNonProvider::class,
                ],
            ],
        ]);

        $providers = $registry->getProviders();

        self::assertArrayHasKey('registry_good', $providers);
        self::assertCount(1, $providers);
        self::assertNotSame([], $registry->getWarnings());
    }

    public function testDuplicateProviderCodeFailsFast(): void
    {
        $registry = new FakeDataProviderRegistry([
            'Test_Module' => [
                [
                    'relative_path' => 'extends/module/Weline_FakeData/Provider/GoodProvider.php',
                    'source_file' => __FILE__,
                    'class_name' => RegistryGoodProvider::class,
                ],
                [
                    'relative_path' => 'extends/module/Weline_FakeData/Provider/DuplicateProvider.php',
                    'source_file' => __FILE__,
                    'class_name' => RegistryDuplicateProvider::class,
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $registry->getProviders();
    }
}

class RegistryGoodProvider implements FakeDataProviderInterface
{
    public function getCode(): string
    {
        return 'registry_good';
    }

    public function getModuleName(): string
    {
        return 'Test_Module';
    }

    public function getLabel(): string
    {
        return 'Registry good';
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function getDependencies(): array
    {
        return [];
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

final class RegistryDuplicateProvider extends RegistryGoodProvider
{
}

final class RegistrySkippedProvider extends RegistryGoodProvider
{
    public function getCode(): string
    {
        return 'registry_skipped';
    }
}

final class RegistryNonProvider
{
}
