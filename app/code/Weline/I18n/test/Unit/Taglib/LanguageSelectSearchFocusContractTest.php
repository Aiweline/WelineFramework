<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class LanguageSelectSearchFocusContractTest extends TestCase
{
    public function testSearchFocusIsDeferredPastTriggerClick(): void
    {
        $runtime = $this->read('view/statics/js/language-select.js');
        $theme = $this->readTheme('view/statics/ui/components/weline-language-select.js');

        self::assertStringContainsString('const focusSearch = () =>', $runtime);
        self::assertStringContainsString('window.setTimeout(() =>', $runtime);
        self::assertStringContainsString("getAttribute('data-w-search')", $runtime);
        self::assertStringContainsString("listen(search, 'compositionend'", $runtime);
        self::assertStringContainsString('focusSearch();', $runtime);
        self::assertStringContainsString('focusSearch', $theme);
        self::assertStringContainsString('window.setTimeout', $theme);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }

    private function readTheme(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 4) . '/Theme/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
