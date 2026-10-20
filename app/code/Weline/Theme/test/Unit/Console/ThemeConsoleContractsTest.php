<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Console\Theme\Upgrade as ThemeUpgradeCommand;
use Weline\Theme\Model\WelineTheme;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeConsoleContractsPass().
 */
final class ThemeConsoleContractsTest extends TestCase
{
    public function testAllThemeConsoleContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PublishNotFoundStaticCommand::testCommandExposesForceAndLangAndCallsGenerator' => function () {
            $this->assert_PublishNotFoundStaticCommand_testCommandExposesForceAndLangAndCallsGenerator();
        },
            'ThemeUpgradeCommand::testLayoutEntitySolidifyIsInvokedAfterStaticPublish' => function () {
            $this->assert_ThemeUpgradeCommand_testLayoutEntitySolidifyIsInvokedAfterStaticPublish();
        },
            'ThemeUpgradeCommand::testNamedThemeLookupUsesTheDeclaredNameFieldConstant' => function () {
            $this->assert_ThemeUpgradeCommand_testNamedThemeLookupUsesTheDeclaredNameFieldConstant();
        },
            'ThemeUpgradeCommand::testDefaultUpgradeTargetsApplicationReferencedThemes' => function () {
            $this->assert_ThemeUpgradeCommand_testDefaultUpgradeTargetsApplicationReferencedThemes();
        },
            'ThemeUpgradeCommand::testDesignAreaAssetsAreMirroredIntoThemeModuleStaticNamespace' => function () {
            $this->assert_ThemeUpgradeCommand_testDesignAreaAssetsAreMirroredIntoThemeModuleStaticNamespace();
        },
            'ThemeUpgradeCommand::testCliMetadataIsNotTreatedAsAModuleFilter' => function () {
            $this->assert_ThemeUpgradeCommand_testCliMetadataIsNotTreatedAsAModuleFilter();
        },
            'ThemeUpgradeCommand::testNamedThemeFlagFromAssociativeArgs' => function () {
            $this->assert_ThemeUpgradeCommand_testNamedThemeFlagFromAssociativeArgs();
        },
            'ThemeUpgradeCommand::testParseArgumentsRecognizesAllAndScopeFilters' => function () {
            $this->assert_ThemeUpgradeCommand_testParseArgumentsRecognizesAllAndScopeFilters();
        },
            'ThemeUpgradeCommand::testParseArgumentsRecognizesLayoutAndOptionCascade' => function () {
            $this->assert_ThemeUpgradeCommand_testParseArgumentsRecognizesLayoutAndOptionCascade();
        },
            'ThemeUpgradeCommand::testParseArgumentsRecognizesForceFlushStorefrontFpc' => function () {
            $this->assert_ThemeUpgradeCommand_testParseArgumentsRecognizesForceFlushStorefrontFpc();
        },
            'ThemeUpgradeCommand::testParseArgumentsRejectsOptionWithoutLayout' => function () {
            $this->assert_ThemeUpgradeCommand_testParseArgumentsRejectsOptionWithoutLayout();
        },
            'ThemeUpgradeCommand::testTipDescribesBoundThemesNotLegacyActiveFlags' => function () {
            $this->assert_ThemeUpgradeCommand_testTipDescribesBoundThemesNotLegacyActiveFlags();
        },
            'ThemeUpgradeCommand::testNamespacedModuleThemeRequestPathIncludesThemeIdentity' => function () {
            $this->assert_ThemeUpgradeCommand_testNamespacedModuleThemeRequestPathIncludesThemeIdentity();
        },
            'ThemeUpgradeCommand::testPublisherCreatesMissingDestinationDirectories' => function () {
            $this->assert_ThemeUpgradeCommand_testPublisherCreatesMissingDestinationDirectories();
        },
            'ThemeUpgradeCommand::testAbsoluteCoreThemePathPublishesInsideTheStaticThemeNamespace' => function () {
            $this->assert_ThemeUpgradeCommand_testAbsoluteCoreThemePathPublishesInsideTheStaticThemeNamespace();
        },
            'ThemeUpgradeCommand::testDesignDocAndTestDirectoriesAreNeverPublished' => function () {
            $this->assert_ThemeUpgradeCommand_testDesignDocAndTestDirectoriesAreNeverPublished();
        },
            'ThemeUpgradeCommand::testFetchThemeFilesSkipsDesignDocsAndTestScripts' => function () {
            $this->assert_ThemeUpgradeCommand_testFetchThemeFilesSkipsDesignDocsAndTestScripts();
        },
            'ThemeUpgradeCommand::testPublisherRejectsAFileOutsideTheThemeRoot' => function () {
            $this->assert_ThemeUpgradeCommand_testPublisherRejectsAFileOutsideTheThemeRoot();
        },
            'ThemeUpgradeCommand::testFetchThemeFilesSkipsModuleEmailServerTemplates' => function () {
            $this->assert_ThemeUpgradeCommand_testFetchThemeFilesSkipsModuleEmailServerTemplates();
        },
            'ThemeUpgradeCommand::testFetchThemeFilesSkipsOutOfRootOriginWithoutThrowing' => function () {
            $this->assert_ThemeUpgradeCommand_testFetchThemeFilesSkipsOutOfRootOriginWithoutThrowing();
        },
            'ThemeUpgradeCommand::testDefaultThemeViewTreeHasNoModuleServerTemplateMirrors' => function () {
            $this->assert_ThemeUpgradeCommand_testDefaultThemeViewTreeHasNoModuleServerTemplateMirrors();
        },
            'ThemeUpgradeCommand::testFetchThemeFilesAfterAnotherThemeDoesNotLeakForeignOrigins' => function () {
            $this->assert_ThemeUpgradeCommand_testFetchThemeFilesAfterAnotherThemeDoesNotLeakForeignOrigins();
        },
        ];
    }

