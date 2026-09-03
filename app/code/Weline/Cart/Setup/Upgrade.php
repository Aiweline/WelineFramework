<?php

declare(strict_types=1);

namespace Weline\Cart\Setup;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\DefaultLayoutSeeder;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

final class Upgrade implements UpgradeInterface
{
    public const VERSION = '1.3.3';

    public function setup(Setup $setup, Context $context): void
    {
        $this->seedLayoutSlotDefaults('mini-cart', ['footer-extras']);
        $this->seedLayoutSlotDefaults('cart', ['cart-summary-discount', 'cart-summary-note']);
    }

    /**
     * @param list<string> $slotIds
     */
    private function seedLayoutSlotDefaults(string $layoutType, array $slotIds): void
    {
        try {
            /** @var WidgetDefaultInjectionService $injectionService */
            $injectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            /** @var DefaultLayoutSeeder $seeder */
            $seeder = ObjectManager::getInstance(DefaultLayoutSeeder::class);
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
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

            foreach ($themes as $themeRow) {
                if (!is_array($themeRow)) {
                    continue;
                }
                $themeId = (int)($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }

                $seeder->seedDefaultLayout($themeId, $layoutType, false);

                foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                    foreach ($slotIds as $slotId) {
                        $injectionService->initSlotDefaultInjections(
                            $themeId,
                            $layoutType,
                            $identity,
                            $slotId,
                            PreviewContextService::AREA_FRONTEND,
                            $status,
                        );
                    }
                }

                /** @var \Weline\Theme\Service\ThemeLayoutService $layoutService */
                $layoutService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class);
                $layoutService->publishLayout(
                    $themeId,
                    $layoutType,
                    $identity,
                    true,
                    [
                        'reason' => 'cart coupon/note slot default injection publish',
                        'actor' => 'system:Weline_Cart:Upgrade',
                    ],
                );
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '购物车布局槽默认部件迁移失败（%{1}）：%{2}',
                [$layoutType, $e->getMessage()]
            ), 0, $e);
        }
    }
}
