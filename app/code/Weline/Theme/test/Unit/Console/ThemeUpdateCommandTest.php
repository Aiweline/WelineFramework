<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Cli;
use Weline\Theme\Console\Theme\Update;
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

    public function testExecutedCommandLineKeepsThemeArgument(): void
    {
        self::assertSame(
            'theme:update -t hanfu',
            Cli::formatExecutedCommandLine('theme:update', ['theme:update', '-t', 'hanfu'])
        );
    }
}
