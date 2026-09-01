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
    public const VERSION = '1.2.2';

    public function setup(Setup $setup, Context $context): void
    {
        $this->seedMiniCartFooterExtrasDefaults();
    }

    private function seedMiniCartFooterExtrasDefaults(): void
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
                'scope' => 'default.default.default',
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

                $seeder->seedDefaultLayout($themeId, 'mini-cart', false);

                foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                    $injectionService->initSlotDefaultInjections(
                        $themeId,
                        'mini-cart',
                        $identity,
                        'footer-extras',
                        PreviewContextService::AREA_FRONTEND,
                        $status,
                    );
                }
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '迷你购物车 footer-extras 默认部件迁移失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }
}
