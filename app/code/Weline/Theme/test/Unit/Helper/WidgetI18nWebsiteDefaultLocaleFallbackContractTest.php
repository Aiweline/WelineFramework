<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * WO-HP-P1-04：无 path/RequestContext 时 WidgetI18n 应回落网站默认语，禁止硬编码 zh_Hans_CN。
 */
final class WidgetI18nWebsiteDefaultLocaleFallbackContractTest extends TestCase
{
    public function testResolveStorefrontLocaleFallsBackToWebsiteDefaultLanguage(): void
    {
        $path = dirname(__DIR__, 3) . '/Helper/WidgetI18n.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('State::resolveWebsiteDefaultLanguage()', $source);
        self::assertStringContainsString('无 path / RequestContext 时回落到网站默认语', $source);
        // 仍保留最终安全底，但必须先尝试网站默认语
        self::assertLessThan(
            strpos($source, "return 'zh_Hans_CN';"),
            strrpos($source, 'resolveWebsiteDefaultLanguage'),
            'website default lookup must precede hard-coded zh_Hans_CN fallback'
        );
    }
}
