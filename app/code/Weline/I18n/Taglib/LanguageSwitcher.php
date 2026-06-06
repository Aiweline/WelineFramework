<?php
declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\Taglib\TaglibInterface;

class LanguageSwitcher implements TaglibInterface
{
    private const SWITCHER_HTML_CACHE_TTL = 60.0;
    private const SWITCHER_LANGUAGE_CACHE_TTL = 300.0;

    /**
     * @var array<string, array{expires: float, html: string}>
     */
    private static array $htmlCache = [];

    /**
     * @var array<string, array{expires: float, languages: array}>
     */
    private static array $languageCache = [];

    public static function name(): string
    {
        return 'i18n:language:switcher';
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
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            $websiteId = 0;
            $isBackendArea = false;
            $request = null;
            try {
                /** @var Request $request */
                $request = ObjectManager::getInstance(Request::class);
                $websiteId = (int)($request->getData('website_id') ?? 0);
                $isBackendArea = (bool)$request->isBackend()
                    || (\method_exists($request, 'isApiBackend') && (bool)$request->isApiBackend());
            } catch (\Throwable) {
                $websiteId = 0;
                $isBackendArea = false;
            }

            // 后台读取完整 locale 集合，再按 Locals 激活状态过滤；避免仅“已安装语言包目录”导致的漏项。
            $welineLanguages = self::getLanguageOptions(State::getLangLocal(), $isBackendArea, $websiteId);

            if ($isBackendArea) {
                // 后台：读取 i18n 模块已安装且已激活语言
                try {
                    /** @var ActiveLocaleCodeProvider $activeLocaleCodeProvider */
                    $activeLocaleCodeProvider = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
                    $allowedMap = $activeLocaleCodeProvider->getInstalledActiveCodeMap();
                    if ($allowedMap !== []) {
                        $allowedMapLower = [];
                        foreach (\array_keys($allowedMap) as $allowedCode) {
                            $allowedMapLower[\strtolower((string)$allowedCode)] = true;
                        }
                        $filteredLanguages = [];
                        foreach ($welineLanguages as $languageCode => $languageData) {
                            if (isset($allowedMap[(string)$languageCode]) || isset($allowedMapLower[\strtolower((string)$languageCode)])) {
                                $filteredLanguages[$languageCode] = $languageData;
                            }
                        }
                        // 后台严格使用「已安装+已激活」语言集合，不参与网站维度过滤。
                        $welineLanguages = $filteredLanguages;
                    }
                } catch (\Throwable) {
                    // 失败时保持已安装语言兜底
                }
            } elseif ($websiteId > 0) {
                // 前台：按站点语言限制语言选项
                $websiteLanguageCodes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
                if (is_array($websiteLanguageCodes) && !empty($websiteLanguageCodes)) {
                    $allowedMap = [];
                    foreach ($websiteLanguageCodes as $websiteLanguageCode) {
                        $websiteLanguageCode = (string)$websiteLanguageCode;
                        if ($websiteLanguageCode !== '') {
                            $allowedMap[$websiteLanguageCode] = true;
                        }
                    }
                    if (!empty($allowedMap)) {
                        $filteredLanguages = [];
                        foreach ($welineLanguages as $languageCode => $languageData) {
                            if (isset($allowedMap[(string)$languageCode])) {
                                $filteredLanguages[$languageCode] = $languageData;
                            }
                        }
                        if (!empty($filteredLanguages)) {
                            $welineLanguages = $filteredLanguages;
                        }
                    }
                }
            }

            $currentCode = State::getLangLocal();
            $firstCode = (string)(array_key_first($welineLanguages) ?? 'zh_Hans_CN');
            $firstData = (array)($welineLanguages[$firstCode] ?? []);
            $welineCurrentLanguage = [
                'code' => $firstCode,
                'name' => (string)($firstData['name'] ?? '中文'),
                'flag' => (string)($firstData['flag'] ?? ''),
            ];
            if (isset($welineLanguages[$currentCode])) {
                $welineCurrentLanguage = $welineLanguages[$currentCode];
                $welineCurrentLanguage['code'] = $currentCode;
            }

            $currentCode = (string)($welineCurrentLanguage['code'] ?? '');
            $currentName = htmlspecialchars((string)($welineCurrentLanguage['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $currentFlag = self::sanitizeInlineFlagMarkup((string)($welineCurrentLanguage['flag'] ?? ''));
            $renderFor = strtolower(trim((string)($attributes['for'] ?? '')));
            $switcherScopeId = $isBackendArea ? 'backend' : (string)$websiteId;
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
                $currentPath = (string)($request->getUrlPath() ?: '/');
                $queryString = trim((string)($request->getServer('QUERY_STRING') ?? ''), '?');
                $currentSearch = $queryString !== '' ? '?' . $queryString : '';
                $backendRoute = trim((string)($request->getServer('WELINE_AREA_ROUTE') ?? ''), '/');
            }
            if ($isBackendArea) {
                $backendRoute = trim((string)(Env::getAreaRoutePrefix('backend') ?? $backendRoute), '/');
            }
            $currentCurrency = State::getCurrency();
            $backendRouteJson = json_encode($backendRoute, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            if (!is_string($backendRouteJson)) {
                $backendRouteJson = '""';
            }
            $defaultAwareBuildLangHrefFallbackJs =
                'buildLangHrefFallback=function(lang){'
                . 'var pathname=window.location.pathname||"/";var search=window.location.search||"";'
                . 'var pathParts=String(pathname||"/").split("/").filter(Boolean);'
                . 'var currencyPattern=/^[A-Z]{3}$/;var langPattern=/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;'
                . 'var cfg=window.__WelineThemeConfig||{};'
                . 'var defaultCurrency=String(cfg.defaultCurrency||"CNY").toUpperCase();'
                . 'var defaultLang=String(cfg.defaultLang||cfg.defaultLanguage||(cfg.i18n&&(cfg.i18n.defaultLang||cfg.i18n.defaultLanguage))||"zh_Hans_CN").replace(/-/g,"_").toLowerCase();'
                . 'var backendKey=String(' . $backendRouteJson . '||(window.site&&window.site.area)||(window.Weline&&window.Weline.config&&window.Weline.config.url&&window.Weline.config.url.adminArea)||"");'
                . 'var currency="";for(var i=0;i<pathParts.length;i++){if(currencyPattern.test(pathParts[i])){currency=pathParts[i].toUpperCase();break;}}'
                . 'if(!currency){currency=String((cfg.currentCurrency)||"CNY").toUpperCase();}'
                . 'var prefixIndex=-1;if(backendKey){prefixIndex=pathParts.findIndex(function(part){return !langPattern.test(part)&&!currencyPattern.test(part)&&String(part).toLowerCase()===backendKey.toLowerCase();});}'
                . 'var prefixSegment=prefixIndex>=0?pathParts[prefixIndex]:backendKey;var remain=[];'
                . 'pathParts.forEach(function(part,index){if(langPattern.test(part)||currencyPattern.test(part)){return;}if(index===prefixIndex){return;}remain.push(part);});'
                . 'var out=[];if(prefixSegment){out.push(prefixSegment);}if(currency&&currency!==defaultCurrency){out.push(currency);}'
                . 'var normalizedLang=String(lang||"").replace(/-/g,"_");if(normalizedLang&&normalizedLang.toLowerCase()!==defaultLang){out.push(normalizedLang);}'
                . 'if(remain.length){out.push.apply(out,remain);}return "/"+out.join("/")+(search||"");'
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
            );
            $now = \microtime(true);
            if (isset(self::$htmlCache[$htmlCacheKey]) && self::$htmlCache[$htmlCacheKey]['expires'] >= $now) {
                return self::$htmlCache[$htmlCacheKey]['html'];
            }
            unset(self::$htmlCache[$htmlCacheKey]);

            $html = [];
            $html[] = '<div class="dropdown d-inline-block" data-i18n-switcher data-i18n-switcher-id="' . $switcherId . '">';
            $html[] = '    <button type="button" id="' . htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') . '" class="btn header-item waves-effect weline-i18n-switcher-toggle position-relative" style="z-index:1056" aria-haspopup="true" aria-expanded="false" aria-controls="' . htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') . '">';
            if ($renderFor === 'js') {
                $html[] = '        ' . $currentFlag . '<span class="align-middle current-language"> ' . $currentDisplay . '</span>';
            } else {
                $html[] = '        ' . $currentFlag . '<span class="align-middle"> ' . $currentName . '</span>';
            }
            $html[] = '    </button>';
            $html[] = '    <div id="' . htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') . '" class="dropdown-menu dropdown-menu-end languages weline-i18n-switcher-panel" role="menu" aria-labelledby="' . htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') . '">';
            $html[] = '        <div class="px-2 pt-2 pb-1">';
            $html[] = '            <input type="text" class="form-control weline-language-search" placeholder="' . htmlspecialchars(__('搜索语言'), ENT_QUOTES, 'UTF-8') . '" autocomplete="off">';
            $html[] = '        </div>';

            foreach ($welineLanguages as $code => $language) {
                $code = (string)$code;
                $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                $flag = self::sanitizeInlineFlagMarkup((string)($language['flag'] ?? ''));
                $name = htmlspecialchars((string)($language['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $active = $currentCode === $code ? ' active' : '';
                $searchText = htmlspecialchars(strtolower($code . ' ' . strip_tags((string)($language['name'] ?? ''))), ENT_QUOTES, 'UTF-8');
                $href = htmlspecialchars(self::buildLanguageHref($currentPath, $currentSearch, $code, $currentCurrency, $backendRoute), ENT_QUOTES, 'UTF-8');
                if ($renderFor === 'js') {
                    $html[] = '        <a href="' . $href . '" data-language-option="1" data-lang="' . $safeCode . '" data-search="' . $searchText . '" class="dropdown-item notify-item language-option weline-language-option' . $active . '">';
                } else {
                    $html[] = '        <a href="' . $href . '" data-language-option="1" data-lang="' . $safeCode . '" data-search="' . $searchText . '" class="dropdown-item notify-item weline-language-option' . $active . '">';
                }
                $html[] = '            ' . $flag . '<span class="align-middle"> ' . $name . '</span>';
                $html[] = '        </a>';
            }

            $html[] = '    </div>';
            $html[] = '</div>';
            $html[] = '<style>';
            $html[] = '.weline-i18n-switcher-panel{min-width:320px;max-width:min(420px,calc(100vw - 16px));padding:0.5rem;background:var(--backend-color-card-bg,var(--backend-color-bg-primary,#fff));border:1px solid var(--backend-color-card-border,var(--backend-color-border-light,#e9ecef));border-radius:var(--backend-border-radius-xl,1rem);box-shadow:var(--backend-dropdown-shadow,0 10px 30px rgba(0,0,0,.12));}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search{min-height:var(--backend-form-control-height,44px);height:var(--backend-form-control-height,44px);padding:0.5rem 0.85rem;line-height:var(--backend-line-height-base,1.5);border:1px solid var(--backend-color-input-border,var(--backend-color-border,#ced4da));border-radius:var(--backend-border-radius-md,0.5rem);background:var(--backend-color-input-bg,var(--backend-color-bg-primary,#fff));color:var(--backend-color-text-primary,#212529);font-size:var(--backend-font-size-base,.875rem);box-shadow:none;transition:border-color .18s ease,box-shadow .18s ease,background-color .18s ease;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search::placeholder{color:var(--backend-color-text-placeholder,var(--backend-color-text-tertiary,#adb5bd));opacity:1;}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-search:focus{border-color:var(--backend-color-input-focus-border,var(--backend-color-primary,#556ee6));background:var(--backend-color-bg-primary,#fff);color:var(--backend-color-text-primary,#212529);box-shadow:var(--backend-focus-ring,0 0 0 0.2rem rgba(85,110,230,.25));}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option{display:flex;align-items:center;gap:0.5rem;padding:0.55rem 0.75rem;border-radius:var(--backend-border-radius-md,0.5rem);color:var(--backend-color-text-secondary,#495057);line-height:var(--backend-line-height-base,1.5);}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option:hover,.weline-i18n-switcher-panel .weline-language-option.active{background:var(--backend-color-bg-secondary,#f8f9fa);color:var(--backend-color-text-primary,#212529);}';
            $html[] = '.weline-i18n-switcher-panel .weline-language-option .align-middle{display:inline-flex;align-items:center;line-height:var(--backend-line-height-base,1.5);}';
            $html[] = '</style>';
            $html[] = '<script>(function(){';
            $html[] = 'var currentScript=document.currentScript;var root=null;';
            $html[] = 'if(currentScript){var node=currentScript.previousElementSibling;while(node&&!node.hasAttribute("data-i18n-switcher-id")){node=node.previousElementSibling;}root=node;}';
            $html[] = 'if(!root||root.dataset.welineI18nNative==="1"){return;}root.dataset.welineI18nNative="1";';
            $html[] = 'var toggle=root.querySelector(".weline-i18n-switcher-toggle");';
            $html[] = 'var panel=root.querySelector(".weline-i18n-switcher-panel");';
            $html[] = 'var input=root.querySelector(".weline-language-search");';
            $html[] = 'if(!toggle||!panel){return;}';
            $html[] = 'var options=function(){return root.querySelectorAll(".weline-language-option");};';
            $html[] = 'var emptyId="weline-language-empty-' . $switcherId . '";';
            $html[] = 'function ensureEmpty(){var empty=root.querySelector("#"+emptyId);if(!empty){empty=document.createElement("div");empty.id=emptyId;empty.className="dropdown-item text-muted small";empty.style.display="none";empty.textContent="' . addslashes(__('无匹配语言')) . '";panel.appendChild(empty);}return empty;}';
            $html[] = 'function filter(){if(!input){return;}var kw=String(input.value||"").trim().toLowerCase();var visible=0;options().forEach(function(opt){var search=(opt.getAttribute("data-search")||"").toLowerCase();var ok=(kw==="")||search.indexOf(kw)!==-1;opt.style.display=ok?"":"none";if(ok){visible++;}});var empty=ensureEmpty();empty.style.display=visible===0?"":"none";}';
            $html[] = 'function openMenu(){panel.classList.add("show");toggle.setAttribute("aria-expanded","true");if(input){input.value="";filter();setTimeout(function(){try{input.focus();}catch(e){}},0);}}';
            $html[] = 'function closeMenu(reason){panel.classList.remove("show");toggle.setAttribute("aria-expanded","false");if(input){input.value="";filter();}}';
            $html[] = 'function isOpen(){return panel.classList.contains("show");}';
            $html[] = 'toggle.addEventListener("click",function(e){e.stopPropagation();if(isOpen()){closeMenu("toggle-click");}else{openMenu();}});';
            $html[] = 'function stopInside(e){e.stopPropagation();}';
            $html[] = 'panel.addEventListener("pointerdown",function(e){stopInside(e);});';
            $html[] = 'panel.addEventListener("mousedown",stopInside);';
            $html[] = 'panel.addEventListener("click",stopInside);';
            $html[] = 'if(input){input.addEventListener("input",function(){filter();});input.addEventListener("pointerdown",stopInside);input.addEventListener("mousedown",stopInside);input.addEventListener("click",stopInside);}';
            $html[] = 'function isFromRoot(ev){if(!ev){return false;}if(typeof ev.composedPath==="function"){var path=ev.composedPath();return Array.isArray(path)&&path.indexOf(root)!==-1;}return root.contains(ev.target);}';
            $html[] = 'document.addEventListener("pointerdown",function(ev){if(!isOpen()){return;}if(isFromRoot(ev)){return;}closeMenu("doc-pointerdown-outside");},true);';
            $html[] = 'document.addEventListener("mousedown",function(ev){if(!isOpen()){return;}var fromRoot=isFromRoot(ev);if(fromRoot){return;}closeMenu("doc-mousedown-outside");},true);';
            $html[] = 'document.addEventListener("click",function(ev){if(!isOpen()){return;}var fromRoot=isFromRoot(ev);if(fromRoot){return;}closeMenu("doc-click-outside");},true);';
            $html[] = 'document.addEventListener("keydown",function(e){if(e.key!=="Escape"||!isOpen()){return;}closeMenu();try{toggle.focus();}catch(err){}});';
            $html[] = 'function buildLangHrefFallback(lang){var pathname=window.location.pathname||"/";var search=window.location.search||"";var pathParts=String(pathname||"/").split("/").filter(Boolean);var currencyPattern=/^[A-Z]{3}$/;var langPattern=/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;var backendKey=String(' . $backendRouteJson . '||(window.site&&window.site.area)||(window.Weline&&window.Weline.config&&window.Weline.config.url&&window.Weline.config.url.adminArea)||"");var currency="";for(var i=0;i<pathParts.length;i++){if(currencyPattern.test(pathParts[i])){currency=pathParts[i].toUpperCase();break;}}if(!currency){currency=((window.__WelineThemeConfig&&window.__WelineThemeConfig.currentCurrency)||"CNY").toUpperCase();}var prefixIndex=-1;if(backendKey){prefixIndex=pathParts.findIndex(function(part){return !langPattern.test(part)&&!currencyPattern.test(part)&&String(part).toLowerCase()===backendKey.toLowerCase();});}var prefixSegment=prefixIndex>=0?pathParts[prefixIndex]:backendKey;var remain=[];pathParts.forEach(function(part,index){if(langPattern.test(part)||currencyPattern.test(part)){return;}if(index===prefixIndex){return;}remain.push(part);});if(prefixSegment){return "/"+prefixSegment+"/"+currency+"/"+lang+(remain.length?"/"+remain.join("/"):"")+search;}return "/"+currency+"/"+lang+(remain.length?"/"+remain.join("/"):"")+search;}';
            $html[] = $defaultAwareBuildLangHrefFallbackJs;
            $html[] = 'function buildLangHref(lang){var pathname=window.location.pathname||"/";var search=window.location.search||"";if(window.WelineI18n&&typeof window.WelineI18n.buildLanguageUrl==="function"){return window.WelineI18n.buildLanguageUrl(lang,pathname,search);}return buildLangHrefFallback(lang);}';
            $html[] = 'root.querySelectorAll("[data-language-option]").forEach(function(opt){';
            $html[] = 'var code=opt.getAttribute("data-lang")||"";if(!code){return;}';
            $html[] = 'var href=buildLangHref(code);if(href){opt.setAttribute("href",href);}';
            $html[] = 'if(opt.dataset.welineLangBound==="1"){return;}opt.dataset.welineLangBound="1";';
            $html[] = 'opt.addEventListener("click",function(event){';
            $html[] = 'event.preventDefault();';
            $html[] = 'var attrHref=opt.getAttribute("href")||"";';
            $html[] = 'var recomputed=buildLangHref(code)||"";';
            $html[] = 'var target=attrHref||href||recomputed;';
            $html[] = 'if(window.WelineI18n&&typeof window.WelineI18n.switchLang==="function"){window.WelineI18n.switchLang(code);return;}';
            $html[] = 'if(target){window.location.href=target;}';
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
                $html[] = 'function buildLangHrefFallback(lang){var pathname=window.location.pathname||"/";var search=window.location.search||"";var pathParts=String(pathname||"/").split("/").filter(Boolean);var currencyPattern=/^[A-Z]{3}$/;var langPattern=/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;var backendKey=String(' . $backendRouteJson . '||(window.site&&window.site.area)||(window.Weline&&window.Weline.config&&window.Weline.config.url&&window.Weline.config.url.adminArea)||"");var currency="";for(var i=0;i<pathParts.length;i++){if(currencyPattern.test(pathParts[i])){currency=pathParts[i].toUpperCase();break;}}if(!currency){currency=((window.__WelineThemeConfig&&window.__WelineThemeConfig.currentCurrency)||"CNY").toUpperCase();}var prefixIndex=-1;if(backendKey){prefixIndex=pathParts.findIndex(function(part){return !langPattern.test(part)&&!currencyPattern.test(part)&&String(part).toLowerCase()===backendKey.toLowerCase();});}var prefixSegment=prefixIndex>=0?pathParts[prefixIndex]:backendKey;var remain=[];pathParts.forEach(function(part,index){if(langPattern.test(part)||currencyPattern.test(part)){return;}if(index===prefixIndex){return;}remain.push(part);});if(prefixSegment){return "/"+prefixSegment+"/"+currency+"/"+lang+(remain.length?"/"+remain.join("/"):"")+search;}return "/"+currency+"/"+lang+(remain.length?"/"+remain.join("/"):"")+search;}';
                $html[] = $defaultAwareBuildLangHrefFallbackJs;
                $html[] = 'function buildLangHref(lang){var pathname=window.location.pathname||"/";var search=window.location.search||"";if(window.WelineI18n&&typeof window.WelineI18n.buildLanguageUrl==="function"){return window.WelineI18n.buildLanguageUrl(lang,pathname,search);}return buildLangHrefFallback(lang);}';
                $html[] = 'function hasDiff(lang){var currentEl=root.querySelector(".current-language");var should=toShort(lang);var active=root.querySelector("[data-language-option].active,.language-option.active,a[data-lang].active");var activeLang=active?(active.getAttribute("data-lang")||active.dataset.lang||""):"";if(currentEl&&String(currentEl.textContent||"").trim()!==should){return true;}if(activeLang!==lang){return true;}return false;}';
                $html[] = 'function rerender(lang){var currentEl=root.querySelector(".current-language");if(currentEl){currentEl.textContent=toShort(lang);}var opts=root.querySelectorAll("[data-language-option],.language-option,a[data-lang]");opts.forEach(function(opt){var code=opt.getAttribute("data-lang")||opt.dataset.lang||"";if(!code){return;}if(code===lang){opt.classList.add("active");}else{opt.classList.remove("active");}var href=buildLangHref(code);if(href){opt.setAttribute("href",href);}});}';
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
        };
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

    private static function getLanguageOptions(string $displayLocale, bool $isBackendArea, int $websiteId): array
    {
        $displayLocale = \trim($displayLocale) !== '' ? $displayLocale : 'zh_Hans_CN';
        $installedOnly = !$isBackendArea;
        $scopeKey = $isBackendArea ? 'backend' : 'frontend:' . $websiteId;
        $cacheKey = $scopeKey . '|' . $displayLocale . '|' . (int)$installedOnly;
        $now = \microtime(true);
        if (isset(self::$languageCache[$cacheKey]) && self::$languageCache[$cacheKey]['expires'] >= $now) {
            return self::$languageCache[$cacheKey]['languages'];
        }
        unset(self::$languageCache[$cacheKey]);

        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        $welineLanguages = $i18n->getLocalesWithFlagsDisplaySelf($displayLocale, 24, 18, $installedOnly, true);

        if ($isBackendArea) {
            $welineLanguages = self::filterBackendLanguages($welineLanguages);
        } elseif ($websiteId > 0) {
            $welineLanguages = self::filterFrontendLanguages($welineLanguages, $websiteId);
        }

        self::$languageCache[$cacheKey] = [
            'expires' => $now + self::SWITCHER_LANGUAGE_CACHE_TTL,
            'languages' => $welineLanguages,
        ];

        return $welineLanguages;
    }

    private static function filterBackendLanguages(array $welineLanguages): array
    {
        try {
            /** @var ActiveLocaleCodeProvider $activeLocaleCodeProvider */
            $activeLocaleCodeProvider = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
            $allowedMap = $activeLocaleCodeProvider->getInstalledActiveCodeMap();
            if ($allowedMap === []) {
                return $welineLanguages;
            }

            $allowedMapLower = [];
            foreach (\array_keys($allowedMap) as $allowedCode) {
                $allowedMapLower[\strtolower((string)$allowedCode)] = true;
            }

            $filteredLanguages = [];
            foreach ($welineLanguages as $languageCode => $languageData) {
                if (isset($allowedMap[(string)$languageCode]) || isset($allowedMapLower[\strtolower((string)$languageCode)])) {
                    $filteredLanguages[$languageCode] = $languageData;
                }
            }

            return $filteredLanguages;
        } catch (\Throwable) {
            return $welineLanguages;
        }
    }

    private static function filterFrontendLanguages(array $welineLanguages, int $websiteId): array
    {
        $websiteLanguageCodes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
        if (!is_array($websiteLanguageCodes) || $websiteLanguageCodes === []) {
            return $welineLanguages;
        }

        $allowedMap = [];
        foreach ($websiteLanguageCodes as $websiteLanguageCode) {
            $websiteLanguageCode = (string)$websiteLanguageCode;
            if ($websiteLanguageCode !== '') {
                $allowedMap[$websiteLanguageCode] = true;
            }
        }
        if ($allowedMap === []) {
            return $welineLanguages;
        }

        $filteredLanguages = [];
        foreach ($welineLanguages as $languageCode => $languageData) {
            if (isset($allowedMap[(string)$languageCode])) {
                $filteredLanguages[$languageCode] = $languageData;
            }
        }

        return $filteredLanguages !== [] ? $filteredLanguages : $welineLanguages;
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

    private static function buildLanguageHref(
        string $path,
        string $search,
        string $targetLang,
        string $fallbackCurrency = 'CNY',
        string $preferredPrefix = ''
    ): string {
        $preferredPrefix = trim($preferredPrefix, '/');
        $path = (string)(parse_url($path, PHP_URL_PATH) ?: $path ?: '/');
        $pathParts = array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
        $langPattern = '/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i';
        $currencyPattern = '/^[A-Z]{3}$/';
        $prefixIndex = -1;

        if ($preferredPrefix !== '') {
            foreach ($pathParts as $index => $part) {
                if (!preg_match($currencyPattern, $part)
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
            if (preg_match($currencyPattern, $part)) {
                $detectedCurrency = strtoupper($part);
                break;
            }
        }

        $remain = [];
        foreach ($pathParts as $index => $part) {
            if (preg_match($currencyPattern, $part) || preg_match($langPattern, $part)) {
                continue;
            }
            if ($index === $prefixIndex) {
                continue;
            }
            $remain[] = $part;
        }

        $currency = strtoupper($detectedCurrency ?: $fallbackCurrency ?: 'CNY');
        $defaultCurrency = self::defaultCurrency();
        $defaultLanguage = self::defaultLanguage();
        $out = [];
        if ($prefix !== '') {
            $out[] = $prefix;
        }
        if ($currency !== '' && $currency !== $defaultCurrency) {
            $out[] = $currency;
        }
        if (!self::sameLanguage($targetLang, $defaultLanguage)) {
            $out[] = $targetLang;
        }
        if ($remain !== []) {
            array_push($out, ...$remain);
        }

        $normalizedSearch = self::sanitizeLanguageSearch($search);
        return '/' . implode('/', $out) . ($normalizedSearch !== '' ? '?' . $normalizedSearch : '');
    }

    private static function defaultCurrency(): string
    {
        $currency = strtoupper(trim((string)(
            w_env('website.currency', '')
            ?: Env::get('currency', 'CNY')
            ?: 'CNY'
        )));
        return $currency !== '' ? $currency : 'CNY';
    }

    private static function defaultLanguage(): string
    {
        $language = trim((string)(
            w_env('website.language', '')
            ?: Env::get('locale', Env::get('lang', 'zh_Hans_CN'))
            ?: 'zh_Hans_CN'
        ));
        return $language !== '' ? str_replace('-', '_', $language) : 'zh_Hans_CN';
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
        return '<p><code>&lt;w:i18n:language:switcher /&gt;</code> 通用语言切换下拉标签</p>';
    }
}
