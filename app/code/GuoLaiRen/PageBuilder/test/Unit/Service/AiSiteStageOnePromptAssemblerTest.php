<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteStageOnePromptAssembler;
use PHPUnit\Framework\TestCase;

final class AiSiteStageOnePromptAssemblerTest extends TestCase
{
    public function testAssembleKeepsUserUpstreamAndSystemSectionsSeparate(): void
    {
        $assembler = new AiSiteStageOnePromptAssembler();
        $prompt = $assembler->assemble([
            'system_task' => ['Task line'],
            'user_inputs' => ['brief_description' => '霓虹棋牌官网'],
            'upstream_artifacts' => ['requirement_parse' => ['parsed_one_line_brief' => '已解析的一句话']],
            'contract_lines' => ['Gate line'],
            'output_schema' => ['{"requirement_expansion":{}}'],
            'self_check' => ['Check line'],
        ]);

        self::assertStringContainsString('【系统提示词】', $prompt);
        self::assertStringContainsString('【用户提示词】', $prompt);
        self::assertStringContainsString('【上游产物】', $prompt);
        self::assertStringContainsString('【阶段契约】', $prompt);
        self::assertStringContainsString('【输出 Schema】', $prompt);
        self::assertStringContainsString('【返回前自检】', $prompt);
        self::assertStringContainsString('- brief_description: 霓虹棋牌官网', $prompt);
        self::assertStringContainsString('parsed_one_line_brief', $prompt);
        self::assertStringNotContainsString('Brief:', $prompt);
    }
}
