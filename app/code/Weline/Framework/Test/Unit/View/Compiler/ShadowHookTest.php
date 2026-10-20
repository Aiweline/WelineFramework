<?php

declare(strict_types=1);

/**
 * 影子挂钩：默认关闭、等价不落盘、差异落盘、异常 fail-open。
 *
 * 这里不经过 `Template::compileSourceBytes()`（避免测试写 `generated/`），
 * 而是直接验证挂钩的 fail-open 与报告语义；接线本身只在 `compileSourceBytes`
 * 里加了一个默认关闭的分支。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Shadow\ShadowConfig;
use Weline\Framework\View\Compiler\Shadow\ShadowHook;
use Weline\Framework\View\Template;

final class ShadowHookTest extends TestCase
{
    private function diffDir(): string
    {
        return \sys_get_temp_dir() . '/weline-shadow-hook-test-' . \bin2hex(\random_bytes(4));
    }

    public function testDisabledConfigReturnsNullWithoutWriting(): void
    {
        $dir = $this->diffDir();
        $report = ShadowHook::compare(
            '<div><lang>你好</lang></div>',
            'hook-disabled.phtml',
            'legacy',
            Template::getInstance(),
            new ShadowConfig(enabled: false),
            $dir,
        );

        self::assertNull($report);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testEqualSampleProducesNoDiff(): void
    {
        $dir = $this->diffDir();
        $source = '<div><lang>你好</lang></div>';
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'hook-equal.phtml');

        $report = ShadowHook::compare(
            $source,
            'hook-equal.phtml',
            $legacy,
            $template,
            new ShadowConfig(enabled: true),
            $dir,
        );

        self::assertNotNull($report);
        self::assertTrue($report->equal, $report->summary());
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testDiffWritesShadowFile(): void
    {
        $dir = $this->diffDir();
        $report = ShadowHook::compare(
            '<div><lang>你好</lang></div>',
            'hook-diff.phtml',
            'deliberately-different',
            Template::getInstance(),
            new ShadowConfig(enabled: true),
            $dir,
        );

        self::assertNotNull($report);
        self::assertFalse($report->equal);
        self::assertDirectoryExists($dir);
        self::assertCount(1, \glob($dir . '/*.diff') ?: []);
    }

    public function testCompilerFailureIsFailOpen(): void
    {
        $dir = $this->diffDir();
        // 不传 Template：`@static` 在 Lower 里显式抛错，挂钩必须吞掉并返回 null。
        $report = ShadowHook::compare(
            '<div>@static(Weline_Test::css/missing.css)</div>',
            'hook-fail-open.phtml',
            'legacy',
            null,
            new ShadowConfig(enabled: true),
            $dir,
        );

        self::assertNull($report);
        self::assertDirectoryDoesNotExist($dir);
    }
}
