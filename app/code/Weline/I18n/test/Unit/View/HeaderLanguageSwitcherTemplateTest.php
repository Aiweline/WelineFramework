<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderLanguageSwitcherTemplateTest extends TestCase
{
    public function testHeaderHookDelegatesToStandardSwitcher(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-language-switcher.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('LanguageSwitcher::render', $content);
        self::assertStringNotContainsString('getLocalesWithFlagsDisplaySelf', $content);
        self::assertStringNotContainsString('LanguageSupportRequestService', $content);
        self::assertStringNotContainsString('i18n_language_requests', $content);
        self::assertStringNotContainsString('weline-choice-name', $content);
    }
}
