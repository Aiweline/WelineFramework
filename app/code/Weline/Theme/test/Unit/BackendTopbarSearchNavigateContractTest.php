<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BackendTopbarSearchNavigateContractTest extends TestCase
{
    public function testHeaderSearchAndBackendCssSupportAreaNavigateHits(): void
    {
        $root = dirname(__DIR__, 6);
        $js = (string)\file_get_contents($root . '/app/code/Weline/Theme/view/statics/js/widgets/header-search.js');
        $srcCss = (string)\file_get_contents($root . '/app/code/Weline/Theme/view/ui/css/backend.css');
        $pubCss = (string)\file_get_contents($root . '/app/code/Weline/Theme/view/statics/ui/weline-backend.css');

        self::assertStringContainsString('data-navigate-hits', $js);
        self::assertStringContainsString('data-search-area', $js);
        self::assertStringContainsString('area: searchArea', $js);
        self::assertStringContainsString('data-w-search-backend-form', $js);
        self::assertStringContainsString('suggestion-item__subtitle', $js);
        self::assertStringContainsString('appendSuggestionRows', $js);
        self::assertStringContainsString('suggestion-group__label', $js);
        self::assertStringContainsString('suggestion-item__key', $js);

        foreach ([$srcCss, $pubCss] as $css) {
            self::assertStringContainsString('.w-backend-topbar__center', $css);
            self::assertStringContainsString('.w-backend-topbar-search', $css);
            self::assertStringContainsString('.suggestion-item__subtitle', $css);
            self::assertStringContainsString('.w-backend-topbar-search .search-type-trigger', $css);
            self::assertStringContainsString('border-radius: 999px', $css);
            self::assertStringContainsString('background: transparent', $css);
            self::assertStringContainsString('.suggestion-group__label', $css);
            self::assertStringContainsString('.suggestion-item__key', $css);
        }
    }
}
