<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

/**
 * 个人中心页面会聚合多模块 Hook，需预加载对应 i18n 词典。
 */
final class AccountI18nModuleRegistry
{
    /**
     * @return list<string>
     */
    public static function modules(): array
    {
        return [
            'Weline_Customer',
            'Weline_Order',
            'Weline_Shipping',
            'Weline_TwoFactorAuth',
            'Weline_Frontend',
            'Weline_Theme',
            'Weline_Backend',
            'Weline_I18n',
            'Weline_Currency',
            'Weline_Seo',
            'WeShop_Cart',
            'WeShop_Catalog',
            'WeShop_Order',
            'WeShop_RMA',
            'WeShop_Subscription',
            'WeShop_Notification',
            'WeShop_Auth',
            'WeShop_Customer',
        ];
    }
}
