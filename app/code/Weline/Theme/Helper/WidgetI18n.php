<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;


use Weline\Framework\Env\WelineEnv;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\I18n\Api\Translation\BatchTranslationResolverInterface;

/**
 * 主题部件文案：布局配置里存的是中文源串，渲染时按当前语言解析（含 Weline_Theme 语言包回退）。
 */
final class WidgetI18n
{
    private const REQUEST_MEMO_KEY = 'theme.widget_i18n.memo';

    private const REQUEST_MEMO_LIMIT = 512;

    private const PREFERRED_MODULES = [
        'Weline_Theme',
        'Weline_Websites',
        'Weline_Newsletter',
        'Weline_I18n',
        'Weline_Blog',
        'Weline_Review',
        'Weline_Product',
        'Weline_Search',
        'Weline_Shipping',
        'Weline_Checkout',
        'Weline_B2B',
        'Weline_HelpPay',
        'Weline_Cart',
        'Weline_CustomerService',
        'Weline_Promotion',
        'Weline_Affiliate',
        'Weline_StoreMusic',
        'Weline_Consent',
        'Weline_Compare',
        'Weline_Marketing',
        'Weline_Order',
        'Weline_RecentlyViewed',
        'Weline_Faq',
        'WeShop_Product',
        'WeShop_Catalog',
    ];

    /**
     * Pure URI helper for path-shape checks / writers. Not a storefront locale authority.
     * Keep in sync with default-site + installed storefront packs.
     */
    public const STOREFRONT_PATH_LOCALE_PATTERN = '#/(ar_SA|bg_BG|bn_BD|ca_ES|cs_CZ|da_DK|de_DE|el_GR|en_GB|en_US|es_ES|es_MX|et_EE|fi_FI|fr_CA|fr_FR|ga_IE|hi_IN|hr_HR|hu_HU|id_ID|is_IS|it_IT|ja_JP|ko_KR|lt_LT|lv_LV|mt_MT|nb_NO|nl_NL|pl_PL|pt_BR|pt_PT|ro_RO|ru_RU|sk_SK|sl_SI|sv_SE|th_TH|tr_TR|uk_UA|ur_PK|vi_VN|zh_Hans_CN|zh_Hant_TW|zh_CN)(?:/|$)#';

    public static function localeFromRequestUri(string $requestUri): ?string
    {
        if ($requestUri !== '' && preg_match(self::STOREFRONT_PATH_LOCALE_PATTERN, $requestUri, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    /**
     * Current storefront locale for chrome/Phrase.
     * Sole authority: WelineEnv::getLang() → request override + RequestContext (App init writers).
     */
    public static function storefrontLocale(): string
    {
        return WelineEnv::getLang();
    }

    /** 已知文案集合先批量解析，后续 label() 保持原有输出及参数替换。 */
    public static function prefetchLabels(array $sources): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        $lang = self::storefrontLocale();
        $pending = [];
        $words = [];
        foreach ($sources as $source) {
            $source = trim((string)$source);
            if ($source === '' || self::hasRequestMemo(self::requestMemoKey($source, $lang, []))) {
                continue;
            }
            $pending[$source] = $source;
            $words[$source] = $source;
            if (preg_match('/^[a-z]/', $source) === 1) {
                $words[ucfirst($source)] = ucfirst($source);
            }
        }
        if ($pending === []) {
            return;
        }
        $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
        if (!$resolver instanceof BatchTranslationResolverInterface) {
            return;
        }
        $translations = $resolver->translateMany(array_values($words), $lang, self::PREFERRED_MODULES);
        foreach ($pending as $source) {
            $translated = $translations[$source] ?? $source;
            if ($translated === $source && preg_match('/^[a-z]/', $source) === 1) {
                $alias = ucfirst($source);
                $aliasTranslation = $translations[$alias] ?? $alias;
                if ($aliasTranslation !== '' && $aliasTranslation !== $alias) {
                    $translated = $aliasTranslation;
                }
            }
            if ($translated === '' || \Weline\Framework\View\Helper\EmbeddedPageTitle::isInternalIdentifier($translated)) {
                $translated = \Weline\Framework\View\Helper\EmbeddedPageTitle::isInternalIdentifier($source) ? '' : $source;
            }
            self::setRequestMemo(self::requestMemoKey($source, $lang, []), $translated);
        }
    }

    /**
     * Translate policy/editor HTML whose Chinese source lives as sentence-level CSV keys.
     * Whole-string lookup first; on miss, translate each text node between tags.
     */
    public static function html(string $markup, string $fallback = ''): string
    {
        $html = trim($markup !== '' ? $markup : $fallback);
        if ($html === '') {
            return '';
        }

        $whole = self::label($html);
        if ($whole !== $html || !str_contains($html, '<')) {
            return $whole;
        }

        return (string) preg_replace_callback(
            '/>([^<]*)</u',
            static function (array $match): string {
                $raw = $match[1];
                if ($raw === '' || trim($raw) === '') {
                    return '>' . $raw . '<';
                }
                if (preg_match('/^(\s*)(.*?)(\s*)$/us', $raw, $parts) !== 1) {
                    return '>' . $raw . '<';
                }
                $source = $parts[2];
                if ($source === '' || !preg_match('/\p{Han}/u', $source)) {
                    return '>' . $raw . '<';
                }
                $translated = self::label($source);
                if ($translated === '' || $translated === $source) {
                    return '>' . $raw . '<';
                }

                return '>' . $parts[1] . $translated . $parts[3] . '<';
            },
            $html
        );
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

        $lang = self::storefrontLocale();
        $memoKey = self::requestMemoKey($key, $lang, $args);
        if (self::hasRequestMemo($memoKey)) {
            return self::getRequestMemo($memoKey);
        }

        $baseMemoKey = self::requestMemoKey($key, $lang, []);
        if ($args !== [] && self::hasRequestMemo($baseMemoKey)) {
            $translated = self::getRequestMemo($baseMemoKey);
        } else {

            /** @var TranslationResolverInterface $resolver */
            $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
            $preferredModules = self::PREFERRED_MODULES;
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
}
