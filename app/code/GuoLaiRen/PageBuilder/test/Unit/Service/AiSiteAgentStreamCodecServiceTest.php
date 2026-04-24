<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentStreamCodecService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentStreamCodecServiceTest extends TestCase
{
    public function testChunkStringForSseNormalizesLineEndingsAndPreservesMultibyteCharacters(): void
    {
        $service = new AiSiteAgentStreamCodecService();

        self::assertSame(
            ["ab\n", "éé1", "2"],
            $service->chunkStringForSse("ab\r\néé12", 3)
        );
    }

    public function testExtractPlanMarkdownJsonStreamDeltaDecodesJsonEscapesIncrementally(): void
    {
        $service = new AiSiteAgentStreamCodecService();
        $state = ['stage' => 'seek_key', 'i' => 0, 'decoded' => '', 'emitted' => 0];
        $buffer = '{"markdown":"Hello\\n\\u00e9\\u00f1"}';

        $first = $service->extractPlanMarkdownJsonStreamDelta($buffer, $state);
        $second = $service->extractPlanMarkdownJsonStreamDelta($buffer, $state);

        self::assertSame("Hello\néñ", $first);
        self::assertSame('', $second);
        self::assertSame('done', $state['stage']);
    }

    public function testSplitMarkdownBlocksUsesHeadingBoundaries(): void
    {
        $service = new AiSiteAgentStreamCodecService();

        self::assertSame(
            [
                "# Title\nIntro",
                "## Section\nBody",
                "### Detail\nMore",
            ],
            $service->splitMarkdownBlocks("# Title\nIntro\n\n## Section\nBody\n\n### Detail\nMore")
        );
    }
}
