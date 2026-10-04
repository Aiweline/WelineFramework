<?php
declare(strict_types=1);
namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Observer\InstallBackendThemeApplicationContext;
use Weline\SystemConfig\Service\BackendThemeApplicationService;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;

final class BackendThemeApplicationServiceTest extends TestCase
{
    private mixed $stored = null;
    private int $exports = 0;
    private ConfigStore $configs;
    private ThemeApplicationReferenceReaderInterface $reader;
    protected function tearDown(): void
    {
        foreach (['backend.runtime','backend.asset','frontend.runtime'] as $key) {
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . $key);
        }
    }

    public function testBackendRequestInstallsOwnPackageReferenceWithoutWebsiteOrLegacyLookup(): void
    {
        self::assertTrue(class_exists(BackendThemeApplicationService::class), 'Backend application consumer missing');
        $this->stored = $this->reference(8, 0, 0);
        $service = $this->service([]);
        $registry=new \Weline\Framework\Compilation\ServiceProviderRegistry();
        (new \ReflectionProperty($registry,'providers'))->setValue($registry,[ThemeApplicationReferenceReaderInterface::class=>get_class($this->reader)]);
        \Weline\Framework\Manager\ObjectManager::setInstance(get_class($this->reader),$this->reader);
        $observer = new InstallBackendThemeApplicationContext($this->configs,new \Weline\Framework\Runtime\RuntimeProviderResolver($registry));
        $front = new Event(['area'=>'frontend']);
        $observer->execute($front);
        self::assertNull(ThemeApplicationContext::current('backend'));
        $event = new Event(['area'=>'backend','scope_identity'=>null]);
        $observer->execute($event);
        $context = ThemeApplicationContext::current('backend');
        self::assertSame('system-config', $context->provider);
        self::assertSame('backendglobal', $context->scopeKey);
        self::assertSame(8, $context->themeId);
        self::assertSame(0, $context->themeVersionId);
        self::assertSame(0, $context->contentRevision);
        self::assertSame('default.default.default', $context->versionOwnerScope);
        self::assertSame('backend', $context->area);
        self::assertNull(ThemeApplicationContext::current('backend','asset'));
        self::assertSame(0, $this->exports);
    }

    public function testUpgradeMigratesAccurateOldBackendActiveOnceWithoutBorrowingFrontendVersion(): void
    {
        self::assertTrue(class_exists(BackendThemeApplicationService::class), 'Backend migration missing');
        $service = $this->service(['active_defaults'=>['backend'=>['theme_id'=>8],'frontend'=>['theme_id'=>3]],
            'bindings'=>[], 'unresolved'=>[], 'source_records'=>['versions'=>[
                ['theme_id'=>3,'scope'=>'default.default.default','store_mode'=>'normal','area'=>'frontend','version_id'=>1,'content_revision'=>6],
            ],'selections'=>[]]]);
        self::assertTrue($service->migrateLegacy('en_US'));
        self::assertSame($this->reference(8,0,0), $this->stored);
        self::assertFalse($service->migrateLegacy('en_US'));
        self::assertSame(1, $this->exports);
    }

    public function testUpgradeKeepsExistingReferenceAndDoesNotExportLegacyAgain(): void
    {
        self::assertTrue(class_exists(BackendThemeApplicationService::class), 'Backend migration missing');
        $this->stored=$this->reference(9,21,4);
        self::assertFalse($this->service([])->migrateLegacy('en_US'));
        self::assertSame(21,$this->stored['theme_version_id']);
        self::assertSame(0,$this->exports);
    }

    public function testObserverDoesNotRequireThemeWhenItsPublicProviderIsNotConfigured(): void
    {
        $this->service([]);
        $registry=new \Weline\Framework\Compilation\ServiceProviderRegistry();
        (new \ReflectionProperty($registry,'providers'))->setValue($registry,[]);
        $observer=new InstallBackendThemeApplicationContext($this->configs,new \Weline\Framework\Runtime\RuntimeProviderResolver($registry));
        $event=new Event(['area'=>'backend']);
        $observer->execute($event);
        self::assertNull(ThemeApplicationContext::current('backend'));
        self::assertSame(0,$this->exports);
    }

    public function testMissingConfigFallsBackToRegisteredDefaultWithoutWriting(): void
    {
        self::assertTrue(class_exists(BackendThemeApplicationService::class), 'Backend application consumer missing');
        $service = $this->service(['active_defaults' => ['backend' => ['theme_id' => 8]]]);
        $context = $service->buildContext();
        self::assertSame(0, $context->themeId);
        self::assertSame(0, $context->themeVersionId);
        self::assertSame('backend', $context->area);
        self::assertNull($this->stored);
        self::assertSame(0, $this->exports);
    }

    private function reference(int $theme, int $version, int $revision): array
    {
        return ['theme_id' => $theme, 'theme_version_id' => $version, 'content_revision' => $revision,
            'owner_scope' => 'default.default.default', 'store_mode' => 'normal', 'area' => 'backend',
            'source_kind' => $version === 0 ? 'package_defaults' : 'version_snapshot', 'default_locale' => 'en_US'];
    }

    private function service(array $legacy): BackendThemeApplicationService
    {
        $model = $this->createMock(SystemConfig::class);
        $model->method('getConfig')->willReturnCallback(function ($key, $module, $area, $default, $scope, $locale) {
            self::assertSame('backend_theme_application', $key); self::assertSame('Weline_SystemConfig', $module);
            self::assertSame('backend', $area); self::assertSame('default.default.default', $scope); self::assertSame('default', $locale);
            return $this->stored;
        });
        $model->method('setScopedConfig')->willReturnCallback(function ($key, $value, $module, $area, $scope, $locale) {
            self::assertSame('backend_theme_application', $key); self::assertSame('backend', $area);
            self::assertSame('default.default.default', $scope); self::assertSame('default', $locale);
            $this->stored = $value; return true;
        });
        $reader = $this->createMock(ThemeApplicationReferenceReaderInterface::class);
        $reader->method('validateReference')->willReturnCallback(static fn(array $ref) => $ref + ['source_kind' => (int)$ref['theme_version_id'] === 0 ? 'package_defaults' : 'version_snapshot']);
        $reader->method('resourceReferences')->willReturn([]);
        $reader->method('exportLegacyApplicationSnapshot')->willReturnCallback(function () use ($legacy) { $this->exports++; return $legacy; });
        $defaultTheme = $this->createMock(DefaultThemeInterface::class);
        $defaultTheme->method('defaultApplicationReference')->willReturnCallback(
            fn(string $area, string $ownerScope, string $storeMode) => [
                'theme_id' => 0,
                'theme_version_id' => 0,
                'content_revision' => 0,
                'owner_scope' => $ownerScope,
                'store_mode' => $storeMode,
                'area' => $area,
                'source_kind' => 'package_defaults',
            ],
        );
        $defaultTheme->method('getRegisteredDefault')->willReturn([
            'id' => 0,
            'name' => 'Default 默认主题',
            'module_name' => 'Weline_Theme',
            'path' => '/module/view/theme',
            'source' => 'module_default',
        ]);
        $defaultTheme->method('isModuleDefaultThemeId')->willReturnCallback(
            static fn(int $themeId): bool => $themeId === 0,
        );
        $this->configs = new ConfigStore($model); $this->reader = $reader;
        return new BackendThemeApplicationService($this->configs, $reader, $defaultTheme);
    }
}
