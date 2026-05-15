<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use PHPUnit\Framework\TestCase;

final class AiResponseJsonParserTest extends TestCase
{
    public function testExtractAndDecodeRepairsTruncatedRootJson(): void
    {
        $parser = new AiResponseJsonParser();

        $decoded = $parser->extractAndDecode('{"markdown":"# Site Blueprint","plan_json":{"site_strategy":{"site_display_name":"Demo"}');

        self::assertIsArray($decoded);
        self::assertSame('# Site Blueprint', (string)($decoded['markdown'] ?? ''));
        self::assertSame('Demo', (string)($decoded['plan_json']['site_strategy']['site_display_name'] ?? ''));
    }

    public function testExtractAndDecodeSkipsMarkdownPrefixBeforeJson(): void
    {
        $parser = new AiResponseJsonParser();

        $decoded = $parser->extractAndDecode("# Site Blueprint\n\n{\"markdown\":\"# Site Blueprint\",\"plan_json\":{\"site_strategy\":{\"site_display_name\":\"Demo\"}}}");

        self::assertIsArray($decoded);
        self::assertSame('Demo', (string)($decoded['plan_json']['site_strategy']['site_display_name'] ?? ''));
    }

    public function testExtractAndDecodeUsesFirstBalancedObjectWhenModelAppendsSecondJsonObject(): void
    {
        $parser = new AiResponseJsonParser();

        $decoded = $parser->extractAndDecode(
            '{"html_content":"<section>First</section>","js_content":""}'
            . '{"html_content":"<section>Second</section>","js_content":""}'
        );

        self::assertIsArray($decoded);
        self::assertSame('<section>First</section>', (string)($decoded['html_content'] ?? ''));
    }

    public function testExtractAndDecodeRepairsMissingColonAfterKnownComponentKey(): void
    {
        $parser = new AiResponseJsonParser();

        $decoded = $parser->extractAndDecode(
            '{"extra_fields":"","php_variables "" ,"css_extra":"#componentId{}","css_responsive":"","html_content":"<img src=\"/pub/media/real.jpg\">","js_content":""}'
        );

        self::assertIsArray($decoded);
        self::assertSame('', (string)($decoded['php_variables'] ?? 'missing'));
        self::assertSame('#componentId{}', (string)($decoded['css_extra'] ?? ''));
    }
}
