<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Api\AiSiteBuilderProviderInterface;
use Weline\Websites\Service\AiWorkbench\ExtensionPointReader;
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;

class ProviderRegistryTest extends TestCore
{
    public function testGetProvidersFiltersInvalidImplementationsAndSortsEnabledProviders(): void
    {
        $registry = new ProviderRegistry(
            ObjectManager::getInstance(),
            new ProviderRegistryFakeExtensionPointReader(
                ['Weline_Websites' => ['AiSiteBuilderProvider' => true]],
                [
                    'Weline_Websites' => [
                        'AiSiteBuilderProvider' => [
                            ['class_name' => ProviderRegistryEnabledSlowProvider::class],
                            ['class_name' => ProviderRegistryDisabledProvider::class],
                            ['class_name' => ProviderRegistryEnabledFastProvider::class],
                            ['class_name' => ProviderRegistryInvalidProvider::class],
                        ],
                    ],
                ],
                100
            )
        );

        $enabledProviders = $registry->getProviders();
        $allProviders = $registry->getProviders(false);

        $this->assertSame(
            ['provider_fast', 'provider_slow'],
            array_keys($enabledProviders)
        );
        $this->assertSame(
            ['provider_disabled', 'provider_fast', 'provider_slow'],
            array_keys($allProviders)
        );
        $this->assertInstanceOf(
            AiSiteBuilderProviderInterface::class,
            $registry->getProvider('provider_fast')
        );
        $this->assertNull($registry->getProvider('missing_provider'));
    }

    public function testRegistryKeepsCacheUntilRegistryMtimeChanges(): void
    {
        $reader = new ProviderRegistryFakeExtensionPointReader(
            ['Weline_Websites' => ['AiSiteBuilderProvider' => true]],
            [
                'Weline_Websites' => [
                    'AiSiteBuilderProvider' => [
                        ['class_name' => ProviderRegistryEnabledFastProvider::class],
                    ],
                ],
            ],
            100
        );

        $registry = new ProviderRegistry(ObjectManager::getInstance(), $reader);

        $firstLoad = $registry->getProviders();

        $reader->setEntries([
            'Weline_Websites' => [
                'AiSiteBuilderProvider' => [
                    ['class_name' => ProviderRegistryEnabledSlowProvider::class],
                ],
            ],
        ]);

        $cachedLoad = $registry->getProviders();

        $reader->setRegistryFileMtime(200);
        $reloaded = $registry->getProviders();

        $this->assertSame(['provider_fast'], array_keys($firstLoad));
        $this->assertSame(['provider_fast'], array_keys($cachedLoad));
        $this->assertSame(['provider_slow'], array_keys($reloaded));
    }
}

final class ProviderRegistryFakeExtensionPointReader extends ExtensionPointReader
{
    public function __construct(
        private array $definitions,
        private array $entries,
        private int $registryFileMtime
    ) {
    }

    public function hasExtensionPoint(string $moduleName, string $extensionPointName, bool $forceReload = false): bool
    {
        return (bool)($this->definitions[$moduleName][$extensionPointName] ?? false);
    }

    public function getExtensionEntries(string $moduleName, string $extensionPointName, bool $forceReload = false): array
    {
        return $this->entries[$moduleName][$extensionPointName] ?? [];
    }

    public function getRegistryFileMtime(): int
    {
        return $this->registryFileMtime;
    }

    public function resolveClassName(array $extension): ?string
    {
        return $extension['class_name'] ?? null;
    }

    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    public function setRegistryFileMtime(int $registryFileMtime): void
    {
        $this->registryFileMtime = $registryFileMtime;
    }
}

final class ProviderRegistryEnabledFastProvider implements AiSiteBuilderProviderInterface
{
    public function getCode(): string
    {
        return 'provider_fast';
    }

    public function getName(): string
    {
        return 'Fast Provider';
    }

    public function getDescription(): string
    {
        return 'Fast provider for registry tests.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 10;
    }
}

final class ProviderRegistryEnabledSlowProvider implements AiSiteBuilderProviderInterface
{
    public function getCode(): string
    {
        return 'provider_slow';
    }

    public function getName(): string
    {
        return 'Slow Provider';
    }

    public function getDescription(): string
    {
        return 'Slow provider for registry tests.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 50;
    }
}

final class ProviderRegistryDisabledProvider implements AiSiteBuilderProviderInterface
{
    public function getCode(): string
    {
        return 'provider_disabled';
    }

    public function getName(): string
    {
        return 'Disabled Provider';
    }

    public function getDescription(): string
    {
        return 'Disabled provider for registry tests.';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function getSortOrder(): int
    {
        return 1;
    }
}

final class ProviderRegistryInvalidProvider
{
}
