<?php

declare(strict_types=1);

namespace Weline\HelpPay\Setup;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\DefaultLayoutSeeder;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

/**
 * 1.0.2：把 required 帮我付部件写入购物车/结账空槽并发布（此前仅有 Slot，无注入）。
 * 1.0.3：商品页注入 product-help-pay（quiet「找朋友代付」）。
 */
final class Upgrade implements UpgradeInterface
{
    public const VERSION = '1.0.3';

    public function setup(Setup $setup, Context $context): void
    {
        $this->seedHelpPaySurfaces();
    }

    private function seedHelpPaySurfaces(): void
    {
        try {
            /** @var WidgetDefaultInjectionService $injectionService */
            $injectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            /** @var DefaultLayoutSeeder $seeder */
            $seeder = ObjectManager::getInstance(DefaultLayoutSeeder::class);
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            /** @var ThemeLayoutService $layoutService */
            $layoutService = ObjectManager::getInstance(ThemeLayoutService::class);
            $themes = $themeModel->reset()->select()->fetchArray();
            if (!is_array($themes)) {
                return;
            }

            $identity = [
                'layout_option' => 'default',
                'scope' => 'default.__store__.__channel__',
                'locale_code' => '',
                'target_type' => 'global',
                'target_id' => 0,
            ];

            $jobs = [
                ['cart', 'cart-summary-help-pay'],
                ['checkout', 'checkout-summary-help-pay'],
                ['product', 'product-purchase-actions'],
            ];

            foreach ($themes as $themeRow) {
                if (!is_array($themeRow)) {
                    continue;
                }
                $themeId = (int) ($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }

                foreach ($jobs as [$layoutType, $slotId]) {
                    $seeder->seedDefaultLayout($themeId, $layoutType, false);
                    foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                        $injectionService->initSlotDefaultInjections(
                            $themeId,
                            $layoutType,
                            $identity,
                            $slotId,
                            PreviewContextService::AREA_FRONTEND,
                            $status,
                        );
                    }
                    $layoutService->publishLayout(
                        $themeId,
                        $layoutType,
                        $identity,
                        true,
                        [
                            'reason' => 'helppay cart/checkout/product slot default injection publish',
                            'actor' => 'system:Weline_HelpPay:Upgrade',
                        ],
                    );
                }
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                'HelpPay 购物车/结账/商品帮我付槽默认注入失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }
}
