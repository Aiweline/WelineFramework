<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WishlistPageTemplateContractTest extends TestCase
{
    public function testWishlistPageUsesProductCardShopperActions(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/wishlist/index.phtml';
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/wishlist-account.css';
        self::assertFileExists($templateFile);
        self::assertFileExists($cssFile);
        $source = (string)file_get_contents($templateFile);
        $css = (string)file_get_contents($cssFile);

        self::assertStringContainsString('partials/product/shopper-actions.phtml', $source);
        self::assertStringContainsString("'show_wishlist' => true", $source);
        self::assertStringContainsString("'show_compare' => true", $source);
        self::assertStringContainsString("'show_quickview' => true", $source);
        self::assertStringContainsString('product-card', $source);
        self::assertStringContainsString('product-image-wrapper', $source);
        self::assertStringContainsString('account-card', $source);
        self::assertStringContainsString('account-card__header', $source);
        self::assertStringContainsString('account-card__body', $source);
        self::assertStringContainsString("fetchTagSource", $source);
        self::assertStringContainsString('Weline_Wishlist::css/wishlist-account.css', $source);
        self::assertStringContainsString('<link rel="stylesheet"', $source);
        self::assertStringNotContainsString('<css>', $source);
        self::assertStringContainsString('data-weline-load="wishlist,api"', $source);
        self::assertStringNotContainsString('<script>', $source);
        self::assertStringNotContainsString('<style>', $source);
        self::assertStringNotContainsString('100vw', $source);
        self::assertStringNotContainsString('calc(50% - 50vw)', $source);

        self::assertStringContainsString('.product-actions', $css);
        self::assertStringContainsString('btn-wishlist.is-active', $css);
        self::assertStringContainsString('--color-primary', $css);
        self::assertStringContainsString('--color-border-light', $css);
        self::assertStringNotContainsString('100vw', $css);
        self::assertStringNotContainsString('calc(50% - 50vw)', $css);
    }
}
