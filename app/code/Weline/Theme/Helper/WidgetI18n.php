<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

/**
 * 主题部件文案：布局配置里存的是中文源串，渲染时按当前语言解析（含 Weline_Theme 语言包回退）。
 */
final class WidgetI18n
{
    public static function label(string $configuredTitle, string $defaultSource = ''): string
    {
        $key = trim($configuredTitle !== '' ? $configuredTitle : $defaultSource);
        if ($key === '') {
            return '';
        }

        $lang = self::resolveStorefrontLocale();
        /** @var TranslationResolverInterface $resolver */
        $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
        return $resolver->translate(
            $key,
            $lang,
            ['Weline_Theme', 'Weline_I18n', 'WeShop_Product', 'WeShop_Catalog'],
        );
    }

    private static function resolveStorefrontLocale(): string
    {
        $lang = trim(State::getLangLocal());
        $requestUri = (string) (\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        if ($requestUri !== '' && preg_match('#/(en_US|zh_Hans_CN|zh_CN)(?:/|$)#', $requestUri, $matches)) {
            return (string) $matches[1];
        }

        return $lang !== '' ? $lang : 'zh_Hans_CN';
    }
}
