<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentWorkspaceDebugDefaultsTest extends TestCase
{
    /**
     * 锁定 AI 建站工作台"调试预填"内置常量的非空性以及默认主语言的当前真相。
     * 之前硬编码 `en_US`，但生产代码已改为简体中文默认（与后台配置一致，见 DEFAULT_LOCALE 注释）。
     */
    public function testDebugDefaultConstantsAreNonEmpty(): void
    {
        self::assertNotSame('', \trim(AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE));
        self::assertNotSame('', \trim(AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION));
        self::assertStringNotContainsString('websiteProfile', AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE);
        self::assertStringNotContainsString('Teenipiya', AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE);
        self::assertStringNotContainsString('APK', AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION);
        self::assertStringNotContainsString('Teenipiya', AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION);
        self::assertSame('zh_Hans_CN', AiSiteAgentWorkspaceDebugDefaults::DEFAULT_LOCALE);
    }

    public function testNormalizeDefaultLocale(): void
    {
        $default = AiSiteAgentWorkspaceDebugDefaults::DEFAULT_LOCALE;
        self::assertSame($default, AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(null));
        self::assertSame($default, AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(''));
        self::assertSame($default, AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale('invalid'));
        self::assertSame('zh_Hans_CN', AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale('zh_Hans_CN'));
        self::assertSame('en_US', AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale('en_US'));
    }
}