private function assert_PublishNotFoundStaticCommand_testCommandExposesForceAndLangAndCallsGenerator(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/PublishNotFoundStatic.php');
    self::assertStringContainsString('theme:publish-not-found-static', $source);
    self::assertStringContainsString('--force', $source);
    self::assertStringContainsString('publishAll', $source);
    self::assertStringContainsString('publishOne', $source);
    self::assertStringContainsString('forgetByPrefix', $source);
    self::assertStringContainsString('reportSampleSizes', $source);
    self::assertStringContainsString('widgetTranslations', $source);
}

private function assert_ThemeUpgradeCommand_testLayoutEntitySolidifyIsInvokedAfterStaticPublish(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertIsString($source);
    self::assertStringContainsString('ThemeLayoutEntityUpgradeSolidifyService', $source);
    self::assertStringContainsString('cutoverFromThemeCommand', $source);
    self::assertStringContainsString('publishForThemeUpgrade', $source);
    $modePos = strpos($source, 'publishForThemeUpgrade');
    $cutoverPos = strpos($source, 'cutoverFromThemeCommand');
    self::assertNotFalse($modePos);
    self::assertNotFalse($cutoverPos);
    self::assertLessThan($cutoverPos, $modePos);
    self::assertStringContainsString('generated/theme-layout-entities', $source);
    self::assertStringContainsString('purge 旧布局固化物', $source);
    // Must not skip purge (old solidify-only path was the premature-close root cause).
    self::assertStringNotContainsString('solidifyAllThemes();', $source);
    self::assertStringNotContainsString('solidifyFromThemeCommand($themes', $source);
}

private function assert_ThemeUpgradeCommand_testNamedThemeLookupUsesTheDeclaredNameFieldConstant(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertIsString($source);
    self::assertStringContainsString('WelineTheme::schema_fields_NAME', $source);
    self::assertStringNotContainsString('WelineTheme::filed_NAME', $source);
}

private function assert_ThemeUpgradeCommand_testDefaultUpgradeTargetsApplicationReferencedThemes(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertIsString($source);
    self::assertStringContainsString('resolveThemesToUpgrade', $source);
    self::assertStringContainsString('ThemeApplicationUsageService', $source);
    self::assertStringContainsString('themesForDefaultUpgrade', $source);
    self::assertStringNotContainsString('getActiveTheme($area)', $source);
    self::assertStringContainsString('upgradeOneTheme', $source);
}

