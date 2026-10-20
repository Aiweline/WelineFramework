<?php

declare(strict_types=1);

/**
 * 影子开关 + 执行器测试。
 *
 * 三个判据：
 * 1. 配置解析（字符串/数组/布尔三种写法）；
 * 2. 等价样本报告 `equal=true` 且不落 diff；
 * 3. 不等价样本报告首处差异位置、并把 diff 落到目录。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Shadow\ShadowConfig;
use Weline\Framework\View\Compiler\Shadow\ShadowRunner;
use Weline\Framework\View\Compiler\Tag\Builtin\ControlFlowTag;
use Weline\Framework\View\Compiler\Tag\Builtin\LangTag;
use Weline\Framework\View\Compiler\Tag\Builtin\VarTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class ShadowRunnerTest extends TestCase
{
    private function registry(): Registry
    {
        return new Registry([
            LangTag::descriptor(),
            VarTag::descriptor(),
            ...ControlFlowTag::descriptors(),
        ]);
    }

    private function matcher(): StaticTagNameMatcher
    {
        return new StaticTagNameMatcher(['lang', 'var', 'if', 'elseif', 'else', 'foreach']);
    }

    public function testConfigParsing(): void
    {
        $config = ShadowConfig::fromArray([
            'engine' => [
                'shadow' => '1',
                'v3' => false,
                'tags' => 'lang, trans',
                'shadow_tags' => ['if', 'Foreach'],
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertFalse($config->v3);
        self::assertTrue($config->shouldShadow());
        self::assertSame(['lang', 'trans'], $config->tags);
        self::assertSame(['if', 'foreach'], $config->shadowTags);
        self::assertTrue($config->isTagShadowed('IF'));
        self::assertFalse($config->isTagShadowed('lang'));
    }

    public function testEqualSampleProducesNoDiffFile(): void
    {
        $dir = \sys_get_temp_dir() . '/weline-shadow-test-' . \bin2hex(\random_bytes(4));
        $source = '<div><lang>你好</lang>{{title}}<if condition="$x">A<else/>B</if></div>';

        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'shadow-equal.phtml');

        $runner = new ShadowRunner($this->registry(), $this->matcher(), $dir);
        $report = $runner->compare($source, 'shadow-equal.phtml', $legacy);

        self::assertTrue($report->equal, $report->summary());
        self::assertSame(-1, $report->firstDiffAt);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testProdHtmlCommentsAreRemovedBeforeCompare(): void
    {
        $dir = \sys_get_temp_dir() . '/weline-shadow-test-' . \bin2hex(\random_bytes(4));
        $source = "<!-- hidden comment -->\n<div><lang>你好</lang></div>";

        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'shadow-comment.phtml');

        $runner = new ShadowRunner($this->registry(), $this->matcher(), $dir);
        $report = $runner->compare($source, 'shadow-comment.phtml', $legacy);

        self::assertTrue($report->equal, $report->summary());
        if (\defined('PROD') && \PROD) {
            self::assertStringNotContainsString('hidden comment', $legacy);
        }
    }

    public function testDiffSampleReportsOffsetAndWritesFile(): void
    {
        $dir = \sys_get_temp_dir() . '/weline-shadow-test-' . \bin2hex(\random_bytes(4));
        $source = '<div><lang>你好</lang></div>';

        $runner = new ShadowRunner($this->registry(), $this->matcher(), $dir);
        $report = $runner->compare($source, 'shadow-diff.phtml', 'deliberately-different');

        self::assertFalse($report->equal);
        self::assertSame(0, $report->firstDiffAt);
        self::assertStringContainsString('DIFF', $report->summary());
        self::assertDirectoryExists($dir);
        $files = \glob($dir . '/*.diff') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('deliberately-different', (string)\file_get_contents($files[0]));
    }
}
