<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Service\RegisteredDefaultTheme;

final class RegisteredDefaultThemeContractTest extends TestCase
{
    public function testRegisteredDefaultIsThemeModuleDiskNotFixedId(): void
    {
        $api = file_get_contents(dirname(__DIR__, 3) . '/Api/DefaultThemeInterface.php');
        $service = file_get_contents(dirname(__DIR__, 3) . '/Service/RegisteredDefaultTheme.php');
        $module = file_get_contents(dirname(__DIR__, 3) . '/etc/module.php');
        $env = file_get_contents(dirname(__DIR__, 4) . '/Framework/App/Env.php');
        self::assertIsString($api);
        self::assertIsString($service);
        self::assertIsString($module);
        self::assertIsString($env);
        self::assertStringContainsString('interface DefaultThemeInterface', $api);
        self::assertStringContainsString('MODULE_DEFAULT_THEME_ID', $api);
        self::assertStringContainsString('MODULE_ORIGIN', $api);
        self::assertStringContainsString('isModuleDefaultThemeId', $api);
        self::assertStringContainsString('class RegisteredDefaultTheme', $service);
        self::assertStringContainsString('moduleThemePath', $service);
        self::assertStringContainsString('lookupCatalogThemeId', $service);
        self::assertStringContainsString('DefaultThemeInterface::class', $module);
        self::assertStringContainsString(RegisteredDefaultTheme::class, $module);
        self::assertStringNotContainsString('Env::default_theme_DATA', $service);
        self::assertStringNotContainsString('public const default_theme_DATA', $env);
        // 禁止把 Default 写死成 id=1。
        self::assertStringNotContainsString("theme_id' => 1", $service);
        self::assertStringNotContainsString('id === 1', $service);

        $contextService = file_get_contents(dirname(__DIR__, 3) . '/Service/ThemeContextService.php');
        self::assertIsString($contextService);
        self::assertStringNotContainsString("throw new \\RuntimeException('theme_consumer_context_required')", $contextService);
        self::assertStringContainsString('resolveRegisteredDefaultTheme', $contextService);
        self::assertStringContainsString('buildModuleDefaultTheme', $contextService);

        $backend = file_get_contents(dirname(__DIR__, 4) . '/SystemConfig/Service/BackendThemeApplicationService.php');
        self::assertIsString($backend);
        self::assertStringNotContainsString('backend_theme_application_not_configured', $backend);
        self::assertStringContainsString('DefaultThemeInterface', $backend);

        $reader = file_get_contents(dirname(__DIR__, 3) . '/Service/Version/ThemeApplicationReferenceReader.php');
        self::assertIsString($reader);
        self::assertStringContainsString('$id === 0', $reader);
    }
}
