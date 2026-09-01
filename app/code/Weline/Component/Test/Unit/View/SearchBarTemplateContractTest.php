<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class SearchBarTemplateContractTest extends TestCase
{
    public function testSearchBarUsesCombinedThemeComponent(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/form/search-bar.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('w-search-bar', $content);
        self::assertStringContainsString('w-search-bar__control', $content);
        self::assertStringContainsString('w-search-bar__input', $content);
        self::assertStringContainsString('w-search-bar__submit', $content);
        self::assertStringContainsString('client_mode', $content);
        self::assertStringContainsString('data-w-system-config-search-input', $content);
        self::assertStringContainsString('id="wsc-search-input"', $content);
        self::assertStringContainsString('id="wsc-search-root"', $content);
        self::assertStringContainsString('w-search-bar__clear', $content);
        self::assertStringContainsString('<svg', $content);
        self::assertStringNotContainsString('w:icon', $content);
    }
}