private function assert_ThemeUpgradeCommand_testDesignAreaAssetsAreMirroredIntoThemeModuleStaticNamespace(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertIsString($source);
    self::assertStringContainsString('publishDesignAreaAssetsIntoThemeModuleNamespace', $source);
    self::assertStringContainsString("buildNamespacedModuleThemeRequestPath(\n                    \$publicThemePath,\n                    'Weline',\n                    'Theme'", $source);
    self::assertStringContainsString('设计主题 area 非图片资源双写到 Weline/Theme/view/theme', $source);
}

private function assert_ThemeUpgradeCommand_testCliMetadataIsNotTreatedAsAModuleFilter(): void
{
    [$themeName, $modules, $allVersions, $scope, $layout, $option, $flushFpc] = ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '-t', 2 => 'weshop-motor', 'command' => 'theme:upgrade', 't' => 'weshop-motor']);
    self::assertSame('weshop-motor', $themeName);
    self::assertSame([], $modules);
    self::assertFalse($allVersions);
    self::assertSame('', $scope);
    self::assertSame('', $layout);
    self::assertSame('', $option);
    self::assertFalse($flushFpc);
}

private function assert_ThemeUpgradeCommand_testNamedThemeFlagFromAssociativeArgs(): void
{
    [$themeName, $modules, $allVersions, $scope, $layout, $option, $flushFpc] = ThemeUpgradeCommand::parseArguments(['command' => 'theme:upgrade', 't' => 'daocharms']);
    self::assertSame('daocharms', $themeName);
    self::assertSame([], $modules);
    self::assertFalse($allVersions);
    self::assertSame('', $scope);
    self::assertSame('', $layout);
    self::assertSame('', $option);
    self::assertFalse($flushFpc);
}

private function assert_ThemeUpgradeCommand_testParseArgumentsRecognizesAllAndScopeFilters(): void
{
    [$themeName, $modules, $allVersions, $scope, $layout, $option, $flushFpc] = ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '--all', 2 => '-t', 3 => 'hanfu', 4 => '--scope', 5 => 'default.default.default']);
    self::assertSame('hanfu', $themeName);
    self::assertSame([], $modules);
    self::assertTrue($allVersions);
    self::assertSame('default.default.default', $scope);
    self::assertSame('', $layout);
    self::assertSame('', $option);
    self::assertFalse($flushFpc);
}

private function assert_ThemeUpgradeCommand_testParseArgumentsRecognizesLayoutAndOptionCascade(): void
{
    [$themeName, $modules, $allVersions, $scope, $layout, $option, $flushFpc] = ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '-t', 2 => 'hanfu', 3 => '-l', 4 => 'homepage', 5 => '-o', 6 => 'default']);
    self::assertSame('hanfu', $themeName);
    self::assertSame([], $modules);
    self::assertFalse($allVersions);
    self::assertSame('', $scope);
    self::assertSame('homepage', $layout);
    self::assertSame('default', $option);
    self::assertFalse($flushFpc);
    [, , , , $layoutOnly, $optionOnly] = ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '--layout', 2 => 'checkout']);
    self::assertSame('checkout', $layoutOnly);
    self::assertSame('', $optionOnly);
}

private function assert_ThemeUpgradeCommand_testParseArgumentsRecognizesForceFlushStorefrontFpc(): void
{
    [, , , , , , $flushPositional] = ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '-t', 2 => 'hanfu', 3 => '-f']);
    self::assertTrue($flushPositional);
    [, , , , , , $flushLong] = ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '--force', 2 => '-t', 3 => 'hanfu']);
    self::assertTrue($flushLong);
    [, , , , , , $flushAssoc] = ThemeUpgradeCommand::parseArguments(['command' => 'theme:upgrade', 't' => 'hanfu', 'f' => true]);
    self::assertTrue($flushAssoc);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertStringContainsString('flushStorefrontFpcAfterUpgrade', $source);
    self::assertStringContainsString('ThemeRuntimeCacheCleaner', $source);
    self::assertStringContainsString('clearAllThemeRelatedCaches', $source);
    self::assertStringContainsString('theme_upgrade_force_fpc', $source);
    self::assertStringContainsString('-f, --force', $source);
    self::assertStringContainsString('升级并清店面 FPC/CDN', $source);
}

private function assert_ThemeUpgradeCommand_testParseArgumentsRejectsOptionWithoutLayout(): void
{
    $this->expectException(\Weline\Framework\Console\ConsoleException::class);
    ThemeUpgradeCommand::parseArguments([0 => 'theme:upgrade', 1 => '-o', 2 => 'default']);
}

