<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

/**
 * 主题部件文案：布局配置里存的是中文源串，渲染时按当前语言解析（含 Weline_Theme 语言包回退）。
 */
final class WidgetI18n
{
    /**
     * Resolve configured/default storefront copy and expand framework-style positional placeholders.
     *
     * @param list<scalar|null> $args
     */
    public static function label(string $configuredTitle, string $defaultSource = '', array $args = []): string
    {
        $key = trim($configuredTitle !== '' ? $configuredTitle : $defaultSource);
        if ($key === '') {
            return '';
        }

        $lang = self::resolveStorefrontLocale();
        /** @var TranslationResolverInterface $resolver */
        $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
        $preferredModules = [
            'Weline_Theme',
            'Weline_I18n',
            'Weline_Blog',
            'Weline_Review',
            'Weline_Product',
            'Weline_Checkout',
            'WeShop_Product',
            'WeShop_Catalog',
        ];
        $translated = $resolver->translate(
            $key,
            $lang,
            $preferredModules,
        );
        if ($translated === $key && preg_match('/^[a-z]/', $key) === 1) {
            $titleCaseAlias = ucfirst($key);
            $aliasTranslation = $resolver->translate($titleCaseAlias, $lang, $preferredModules);
            if ($aliasTranslation !== '' && $aliasTranslation !== $titleCaseAlias) {
                $translated = $aliasTranslation;
            }
        }

        foreach (array_values($args) as $index => $value) {
            $translated = str_replace('%{' . ($index + 1) . '}', (string)$value, $translated);
        }

        return $translated;
    }

    private static function resolveStorefrontLocale(): string
    {
        try {
            $requestLocale = trim((string)(RequestContext::locale() ?? ''));
            if ($requestLocale !== ''
                && preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $requestLocale)
            ) {
                return $requestLocale;
            }
        } catch (\Throwable) {
            // CLI and early bootstrap paths may not have a request context yet.
        }

        $lang = trim(State::getLangLocal());
        $requestUri = (string) (\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        if ($requestUri !== '' && preg_match('#/(ar_SA|en_US|zh_Hans_CN|zh_CN)(?:/|$)#', $requestUri, $matches)) {
            return (string) $matches[1];
        }

        return $lang !== '' ? $lang : 'zh_Hans_CN';
    }
}
