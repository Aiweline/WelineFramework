<?php
declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\LocaleCatalogScope;
use Weline\I18n\Service\LocaleCatalogScopeResolver;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\SystemConfig\Api\ConfigReader;

class LanguageSwitcher implements TaglibInterface
{
    private const SWITCHER_HTML_CACHE_TTL = 60.0;
    private const SWITCHER_LANGUAGE_CACHE_TTL = 300.0;
    public const TAG_NAME = 'i18n:switcher';
    public const LEGACY_TAG_NAME = 'i18n:language:switcher';

    /**
     * @var array<string, array{expires: float, html: string}>
     */
    private static array $htmlCache = [];

    /**
     * @var array<string, array{expires: float, languages: array}>
     */
    private static array $languageCache = [];

    /**
     * Drop process-local language/html memo so lifecycle changes take effect immediately.
     */
    public static function clearProcessCaches(): void
    {
        self::$htmlCache = [];
        self::$languageCache = [];
    }

    public static function name(): string
    {
        return self::TAG_NAME;
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'for' => false,
            'allowed-values' => false,
            'option-values' => false,
            'options-values' => false,
            'locales' => false,
            'current' => false,
            'value' => false,
            'navigation' => false,
            'website-id' => false,
            'show-request' => false,
            'label-mode' => false,
        ];
    }

    public static function callback(): callable
    {
        // 编译期只输出运行时调用：语言列表会随 Locale 安装态变化，不能把 HTML 烘焙进模板。
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            $attrs = \is_array($attributes) ? $attributes : [];
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

            return '<?php ' . $code
                . ' echo \\Weline\\I18n\\Taglib\\LanguageSwitcher::render(['
                . '\'for\' => (string)($Taglib__for ?? \'\'),'
                . '\'allowed_values\' => $Taglib__allowed_values ?? ($Taglib__option_values ?? ($Taglib__options_values ?? ($Taglib__locales ?? null))),'
                . '\'current\' => (string)($Taglib__current ?? ($Taglib__value ?? \'\')),'
                . '\'navigation\' => (string)($Taglib__navigation ?? \'path\'),'
                . '\'website_id\' => $Taglib__website_id ?? null,'
                . '\'show_request\' => $Taglib__show_request ?? null,'
                . '\'label_mode\' => (string)($Taglib__label_mode ?? \'short\'),'
                . ']); ?>';
        };
    }

    /**
     * Runtime render entry used by compiled templates.
     *
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
            $websiteId = 0;
            $request = null;
            try {
                /** @var Request $request */
                $request = ObjectManager::getInstance(Request::class);
                $websiteId = (int)($request->getData('website_id') ?? 0);
            } catch (\Throwable) {
                $websiteId = 0;
            }
            try {
                $contextWebsiteId = \Weline\Framework\Runtime\RequestContext::getWelineWebsiteId();
                if ($contextWebsiteId !== null) {
                    $websiteId = (int)$contextWebsiteId;
                }
            } catch (\Throwable) {
            }
            // Worker chrome 预热时可能尚无 HTTP Request 后台态，但 ThemeData area 已是 backend。
            $isBackendArea = self::resolveIsBackendArea($request);
            $allowedValues = self::normalizeAllowedValues($attributes['allowed_values'] ?? null);
            $navigation = \strtolower(\trim((string)($attributes['navigation'] ?? 'path')));
            if (!\in_array($navigation, ['path', 'emit'], true)) {
                $navigation = 'path';
            }
            $currentOverride = \trim((string)($attributes['current'] ?? ''));
            $websiteIdAttr = self::normalizeOptionalInt($attributes['website_id'] ?? null);
            $showRequestOverride = self::normalizeOptionalBool($attributes['show_request'] ?? null);
            $labelMode = \strtolower(\trim((string)($attributes['label_mode'] ?? 'short')));
            if (!\in_array($labelMode, ['short', 'display'], true)) {
                $labelMode = 'short';
            }

            /** @var LocaleCatalogScopeResolver $scopeResolver */
            $scopeResolver = ObjectManager::getInstance(LocaleCatalogScopeResolver::class);
            $scope = $scopeResolver->resolve(
                $isBackendArea,
                $websiteId,
                $allowedValues,
                $currentOverride !== '' ? $currentOverride : null,
                $showRequestOverride,
                $websiteIdAttr,
            );
            $websiteId = $scope->websiteId;
            $showLanguageRequest = $scope->allowRequest;
            $currentCode = $scope->currentCode;
            $displayLocale = $scope->displayLocale;
            $welineLanguages = self::buildLanguagesFromScope($scope, $displayLocale);
            $languageGroups = self::groupLanguagesByCountry($welineLanguages, $displayLocale, $currentCode);

            $firstCode = (string)(array_key_first($welineLanguages) ?? $scope->defaultCode);
            $firstData = (array)($welineLanguages[$firstCode] ?? []);
            $welineCurrentLanguage = [
                'code' => $firstCode,
                'name' => (string)($firstData['name'] ?? '中文'),
                'tag_label' => (string)($firstData['tag_label'] ?? ($firstData['name'] ?? '中文')),
                'display_name' => (string)($firstData['display_name'] ?? ($firstData['name'] ?? '中文')),
                'flag' => (string)($firstData['flag'] ?? ''),
            ];
            if (isset($welineLanguages[$currentCode])) {
                $welineCurrentLanguage = $welineLanguages[$currentCode];
                $welineCurrentLanguage['code'] = $currentCode;
            } elseif ($currentOverride !== '' && $scope->mode === LocaleCatalogScope::MODE_INJECTED) {
                $injected = self::buildLanguagesFromCodes([$currentOverride], $displayLocale);
                if (isset($injected[$currentOverride])) {
                    $welineCurrentLanguage = $injected[$currentOverride];
                    $welineCurrentLanguage['code'] = $currentOverride;
                }
            }

            $currentCode = (string)($welineCurrentLanguage['code'] ?? '');
            $currentLabelRaw = $labelMode === 'display'
                ? (string)($welineCurrentLanguage['display_name'] ?? ($welineCurrentLanguage['name'] ?? ''))
                : (string)($welineCurrentLanguage['tag_label'] ?? ($welineCurrentLanguage['name'] ?? ''));
            $currentName = htmlspecialchars($currentLabelRaw, ENT_QUOTES, 'UTF-8');
            $currentFlag = self::sanitizeInlineFlagMarkup((string)($welineCurrentLanguage['flag'] ?? ''));
            $renderFor = strtolower(trim((string)($attributes['for'] ?? '')));
            $switcherScopeId = $scope->mode . ':' . ($isBackendArea ? 'backend' : (string)$websiteId);
            $switcherId = 'weline-i18n-switcher-' . substr(md5($switcherScopeId . '|' . $currentCode . '|' . json_encode(array_keys($welineLanguages))), 0, 12);
            $parts = explode('_', $currentCode);
            $shortCode = strtoupper(substr($currentCode, 0, 2));
            if (count($parts) >= 2) {
                $lang = strtoupper($parts[0]);
                $region = strtoupper($parts[1]);
                if ($lang === 'ZH') {
                    $shortCode = $region === 'HANT' ? 'TW' : 'ZH';
                } else {
                    $shortCode = substr($lang, 0, 2);
                }
            }
            $currentDisplay = htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8');

            $toggleId = $switcherId . '-toggle';
            $panelId = $switcherId . '-panel';
            $currentPath = '/';
            $currentSearch = '';
            $backendRoute = '';
            if ($request instanceof Request) {
                // After URL rewrite, getUrlPath() is the internal controller path
                // (e.g. /pagebuilder/frontend/page/view). Language hrefs must keep
                // the visitor-facing rewrite path (/about), or locale switches land
                // on internal routes and break ScopeIdentity / look blank.
                //
                // Path resolution must NOT trust ThemeData area: worker chrome
                // warmup can leave area=backend on live frontend requests, which
                // would incorrectly keep the internal controller path.
                $requestIsBackend = false;
                try {
                    $requestIsBackend = (bool)$request->isBackend()
                        || (\method_exists($request, 'isApiBackend') && (bool)$request->isApiBackend());
                } catch (\Throwable) {
                    $requestIsBackend = false;
                }
                if ($requestIsBackend) {
                    $currentPath = (string)($request->getUrlPath() ?: '/');
                    $currentSearch = self::resolveCurrentSearch($request);
                } else {
                    $publicRoute = self::resolvePublicFrontendRoute($request);
                    $currentPath = $publicRoute['path'];
                    $currentSearch = $publicRoute['search'];
                }
                $backendRoute = trim((string)($request->getServer('WELINE_AREA_ROUTE') ?? ''), '/');
            }
            if ($isBackendArea) {
                $backendRoute = trim((string)(Env::getAreaRoutePrefix('backend') ?? $backendRoute), '/');
            }
            $currentCurrency = State::getCurrency();
            if (!State::isAllowedCurrencyCode($currentCurrency)) {
                $currentCurrency = self::defaultCurrency();
            }
            $backendRouteJson = json_encode($backendRoute, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            if (!is_string($backendRouteJson)) {
                $backendRouteJson = '""';
            }
            $websiteMount = self::resolveWebsiteMountPath($request instanceof Request ? $request : null);
            $websiteMountJson = json_encode($websiteMount, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            if (!is_string($websiteMountJson)) {
                $websiteMountJson = '""';
            }
            // Website mount is a fixed base ([website]/[currency?]/[lang?]/[path]),
            // never a splittable route segment mixed with locale/page parts.
            $defaultAwareBuildLangHrefFallbackJs =
                'buildLangHrefFallback=function(lang){'
                . 'var pathname=window.location.pathname||"/";var search=window.location.search||"";'
                . 'var mount=String(' . $websiteMountJson . '||"").replace(/^\\/+|\\/+$/g,"");'
                . 'if(mount){var mountPrefix="/"+mount;var lower=String(pathname).toLowerCase();var mp=mountPrefix.toLowerCase();'
                . 'if(lower===mp||lower.indexOf(mp+"/")===0){pathname=pathname.slice(mountPrefix.length)||"/";}}'
                . 'var pathParts=String(pathname||"/").split("/").filter(Boolean);'
                . 'var currencyPattern=/^[A-Z]{3}$/;var langPattern=/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;'
                . 'var cfg=window.__WelineThemeConfig||{};'
                . 'function addCurrency(codes,value){if(value&&typeof value==="object"){value=value.code||value.currency||value.currency_code||value.value||"";}var code=String(value||"").trim().toUpperCase();if(currencyPattern.test(code)){codes[code]=true;}}'
                . 'function collectCurrency(codes,source){if(!source){return;}if(Array.isArray(source)){source.forEach(function(item){addCurrency(codes,item);});return;}if(typeof source==="object"){Object.keys(source).forEach(function(key){addCurrency(codes,key);addCurrency(codes,source[key]);});return;}String(source).split(/[,\\s|]+/).forEach(function(code){addCurrency(codes,code);});}'
                . 'function supportedCurrencies(){var site=window.site||{};var codes={};[cfg.availableCurrencies,cfg.supportedCurrencies,cfg.currencyCodes,cfg.currencies,cfg.site&&cfg.site.availableCurrencies,cfg.site&&cfg.site.supportedCurrencies,cfg.site&&cfg.site.currencyCodes,cfg.site&&cfg.site.currencies,site.availableCurrencies,site.supportedCurrencies,site.currencyCodes,site.currencies].forEach(function(source){collectCurrency(codes,source);});addCurrency(codes,cfg.defaultCurrency||(cfg.site&&(cfg.site.defaultCurrency||cfg.site.default_currency))||site.defaultCurrency||site.default_currency);return codes;}'
                . 'function isCurrency(value){var code=String(value||"").trim().toUpperCase();return currencyPattern.test(code)&&!!supportedCurrencies()[code];}'
                . 'var defaultCurrency=String(cfg.defaultCurrency||"CNY").toUpperCase();'
                . 'var defaultLang=String(cfg.defaultLang||cfg.defaultLanguage||(cfg.i18n&&(cfg.i18n.defaultLang||cfg.i18n.defaultLanguage))||"zh_Hans_CN").replace(/-/g,"_").toLowerCase();'
                . 'var backendKey=String(' . $backendRouteJson . '||(window.site&&window.site.area)||(window.Weline&&window.Weline.config&&window.Weline.config.url&&window.Weline.config.url.adminArea)||"");'
                . 'var currency="";for(var i=0;i<pathParts.length;i++){if(isCurrency(pathParts[i])){currency=pathParts[i].toUpperCase();break;}}'
                . 'if(!currency&&isCurrency(cfg.currentCurrency)){currency=String(cfg.currentCurrency).toUpperCase();}'
                . 'var prefixIndex=-1;if(backendKey){prefixIndex=pathParts.findIndex(function(part){return !langPattern.test(part)&&!isCurrency(part)&&String(part).toLowerCase()===backendKey.toLowerCase();});}'
                . 'var prefixSegment=prefixIndex>=0?pathParts[prefixIndex]:backendKey;var remain=[];'
                . 'pathParts.forEach(function(part,index){if(langPattern.test(part)||isCurrency(part)){return;}if(index===prefixIndex){return;}remain.push(part);});'
                . 'var out=[];if(prefixSegment){out.push(prefixSegment);}'
                . 'if(currency&&currency!==defaultCurrency){out.push(currency);}'
                . 'var normalizedLang=String(lang||"").replace(/-/g,"_");if(normalizedLang&&normalizedLang.toLowerCase()!==defaultLang){out.push(normalizedLang);}'
                . 'if(remain.length){out.push.apply(out,remain);}'
                . 'var relativePath=out.length?("/"+out.join("/")):"/";'
                . 'if(prefixSegment||!mount){return relativePath+(search||"");}'
                . 'return (relativePath==="/"?("/"+mount+"/"):("/"+mount+relativePath))+(search||"");'
                . '};';
            $htmlCacheKey = self::buildHtmlCacheKey(
                $isBackendArea,
                $websiteId,
                $renderFor,
                $currentCode,
                $currentCurrency,
                $currentPath,
                $currentSearch,
                $backendRoute,
                \array_keys($welineLanguages)
            ) . '|language_request=' . ($showLanguageRequest ? '1' : '0')
                . '|navigation=' . $navigation
                . '|markup=v2-lang-native-path-nav-9'
                . '|mount=' . $websiteMount;
            $now = \microtime(true);
            if (isset(self::$htmlCache[$htmlCacheKey]) && self::$htmlCache[$htmlCacheKey]['expires'] >= $now) {
                return self::$htmlCache[$htmlCacheKey]['html'];
            }
            unset(self::$htmlCache[$htmlCacheKey]);

            $i18nRuntimeFile = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'I18n' . DS
                . 'view' . DS . 'statics' . DS . 'js' . DS . 'i18n.js';
            $i18nRuntimeVersion = \is_file($i18nRuntimeFile)
                ? (string)(\filemtime($i18nRuntimeFile) ?: 0)
                : '0';
            $html = [];
            $html[] = '<div class="dropdown d-inline-block" data-i18n-switcher data-i18n-switcher-id="'
                . $switcherId
                . '" data-i18n-navigation="'
                . htmlspecialchars($navigation, ENT_QUOTES, 'UTF-8')
                . '" data-website-mount="'
                . htmlspecialchars($websiteMount, ENT_QUOTES, 'UTF-8')
                . '">';
            $html[] = '    <button type="button" id="' . htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') . '" class="btn header-item waves-effect weline-i18n-switcher-toggle position-relative" style="z-index:1056" aria-haspopup="true" aria-expanded="false" aria-controls="' . htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') . '">';
            if ($renderFor === 'js') {
                $html[] = '        ' . $currentFlag . '<span class="align-middle current-language"> ' . $currentDisplay . '</span>';
            } else {
                $html[] = '        ' . $currentFlag . '<span class="align-middle"> ' . $currentName . '</span>';
            }
            $html[] = '    </button>';
            $html[] = '    <div id="' . htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') . '" class="dropdown-menu dropdown-menu-end languages weline-i18n-switcher-panel" role="menu" aria-labelledby="' . htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') . '" hidden style="display:none !important">';
            $html[] = '        <div class="weline-language-search-wrap px-2 pt-2 pb-1">';
            $html[] = '            <input type="text" class="form-control weline-language-search" placeholder="' . htmlspecialchars(__('搜索国家、语言或代码...'), ENT_QUOTES, 'UTF-8') . '" autocomplete="off">';
            $html[] = '        </div>';

            $html[] = '        <div class="weline-language-groups" data-language-scroll>';
            foreach ($languageGroups as $groupIndex => $languageGroup) {
                $countryNameRaw = (string)($languageGroup['country_name'] ?? __('未分组国家'));
                $countryCodeRaw = (string)($languageGroup['country_code'] ?? '');
                $countryName = htmlspecialchars($countryNameRaw, ENT_QUOTES, 'UTF-8');
                $countryCode = htmlspecialchars($countryCodeRaw, ENT_QUOTES, 'UTF-8');
                $groupId = $switcherId . '-group-' . (int)$groupIndex;
                $html[] = '            <div class="weline-language-group" data-language-group role="group" aria-labelledby="' . htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') . '">';
                $html[] = '                <div id="' . htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') . '" class="weline-language-group-label"><span>' . $countryName . '</span><small>' . $countryCode . '</small></div>';
                foreach ((array)($languageGroup['languages'] ?? []) as $code => $language) {
                    $code = (string)$code;
                    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                    $flag = self::sanitizeInlineFlagMarkup((string)($language['flag'] ?? ''));
                    $nameRaw = (string)($language['display_name'] ?? ($language['name'] ?? $code));
                    $name = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
                    $active = $currentCode === $code ? ' active' : '';
                    $selfName = \trim((string)($language['self_name'] ?? ''));
                    $referenceName = \trim((string)($language['reference_name'] ?? ($language['english_name'] ?? '')));
                    $metaParts = [$code];
                    // Prefer native endonym in the subtitle (中文…), fall back to English only if missing.
                    $metaSecondary = $selfName !== '' ? $selfName : $referenceName;
                    if ($metaSecondary !== '' && $metaSecondary !== $nameRaw && !\in_array($metaSecondary, $metaParts, true)) {
                        $metaParts[] = $metaSecondary;
                    }
                    if ($countryNameRaw !== '' && $countryNameRaw !== $nameRaw && !\in_array($countryNameRaw, $metaParts, true)) {
                        $metaParts[] = $countryNameRaw;
                    }
                    $meta = htmlspecialchars(\implode(' | ', $metaParts), ENT_QUOTES, 'UTF-8');
                    $searchParts = [
                        $code,
                        $nameRaw,
                        (string)($language['name'] ?? ''),
                        (string)($language['self_name'] ?? ''),
                        $referenceName,
                        $countryNameRaw,
                        $countryCodeRaw,
                        (string)($language['search'] ?? ''),
                    ];
                    $searchText = htmlspecialchars(\mb_strtolower(\implode(' ', $searchParts), 'UTF-8'), ENT_QUOTES, 'UTF-8');
                    $href = htmlspecialchars(self::buildLanguageHref($currentPath, $currentSearch, $code, $currentCurrency, $backendRoute), ENT_QUOTES, 'UTF-8');
                    $optionRole = ' role="menuitemradio" aria-checked="' . ($active !== '' ? 'true' : 'false') . '"';
                    if ($renderFor === 'js') {
                        $html[] = '                <a href="' . $href . '" data-i18n-authoritative-href="1" data-language-option="1" data-lang="' . $safeCode . '" data-search="' . $searchText . '" class="dropdown-item notify-item language-option weline-language-option' . $active . '"' . $optionRole . '>';
                    } else {
                        $html[] = '                <a href="' . $href . '" data-i18n-authoritative-href="1" data-language-option="1" data-lang="' . $safeCode . '" data-search="' . $searchText . '" class="dropdown-item notify-item weline-language-option' . $active . '"' . $optionRole . '>';
                    }
                    $html[] = '                    <span class="weline-language-option-flag">' . $flag . '</span><span class="weline-language-option-copy"><strong>' . $name . '</strong><small>' . $meta . '</small></span>';
                    $html[] = '                </a>';
                }
                $html[] = '            </div>';
            }
            $html[] = '        </div>';
            if ($showLanguageRequest) {
                $html[] = '        <div class="weline-language-request-entry">';
                $html[] = '            <button type="button" class="weline-language-request-open" data-language-request-open aria-haspopup="dialog" aria-controls="' . htmlspecialchars($switcherId . '-request-dialog', ENT_QUOTES, 'UTF-8') . '">';
                $html[] = '                <i class="mdi mdi-translate"></i><span>' . htmlspecialchars(__('申请支持其他语言'), ENT_QUOTES, 'UTF-8') . '</span>';
                $html[] = '            </button>';
                $html[] = '        </div>';
            }

            $html[] = '    </div>';
            if ($showLanguageRequest) {
                $html[] = '    <div class="weline-language-request-modal" data-language-request-modal hidden>';
                $html[] = '        <div class="weline-language-request-backdrop" data-language-request-close></div>';
                $html[] = '        <div id="' . htmlspecialchars($switcherId . '-request-dialog', ENT_QUOTES, 'UTF-8') . '" class="weline-language-request-dialog" role="dialog" aria-modal="true" aria-labelledby="' . htmlspecialchars($switcherId . '-request-title', ENT_QUOTES, 'UTF-8') . '" tabindex="-1">';
                $html[] = '            <div class="weline-language-request-header"><h2 id="' . htmlspecialchars($switcherId . '-request-title', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(__('申请支持其他语言'), ENT_QUOTES, 'UTF-8') . '</h2><button type="button" class="weline-language-request-close" data-language-request-close aria-label="' . htmlspecialchars(__('关闭'), ENT_QUOTES, 'UTF-8') . '"><i class="mdi mdi-close"></i></button></div>';
                $html[] = '            <div class="weline-language-request-body" data-language-request-body><div class="weline-language-request-loading" role="status">' . htmlspecialchars(__('正在加载语言目录与人机验证...'), ENT_QUOTES, 'UTF-8') . '</div></div>';
                $html[] = '        </div>';
                $html[] = '    </div>';
            }
            $html[] = '</div>';
            $html[] = '<style>';
            // Closed by default even when Bootstrap `.dropdown-menu{display:none}` is absent.
            // z-index must beat .main-content paint order; panel is portaled to <body> while open
            // so #page-topbar overflow/backdrop-filter cannot clip or trap it.
            // Panel: flex column; only .weline-language-groups scrolls. Search + request entry stay pinned.
            $html[] = '.weline-i18n-switcher-panel{display:none;position:absolute;z-index:10055;min-width:min(320px,calc(100vw - 16px));max-width:min(420px,calc(100vw - 16px));max-height:min(70vh,420px);padding:0.5rem;background:var(--backend-color-card-bg,var(--backend-color-bg-primary,#fff));border:1px solid var(--backend-color-card-border,var(--backend-color-border-light,#e9ecef));border-radius:var(--backend-border-radius-xl,1rem);box-shadow:var(--backend-dropdown-shadow,0 10px 30px rgba(0,0,0,.12));overflow:hidden;flex-direction:column;}';
            $html[] = '.weline-i18n-switcher-panel.show,body>.weline-i18n-switcher-panel.show{display:flex !important;position:fixed;top:auto !important;right:auto !important;left:auto !important;bottom:auto !important;z-index:10055;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search-wrap{flex:0 0 auto;position:sticky;top:0;z-index:3;background:var(--backend-color-card-bg,var(--backend-color-bg-primary,#fff));}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search{min-height:var(--backend-form-control-height,44px);height:var(--backend-form-control-height,44px);padding:0.5rem 0.85rem;line-height:var(--backend-line-height-base,1.5);border:1px solid var(--backend-color-input-border,var(--backend-color-border,#ced4da));border-radius:var(--backend-border-radius-md,0.5rem);background:var(--backend-color-input-bg,var(--backend-color-bg-primary,#fff));color:var(--backend-color-text-primary,#212529);font-size:var(--backend-font-size-base,.875rem);box-shadow:none;transition:border-color .18s ease,box-shadow .18s ease,background-color .18s ease;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search::placeholder{color:var(--backend-color-text-placeholder,var(--backend-color-text-tertiary,#adb5bd));opacity:1;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search:focus-visible{border-color:var(--backend-color-input-focus-border,var(--backend-color-primary,#556ee6));background:var(--backend-color-bg-primary,#fff);color:var(--backend-color-text-primary,#212529);box-shadow:var(--backend-focus-ring,0 0 0 0.2rem rgba(85,110,230,.25));outline:none;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-groups{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:0 0.25rem 0.25rem;scrollbar-width:thin;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-group{margin-top:0.25rem;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-group-label{display:flex;align-items:center;justify-content:space-between;gap:0.5rem;padding:0.45rem 0.75rem;background:var(--backend-color-bg-secondary,#f8fafc);color:var(--backend-color-text-secondary,#64748b);font-size:0.75rem;font-weight:600;text-transform:uppercase;border-bottom:1px solid var(--backend-color-border-light,#edf2f7);}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-group-label small{font-size:0.7rem;font-weight:500;letter-spacing:.04em;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option{display:flex;align-items:flex-start;gap:0.65rem;padding:0.6rem 0.75rem;border-radius:var(--backend-border-radius-md,0.5rem);color:var(--backend-color-text-secondary,#495057);line-height:var(--backend-line-height-base,1.5);transition:background-color .18s ease,color .18s ease;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option:hover,.weline-i18n-switcher-panel .weline-language-option.active,.weline-i18n-switcher-panel .weline-language-option:focus-visible{background:var(--backend-color-bg-secondary,#f8f9fa);color:var(--backend-color-text-primary,#212529);outline:none;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option-flag{display:inline-flex;align-items:center;flex:0 0 auto;min-width:1.5rem;padding-top:.1rem;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option-flag svg{display:block;width:1.5rem;height:auto;max-height:1.1rem;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:.05rem;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option-copy strong{font-size:.875rem;font-weight:600;color:inherit;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option-copy small{font-size:.72rem;color:var(--backend-color-text-secondary,#64748b);white-space:normal;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-request-entry{flex:0 0 auto;position:sticky;bottom:0;z-index:4;margin:0 -.15rem -.15rem;padding:.55rem .4rem .35rem;border-top:1px solid #e2e8f0;background:var(--backend-color-card-bg,var(--backend-color-bg-primary,#fff));box-shadow:0 -6px 12px rgba(15,23,42,.06)}.weline-language-request-open{display:flex;width:100%;align-items:center;justify-content:center;gap:.45rem;min-height:42px;border:1px solid #2563eb;border-radius:.5rem;background:#fff;color:#1d4ed8;font-weight:600;cursor:pointer;transition:background-color .18s ease,color .18s ease}.weline-language-request-open:hover,.weline-language-request-open:focus-visible{background:#eff6ff;outline:3px solid rgba(37,99,235,.2)}';
            $html[] = '.weline-language-request-modal[hidden]{display:none}.weline-language-request-modal{position:fixed;inset:0;z-index:10050;display:grid;place-items:center;padding:1rem}.weline-language-request-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.62)}.weline-language-request-dialog{position:relative;width:min(760px,100%);max-height:min(820px,calc(100vh - 2rem));overflow:auto;border-radius:.75rem;background:#f8fafc;border:1px solid #cbd5e1;color:#1e293b}.weline-language-request-header{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc}.weline-language-request-header h2{margin:0;font-size:1.15rem}.weline-language-request-close{display:grid;place-items:center;width:40px;height:40px;border:0;border-radius:.5rem;background:transparent;color:#334155;cursor:pointer}.weline-language-request-close:hover,.weline-language-request-close:focus-visible{background:#e2e8f0;outline:3px solid rgba(37,99,235,.2)}.weline-language-request-body{padding:1.25rem}.weline-language-request-loading{padding:2rem;text-align:center;color:#475569}.weline-language-request-retry{display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:1.5rem;text-align:center}.weline-language-request-retry button{min-height:42px;border:1px solid #2563eb;border-radius:.5rem;background:#2563eb;color:#fff;padding:.55rem 1rem;cursor:pointer}@media(max-width:575px){.weline-language-request-modal{padding:0}.weline-language-request-dialog{width:100%;height:100%;max-height:none;border-radius:0}.weline-language-request-body{padding:1rem}}@media(prefers-reduced-motion:reduce){.weline-language-request-open{transition:none}}';
            $html[] = '</style>';
            $html[] = '<script src="/Weline/I18n/view/statics/js/i18n.js?v='
                . htmlspecialchars($i18nRuntimeVersion, ENT_QUOTES, 'UTF-8')
                . '" data-weline-i18n-runtime="1"></script>';
            $html[] = '<script>(function(){';
            $html[] = 'var currentScript=document.currentScript;var root=null;';
            $html[] = 'if(currentScript){var node=currentScript.previousElementSibling;while(node&&!node.hasAttribute("data-i18n-switcher-id")){node=node.previousElementSibling;}root=node;}';
            $html[] = 'if(!root||root.dataset.welineI18nNative==="1"){return;}root.dataset.welineI18nNative="1";';
            $html[] = 'var toggle=root.querySelector(".weline-i18n-switcher-toggle");';
            $html[] = 'var panel=root.querySelector(".weline-i18n-switcher-panel");';
            $html[] = 'var input=root.querySelector(".weline-language-search");';
            $html[] = 'var requestOpen=root.querySelector("[data-language-request-open]");var requestModal=root.querySelector("[data-language-request-modal]");var requestBody=root.querySelector("[data-language-request-body]");var requestLoaded=false;var requestLoading=false;var requestReturnFocus=null;var requestDraft=null;';
            $html[] = 'if(!toggle||!panel){return;}';
            $html[] = 'var options=function(){return root.querySelectorAll(".weline-language-option");};';
            $html[] = 'var groups=function(){return root.querySelectorAll("[data-language-group]");};';
            $html[] = 'var emptyId="weline-language-empty-' . $switcherId . '";';
            $html[] = 'function ensureEmpty(){var empty=root.querySelector("#"+emptyId);if(!empty){empty=document.createElement("div");empty.id=emptyId;empty.className="dropdown-item text-muted small";empty.style.display="none";empty.textContent="' . addslashes(__('无匹配语言')) . '";panel.appendChild(empty);}return empty;}';
            $html[] = 'function filter(){if(!input){return;}var kw=String(input.value||"").trim().toLowerCase();var visible=0;groups().forEach(function(group){var groupVisible=0;group.querySelectorAll(".weline-language-option").forEach(function(opt){var search=(opt.getAttribute("data-search")||"").toLowerCase();var ok=(kw==="")||search.indexOf(kw)!==-1;opt.style.display=ok?"":"none";opt.setAttribute("aria-hidden",ok?"false":"true");if(ok){groupVisible++;}});group.style.display=groupVisible>0?"":"none";visible+=groupVisible;});var empty=ensureEmpty();empty.style.display=visible===0?"":"none";}';
            $html[] = 'var panelHome=panel.parentNode;var panelNext=panel.nextSibling;';
            $html[] = 'function mountPanelToBody(){if(!document.body){return;}if(panel.parentNode!==document.body){document.body.appendChild(panel);}panel.style.zIndex="10055";}';
            $html[] = 'function restorePanelHome(){if(!panelHome){return;}if(panel.parentNode===panelHome){return;}if(panelNext&&panelNext.parentNode===panelHome){panelHome.insertBefore(panel,panelNext);}else{panelHome.appendChild(panel);}}';
            $html[] = 'function clearPanelPlacement(){panel.style.position="";panel.style.removeProperty("top");panel.style.removeProperty("left");panel.style.removeProperty("right");panel.style.removeProperty("bottom");panel.style.maxHeight="";panel.style.width="";panel.style.visibility="";panel.style.zIndex="";panel.style.setProperty("display","none","important");restorePanelHome();}';
            $html[] = 'function placePanelAgainstToggle(){var margin=8,gap=8;var tr=toggle.getBoundingClientRect();mountPanelToBody();panel.style.position="fixed";panel.style.setProperty("right","auto","important");panel.style.setProperty("bottom","auto","important");panel.style.setProperty("left","0px","important");panel.style.setProperty("top","0px","important");panel.style.visibility="hidden";panel.style.maxHeight="";panel.style.setProperty("display","flex","important");panel.style.zIndex="10055";var pw=Math.max(panel.offsetWidth||0,Math.min(320,Math.max(0,(window.innerWidth||0)-16)));var ph=panel.offsetHeight||0;var vw=window.innerWidth||document.documentElement.clientWidth||pw;var vh=window.innerHeight||document.documentElement.clientHeight||ph;if(pw>vw-margin*2){pw=Math.max(160,vw-margin*2);panel.style.width=pw+"px";pw=panel.offsetWidth||pw;ph=panel.offsetHeight||ph;}var left=tr.right-pw;if(left<margin){left=margin;}if(left+pw>vw-margin){left=Math.max(margin,vw-margin-pw);}var top=tr.bottom+gap;if(top+ph>vh-margin){var above=tr.top-gap-ph;if(above>=margin){top=above;}else{top=Math.max(margin,Math.min(top,vh-margin-Math.min(ph,vh-margin*2)));panel.style.maxHeight=Math.max(120,vh-top-margin)+"px";ph=panel.offsetHeight||ph;if(top+ph>vh-margin){top=Math.max(margin,vh-margin-ph);}}}panel.style.setProperty("left",Math.round(left)+"px","important");panel.style.setProperty("top",Math.round(top)+"px","important");panel.style.visibility="";}';
            $html[] = 'function openMenu(){panel.hidden=false;panel.classList.add("show");toggle.setAttribute("aria-expanded","true");panel.style.setProperty("display","flex","important");placePanelAgainstToggle();if(input){input.value="";filter();setTimeout(function(){try{input.focus();}catch(e){}},0);requestAnimationFrame(function(){placePanelAgainstToggle();});}}';
            $html[] = 'function closeMenu(reason){panel.classList.remove("show");panel.hidden=true;toggle.setAttribute("aria-expanded","false");clearPanelPlacement();if(input){input.value="";filter();}}';
            $html[] = 'function isOpen(){return panel.classList.contains("show");}';
            $html[] = 'clearPanelPlacement();';
            $html[] = 'window.addEventListener("resize",function(){if(isOpen()){placePanelAgainstToggle();}});';
            $html[] = 'window.addEventListener("scroll",function(){if(isOpen()){placePanelAgainstToggle();}},true);';
            $html[] = 'toggle.addEventListener("click",function(e){e.stopPropagation();if(isOpen()){closeMenu("toggle-click");}else{openMenu();}});';
            $html[] = 'function stopInside(e){e.stopPropagation();}';
            $html[] = 'panel.addEventListener("pointerdown",function(e){stopInside(e);});';
            $html[] = 'panel.addEventListener("mousedown",stopInside);';
            $html[] = 'panel.addEventListener("click",stopInside);';
            $html[] = 'if(input){input.addEventListener("input",function(){filter();});input.addEventListener("pointerdown",stopInside);input.addEventListener("mousedown",stopInside);input.addEventListener("click",stopInside);}';
            $html[] = 'function isFromRoot(ev){if(!ev){return false;}if(typeof ev.composedPath==="function"){var path=ev.composedPath();return Array.isArray(path)&&(path.indexOf(root)!==-1||path.indexOf(panel)!==-1);}return root.contains(ev.target)||panel.contains(ev.target);}';
            $html[] = 'document.addEventListener("pointerdown",function(ev){if(!isOpen()){return;}if(isFromRoot(ev)){return;}closeMenu("doc-pointerdown-outside");},true);';
            $html[] = 'document.addEventListener("mousedown",function(ev){if(!isOpen()){return;}var fromRoot=isFromRoot(ev);if(fromRoot){return;}closeMenu("doc-mousedown-outside");},true);';
            $html[] = 'document.addEventListener("click",function(ev){if(!isOpen()){return;}var fromRoot=isFromRoot(ev);if(fromRoot){return;}closeMenu("doc-click-outside");},true);';
            $html[] = 'document.addEventListener("keydown",function(e){if(e.key!=="Escape"||!isOpen()){return;}closeMenu();try{toggle.focus();}catch(err){}});';
            if ($showLanguageRequest) {
                $html[] = 'function executeMountedScripts(container){Array.prototype.slice.call(container.querySelectorAll("script")).forEach(function(oldScript){var script=document.createElement("script");Array.prototype.slice.call(oldScript.attributes||[]).forEach(function(attr){script.setAttribute(attr.name,attr.value);});script.text=oldScript.textContent||"";oldScript.parentNode.replaceChild(script,oldScript);});}';
                $html[] = 'function restoreRequestDraft(){if(!requestBody||!requestDraft){return;}var name=requestBody.querySelector("[name=\"name\"]");var email=requestBody.querySelector("[name=\"email\"]");if(name){name.value=String(requestDraft.name||"");}if(email){email.value=String(requestDraft.email||"");}var select=requestBody.querySelector(".weline-language-select[data-component-id]");var componentId=select?select.getAttribute("data-component-id"):"";var component=componentId&&window.WelineLanguageSelect?window.WelineLanguageSelect[componentId]:null;if(component&&typeof component.setValues==="function"){component.setValues(Array.isArray(requestDraft.locales)?requestDraft.locales:[]);}requestDraft=null;}';
                $html[] = 'function renderRequestError(error){if(!requestBody){return;}requestBody.innerHTML="";var box=document.createElement("div");box.className="weline-language-request-retry";var msg=document.createElement("p");msg.textContent=(error&&error.message)?error.message:"' . addslashes(__('加载失败，请稍后重试')) . '";var retry=document.createElement("button");retry.type="button";retry.textContent="' . addslashes(__('重新加载')) . '";retry.addEventListener("click",function(){loadRequestForm();});box.appendChild(msg);box.appendChild(retry);requestBody.appendChild(box);}';
                $html[] = 'function loadRequestForm(){if(requestLoaded||requestLoading||!requestBody){return;}requestLoading=true;requestBody.innerHTML="<div class=\"weline-language-request-loading\" role=\"status\">' . addslashes(__('正在加载语言目录与人机验证...')) . '</div>";Promise.resolve(window.Weline&&window.Weline.Api?window.Weline.Api.resource("i18n_language_requests"):Promise.reject(new Error("Weline.Api unavailable"))).then(function(api){return api.getLanguageSupportRequestForm({});}).then(function(result){var markup=result&&result.html?result.html:(result&&result.data&&result.data.html?result.data.html:"");if(!markup){throw new Error("' . addslashes(__('申请表加载失败')) . '");}requestBody.innerHTML=markup;executeMountedScripts(requestBody);requestLoaded=true;restoreRequestDraft();}).catch(renderRequestError).finally(function(){requestLoading=false;});}';
                $html[] = 'function requestFocusable(){return requestModal?requestModal.querySelectorAll("button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href],[tabindex]:not([tabindex=\"-1\"])"):[];}';
                $html[] = 'function openRequest(){if(!requestModal){return;}requestReturnFocus=document.activeElement;closeMenu("request-open");requestModal.hidden=false;document.documentElement.style.overflow="hidden";var dialog=requestModal.querySelector(".weline-language-request-dialog");if(dialog){dialog.focus();}loadRequestForm();}';
                $html[] = 'function closeRequest(){if(!requestModal||requestModal.hidden){return;}requestModal.hidden=true;document.documentElement.style.overflow="";if(requestReturnFocus&&typeof requestReturnFocus.focus==="function"){requestReturnFocus.focus();}}';
                $html[] = 'if(requestOpen){requestOpen.addEventListener("click",function(event){event.preventDefault();event.stopPropagation();openRequest();});}';
                $html[] = 'if(requestModal){requestModal.querySelectorAll("[data-language-request-close]").forEach(function(close){close.addEventListener("click",closeRequest);});requestModal.addEventListener("weline:captcha:refresh-requested",function(event){requestDraft=event&&event.detail?event.detail:null;requestLoaded=false;loadRequestForm();});requestModal.addEventListener("keydown",function(event){if(event.key==="Escape"){event.preventDefault();closeRequest();return;}if(event.key!=="Tab"){return;}var focusable=Array.prototype.slice.call(requestFocusable());if(!focusable.length){event.preventDefault();return;}var first=focusable[0],last=focusable[focusable.length-1];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}});}';
            }
            // Overwritten below by default-aware builder (omits default currency/locale).
            $html[] = 'function buildLangHrefFallback(lang){return "/"+(String(lang||"").replace(/-/g,"_")||"");}';
            $html[] = $defaultAwareBuildLangHrefFallbackJs;
            $html[] = 'function buildLangHref(lang){var pathname=window.location.pathname||"/";var search=window.location.search||"";if(window.WelineI18n&&typeof window.WelineI18n.buildLanguageUrl==="function"){return window.WelineI18n.buildLanguageUrl(lang,pathname,search);}return buildLangHrefFallback(lang);}';
            $html[] = 'var navigation=String(root.getAttribute("data-i18n-navigation")||"path").toLowerCase();';
            $html[] = 'root.querySelectorAll("[data-language-option]").forEach(function(opt){';
            $html[] = 'var code=opt.getAttribute("data-lang")||"";if(!code){return;}';
            $html[] = 'if(opt.dataset.welineLangBound==="1"){return;}opt.dataset.welineLangBound="1";';
            $html[] = 'opt.addEventListener("click",function(event){';
            $html[] = 'var href=opt.getAttribute("href")||"";var i18n=window.WelineI18n;';
            $html[] = 'closeMenu("language-selected");';
            $html[] = 'if(navigation==="emit"){';
            // Preview/canvas emit mode has no real navigation target — cancel the
            // anchor and broadcast the locale to the parent workbench.
            $html[] = 'event.preventDefault();';
            $html[] = 'if(typeof event.stopImmediatePropagation==="function"){event.stopImmediatePropagation();}else{event.stopPropagation();}';
            $html[] = 'root.querySelectorAll("[data-language-option]").forEach(function(node){var active=(node.getAttribute("data-lang")||"")===code;if(active){node.classList.add("active");}else{node.classList.remove("active");}node.setAttribute("aria-checked",active?"true":"false");});';
            $html[] = 'var currentEl=root.querySelector(".current-language");if(currentEl){var parts=String(code).split("_");var short=parts.length>=2?(parts[0].toUpperCase()==="ZH"?(String(parts[1]).toUpperCase()==="HANT"?"TW":"ZH"):parts[0].substring(0,2).toUpperCase()):String(code).substring(0,2).toUpperCase();currentEl.textContent=short;}';
            $html[] = 'try{root.dispatchEvent(new CustomEvent("weline:i18n:locale-change",{bubbles:true,detail:{locale:code}}));}catch(err){}';
            $html[] = 'return;}';
            // Path/LIVE mode: write the language cookie then let the browser follow
            // the authoritative <a href>. preventDefault + location.assign races
            // into an empty document in Chromium/Electron (200 + decodedBodySize 0).
            $html[] = 'if(typeof event.stopImmediatePropagation==="function"){event.stopImmediatePropagation();}else{event.stopPropagation();}';
            $html[] = 'if(i18n&&typeof i18n.writeLanguagePreference==="function"){i18n.writeLanguagePreference(code);}';
            $html[] = 'try{var target=new URL(href||"#",window.location.origin);var samePath=target.pathname===(window.location.pathname||"/")&&target.search===(window.location.search||"")&&target.hash===(window.location.hash||"");if(samePath){event.preventDefault();if(i18n&&typeof i18n.switchLang==="function"){i18n.switchLang(code,href);}else{window.location.reload();}return;}}catch(err){}';
            $html[] = 'if(!href||href==="#"){event.preventDefault();if(i18n&&typeof i18n.switchLang==="function"){i18n.switchLang(code,href);}return;}';
            // Native navigation — do not preventDefault.
            $html[] = '});';
            $html[] = '});';
            $html[] = 'filter();';
            $html[] = '})();</script>';
            if ($renderFor === 'js') {
                $html[] = '<script>(function(){';
                $html[] = 'var root=document.querySelector(\'[data-i18n-switcher-id="' . $switcherId . '"]\');if(!root){return;}';
                $html[] = 'function detectLang(){try{if(typeof window.getCookie==="function"){var c=window.getCookie("WELINE_USER_LANG");if(c){return c;}}}catch(e){}';
                $html[] = 'try{var u=new URLSearchParams(window.location.search);var q=u.get("lang");if(q){return q;}}catch(e){}';
                $html[] = 'var p=(window.location.pathname||"").split("/").filter(Boolean);for(var i=0;i<p.length;i++){if(/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(p[i])){return p[i];}}';
                $html[] = 'return (window.site&&window.site.lang)||"zh_Hans_CN";}';
                $html[] = 'function toShort(code){if(!code){return"ZH";}var parts=String(code).split("_");if(parts.length>=2){var lang=parts[0].toUpperCase();var region=parts[1].toUpperCase();if(lang==="ZH"){return region==="HANT"?"TW":"ZH";}return lang.substring(0,2);}return String(code).substring(0,2).toUpperCase();}';
                $html[] = 'function buildLangHrefFallback(lang){return "/"+(String(lang||"").replace(/-/g,"_")||"");}';
                $html[] = $defaultAwareBuildLangHrefFallbackJs;
                $html[] = 'function buildLangHref(lang){var pathname=window.location.pathname||"/";var search=window.location.search||"";if(window.WelineI18n&&typeof window.WelineI18n.buildLanguageUrl==="function"){return window.WelineI18n.buildLanguageUrl(lang,pathname,search);}return buildLangHrefFallback(lang);}';
                $html[] = 'function hasDiff(lang){var currentEl=root.querySelector(".current-language");var should=toShort(lang);var active=root.querySelector("[data-language-option].active,.language-option.active,a[data-lang].active");var activeLang=active?(active.getAttribute("data-lang")||active.dataset.lang||""):"";if(currentEl&&String(currentEl.textContent||"").trim()!==should){return true;}if(activeLang!==lang){return true;}return false;}';
                $html[] = 'function rerender(lang){var currentEl=root.querySelector(".current-language");if(currentEl){currentEl.textContent=toShort(lang);}var opts=root.querySelectorAll("[data-language-option],.language-option,a[data-lang]");opts.forEach(function(opt){var code=opt.getAttribute("data-lang")||opt.dataset.lang||"";if(!code){return;}var active=code===lang;if(active){opt.classList.add("active");}else{opt.classList.remove("active");}opt.setAttribute("aria-checked",active?"true":"false");});}';
                $html[] = 'function reconcileOnce(){var lang=detectLang();if(hasDiff(lang)){rerender(lang);}}';
                $html[] = 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){setTimeout(reconcileOnce,120);},{once:true});}else{setTimeout(reconcileOnce,120);}';
                $html[] = '})();</script>';
            }

            $output = implode("\n", $html);
            self::$htmlCache[$htmlCacheKey] = [
                'expires' => $now + self::SWITCHER_HTML_CACHE_TTL,
                'html' => $output,
            ];

            return $output;
    }

    /**
     * @param string[] $displayLocales
     */
    public static function warmBackendCaches(array $displayLocales = []): void
    {
        if ($displayLocales === []) {
            $displayLocales = [State::getLangLocal(), 'zh_Hans_CN', 'en_US'];
        }

        $seen = [];
        foreach ($displayLocales as $displayLocale) {
            $displayLocale = \trim((string)$displayLocale);
            if ($displayLocale === '') {
                continue;
            }
            $cacheKey = \strtolower($displayLocale);
            if (isset($seen[$cacheKey])) {
                continue;
            }
            $seen[$cacheKey] = true;
            self::getLanguageOptions($displayLocale, true, 0);
        }
    }

    private static function resolveIsBackendArea(?Request $request): bool
    {
        try {
            if ($request instanceof Request) {
                if ((bool)$request->isBackend()
                    || (\method_exists($request, 'isApiBackend') && (bool)$request->isApiBackend())
                ) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                $area = \strtolower((string)(\Weline\Theme\Helper\ThemeData::getCurrentArea() ?? ''));
                if ($area === 'backend') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function isLanguageRequestEnabled(): bool
    {
        try {
            $value = ObjectManager::getInstance(ConfigReader::class)->get(
                'i18n/language_request/enabled',
                'Weline_I18n',
                ConfigReader::area_FRONTEND,
                true,
            );
            return \is_bool($value)
                ? $value
                : \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function buildLanguagesFromScope(LocaleCatalogScope $scope, string $displayLocale): array
    {
        $displayLocale = \trim($displayLocale) !== '' ? $displayLocale : $scope->displayLocale;
        $cacheKey = $scope->mode . ':' . $scope->websiteId . '|' . $displayLocale . '|' . \implode(',', $scope->codes);
        $now = \microtime(true);
        if (isset(self::$languageCache[$cacheKey]) && self::$languageCache[$cacheKey]['expires'] >= $now) {
            return self::$languageCache[$cacheKey]['languages'];
        }
        unset(self::$languageCache[$cacheKey]);

        $languages = self::buildLanguagesFromCodes($scope->codes, $displayLocale);
        self::$languageCache[$cacheKey] = [
            'expires' => $now + self::SWITCHER_LANGUAGE_CACHE_TTL,
            'languages' => $languages,
        ];

        return $languages;
    }

    /**
     * Build switcher language map from canonical LanguageSelect catalog rows.
     *
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    public static function buildLanguagesFromCodes(array $codes, string $displayLocale): array
    {
        $displayLocale = \trim($displayLocale) !== '' ? $displayLocale : 'zh_Hans_CN';
        $wantedOrder = [];
        $wantedKeys = [];
        foreach ($codes as $code) {
            $code = \trim(\str_replace('-', '_', (string)$code));
            if ($code === '') {
                continue;
            }
            $key = \strtolower($code);
            if (isset($wantedKeys[$key])) {
                continue;
            }
            $wantedKeys[$key] = true;
            $wantedOrder[] = $code;
        }
        if ($wantedOrder === []) {
            return [];
        }

        try {
            $items = LanguageSelect::resolveLanguageItems($displayLocale, 'installed', $wantedOrder);
        } catch (\Throwable) {
            $items = [];
        }

        $languages = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = \trim((string)($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $displayName = (string)($item['display_name'] ?? ($item['name'] ?? $code));
            $tagLabel = (string)($item['tag_label'] ?? $displayName);
            $languages[$code] = [
                'code' => $code,
                'name' => $displayName,
                'display_name' => $displayName,
                'tag_label' => $tagLabel,
                'self_name' => (string)($item['self_name'] ?? ''),
                'reference_name' => (string)($item['reference_name'] ?? ''),
                'flag' => (string)($item['flag'] ?? ''),
                'country_code' => (string)($item['country_code'] ?? ''),
                'country_name' => (string)($item['country_name'] ?? ''),
                'search_terms' => (string)($item['search_terms'] ?? ''),
            ];
        }

        return $languages;
    }

    private static function normalizeOptionalInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (\is_bool($raw)) {
            return null;
        }
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_float($raw)) {
            return (int)$raw;
        }
        if (\is_string($raw) && \is_numeric(\trim($raw))) {
            return (int)\trim($raw);
        }

        return null;
    }

    private static function normalizeOptionalBool(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (\is_bool($raw)) {
            return $raw;
        }
        $value = \strtolower(\trim((string)$raw));
        if (\in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $languages
     * @return list<array{country_code: string, country_name: string, languages: array<string, array<string, mixed>>}>
     */
    private static function groupLanguagesByCountry(
        array $languages,
        string $displayLocale,
        string $currentCode = '',
    ): array {
        if ($languages === []) {
            return [];
        }

        $remaining = [];
        foreach ($languages as $code => $language) {
            $normalizedCode = \strtolower(\str_replace('-', '_', \trim((string)$code)));
            if ($normalizedCode === '') {
                continue;
            }
            $remaining[$normalizedCode] = [
                'code' => (string)$code,
                'language' => \is_array($language) ? $language : [],
            ];
        }

        try {
            $catalog = LanguageSelect::getLanguageItems($displayLocale);
        } catch (\Throwable) {
            $catalog = [];
        }

        $ordered = [];
        foreach ($catalog as $item) {
            $catalogCode = (string)($item['code'] ?? '');
            $normalizedCode = \strtolower(\str_replace('-', '_', \trim($catalogCode)));
            if ($normalizedCode === '' || !isset($remaining[$normalizedCode])) {
                continue;
            }
            $entry = $remaining[$normalizedCode];
            unset($remaining[$normalizedCode]);
            $language = \is_array($item) ? $item : [];
            foreach ($entry['language'] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $language[$key] = $value;
                }
            }
            $language['code'] = $entry['code'];
            $ordered[$entry['code']] = $language;
        }

        foreach ($remaining as $entry) {
            $code = (string)$entry['code'];
            $language = $entry['language'];
            $language['code'] = $code;
            if (empty($language['country_code'])) {
                $parts = \preg_split('/[-_]/', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $last = \end($parts);
                if (\is_string($last) && \strlen($last) === 2) {
                    $language['country_code'] = \strtoupper($last);
                }
            }
            $ordered[$code] = $language;
        }

        $groups = [];
        $groupIndexes = [];
        foreach ($ordered as $code => $language) {
            $countryCode = \strtoupper(\trim((string)($language['country_code'] ?? '')));
            $countryName = \trim((string)($language['country_name'] ?? ''));
            if ($countryName === '') {
                $countryName = $countryCode !== '' ? $countryCode : (string)__('未分组国家');
            }
            $groupKey = \strtolower($countryCode . '|' . $countryName);
            if (!isset($groupIndexes[$groupKey])) {
                $groupIndexes[$groupKey] = \count($groups);
                $groups[] = [
                    'country_code' => $countryCode,
                    'country_name' => $countryName,
                    'languages' => [],
                ];
            }
            $groups[$groupIndexes[$groupKey]]['languages'][(string)$code] = $language;
        }

        return self::prioritizeCurrentLanguageGroup($groups, $currentCode);
    }

    /**
     * Pin the current locale's country group (and the locale itself) to the top.
     *
     * Catalog order alone sorts by localized country names; under zh_Hans_CN that
     * puts 中国 before 印度 even when the visitor is already on bn_IN / as_IN.
     *
     * @param list<array{country_code: string, country_name: string, languages: array<string, array<string, mixed>>}> $groups
     * @return list<array{country_code: string, country_name: string, languages: array<string, array<string, mixed>>}>
     */
    private static function prioritizeCurrentLanguageGroup(array $groups, string $currentCode): array
    {
        $currentCode = \strtolower(\str_replace('-', '_', \trim($currentCode)));
        if ($currentCode === '' || $groups === []) {
            return $groups;
        }

        $currentGroupIndex = null;
        $currentLanguageCode = '';
        foreach ($groups as $index => $group) {
            foreach ((array)($group['languages'] ?? []) as $code => $_language) {
                $normalized = \strtolower(\str_replace('-', '_', \trim((string)$code)));
                if ($normalized === $currentCode) {
                    $currentGroupIndex = $index;
                    $currentLanguageCode = (string)$code;
                    break 2;
                }
            }
        }
        if ($currentGroupIndex === null) {
            return $groups;
        }

        $currentGroup = $groups[$currentGroupIndex];
        $languages = (array)($currentGroup['languages'] ?? []);
        if ($currentLanguageCode !== '' && isset($languages[$currentLanguageCode]) && \count($languages) > 1) {
            $pinned = [$currentLanguageCode => $languages[$currentLanguageCode]];
            unset($languages[$currentLanguageCode]);
            $currentGroup['languages'] = $pinned + $languages;
        }

        if ($currentGroupIndex === 0) {
            $groups[0] = $currentGroup;
            return $groups;
        }

        unset($groups[$currentGroupIndex]);
        return \array_values(\array_merge([$currentGroup], $groups));
    }

    /**
     * Fingerprint of backend switcher locale catalog for chrome/output cache keys.
     */
    public static function backendLocaleCatalogFingerprint(): string
    {
        try {
            /** @var ActiveLocaleCodeProvider $activeLocaleCodeProvider */
            $activeLocaleCodeProvider = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
            $codes = $activeLocaleCodeProvider->getInstalledActiveCodes();
        } catch (\Throwable) {
            $codes = [];
        }
        $normalized = [];
        foreach ($codes as $code) {
            $code = \strtolower(\trim((string)$code));
            if ($code !== '') {
                $normalized[$code] = true;
            }
        }
        $keys = \array_keys($normalized);
        \sort($keys);

        return \sha1(\implode('|', $keys));
    }

    /**
     * @deprecated Prefer LocaleCatalogScopeResolver + buildLanguagesFromCodes.
     * Empty website language sets must not fall back to the global catalog.
     *
     * @param array<string, mixed> $welineLanguages
     * @return array<string, mixed>
     */
    private static function filterFrontendLanguages(array $welineLanguages, int $websiteId): array
    {
        $websiteLanguageCodes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
        if (!is_array($websiteLanguageCodes) || $websiteLanguageCodes === []) {
            return [];
        }

        $allowedMap = [];
        foreach ($websiteLanguageCodes as $websiteLanguageCode) {
            $websiteLanguageCode = (string)$websiteLanguageCode;
            if ($websiteLanguageCode !== '') {
                $allowedMap[strtolower(str_replace('-', '_', $websiteLanguageCode))] = true;
            }
        }
        if ($allowedMap === []) {
            return [];
        }

        $filteredLanguages = [];
        foreach ($welineLanguages as $languageCode => $languageData) {
            $key = strtolower(str_replace('-', '_', (string)$languageCode));
            if (isset($allowedMap[$key])) {
                $filteredLanguages[$languageCode] = $languageData;
            }
        }

        return $filteredLanguages;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function normalizeAllowedValues(mixed $raw): array
    {
        if (\is_array($raw)) {
            $values = $raw;
        } elseif ($raw === null || $raw === '') {
            return [];
        } else {
            $raw = \trim((string)$raw);
            if ($raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) {
                $decoded = \json_decode($raw, true);
                $values = (\json_last_error() === \JSON_ERROR_NONE && \is_array($decoded))
                    ? $decoded
                    : (\preg_split('/[\s,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
            } else {
                $values = \preg_split('/[\s,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }
        $result = [];
        foreach ($values as $value) {
            if (\is_array($value) && isset($value['code'])) {
                $value = $value['code'];
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            $key = \strtolower(\str_replace('-', '_', $value));
            if (isset($result[$key])) {
                continue;
            }
            $result[$key] = $value;
        }

        return \array_values($result);
    }

    /**
     * @param array<string, mixed> $languages
     * @param list<string> $allowedValues
     * @return array<string, mixed>
     */
    private static function filterLanguagesByAllowedValues(array $languages, array $allowedValues): array
    {
        if ($allowedValues === []) {
            return $languages;
        }
        $allowedMap = [];
        foreach ($allowedValues as $code) {
            $allowedMap[\strtolower(\str_replace('-', '_', $code))] = true;
        }
        $filtered = [];
        foreach ($languages as $languageCode => $languageData) {
            $key = \strtolower(\str_replace('-', '_', (string)$languageCode));
            if (isset($allowedMap[$key])) {
                $filtered[$languageCode] = $languageData;
            }
        }

        return $filtered !== [] ? $filtered : $languages;
    }

    /**
     * Authoritative locale inject for pre-website / plan-owned language lists.
     * Preserves caller order and resolves labels/flags from the i18n catalog.
     *
     * @param list<string> $codes
     * @return array<string, array{code: string, name: string, flag: string, display_name?: string, reference_name?: string, english_name?: string, self_name?: string, search?: string}>
     */
    public static function buildInjectedLanguages(array $codes, string $displayLocale): array
    {
        return self::buildLanguagesFromCodes($codes, $displayLocale);
    }

    /**
     * @param string[] $languageCodes
     */
    private static function buildHtmlCacheKey(
        bool $isBackendArea,
        int $websiteId,
        string $renderFor,
        string $currentCode,
        string $currentCurrency,
        string $currentPath,
        string $currentSearch,
        string $backendRoute,
        array $languageCodes
    ): string {
        return \md5(\json_encode([
            'scope' => $isBackendArea ? 'backend' : 'frontend:' . $websiteId,
            'for' => $renderFor,
            'lang' => $currentCode,
            'currency' => $currentCurrency,
            'path' => $currentPath,
            'search' => self::sanitizeLanguageSearch($currentSearch),
            'backend_route' => $backendRoute,
            'languages' => $languageCodes,
        ], \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    private static function sanitizeInlineFlagMarkup(string $markup): string
    {
        if ($markup === '') {
            return '';
        }

        return (string)preg_replace('/<\\?xml[^?]*\\?>/i', '', $markup);
    }

    private static function resolveCurrentSearch(Request $request): string
    {
        $queryString = trim((string)($request->getServer('QUERY_STRING') ?? ''), '?');
        if ($queryString === '') {
            $query = $request->getQuery();
            if (is_array($query) && $query !== []) {
                $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
        }

        return $queryString !== '' ? '?' . $queryString : '';
    }

    /**
     * Visitor-facing path for language hrefs after URL rewrite.
     *
     * Prefer PageBuilder handle / origin URI over the internal controller path.
     *
     * @return array{path: string, search: string}
     */
    private static function resolvePublicFrontendRoute(Request $request): array
    {
        $path = self::resolvePublicFrontendPath($request);
        // Friendly rewrites never need internal page_id on language switches.
        return [
            'path' => $path !== '' ? $path : '/',
            'search' => '',
        ];
    }

    private static function resolvePublicFrontendPath(Request $request): string
    {
        $handle = trim((string)$request->getGet('handle', ''));
        $pageId = (int)$request->getGet('page_id', 0);
        if ($handle === '') {
            try {
                $handle = trim((string)WelineEnv::getGet('handle', ''));
            } catch (\Throwable) {
                $handle = '';
            }
        }
        if ($pageId <= 0) {
            try {
                $pageId = (int)WelineEnv::getGet('page_id', 0);
            } catch (\Throwable) {
                $pageId = 0;
            }
        }

        if ($handle === '' && $pageId > 0 && class_exists(\GuoLaiRen\PageBuilder\Model\Page::class)) {
            try {
                /** @var \GuoLaiRen\PageBuilder\Model\Page $pageModel */
                $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
                $page = clone $pageModel;
                $page->load($pageId);
                if ($page->getId()) {
                    $handle = trim((string)$page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_HANDLE));
                    if ((string)$page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_TYPE)
                        === \GuoLaiRen\PageBuilder\Model\Page::TYPE_HOME
                    ) {
                        return '/';
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($handle !== '') {
            $normalizedHandle = strtolower($handle);
            if (in_array($normalizedHandle, ['index', 'home', 'ai-site'], true)) {
                return '/';
            }
            try {
                $websiteId = (int)(RequestContext::getWelineWebsiteId()
                    ?: $request->getServer('WELINE_WEBSITE_ID')
                    ?: 0);
                if ($websiteId > 0 && class_exists(\GuoLaiRen\PageBuilder\Model\Page::class)) {
                    /** @var \GuoLaiRen\PageBuilder\Model\Page $pageModel */
                    $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
                    $page = clone $pageModel;
                    $page->clear()
                        ->where(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_HANDLE, $handle)
                        ->find()
                        ->fetch();
                    if ($page->getId()
                        && (string)$page->getData(\GuoLaiRen\PageBuilder\Model\Page::schema_fields_TYPE)
                            === \GuoLaiRen\PageBuilder\Model\Page::TYPE_HOME
                    ) {
                        return '/';
                    }
                }
            } catch (\Throwable) {
            }

            return '/' . ltrim($handle, '/');
        }

        $candidates = [
            (string)($request->getServer('WELINE_ORIGIN_REQUEST_URI') ?? ''),
            (string)($request->getServer('ORIGIN_REQUEST_URI') ?? ''),
            (string)WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', ''),
            (string)WelineEnv::server('ORIGIN_REQUEST_URI', ''),
            (string)WelineEnv::get('origin_request_uri', ''),
            (string)($request->getServer('REQUEST_URI') ?? ''),
            (string)WelineEnv::server('REQUEST_URI', ''),
            (string)WelineEnv::get('request.uri', ''),
        ];

        $toPath = static function (string $candidate): string {
            $uri = trim($candidate);
            if ($uri === '') {
                return '';
            }
            if (str_contains($uri, '://')) {
                $parsed = parse_url($uri, PHP_URL_PATH);
                $uri = is_string($parsed) ? $parsed : '';
            } else {
                $parsed = parse_url($uri, PHP_URL_PATH);
                if (is_string($parsed) && $parsed !== '') {
                    $uri = $parsed;
                } else {
                    $uri = strtok($uri, '?') ?: $uri;
                }
            }

            return trim(str_replace('\\', '/', (string)$uri), '/');
        };

        $isInternalPageBuilderPath = static function (string $path): bool {
            $normalized = strtolower(trim($path, '/'));

            return $normalized === 'pagebuilder/frontend/page'
                || str_starts_with($normalized, 'pagebuilder/frontend/page/');
        };

        foreach ($candidates as $candidate) {
            $path = $toPath($candidate);
            if ($path === '') {
                if (trim($candidate) !== '') {
                    return '/';
                }
                continue;
            }
            if ($isInternalPageBuilderPath($path)) {
                continue;
            }

            $segments = array_values(array_filter(
                explode('/', $path),
                static fn(string $segment): bool => $segment !== ''
            ));
            try {
                $localization = State::resolveLocalizationFromPathSegments($segments);
                $remaining = is_array($localization['remaining'] ?? null)
                    ? $localization['remaining']
                    : $segments;
            } catch (\Throwable) {
                $remaining = $segments;
            }

            $remainingPath = implode('/', array_map('strval', $remaining));
            if ($isInternalPageBuilderPath($remainingPath)) {
                return '/';
            }

            $storefrontPath = $remaining === [] ? '/' : '/' . $remainingPath;

            return self::stripWebsiteMountFromStorefrontPath($storefrontPath, $request);
        }

        $fallback = (string)($request->getUrlPath() ?: '/');
        if ($isInternalPageBuilderPath(trim($fallback, '/'))) {
            return '/';
        }

        return self::stripWebsiteMountFromStorefrontPath(
            $fallback !== '' ? $fallback : '/',
            $request
        );
    }

    /**
     * Website mount sub_path (e.g. aisite_accept_ok) must not appear in the
     * storefront-relative path used for language hrefs — otherwise locale
     * builders emit /hi_IN/aisite_accept_ok and mount prefixers double it.
     */
    private static function resolveWebsiteMountPath(?Request $request = null): string
    {
        $candidates = [
            $request instanceof Request ? (string)($request->getServer('WELINE_WEBSITE_URL') ?? '') : '',
            (string)WelineEnv::server('WELINE_WEBSITE_URL', ''),
            (string)WelineEnv::get('website_url', ''),
            (string)($_SERVER['WELINE_WEBSITE_URL'] ?? ''),
        ];
        try {
            $fromUrl = \Weline\Framework\Http\Url::resolveCurrentWebsiteMountPath();
            if ($fromUrl !== '') {
                return trim($fromUrl, '/');
            }
        } catch (\Throwable) {
        }
        try {
            $cookie = (string)(\Weline\Framework\Http\Cookie::get('WELINE_WEBSITE_URL') ?? '');
            if ($cookie !== '') {
                $candidates[] = $cookie;
            }
        } catch (\Throwable) {
        }
        foreach ($candidates as $websiteUrl) {
            $websiteUrl = trim((string)$websiteUrl);
            if ($websiteUrl === '') {
                continue;
            }
            $path = '';
            if (str_contains($websiteUrl, '://')) {
                $parsed = parse_url($websiteUrl, PHP_URL_PATH);
                $path = is_string($parsed) ? $parsed : '';
            } elseif (str_starts_with($websiteUrl, '/')) {
                $path = $websiteUrl;
            } elseif (preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $websiteUrl) === 1) {
                return $websiteUrl;
            }
            $mount = trim(str_replace('\\', '/', $path), '/');
            if ($mount !== '') {
                return $mount;
            }
        }

        // Blog / reserved routes sometimes lose WELINE_WEBSITE_URL path; recover from
        // the origin URI when the first segment is a mount followed by storefront tail.
        $uri = $request instanceof Request
            ? (string)($request->getServer('WELINE_ORIGIN_REQUEST_URI')
                ?: $request->getServer('REQUEST_URI')
                ?: '')
            : (string)($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? $_SERVER['REQUEST_URI'] ?? '');
        $uriPath = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        $segments = array_values(array_filter(
            explode('/', trim(str_replace('\\', '/', $uriPath), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        if ($segments === []) {
            return '';
        }
        $first = (string)$segments[0];
        $langPattern = '/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i';
        if (preg_match($langPattern, $first) === 1 || preg_match('/^[A-Z]{3}$/', $first) === 1) {
            return '';
        }
        if (preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $first) !== 1) {
            return '';
        }
        $rest = array_slice($segments, 1);
        foreach ($rest as $segment) {
            $seg = (string)$segment;
            if (preg_match($langPattern, $seg) === 1
                || preg_match('/^[A-Z]{3}$/', $seg) === 1
                || in_array(strtolower($seg), ['blog', 'about', 'contact', 'privacy', 'terms'], true)
            ) {
                return $first;
            }
        }

        return '';
    }

    private static function stripWebsiteMountFromStorefrontPath(string $path, ?Request $request = null): string
    {
        $mount = self::resolveWebsiteMountPath($request);
        if ($mount === '') {
            return $path !== '' ? $path : '/';
        }

        $segments = array_values(array_filter(
            explode('/', trim(str_replace('\\', '/', $path), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        while ($segments !== [] && strcasecmp((string)$segments[0], $mount) === 0) {
            array_shift($segments);
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    /**
     * Visitor-facing language href.
     *
     * Frontend: delegates to LocalizedUrlBuilder (default currency/locale omitted).
     * Backend: always keeps locale segment; still omits default currency.
     * Non-default currency is preserved until the currency switcher changes it.
     *
     * Website mount sub_path is a fixed base ([website]/[currency?]/[lang?]/[path])
     * and must never be mixed into locale/page segment splitting.
     */
    private static function buildLanguageHref(
        string $path,
        string $search,
        string $targetLang,
        string $fallbackCurrency = 'CNY',
        string $preferredPrefix = ''
    ): string {
        $preferredPrefix = trim($preferredPrefix, '/');
        $websiteMount = self::resolveWebsiteMountPath(null);
        // Peel fixed website base before any locale/currency/page splitting.
        $path = self::stripWebsiteMountFromStorefrontPath(
            (string)(parse_url($path, PHP_URL_PATH) ?: $path ?: '/'),
            null
        );
        $pathParts = array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
        $langPattern = '/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i';
        $prefixIndex = -1;
        $fallbackCurrency = strtoupper(trim($fallbackCurrency ?: 'CNY'));
        $defaultCurrency = self::defaultCurrency();
        $isCurrency = static function (string $part) use ($fallbackCurrency, $defaultCurrency): bool {
            $raw = trim($part);
            $code = strtoupper($raw);
            if (strlen($code) !== 3 || !ctype_upper($code)) {
                return false;
            }

            // Currency path segments are uppercase (CNY/USD). Lowercase route
            // tokens like "cms" must not be treated as currency.
            if ($raw !== $code
                && !State::isAllowedCurrencyCode($code)
                && $code !== $fallbackCurrency
                && $code !== $defaultCurrency
            ) {
                return false;
            }

            return State::isAllowedCurrencyCode($code)
                || $code === $fallbackCurrency
                || $code === $defaultCurrency
                || $raw === $code;
        };

        if ($preferredPrefix !== '') {
            foreach ($pathParts as $index => $part) {
                if (!$isCurrency((string)$part)
                    && !preg_match($langPattern, $part)
                    && strcasecmp($part, $preferredPrefix) === 0) {
                    $prefixIndex = $index;
                    break;
                }
            }
        }

        $prefix = $prefixIndex >= 0 ? $pathParts[$prefixIndex] : $preferredPrefix;
        $detectedCurrency = '';
        foreach ($pathParts as $part) {
            if ($isCurrency((string)$part)) {
                $detectedCurrency = strtoupper($part);
                break;
            }
        }

        $remain = [];
        foreach ($pathParts as $index => $part) {
            if ($isCurrency((string)$part) || preg_match($langPattern, $part)) {
                continue;
            }
            if ($index === $prefixIndex) {
                continue;
            }
            // Mount must never re-enter relative remain after base peel.
            if ($websiteMount !== '' && strcasecmp((string)$part, $websiteMount) === 0) {
                continue;
            }
            $remain[] = $part;
        }

        $currency = strtoupper($detectedCurrency ?: $fallbackCurrency ?: 'CNY');
        if ($currency !== '' && !$isCurrency($currency)) {
            $currency = '';
        }
        // Framework contract: default currency is omitted from language URLs.
        // Non-default currency stays until the currency switcher changes it.
        if ($currency === $defaultCurrency) {
            $currency = '';
        }

        $routePath = $remain === [] ? '/' : '/' . implode('/', $remain);
        $normalizedSearch = self::sanitizeLanguageSearch($search);

        $relativeHref = '';
        // Frontend: system LocalizedUrlBuilder (omits default currency + default locale).
        if ($prefix === '') {
            try {
                /** @var LocalizedUrlBuilderInterface $builder */
                $builder = ObjectManager::getInstance(LocalizedUrlBuilderInterface::class);
                $absolute = $builder->build(
                    'https://weline.local',
                    $routePath,
                    $targetLang,
                    self::defaultLanguage(),
                    $currency !== '' ? $currency : null,
                    $defaultCurrency
                );
                $builtPath = parse_url($absolute, PHP_URL_PATH);
                if (is_string($builtPath) && $builtPath !== '') {
                    $relativeHref = $builtPath;
                }
            } catch (\Throwable) {
            }
        }

        if ($relativeHref === '') {
            // Backend (or LocalizedUrlBuilder unavailable): keep locale always; omit default currency.
            $out = [];
            if ($prefix !== '') {
                $out[] = $prefix;
            }
            if ($currency !== '') {
                $out[] = $currency;
            }
            if ($targetLang !== '') {
                $out[] = $targetLang;
            }
            if ($remain !== []) {
                array_push($out, ...$remain);
            }
            $relativeHref = '/' . implode('/', $out);
        }

        // Re-attach fixed website base outside segment splitting.
        if ($prefix === '' && $websiteMount !== '') {
            $relativeHref = self::joinWebsiteMountAndRelativePath($websiteMount, $relativeHref);
        }

        return $relativeHref . ($normalizedSearch !== '' ? '?' . $normalizedSearch : '');
    }

    private static function joinWebsiteMountAndRelativePath(string $mount, string $relativePath): string
    {
        $mount = trim(str_replace('\\', '/', $mount), '/');
        if ($mount === '') {
            return $relativePath !== '' ? $relativePath : '/';
        }

        $relativePath = (string)(parse_url($relativePath, PHP_URL_PATH) ?: $relativePath ?: '/');
        $segments = array_values(array_filter(
            explode('/', trim(str_replace('\\', '/', $relativePath), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        // Fixed base must not appear inside the relative route at any position.
        $segments = array_values(array_filter(
            $segments,
            static fn(string $segment): bool => strcasecmp($segment, $mount) !== 0
        ));
        if ($segments === []) {
            return '/' . $mount . '/';
        }

        return '/' . $mount . '/' . implode('/', $segments);
    }

    private static function defaultLanguage(): string
    {
        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $fromWebsite = \trim((string)(\Weline\Websites\Data\WebsiteData::getDefaultLanguage() ?? ''));
                if ($fromWebsite !== '') {
                    return \str_replace('-', '_', $fromWebsite);
                }
            }
        } catch (\Throwable) {
        }

        $language = trim((string)(
            w_env('website.language', '')
            ?: Env::get('locale', Env::get('lang', 'zh_Hans_CN'))
            ?: 'zh_Hans_CN'
        ));
        return $language !== '' ? str_replace('-', '_', $language) : 'zh_Hans_CN';
    }

    private static function defaultCurrency(): string
    {
        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $fromWebsite = \strtoupper(\trim((string)(\Weline\Websites\Data\WebsiteData::getDefaultCurrency() ?? '')));
                if ($fromWebsite !== '') {
                    return $fromWebsite;
                }
            }
        } catch (\Throwable) {
        }

        $currency = strtoupper(trim((string)(
            w_env('website.currency', '')
            ?: Env::get('currency', 'CNY')
            ?: 'CNY'
        )));
        return $currency !== '' ? $currency : 'CNY';
    }

    private static function sameLanguage(string $left, string $right): bool
    {
        $left = strtolower(str_replace('-', '_', trim($left)));
        $right = strtolower(str_replace('-', '_', trim($right)));
        return $left !== '' && $right !== '' && $left === $right;
    }

    private static function sanitizeLanguageSearch(string $search): string
    {
        $search = trim($search, '?');
        if ($search === '') {
            return '';
        }

        parse_str($search, $params);
        foreach (array_keys($params) as $key) {
            if (self::isIgnorableLanguageQueryParam((string)$key)) {
                unset($params[$key]);
            }
        }

        return $params === [] ? '' : http_build_query($params);
    }

    private static function isIgnorableLanguageQueryParam(string $key): bool
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return false;
        }

        if (in_array($key, ['_', 'ai_perf', 'fbclid', 'gbraid', 'gclid', 'igshid', 'mc_cid', 'mc_eid', 'msclkid', 'wbraid', 'yclid'], true)) {
            return true;
        }

        return str_starts_with($key, 'utm_')
            || str_starts_with($key, 'mtm_')
            || str_starts_with($key, 'pk_');
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return '<p><code>&lt;w:i18n:switcher /&gt;</code> 按国家分组、支持搜索的标准语言切换标签。旧名 <code>&lt;w:i18n:language:switcher /&gt;</code> 为兼容别名。</p>';
    }
}
