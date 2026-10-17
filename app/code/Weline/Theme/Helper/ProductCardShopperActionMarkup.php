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

        /** @var IconRegistry $icons */
        $icons = ObjectManager::getInstance(IconRegistry::class);
        $esc = static fn (string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $wishlistClass = 'action-btn btn-wishlist' . ($wishlistPixel ? ' weline-pixel::add_to_wishlist' : '');
        $wishlistTitle = (string)\__('加入收藏');
        $compareTitle = (string)\__('加入对比');
        $quickviewTitle = (string)\__('快速查看');

        $html = '<div class="product-actions product-actions--amz"'
            . ' data-weline-load="compareShopper,storefrontShopperToast">';
        if ($showWishlist) {
            $html .= '<button class="' . $esc($wishlistClass) . '"'
                . ' type="button"'
                . ' title="' . $esc($wishlistTitle) . '"'
                . ' aria-label="' . $esc($wishlistTitle) . '"'
                . ' aria-pressed="false"'
                . ' data-product-id="' . $productId . '">'
                . $icons->render('heart', 'sm')
                . '</button>';
        }
        if ($showCompare) {
            $html .= '<button class="action-btn btn-compare"'
                . ' type="button"'
                . ' title="' . $esc($compareTitle) . '"'
                . ' aria-label="' . $esc($compareTitle) . '"'
                . ' data-product-id="' . $productId . '">'
                . $icons->render('list', 'sm')
                . '</button>';
        }
        if ($showQuickview) {
            $html .= '<button class="action-btn btn-quickview"'
                . ' type="button"'
                . ' title="' . $esc($quickviewTitle) . '"'
                . ' aria-label="' . $esc($quickviewTitle) . '"'
                . ' data-product-id="' . $productId . '">'
                . $icons->render('eye', 'sm')
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
