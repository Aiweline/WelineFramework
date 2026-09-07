<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * theme:search-select 选中后须恢复可见展示（勿把 display 永久 inline none）。
 */
final class SearchSelectDisplayContractTest extends TestCase
{
    public function testRuntimeClearsInlineDisplayAndUsesFilteringClass(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/SearchSelect.php');

        self::assertStringContainsString('.w-search-select.is-filtering .w-search-select-display{display:none!important}', $src);
        self::assertStringNotContainsString('.w-search-select-input:focus + .w-search-select-display{display:none}', $src);

        self::assertStringContainsString("if (display) { display.style.display = ''; }", $src);
        self::assertStringContainsString("container.classList.toggle('is-filtering', filtering)", $src);
        self::assertStringContainsString("container.classList.remove('is-filtering')", $src);
        self::assertStringContainsString('input.blur();', $src);
        self::assertStringContainsString('if (!filtering) { syncDisplay(); }', $src);
    }
}
