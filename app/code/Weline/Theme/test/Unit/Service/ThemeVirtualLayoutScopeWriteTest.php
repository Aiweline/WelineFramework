<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ConfigScopeValue;
use Weline\SystemConfig\Api\Scope\ScopedConfigRepositoryInterface;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeVirtualLayoutVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\ThemeVirtualLayoutService;

final class ThemeVirtualLayoutScopeWriteTest extends TestCase
{
    private object $originalCacheCleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCacheCleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
        $cacheCleaner = new class {
            public function clearNonGlobalCaches(?int $themeId = null, string $reason = ''): array
            {
                return ['reason' => $reason, 'theme_id' => $themeId, 'steps' => [], 'failures' => []];
            }
        };
        ObjectManager::setInstance(ThemeRuntimeCacheCleaner::class, $cacheCleaner);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance(ThemeRuntimeCacheCleaner::class, $this->originalCacheCleaner);
        parent::tearDown();
    }

    public function testSaveLayoutSelectionUpgradesLegacyDefaultBeforeScopedConfigWrite(): void
    {
        $service = $this->createService();

        $result = $service->saveLayoutSelection(
            'product',
            1,
            'product',
            'default',
            'default'
        );

        self::assertTrue($result['success'] ?? false);
        self::assertSame('default.default.default', $result['scope'] ?? null);
    }

    public function testDeleteLayoutSelectionUpgradesLegacyDefaultBeforeScopedConfigWrite(): void
    {
        $service = $this->createService();

        $result = $service->deleteLayoutSelection(
            'product',
            1,
            'product',
            'default'
        );

        self::assertTrue($result['success'] ?? false);
        self::assertSame('default.default.default', $result['scope'] ?? null);
    }

    private function createService(): ThemeVirtualLayoutService
    {
        return new ThemeVirtualLayoutService(
            new ThemeVirtualLayout(),
            new ThemeVirtualLayoutVersion(),
            new ActiveThemeForScopeWriteTest(),
            new RejectShortScopeRepository()
        );
    }
}

final class ActiveThemeForScopeWriteTest extends WelineTheme
{
    public function __construct()
    {
        parent::__construct([
            self::schema_fields_ID => 1,
            self::schema_fields_MODULE_NAME => 'Weline_Theme',
        ]);
    }

    public function clearData(bool $with_query = true): static
    {
        return $this;
    }

    public function clearQuery(string $type = ''): static
    {
        return $this;
    }

    public function getActiveTheme(?string $area = null): static
    {
        return $this;
    }
}

final class RejectShortScopeRepository implements ScopedConfigRepositoryInterface
{
    public function normalizeScope(?string $scope = null): string
    {
        $segments = array_values(array_filter(
            array_map('trim', explode('.', strtolower(trim((string)($scope ?? 'default'))))),
            static fn(string $segment): bool => $segment !== ''
        ));
        $segments = array_slice($segments, 0, 3);
        while (count($segments) < 3) {
            $segments[] = 'default';
        }

        return implode('.', $segments);
    }

    public function normalizeLocale(?string $locale = null): string
    {
        return trim((string)($locale ?? '')) ?: 'default';
    }

    public function getFallbackScopes(?string $scope = null): array
    {
        return [$this->normalizeScope($scope)];
    }

    public function saveScopeConfig(
        string $module,
        string $area,
        array $values,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): array {
        (new SystemConfigScopeResolver())->assertWritableRawScope($scope);

        return [
            'success' => true,
            'scope' => $scope,
            'locale' => $this->normalizeLocale($locale),
            'values' => $values,
        ];
    }

    public function resolveConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null,
    ): array {
        return ['found' => false, 'value' => $default];
    }

    public function resolveTypedConfig(
        string $key,
        string $module,
        string $area,
        ScopeIdentity $identity,
        ?string $locale = null,
        mixed $default = null,
    ): ConfigScopeValue {
        throw new \LogicException('Not used by this test.');
    }

    public function getConfigVersions(
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        int $limit = 50,
    ): array {
        return [];
    }

    public function getConfigVersionDetail(int $versionId): ?array
    {
        return null;
    }

    public function getScopedConfigRow(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
    ): ?array {
        return null;
    }

    public function maskSensitiveRow(?array $row): ?array
    {
        return $row;
    }

    public function rollbackScopeConfigVersion(int $versionId, array $options = []): array
    {
        return ['success' => false];
    }
}
