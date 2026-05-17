<?php

declare(strict_types=1);

namespace Tests\Unit\GuoLaiRen\PageBuilder;

use GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException;
use PHPUnit\Framework\TestCase;

/**
 * AiSiteComponentContractException 单元测试。
 *
 * 用 finding-feedback 路径替代「裁断 message 前 220 字」的退化方案，
 * 测试确保渲染格式稳定，能被 strict recovery prompt 逐条引用。
 */
final class AiSiteComponentContractExceptionTest extends TestCase
{
    public function testRenderFindingsForPromptProducesOneLinePerRule(): void
    {
        $exception = new AiSiteComponentContractException(
            'css_extra invalid',
            [
                [
                    'rule' => 'visual_depth.gradient',
                    'field' => 'css_extra',
                    'found' => 'no linear-gradient detected',
                    'expected' => 'css_extra must contain linear-gradient or radial-gradient',
                    'hint' => '在 .pb-c-root background 加 linear-gradient(palette.surface, palette.surface_alt)',
                ],
                [
                    'rule' => 'responsive_support.media_query',
                    'field' => 'css_responsive',
                    'found' => 'empty',
                    'expected' => '@media (max-width: 768px) AND @media (max-width: 420px)',
                ],
            ]
        );

        $rendered = $exception->renderFindingsForPrompt();
        $lines = \array_values(\array_filter(\explode("\n", $rendered)));

        self::assertCount(2, $lines);
        self::assertStringContainsString('[visual_depth.gradient]', $lines[0]);
        self::assertStringContainsString('字段=css_extra', $lines[0]);
        self::assertStringContainsString('修复=', $lines[0]);
        self::assertStringContainsString('[responsive_support.media_query]', $lines[1]);
        self::assertStringContainsString('期望=@media (max-width: 768px)', $lines[1]);
    }

    public function testEmptyFindingsRenderEmptyString(): void
    {
        $exception = new AiSiteComponentContractException('nothing yet', []);
        self::assertSame('', $exception->renderFindingsForPrompt());
    }

    public function testMaxLinesIsRespected(): void
    {
        $findings = [];
        for ($i = 0; $i < 20; $i++) {
            $findings[] = ['rule' => 'visual_depth.row_' . $i, 'field' => 'css_extra'];
        }
        $exception = new AiSiteComponentContractException('overflow', $findings);
        $rendered = $exception->renderFindingsForPrompt(5);
        $lines = \array_values(\array_filter(\explode("\n", $rendered)));
        self::assertCount(5, $lines);
    }
}
