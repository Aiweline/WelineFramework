<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Cli;
use Weline\Theme\Console\Theme\Update;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBuildService;

/**
 * theme:update 不带子命令时必须构建并切换；页脚必须打出 -t 后面的主题名。
 */
final class ThemeUpdateCommandTest extends TestCase
{
    public function testBareThemeFlagAppliesUpdate(): void
    {
        self::assertSame('apply', Update::resolveAction(['-t', 'hanfu']));
        self::assertSame('apply', Update::resolveAction([]));
    }

    public function testExplicitStatusStaysReadOnly(): void
    {
        self::assertSame('status', Update::resolveAction(['status', '-t', 'hanfu']));
        self::assertSame('rollback', Update::resolveAction(['rollback', '-t', 'hanfu']));
    }

    public function testModuleThemeAbsolutePathIsNotAStaticPrefix(): void
    {
        self::assertSame('', ThemeUpdateBuildService::staticNamespaceFromThemePath(
            '/srv/app/code/Weline/Theme/view/theme',
            '/srv/app/design',
        ));
        self::assertSame('Weline/hanfu', ThemeUpdateBuildService::staticNamespaceFromThemePath(
            'Weline/hanfu',
            '/srv/app/design',
        ));
        self::assertSame(
            ['Weline/Theme/ui/weline-foundation.css'],
            ThemeUpdateBuildService::staticVerificationSamples(''),
        );
    }

    public function testStaticSampleUsesNamespacedThemeCss(): void
    {
        self::assertSame(
            [
                'Weline/Theme/ui/weline-foundation.css',
                'Weline/hanfu/Weline/Theme/view/theme/frontend/assets/css/theme.css',
            ],
            ThemeUpdateBuildService::staticVerificationSamples('Weline/hanfu'),
        );
    }

    public function testNewPairDropsOlderVersionAndDeployDirs(): void
    {
        $root = sys_get_temp_dir() . '/weline-pair-' . bin2hex(random_bytes(4));
        $deploy = 'aabbccddeeff';
        $flat = $root . '/' . $deploy . '/Weline/Theme/ui';
        $oldVersion = $root . '/theme/v5/001122334455';
        $oldDeploy = $root . '/theme/v6/001122334455';
        mkdir($flat, 0775, true);
        mkdir($oldVersion, 0775, true);
        mkdir($oldDeploy, 0775, true);
        file_put_contents($flat . '/weline-backend.css', 'new');
        file_put_contents($oldVersion . '/old.css', 'old-version');
        file_put_contents($oldDeploy . '/old.css', 'old-deploy');

        try {
            self::assertSame(['theme', 6], ThemeUpdateBatchService::pairFromPointerRow([
                'pair_segment' => 'theme/v6',
            ]));
            self::assertSame([], ThemeUpdateBatchService::pairFromPointerRow(['theme_prefix' => 'Weline/hanfu']));
            self::assertTrue(ThemeUpdateBatchService::seedThemePairFromNewDeploy($root, 'theme', 6, $deploy));
            $removed = ThemeUpdateBatchService::retireStaleThemePairDirs($root, 'theme', 6, $deploy);
            self::assertSame(2, $removed);
            self::assertFileExists($root . '/theme/v6/' . $deploy . '/Weline/Theme/ui/weline-backend.css');
            self::assertDirectoryDoesNotExist($oldVersion);
            self::assertDirectoryDoesNotExist($oldDeploy);
        } finally {
            ThemeUpdateBatchService::retireStaleThemePairDirs($root, 'theme', 6, $deploy);
            self::removeFixtureTree($root);
        }
    }

    private static function removeFixtureTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeFixtureTree($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }

    public function testExecutedCommandLineKeepsThemeArgument(): void
    {
        self::assertSame(
            'theme:update -t hanfu',
            Cli::formatExecutedCommandLine('theme:update', ['theme:update', '-t', 'hanfu'])
        );
    }
}
