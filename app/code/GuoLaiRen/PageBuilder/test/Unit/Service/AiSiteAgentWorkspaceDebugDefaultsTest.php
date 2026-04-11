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
    }
}
