<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Api\WebsiteThemeSourceInterface;
use Weline\Websites\Service\AiWorkbench\ExtensionPointReader;
use Weline\Websites\Service\AiWorkbench\ThemeSourceRegistry;

class ThemeSourceRegistryTest extends TestCore
{
    public function testGetSourcesFiltersInvalidImplementationsAndSortsEnabledSources(): void
    {
        $registry = new ThemeSourceRegistry(
            ObjectManager::getInstance(),
            new ThemeSourceRegistryFakeExtensionPointReader(
                ['Weline_Websites' => ['WebsiteThemeSource' => true]],
                [
                    'Weline_Websites' => [
                        'WebsiteThemeSource' => [
                            ['class_name' => ThemeSourceRegistryEnabledSlowSource::class],
                            ['class_name' => ThemeSourceRegistryDisabledSource::class],
                            ['class_name' => ThemeSourceRegistryEnabledFastSource::class],
                            ['class_name' => ThemeSourceRegistryInvalidSource::class],
                        ],
                    ],
                ],
                300
            )
        );

        $enabledSources = $registry->getSources();
        $allSources = $registry->getSources(false);

        $this->assertSame(
            ['source_fast', 'source_slow'],
            array_keys($enabledSources)
        );
        $this->assertSame(
            ['source_disabled', 'source_fast', 'source_slow'],
            array_keys($allSources)
        );
        $this->assertInstanceOf(
            WebsiteThemeSourceInterface::class,
            $registry->getSource('source_fast')
        );
        $this->assertNull($registry->getSource('missing_source'));
    }

    public function testRegistryReturnsEmptyArrayWhenExtensionPointIsNotDefined(): void
    {
        $registry = new ThemeSourceRegistry(
            ObjectManager::getInstance(),
            new ThemeSourceRegistryFakeExtensionPointReader([], [], 1)
        );

        $this->assertSame([], $registry->getSources());
        $this->assertNull($registry->getSource('source_fast'));
    }
}

final class ThemeSourceRegistryFakeExtensionPointReader extends ExtensionPointReader
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
}

final class ThemeSourceRegistryEnabledFastSource implements WebsiteThemeSourceInterface
{
    public function getCode(): string
    {
        return 'source_fast';
    }

    public function getName(): string
    {
        return 'Fast Source';
    }

    public function getDescription(): string
    {
        return 'Fast theme source for registry tests.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function listThemes(array $context = []): array
    {
        return [];
    }
}

final class ThemeSourceRegistryEnabledSlowSource implements WebsiteThemeSourceInterface
{
    public function getCode(): string
    {
        return 'source_slow';
    }

    public function getName(): string
    {
        return 'Slow Source';
    }

    public function getDescription(): string
    {
        return 'Slow theme source for registry tests.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 50;
    }

    public function listThemes(array $context = []): array
    {
        return [];
    }
}

final class ThemeSourceRegistryDisabledSource implements WebsiteThemeSourceInterface
{
    public function getCode(): string
    {
        return 'source_disabled';
    }

    public function getName(): string
    {
        return 'Disabled Source';
    }

    public function getDescription(): string
    {
        return 'Disabled theme source for registry tests.';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function getSortOrder(): int
    {
        return 1;
    }

    public function listThemes(array $context = []): array
    {
        return [];
    }
}

final class ThemeSourceRegistryInvalidSource
{
}
