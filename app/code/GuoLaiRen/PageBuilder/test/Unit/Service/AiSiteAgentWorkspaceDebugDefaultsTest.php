<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentWorkspaceDebugDefaultsTest extends TestCase
{
    public function testDebugDefaultConstantsAreNonEmpty(): void
    {
        self::assertNotSame('', \trim(AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE));
        self::assertNotSame('', \trim(AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION));
        self::assertSame('en_US', AiSiteAgentWorkspaceDebugDefaults::DEFAULT_LOCALE);
    }

    public function testNormalizeDefaultLocale(): void
    {
        self::assertSame('en_US', AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(null));
        self::assertSame('en_US', AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(''));
        self::assertSame('en_US', AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale('invalid'));
        self::assertSame('zh_Hans_CN', AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale('zh_Hans_CN'));
    }
}
