<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console\Theme;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Console\Theme\Upgrade as ThemeUpgradeCommand;
use Weline\Theme\Model\WelineTheme;

if (!class_exists(ThemeUpgradeCommand::class, false)) {
    require_once dirname(__DIR__, 4) . '/Console/Theme/Upgrade.php';
}

final class ThemeUpgradeCommandContractTest extends TestCase
{
    public function testNamedThemeLookupUsesTheDeclaredNameFieldConstant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/Console/Theme/Upgrade.php');

        self::assertIsString($source);
        self::assertStringContainsString('WelineTheme::schema_fields_NAME', $source);
        self::assertStringNotContainsString('WelineTheme::filed_NAME', $source);
    }

    public function testCliMetadataIsNotTreatedAsAModuleFilter(): void
    {
        [$themeName, $modules] = ThemeUpgradeCommand::parseArguments([
            0 => 'theme:upgrade',
            1 => '-t',
            2 => 'weshop-motor',
            'command' => 'theme:upgrade',
            't' => 'weshop-motor',
        ]);

        self::assertSame('weshop-motor', $themeName);
        self::assertSame([], $modules);
    }

    public function testNamedThemeFlagFromAssociativeArgs(): void
    {
        [$themeName, $modules] = ThemeUpgradeCommand::parseArguments([
            'command' => 'theme:upgrade',
            't' => 'daocharms',
        ]);

        self::assertSame('daocharms', $themeName);
        self::assertSame([], $modules);
    }

    public function testNamespacedModuleThemeRequestPathIncludesThemeIdentity(): void
    {
        self::assertSame(
            '/static/Weline/daocharms/Weline/Theme/view/theme/frontend/assets/css/theme.css',
            ThemeUpgradeCommand::buildNamespacedModuleThemeRequestPath(
                'Weline/daocharms',
                'Weline',
                'Theme',
                'frontend',
                'assets/css/theme.css',
            )
        );
    }

    public function testPublisherCreatesMissingDestinationDirectories(): void
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

            self::assertSame(
                '.motor-header { color: white; }',
                file_get_contents($destination . '/source.css')
            );
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

    public function testAbsoluteCoreThemePathPublishesInsideTheStaticThemeNamespace(): void
    {
        $sourceRoot = '/Users/example/project/app/code/Weline/Theme/view/theme';
        $sourceFile = $sourceRoot . '/frontend/assets/css/theme.css';

        self::assertSame(
            '/Users/example/project/pub/static/Weline/Theme/view/theme/frontend/assets/css',
            ThemeUpgradeCommand::buildDestinationDirectory(
                $sourceRoot,
                $sourceFile,
                'Weline/Theme/view/theme',
                '/Users/example/project/pub/static'
            )
        );
    }

    public function testDesignDocAndTestDirectoriesAreNeverPublished(): void
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
        self::assertFalse($method->invoke(
            null,
            $root,
            '/Users/example/project/app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css'
        ));
    }

    /**
     * 真实落盘 + 真实 Scan：fetchThemeFiles 只收集运行时资源。
     * 反例来自事故：app/design/{theme}/doc 与 /test 的内部文档、*.py、*.candidate.php
     * 曾被搬进 pub/static，浏览器可直接读取；设计主题的 `*.phtml` 源码模板同理
     * （`/static/{theme}/.../default.phtml` 会回吐模板源码）。
     */
    public function testFetchThemeFilesSkipsDesignDocsAndTestScripts(): void
    {
        try {
            $command = ObjectManager::getInstance(ThemeUpgradeCommand::class);
        } catch (\Throwable $exception) {
            self::markTestSkipped('需要框架容器（app/bootstrap_phpunit.php）构建真实命令：' . $exception->getMessage());
        }

        $workspace = sys_get_temp_dir() . '/weline-theme-fetch-' . bin2hex(random_bytes(6));
        $root = $workspace . '/Weline/hanfu';
        foreach ([
            $root . '/doc/开发/待授权修复',
            $root . '/test',
            $root . '/frontend/assets/css',
            $root . '/frontend/layouts/test',
        ] as $directory) {
            self::assertTrue(mkdir($directory, 0775, true), 'mkdir ' . $directory);
        }

        $fixtures = [
            $root . '/doc/README.md' => 'internal notes',
            $root . '/doc/开发/待授权修复/widget-preview-catalog.candidate.php' => 'internal script',
            $root . '/test/deep-remediation.py' => 'print(1)',
            $root . '/frontend/assets/css/theme.css' => '.theme { color: red; }',
            $root . '/frontend/layouts/test/assets-test.css' => '.test-layout { color: blue; }',
            $root . '/frontend/layouts/test/assets-test.phtml' => 'layout body',
        ];
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
            self::removeTree($workspace);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    public function testPublisherRejectsAFileOutsideTheThemeRoot(): void
    {
        self::assertNull(ThemeUpgradeCommand::buildDestinationDirectory(
            '/Users/example/project/app/design/WeShop/motor',
            '/Users/example/project/app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css',
            'WeShop/motor',
            '/Users/example/project/pub/static'
        ));
    }
}
