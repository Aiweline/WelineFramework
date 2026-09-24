<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderNavFragment;

/**
 * WS2 search-tree fragment gate + structure slim contracts.
 */
final class SearchTypeDropdownFragmentGateContractTest extends TestCase
{
    public function testStableFragmentTemplateOmitsSelectionAndSupportsSlimLabels(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/search/type-dropdown.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('stable_fragment', $src);
        self::assertStringContainsString('data-search-tree-stable', $src);
        self::assertStringContainsString('data-display-label', $src);
        // Slim: only emit data-display-label when different from data-label.
        self::assertStringContainsString('displayLabel !== $label', $src);
        // P11 sprite retained — do not re-split SVG.
        self::assertStringContainsString('localSprite', $src);
        self::assertStringContainsString('renderUse', $src);
        self::assertStringNotContainsString('<select', $src);
    }

    public function testApplySelectionPaintsRequestLocalStateOntoStableHtml(): void
    {
        $stable = <<<'HTML'
<svg class="w-icon-local-sprite"></svg>
<div class="search-type-dropdown" data-search-tree-stable="1">
<input type="hidden" name="type" value="all" class="search-type-input">
<input type="hidden" name="category_id" value="" class="search-category-id-input">
<button type="button" class="search-type-trigger"><span class="search-type-label search-category-label">全部</span></button>
<div class="w-menu" data-w-menu-panel>
<button type="button" class="w-menu__item search-type-option" role="option" data-search-type-option data-value="all" data-label="全部" data-category-id="" aria-selected="false"><span class="search-type-option-label">全部</span></button>
<div class="search-type-node has-children" data-search-type-node data-value="product" data-state="closed">
<button type="button" class="w-menu__item search-type-option--branch" data-search-type-branch data-value="product" data-label="商品" aria-haspopup="true" aria-expanded="false"><span class="search-type-option-label">商品</span></button>
<div class="search-type-submenu search-type-menu" data-search-type-submenu role="group" hidden>
<button type="button" class="w-menu__item search-type-option" role="option" data-search-type-option data-value="product" data-label="全部商品" data-display-label="商品" data-category-id="" aria-selected="false"><span class="search-type-option-label">全部商品</span></button>
<button type="button" class="w-menu__item search-type-option" role="option" data-search-type-option data-value="product" data-label="健身" data-display-label="商品:健身" data-category-id="42" aria-selected="false"><span class="search-type-option-label">健身</span></button>
</div>
</div>
</div>
</div>
HTML;

        $painted = HeaderNavFragment::applySearchTypeDropdownSelection($stable, 'product', 42);

        self::assertStringContainsString('name="type" value="product"', $painted);
        self::assertStringContainsString('name="category_id" value="42"', $painted);
        self::assertStringContainsString('search-type-label search-category-label">商品:健身</span>', $painted);
        self::assertStringContainsString('data-category-id="42"', $painted);
        self::assertStringContainsString('is-active', $painted);
        self::assertStringContainsString('aria-selected="true"', $painted);
        self::assertStringContainsString('data-state="open"', $painted);
        // Stable bag itself must not bake selection — apply happens after.
        self::assertStringContainsString('stable_fragment', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php'
        ));
    }

    public function testHeaderBarUsesFragmentHelperNotInlineRememberWithSelection(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/search/header-bar.phtml'
        );
        self::assertStringContainsString('HeaderNavFragment::fetchSearchTypeDropdown', $src);
        self::assertStringNotContainsString('rememberSearchTypeDropdown(', $src);
    }

    public function testLanguageSwitcherNotTripledInDefaultHeader(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );
        $count = \substr_count($src, 'header-language-switcher');
        // One hook slot (+ class / accept list) — must not restore three full language lists.
        self::assertLessThanOrEqual(3, $count);
        self::assertStringNotContainsString('<w:language', $src);
    }
}
