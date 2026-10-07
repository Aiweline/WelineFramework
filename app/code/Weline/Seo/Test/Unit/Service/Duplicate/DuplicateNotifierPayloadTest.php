<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\DuplicateNotifier;

final class DuplicateNotifierPayloadTest extends TestCase
{
    public function testPayloadContainsReportUrl(): void
    {
        $payload = (new DuplicateNotifier())->buildPayload(
            99,
            3,
            2,
            1,
            'https://admin.example.test/seo/backend/duplicate/report?run_id=99'
        );

        self::assertSame('seo_duplicate_content', $payload['topic']);
        self::assertStringContainsString('report?run_id=99', $payload['content']);
        self::assertSame(
            'https://admin.example.test/seo/backend/duplicate/report?run_id=99',
            $payload['options']['metadata']['report_url']
        );
        self::assertSame('seo_dup_run_99', $payload['options']['dedupe_key']);
    }

    public function testNotifyRunTemplateUsesWelinePlaceholders(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Duplicate/DuplicateNotifier.php'
        );

        self::assertStringContainsString(
            "'站点 #%{1} 检出重复 %{2}、疑似 %{3}。查看报告：%{4}'",
            $source
        );
        self::assertStringContainsString(
            '[$websiteId, $duplicate, $suspect, $reportUrl]',
            $source
        );
        self::assertStringNotContainsString(
            "'站点 #%1 检出重复 %2、疑似 %3。查看报告：%4'",
            $source
        );

        // Mirror Phrase\\Parser array-arg branch without bootstrapping i18n.
        $template = '站点 #%{1} 检出重复 %{2}、疑似 %{3}。查看报告：%{4}';
        $args = [0, 58, 555, 'http://admin.example/report?run_id=37'];
        $filled = $template;
        foreach ($args as $key => $arg) {
            $filled = str_replace('%{' . ($key + 1) . '}', (string)$arg, $filled);
        }

        self::assertSame(
            '站点 #0 检出重复 58、疑似 555。查看报告：http://admin.example/report?run_id=37',
            $filled
        );
    }
}
