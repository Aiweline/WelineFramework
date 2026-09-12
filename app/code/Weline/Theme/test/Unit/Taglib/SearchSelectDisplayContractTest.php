<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * theme:search-select 选中后须恢复可见展示；表内下拉须走 floating portal 逃出 overflow 裁切。
 */
final class SearchSelectDisplayContractTest extends TestCase
{
    public function testRuntimeClearsInlineDisplayAndUsesFilteringClass(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/SearchSelect.php');

        self::assertStringContainsString('.w-search-select.is-filtering .w-search-select-display{display:none!important}', $src);
        self::assertStringContainsString('z-index:calc(var(--weline-z-menu,1050) + 3)', $src);
        self::assertStringContainsString('.w-search-select.open{z-index:calc(var(--weline-z-menu,1050) + 3)}', $src);
        self::assertStringNotContainsString('.w-search-select-input:focus + .w-search-select-display{display:none}', $src);

        self::assertStringContainsString("if (display) { display.style.display = ''; }", $src);
        self::assertStringContainsString("container.classList.toggle('is-filtering', filtering)", $src);
        self::assertStringContainsString("container.classList.remove('is-filtering')", $src);
        self::assertStringContainsString('input.blur();', $src);
        self::assertStringContainsString('if (!filtering) { syncDisplay(); }', $src);
    }

    public function testDropdownUsesFloatingAttachPortalToEscapeOverflow(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/SearchSelect.php');

        self::assertStringContainsString('data-w-float-surface', $src);
        self::assertStringContainsString('data-w-placement="bottom-start"', $src);
        self::assertStringContainsString('floating.attach(container', $src);
        self::assertStringContainsString("anchor: triggerAnchor", $src);
        self::assertStringContainsString("var triggerAnchor = '#' + id + '_trigger'", $src);
        self::assertStringContainsString('.w-field:has(.w-search-select)', $src);
        self::assertStringContainsString("data-w-gap', '2'", $src);
        self::assertStringNotContainsString("anchor: '.w-search-select-trigger'", $src);
        self::assertStringContainsString('data-w-float-anchor', $src);
        self::assertStringContainsString('height:fit-content', $src);
        self::assertStringContainsString('align-self:flex-start', $src);
        self::assertStringContainsString('.w-search-select-dropdown[data-w-floating-positioned]', $src);
        self::assertStringContainsString('position:fixed', $src);
        self::assertStringContainsString("!container.contains(e.target) && !dropdown.contains(e.target)", $src);
    }

    public function testArrowAndClearAreVerticallyCentered(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/SearchSelect.php');

        self::assertStringContainsString('.w-search-select-arrow{position:absolute;top:50%;right:10px;transform:translateY(-50%)', $src);
        self::assertStringContainsString('.w-search-select.open .w-search-select-arrow{transform:translateY(-50%) rotate(180deg)}', $src);
        self::assertStringContainsString('.w-search-select-clear{position:absolute;top:50%;right:25px;transform:translateY(-50%)', $src);
    }

    public function testFailedSearchSetsEmptyCacheToPreventRequestStorm(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/SearchSelect.php');

        self::assertStringContainsString('searchInFlight', $src);
        self::assertStringContainsString('if (!r.ok)', $src);
        self::assertStringContainsString('cache = [];', $src);
        self::assertStringContainsString("throw new Error('search_http_'", $src);
        self::assertStringContainsString("credentials: 'same-origin'", $src);
        self::assertStringContainsString("else if (liveApiUrl()) { doSearch(''); }", $src);
        self::assertStringContainsString('if (!cache) {', $src);
    }
}