private function assert_ThemeUpgradeCommand_testTipDescribesBoundThemesNotLegacyActiveFlags(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertStringContainsString('范围已绑定主题', $source);
    self::assertStringContainsString('当前正式版 + 当前草稿', $source);
    self::assertStringContainsString('--all', $source);
    self::assertStringContainsString('--scope', $source);
    self::assertStringContainsString('--layout', $source);
    self::assertStringContainsString('--option', $source);
    self::assertStringContainsString('layout_type', $source);
    self::assertStringNotContainsString('getActiveTheme($area)', $source);
    self::assertStringNotContainsString('前台+后台激活主题', $source);
}

private function assert_ThemeUpgradeCommand_testNamespacedModuleThemeRequestPathIncludesThemeIdentity(): void
{
    self::assertSame('/static/Weline/daocharms/Weline/Theme/view/theme/frontend/assets/css/theme.css', ThemeUpgradeCommand::buildNamespacedModuleThemeRequestPath('Weline/daocharms', 'Weline', 'Theme', 'frontend', 'assets/css/theme.css'));
}

private function assert_ThemeUpgradeCommand_testPublisherCreatesMissingDestinationDirectories(): void
{
    $workspace = sys_get_temp_dir() . '/weline-theme-upgrade-' . bin2hex(random_bytes(6));
    $source = $workspace . '/source.css';
    $destination = $workspace . '/nested/assets/css';
    mkdir($workspace, 0755, true);
    file_put_contents($source, '.motor-header { color: white; }');
    try {
        $command = (new \ReflectionClass(ThemeUpgradeCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ThemeUpgradeCommand::class, 'copyThemeFile');
        $method->invoke($command, $source, $destination);
        self::assertSame('.motor-header { color: white; }', file_get_contents($destination . '/source.css'));
    } finally {
        if (is_file($destination . '/source.css')) {
            unlink($destination . '/source.css');
        }
        foreach ([$destination, dirname($destination), dirname($destination, 2), $workspace] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}

private function assert_ThemeUpgradeCommand_testAbsoluteCoreThemePathPublishesInsideTheStaticThemeNamespace(): void
{
    $sourceRoot = '/Users/example/project/app/code/Weline/Theme/view/theme';
    $sourceFile = $sourceRoot . '/frontend/assets/css/theme.css';
    self::assertSame('/Users/example/project/pub/static/Weline/Theme/view/theme/frontend/assets/css', ThemeUpgradeCommand::buildDestinationDirectory($sourceRoot, $sourceFile, 'Weline/Theme/view/theme', '/Users/example/project/pub/static'));
}

private function assert_ThemeUpgradeCommand_testDesignDocAndTestDirectoriesAreNeverPublished(): void
{
    $root = '/Users/example/project/app/design/Weline/hanfu';
    $method = new \ReflectionMethod(ThemeUpgradeCommand::class, 'isExcludedPublishPath');
    $method->setAccessible(true);
    self::assertTrue($method->invoke(null, $root, $root . '/doc/README.md'));
    self::assertTrue($method->invoke(null, $root, $root . '/doc/开发/待授权修复/widget-preview-catalog.candidate.php'));
    self::assertTrue($method->invoke(null, $root, $root . '/test/deep-remediation.py'));
    // 设计主题的 `.phtml` 是**源码模板**，不是静态资源；铺进 pub/static 后
    // `/static/{theme}/.../default.phtml` 会回吐模板源码（部分服务器还会执行）。
    self::assertTrue($method->invoke(null, $root, $root . '/frontend/layouts/homepage/default.phtml'));
    self::assertTrue($method->invoke(null, $root, $root . '/frontend/layouts/test/assets-test.phtml'));
    self::assertTrue($method->invoke(null, $root, $root . '/register.php'));
    self::assertFalse($method->invoke(null, $root, $root . '/frontend/assets/css/theme.css'));
    self::assertFalse($method->invoke(null, $root, $root . '/frontend/layouts/test/assets-test.css'));
    self::assertFalse($method->invoke(null, $root, '/Users/example/project/app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css'));
}

/**
 * 真实落盘 + 真实 Scan：fetchThemeFiles 只收集运行时资源。
 * 反例来自事故：app/design/{theme}/doc 与 /test 的内部文档、*.py、*.candidate.php
 * 曾被搬进 pub/static，浏览器可直接读取；设计主题的 `*.phtml` 源码模板同理
 * （`/static/{theme}/.../default.phtml` 会回吐模板源码）。
 */
private function assert_ThemeUpgradeCommand_testFetchThemeFilesSkipsDesignDocsAndTestScripts(): void
{
    try {
        $command = ObjectManager::getInstance(ThemeUpgradeCommand::class);
    } catch (\Throwable $exception) {
        self::markTestSkipped('需要框架容器（app/bootstrap_phpunit.php）构建真实命令：' . $exception->getMessage());
    }
    $workspace = sys_get_temp_dir() . '/weline-theme-fetch-' . bin2hex(random_bytes(6));
    $root = $workspace . '/Weline/hanfu';
    foreach ([$root . '/doc/开发/待授权修复', $root . '/test', $root . '/frontend/assets/css', $root . '/frontend/layouts/test'] as $directory) {
        self::assertTrue(mkdir($directory, 0775, true), 'mkdir ' . $directory);
    }
    $fixtures = [$root . '/doc/README.md' => 'internal notes', $root . '/doc/开发/待授权修复/widget-preview-catalog.candidate.php' => 'internal script', $root . '/test/deep-remediation.py' => 'print(1)', $root . '/frontend/assets/css/theme.css' => '.theme { color: red; }', $root . '/frontend/layouts/test/assets-test.css' => '.test-layout { color: blue; }', $root . '/frontend/layouts/test/assets-test.phtml' => 'layout body'];
    foreach ($fixtures as $path => $content) {
        self::assertNotFalse(file_put_contents($path, $content), 'write ' . $path);
    }
    try {
        $theme = (new \ReflectionClass(WelineTheme::class))->newInstanceWithoutConstructor();
        $theme->setData('path', $root);
        $theme->setData('origin_path', 'Weline/hanfu');
        $sources = array_keys($command->fetchThemeFiles($theme, $root));
        self::assertContains($root . '/frontend/assets/css/theme.css', $sources);
        // `test` 是合法的布局名：布局目录下的**静态资源**照常发布……
        self::assertContains($root . '/frontend/layouts/test/assets-test.css', $sources);
        // ……但 `.phtml` 源码模板绝不发布（Web 根下会回吐源码）。
        self::assertNotContains($root . '/frontend/layouts/test/assets-test.phtml', $sources);
        self::assertNotContains($root . '/doc/README.md', $sources);
        self::assertNotContains($root . '/doc/开发/待授权修复/widget-preview-catalog.candidate.php', $sources);
        self::assertNotContains($root . '/test/deep-remediation.py', $sources);
    } finally {
        self::removeTree_ThemeUpgradeCommand($workspace);
    }
}

private function removeTree_ThemeUpgradeCommand(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

private function assert_ThemeUpgradeCommand_testPublisherRejectsAFileOutsideTheThemeRoot(): void
{
    self::assertNull(ThemeUpgradeCommand::buildDestinationDirectory('/Users/example/project/app/design/WeShop/motor', '/Users/example/project/app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css', 'WeShop/motor', '/Users/example/project/pub/static'));
}

/**
 * Design {Module}/email/** is a server-template overlay (same class as templates/) —
 * path-segment skip must keep it out of fetchThemeFiles even before .phtml exclusion.
 */
private function assert_ThemeUpgradeCommand_testFetchThemeFilesSkipsModuleEmailServerTemplates(): void
{
    try {
        $command = ObjectManager::getInstance(ThemeUpgradeCommand::class);
    } catch (\Throwable $exception) {
        self::markTestSkipped('需要框架容器（app/bootstrap_phpunit.php）构建真实命令：' . $exception->getMessage());
    }
    $workspace = sys_get_temp_dir() . '/weline-theme-email-' . bin2hex(random_bytes(6));
    $root = $workspace . '/Weline/daocharms';
    foreach ([$root . '/frontend/assets/css', $root . '/Weline_Smtp/email'] as $directory) {
        self::assertTrue(mkdir($directory, 0775, true), 'mkdir ' . $directory);
    }
    $css = $root . '/frontend/assets/css/theme.css';
    $email = $root . '/Weline_Smtp/email/shell.phtml';
    self::assertNotFalse(file_put_contents($css, '.ok{}'));
    self::assertNotFalse(file_put_contents($email, '<!-- mail -->'));
    try {
        $theme = (new \ReflectionClass(WelineTheme::class))->newInstanceWithoutConstructor();
        $theme->setData('path', $root);
        $theme->setData('origin_path', 'Weline/daocharms');
        $sources = array_keys($command->fetchThemeFiles($theme, $root));
        self::assertContains($css, $sources);
        self::assertNotContains($email, $sources);
    } finally {
        self::removeTree_ThemeUpgradeCommand($workspace);
    }
}

/**
 * Out-of-root origin must fail-soft (skip), never throw and abort bare theme:upgrade.
 */
private function assert_ThemeUpgradeCommand_testFetchThemeFilesSkipsOutOfRootOriginWithoutThrowing(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Upgrade.php');
    self::assertStringContainsString("DS . 'email' . DS", $source);
    self::assertStringContainsString('跳过主题外文件（不在允许目录内）', $source);
    self::assertStringNotContainsString("throw new ConsoleException(__('主题文件不在允许的主题目录内：')", $source);
}

/**
 * Default view/theme must not host design-module server-template mirrors
 * (Vendor_Module/email or Vendor_Module/templates).
 */
private function assert_ThemeUpgradeCommand_testDefaultThemeViewTreeHasNoModuleServerTemplateMirrors(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme';
    self::assertDirectoryExists($root);
    foreach (glob($root . '/*_*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
        $base = basename($moduleDir);
        // Only Vendor_Module-shaped dirs (single underscore vendor/module split).
        if (!preg_match('/^[A-Za-z0-9]+_[A-Za-z0-9]+$/', $base)) {
            continue;
        }
        self::assertDirectoryDoesNotExist($moduleDir . '/email', 'Default view/theme must not mirror module email: ' . $base);
        self::assertDirectoryDoesNotExist($moduleDir . '/templates', 'Default view/theme must not mirror module templates: ' . $base);
    }
}

/**
 * Shared Scan must not leak a prior design theme's origins into Default fetchThemeFiles.
 */
private function assert_ThemeUpgradeCommand_testFetchThemeFilesAfterAnotherThemeDoesNotLeakForeignOrigins(): void
{
    try {
        $command = ObjectManager::getInstance(ThemeUpgradeCommand::class);
    } catch (\Throwable $exception) {
        self::markTestSkipped('需要框架容器：' . $exception->getMessage());
    }
    $workspace = sys_get_temp_dir() . '/weline-theme-leak-' . bin2hex(random_bytes(6));
    $design = $workspace . '/design';
    $default = $workspace . '/default-theme';
    foreach ([$design . '/frontend/assets/css', $default . '/frontend/assets/css'] as $directory) {
        self::assertTrue(mkdir($directory, 0775, true));
    }
    $foreignCss = $design . '/frontend/assets/css/foreign.css';
    $defaultCss = $default . '/frontend/assets/css/theme.css';
    self::assertNotFalse(file_put_contents($foreignCss, '.foreign{}'));
    self::assertNotFalse(file_put_contents($defaultCss, '.ok{}'));
    try {
        $designTheme = (new \ReflectionClass(WelineTheme::class))->newInstanceWithoutConstructor();
        $designTheme->setData('path', $design);
        $designTheme->setData('origin_path', 'Weline/design');
        $command->fetchThemeFiles($designTheme, $design);
        $defaultTheme = (new \ReflectionClass(WelineTheme::class))->newInstanceWithoutConstructor();
        $defaultTheme->setData('path', $default);
        $defaultTheme->setData('origin_path', 'Weline/Theme/view/theme');
        $sources = array_keys($command->fetchThemeFiles($defaultTheme, $default));
        self::assertContains($defaultCss, $sources);
        self::assertNotContains($foreignCss, $sources);
        foreach ($sources as $origin) {
            self::assertStringStartsWith(rtrim(str_replace('\\', '/', $default), '/') . '/', str_replace('\\', '/', $origin));
        }
    } finally {
        self::removeTree_ThemeUpgradeCommand($workspace);
    }
}
}
