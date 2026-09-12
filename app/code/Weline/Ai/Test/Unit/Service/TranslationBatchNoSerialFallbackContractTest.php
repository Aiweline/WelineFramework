<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class TranslationBatchNoSerialFallbackContractTest extends TestCase
{
    public function testMultiItemBatchDoesNotFallBackToSerialTranslate(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/TranslationService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('count($texts) > 1', $source);
        self::assertStringContainsString('批量翻译结果解析失败', $source);
        self::assertStringContainsString('strrpos($json, \']\')', $source);
        self::assertStringContainsString("'max_tokens'", $source);
        self::assertStringContainsString('splitAndRetryBatchTranslate', $source);
        // Multi-item: binary split retry — never N serial translate() in the catch path.
        self::assertMatchesRegularExpression(
            '/if \(count\(\$texts\) > 1\) \{\s*return \$this->splitAndRetryBatchTranslate/s',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/if \(count\(\$texts\) > 1\) \{[^}]{0,200}foreach \(\$texts as/s',
            $source,
        );
    }
}
