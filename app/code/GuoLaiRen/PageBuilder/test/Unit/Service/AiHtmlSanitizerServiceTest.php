<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiHtmlSanitizerService;
use PHPUnit\Framework\TestCase;

final class AiHtmlSanitizerServiceTest extends TestCase
{
    public function testSanitizeBlockRemovesScript(): void
    {
        $s = new AiHtmlSanitizerService();
        $out = $s->sanitizeBlockHtml('<p>Hi</p><script>alert(1)</script>');
        self::assertStringContainsString('<p>Hi</p>', $out);
        self::assertStringNotContainsString('script', \strtolower($out));
    }

    public function testSanitizeAiLayoutNormalizesBlocks(): void
    {
        $s = new AiHtmlSanitizerService();
        $out = $s->sanitizeAiLayout([
            'blocks' => [
                ['block_id' => '', 'type' => 'x', 'html' => '<p>a</p>'],
            ],
        ]);
        self::assertArrayHasKey('blocks', $out);
        self::assertNotSame('', $out['blocks'][0]['block_id'] ?? '');
    }
}
