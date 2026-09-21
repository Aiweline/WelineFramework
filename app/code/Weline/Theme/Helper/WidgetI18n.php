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
    private const REQUEST_MEMO_KEY = 'theme.widget_i18n.memo';

    private const REQUEST_MEMO_LIMIT = 512;

    /**
     * First-path-segment locales that must win over a lagging RequestContext.
     * Keep in sync with default-site + installed storefront packs.
     */
    public const STOREFRONT_PATH_LOCALE_PATTERN = '#/(ar_SA|bn_BD|de_DE|en_US|es_ES|fr_FR|hi_IN|id_ID|ja_JP|ko_KR|pt_BR|ru_RU|th_TH|ur_PK|vi_VN|zh_Hans_CN|zh_Hant_TW|zh_CN)(?:/|$)#';

    public static function localeFromRequestUri(string $requestUri): ?string
    {
        if ($requestUri !== '' && preg_match(self::STOREFRONT_PATH_LOCALE_PATTERN, $requestUri, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    /**
     * Current storefront locale for chrome/Phrase (path override wins over RequestContext).
     */
    public static function storefrontLocale(): string
    {
        return self::resolveStorefrontLocale();
    }

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
        $memoKey = self::requestMemoKey($key, $lang, $args);
        if (self::hasRequestMemo($memoKey)) {
            return self::getRequestMemo($memoKey);
        }

        /** @var TranslationResolverInterface $resolver */
        $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
        $preferredModules = [
            'Weline_Theme',
            'Weline_I18n',
            'Weline_Blog',
            'Weline_Review',
            'Weline_Product',
            'Weline_Shipping',
            'Weline_Checkout',
            'Weline_B2B',
            'Weline_HelpPay',
            'Weline_Cart',
            'Weline_CustomerService',
            'Weline_Promotion',
            'Weline_Affiliate',
            'Weline_StoreMusic',
            'Weline_RecentlyViewed',
            'Weline_Faq',
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

        // Never surface module codes (Weline_Theme, …) as "translated" chrome copy.
        if ($translated === '' || \Weline\Framework\View\Helper\EmbeddedPageTitle::isInternalIdentifier($translated)) {
            $translated = \Weline\Framework\View\Helper\EmbeddedPageTitle::isInternalIdentifier($key) ? '' : $key;
        }

        self::setRequestMemo($memoKey, $translated);

        return $translated;
    }

    private static function requestMemoKey(string $source, string $locale, array $args): string
    {
        $encodedArgs = $args === []
            ? ''
            : (json_encode(array_values($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize(array_values($args)));

        return $locale . "\0" . $source . "\0" . $encodedArgs;
    }

    private static function hasRequestMemo(string $key): bool
    {
        if (!RequestContext::isInitialized()) {
            return false;
        }

        $memo = RequestContext::get(self::REQUEST_MEMO_KEY, null);

        return is_array($memo) && array_key_exists($key, $memo);
    }

    private static function getRequestMemo(string $key): string
    {
        $memo = RequestContext::get(self::REQUEST_MEMO_KEY, []);

        return (string)($memo[$key] ?? '');
    }

    private static function setRequestMemo(string $key, string $value): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }

        $memo = RequestContext::get(self::REQUEST_MEMO_KEY, []);
        if (!is_array($memo)) {
            $memo = [];
        }
        if (!array_key_exists($key, $memo) && count($memo) >= self::REQUEST_MEMO_LIMIT) {
            array_shift($memo);
        }
        $memo[$key] = $value;
        RequestContext::set(self::REQUEST_MEMO_KEY, $memo);
    }

    private static function resolveStorefrontLocale(): string
    {
        // Fiber-safe static publish sets request language override in Context only
        // (no $_SERVER / WelineEnv mutation). Prefer that before path / globals.
        try {
            $forced = trim((string)State::getRequestLanguageOverride());
            if ($forced !== ''
                && preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $forced)
            ) {
                return $forced;
            }
        } catch (\Throwable) {
        }

        // Path locale wins over RequestContext/KeyBuilder, which can lag on /{locale}/ pages.
        // Never fall back to process $_SERVER under WLS — it is not Fiber-local.
        $requestUri = '';
        try {
            $requestUri = (string) (\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: '');
        } catch (\Throwable) {
        }
        if ($requestUri === '') {
            try {
                $requestUri = (string) (\Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '') ?: '');
            } catch (\Throwable) {
            }
        }
        $pathLocale = self::localeFromRequestUri($requestUri);
        if ($pathLocale !== null) {
            return $pathLocale;
        }

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

        return $lang !== '' ? $lang : 'zh_Hans_CN';
    }
}
