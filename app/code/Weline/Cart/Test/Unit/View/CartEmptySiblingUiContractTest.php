<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Empty-state sibling: secondary twin of「浏览商品」, right-aligned — no callout card.
 */
final class CartEmptySiblingUiContractTest extends TestCase
{
    public function testEmptyActionsRowUsesSecondarySiblingButton(): void
    {
        $root = \dirname(__DIR__, 3);
        $css = (string)\file_get_contents($root . '/view/statics/css/cart-page-amazon.css');
        $page = (string)\file_get_contents($root . '/view/templates/frontend/cart/index.phtml');

        self::assertStringContainsString('weline-cart-shell__empty-actions', $page);
        self::assertStringContainsString('data-cart-siblings', $page);
        self::assertStringContainsString('data-cart-empty-lead', $page);
        self::assertStringContainsString('siblingBrowse', $page);
        self::assertStringContainsString('siblingBrowseLabel', $page);
        self::assertStringContainsString('浏览%1 %2件商品', $page);
        // Any sibling with stock gets a twin button — including gate/non-switchable rows.
        self::assertStringContainsString("btn.setAttribute('data-cart-sibling'", $page);
        self::assertStringNotContainsString('登录后可查看批发车', $page);
        self::assertStringContainsString('emptyLead.hidden = true', $page);
        self::assertStringContainsString('enrichEmptySiblings', $page);
        self::assertStringContainsString('weline-cart-shell__empty-actions', $css);
        self::assertStringContainsString('justify-content: center', $css);
        self::assertStringContainsString('.weline-cart-shell__sibling-cta', $css);
        self::assertStringContainsString('display: inline-flex', $css);
        self::assertStringNotContainsString('display: contents', $css);
        self::assertStringContainsString('function activeCartType', $page);
        self::assertStringContainsString('const mode = activeCartType()', $page);
        self::assertStringNotContainsString('const mode = preferredCartType();\n        try {\n            const cart = await getCartApi();\n            const params = Object.assign(await cartIdentity(mode), { item_id: itemId });', $page);
        // Twin secondary buttons sit side-by-side — not stretched to opposite edges.
        self::assertStringNotContainsString(
            '.weline-cart-shell__empty-actions:has(.weline-cart-shell__siblings:not([hidden]))',
            $css,
        );
        self::assertStringContainsString('var(--cart-secondary-bg)', $css);
        self::assertStringNotContainsString('--color-primary-bg-subtle', $css);
        self::assertStringNotContainsString('border-inline-start', $css);
        self::assertStringNotContainsString("label + '还有 '", $page);
    }

    public function testMiniCartSiblingIsSecondaryButtonWithoutCalloutCard(): void
    {
        $theme = \dirname(__DIR__, 4) . '/Theme';
        $drawer = (string)\file_get_contents($theme . '/view/statics/css/widgets/mini-cart-drawer.css');
        $js = (string)\file_get_contents($theme . '/view/statics/js/widgets/mini-cart-icon.js');

        self::assertStringContainsString(
            "btn.className = 'mini-cart-drawer__btn mini-cart-drawer__btn--secondary'",
            $js,
        );
        self::assertStringContainsString('data-i18n-sibling-browse', $js);
        self::assertStringContainsString('浏览%1 %2件商品', $js);
        self::assertStringNotContainsString("label + '还有 '", $js);
        self::assertStringNotContainsString('--color-primary-bg-subtle', $drawer);
        self::assertStringNotContainsString('border-inline-start', $drawer);

        $phtml = (string)\file_get_contents(
            $theme . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml'
        );
        self::assertStringContainsString('data-i18n-sibling-browse', $phtml);
        self::assertStringContainsString('浏览%1 %2件商品', $phtml);
    }
}
