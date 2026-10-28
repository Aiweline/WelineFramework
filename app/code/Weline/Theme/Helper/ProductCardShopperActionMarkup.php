<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\Ui\IconRegistry;

/**
 * Request-hot product-card shopper actions (wishlist / compare / quickview).
 *
 * Prefer this over nested Template::fetch of shopper-actions.phtml inside list loops.
 */
final class ProductCardShopperActionMarkup
{
    /** Page-local sprite shared by card shopper actions and card add-to-cart. */
    public const ICON_SPRITE_PREFIX = 'w-pcard-icon';

    /** @var list<string> */
    public const ICON_SPRITE_NAMES = ['heart', 'list', 'eye', 'cart'];

    /**
     * Emit the shared card icon sprite once per document (also used by the
     * Cart card add-to-cart widget so both consumers hit the same symbols).
     */
    public static function iconSpriteOnce(): string
    {
        return self::icons()->spriteOnce(self::ICON_SPRITE_PREFIX, self::ICON_SPRITE_NAMES);
    }

    /**
     * Card icon via local sprite <use>; falls back to the inline SVG for names
     * outside ICON_SPRITE_NAMES. Keeps class/data-icon/size contracts.
     */
    public static function iconMarkup(string $name, string $size = 'sm'): string
    {
        if (!\in_array($name, self::ICON_SPRITE_NAMES, true)) {
            return self::icons()->render($name, $size);
        }

        return self::icons()->renderUse(self::ICON_SPRITE_PREFIX . '-' . $name, $size, '', '', $name);
    }

    private static function icons(): IconRegistry
    {
        /** @var IconRegistry $icons */
        $icons = ObjectManager::getInstance(IconRegistry::class);

        return $icons;
    }

    /**
     * @param array{show_wishlist?: bool, show_compare?: bool, show_quickview?: bool} $flags
     */
    public static function render(int $productId, array $flags = [], bool $wishlistPixel = false): string
    {
        if ($productId <= 0) {
            return '';
        }

        $showWishlist = (bool)($flags['show_wishlist'] ?? true);
        $showCompare = (bool)($flags['show_compare'] ?? true);
        $showQuickview = (bool)($flags['show_quickview'] ?? true);
        if (!$showWishlist && !$showCompare && !$showQuickview) {
            return '';
        }

        $esc = static fn (string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $wishlistClass = 'action-btn btn-wishlist' . ($wishlistPixel ? ' weline-pixel::add_to_wishlist' : '');
        $wishlistTitle = (string)\__('加入收藏');
        $compareTitle = (string)\__('加入对比');
        $quickviewTitle = (string)\__('快速查看');

        $html = self::iconSpriteOnce()
            . '<div class="product-actions product-actions--amz"'
            . ' data-weline-load="compareShopper,storefrontShopperToast">';
        if ($showWishlist) {
            $html .= '<button class="' . $esc($wishlistClass) . '"'
                . ' type="button"'
                . ' title="' . $esc($wishlistTitle) . '"'
                . ' aria-label="' . $esc($wishlistTitle) . '"'
                . ' aria-pressed="false"'
                . ' data-product-id="' . $productId . '">'
                . self::iconMarkup('heart')
                . '</button>';
        }
        if ($showCompare) {
            $html .= '<button class="action-btn btn-compare"'
                . ' type="button"'
                . ' title="' . $esc($compareTitle) . '"'
                . ' aria-label="' . $esc($compareTitle) . '"'
                . ' data-product-id="' . $productId . '">'
                . self::iconMarkup('list')
                . '</button>';
        }
        if ($showQuickview) {
            $html .= '<button class="action-btn btn-quickview"'
                . ' type="button"'
                . ' title="' . $esc($quickviewTitle) . '"'
                . ' aria-label="' . $esc($quickviewTitle) . '"'
                . ' data-product-id="' . $productId . '">'
                . self::iconMarkup('eye')
                . '</button>';
        }
        $html .= '</div>';

        return $html;
    }

    public static function renderFromTemplate(Template $template): string
    {
        return self::render(
            ProductCardShopperActionParams::resolveProductId($template),
            [
                'show_wishlist' => ProductCardShopperActionParams::resolveFlag(
                    $template,
                    'shopper_show_wishlist',
                    'show_wishlist',
                    true
                ),
                'show_compare' => ProductCardShopperActionParams::resolveFlag(
                    $template,
                    'shopper_show_compare',
                    'show_compare',
                    true
                ),
                'show_quickview' => ProductCardShopperActionParams::resolveFlag(
                    $template,
                    'shopper_show_quickview',
                    'show_quickview',
                    true
                ),
            ],
            ProductCardShopperActionParams::resolveFlag(
                $template,
                'shopper_wishlist_pixel',
                'wishlist_pixel',
                false
            )
        );
    }
}
