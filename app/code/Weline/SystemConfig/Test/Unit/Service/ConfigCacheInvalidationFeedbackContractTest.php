<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Service\ConfigCacheInvalidationFeedback;

final class ConfigCacheInvalidationFeedbackContractTest extends TestCase
{
    public function testBuildSummaryUsesFriendlyLayeredCopy(): void
    {
        $feedback = new ConfigCacheInvalidationFeedback();
        $built = $feedback->build(
            ['global/storefront/captcha', 'global/storefront/config'],
            [
                ['pool' => 'system_config', 'keys' => ['a', 'b', 'c']],
                ['pool' => 'database', 'keys' => ['a', 'b', 'c']],
            ],
            2,
        );
        self::assertContains('人机验证', $built['friendly_labels']);
        self::assertContains('店面配置', $built['friendly_labels']);
        self::assertSame(3, $built['cache_key_count']);
        self::assertStringContainsString('已刷新缓存', $built['summary']);
        self::assertStringNotContainsString('bump', $built['summary']);
        self::assertStringNotContainsString('池删除', $built['summary']);
        self::assertGreaterThanOrEqual(2, count($built['detail_lines']));
    }

    public function testFormatSavePartsSeparatesTitleAndBody(): void
    {
        $feedback = new ConfigCacheInvalidationFeedback();
        $invalidation = $feedback->build(['global/storefront/captcha'], [
            ['pool' => 'system_config', 'keys' => ['k1']],
        ], 1);
        $parts = $feedback->formatSaveParts(42, $invalidation);
        self::assertSame('配置已保存', $parts['title']);
        self::assertStringContainsString('版本批次 42', $parts['body']);
        self::assertStringContainsString("\n", $parts['message']);
        self::assertStringNotContainsString('bump', $parts['message']);
    }

    public function testControllerAndEmbedUseServerMessage(): void
    {
        $controller = dirname(__DIR__, 3) . '/Controller/Backend/Config.php';
        $js = dirname(__DIR__, 3) . '/view/statics/js/config-embed.js';
        $controllerSrc = (string)file_get_contents($controller);
        $jsSrc = (string)file_get_contents($js);
        self::assertStringContainsString('formatSaveParts', $controllerSrc);
        self::assertStringContainsString('addSuccess($body, $title)', $controllerSrc);
        self::assertStringContainsString('message_title', $jsSrc);
        self::assertStringContainsString('title:', $jsSrc);
    }
}
