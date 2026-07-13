<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Api\Account\AccountI18nModuleProviderInterface;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

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
        $modules = [
            'Weline_Customer',
            'Weline_Order',
            'Weline_Shipping',
        ];
        $baseModules = [
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

        try {
            $providers = ObjectManager::getInstance(ServiceProviderRegistry::class)
                ->implementationsWithPrefix('customer.account_i18n_module.');
            foreach ($providers as $implementation) {
                $provider = ObjectManager::getInstance($implementation);
                if ($provider instanceof AccountI18nModuleProviderInterface) {
                    $modules = \array_merge($modules, $provider->modules());
                }
            }
        } catch (\Throwable) {
        }

        $modules = \array_merge($modules, $baseModules);

        return \array_values(\array_unique(\array_filter(
            \array_map('strval', $modules),
            static fn(string $module): bool => $module !== '',
        )));
    }
}
