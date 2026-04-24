<?php
declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\Taglib\TaglibInterface;

class LanguageSwitcher implements TaglibInterface
{
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
            /** @var I18n $i18n */
            $i18n = ObjectManager::getInstance(I18n::class);
            $welineLanguages = $i18n->getLocalesWithFlagsDisplaySelf(State::getLangLocal(), 24, 18, true, true);

            $websiteId = 0;
            $isBackendArea = false;
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
            $currentFlag = (string)($welineCurrentLanguage['flag'] ?? '');
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

            $html = [];
            if ($renderFor === 'js') {
                $html[] = '<div class="dropdown d-inline-block" data-i18n-switcher data-i18n-switcher-id="' . $switcherId . '">';
            } else {
                $html[] = '<div class="dropdown d-inline-block" data-i18n-switcher-id="' . $switcherId . '">';
            }
            $html[] = '    <button type="button" id="' . htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') . '" class="btn header-item waves-effect weline-i18n-switcher-toggle position-relative" style="z-index:1056" aria-haspopup="true" aria-expanded="false" aria-controls="' . htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') . '">';
            if ($renderFor === 'js') {
                $html[] = '        ' . $currentFlag . '<span class="align-middle current-language"> ' . $currentDisplay . '</span>';
            } else {
                $html[] = '        ' . $currentFlag . '<span class="align-middle"> ' . $currentName . '</span>';
            }
            $html[] = '    </button>';
            $html[] = '    <div id="' . htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') . '" class="dropdown-menu dropdown-menu-end languages weline-i18n-switcher-panel" role="menu" aria-labelledby="' . htmlspecialchars($toggleId, ENT_QUOTES, 'UTF-8') . '">';
            $html[] = '        <div class="px-2 pt-2 pb-1">';
            $html[] = '            <input type="text" class="form-control form-control-sm weline-language-search" placeholder="' . htmlspecialchars((string)__('搜索语言名或语言码'), ENT_QUOTES, 'UTF-8') . '" autocomplete="off">';
            $html[] = '        </div>';

            foreach ($welineLanguages as $code => $language) {
                $code = (string)$code;
                $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                $flag = (string)($language['flag'] ?? '');
                $name = htmlspecialchars((string)($language['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $active = $currentCode === $code ? ' active' : '';
                $searchText = htmlspecialchars(strtolower($code . ' ' . strip_tags((string)($language['name'] ?? ''))), ENT_QUOTES, 'UTF-8');
                if ($renderFor === 'js') {
                    $html[] = '        <a href="javascript:void(0);" data-language-option="1" data-lang="' . $safeCode . '" data-search="' . $searchText . '" class="dropdown-item notify-item language-option weline-language-option' . $active . '">';
                } else {
                    $html[] = '        <a href="javascript:void(0);" data-language-option="1" data-lang="' . $safeCode . '" data-search="' . $searchText . '" class="dropdown-item notify-item weline-language-option' . $active . '">';
                }
                $html[] = '            ' . $flag . '<span class="align-middle"> ' . $name . '</span>';
                $html[] = '        </a>';
            }

            $html[] = '    </div>';
            $html[] = '</div>';
            $html[] = '<script>(function(){';
            $html[] = 'var currentScript=document.currentScript;var root=currentScript?currentScript.previousElementSibling:null;';
            $html[] = 'if(!root||!root.hasAttribute("data-i18n-switcher-id")||root.dataset.welineI18nNative==="1"){return;}root.dataset.welineI18nNative="1";';
            $html[] = 'var toggle=root.querySelector(".weline-i18n-switcher-toggle");';
            $html[] = 'var panel=root.querySelector(".weline-i18n-switcher-panel");';
            $html[] = 'var input=root.querySelector(".weline-language-search");';
            $html[] = 'if(!toggle||!panel){return;}';
            $html[] = 'var options=function(){return root.querySelectorAll(".weline-language-option");};';
            $html[] = 'var emptyId="weline-language-empty-' . $switcherId . '";';
            $html[] = 'function ensureEmpty(){var empty=root.querySelector("#"+emptyId);if(!empty){empty=document.createElement("div");empty.id=emptyId;empty.className="dropdown-item text-muted small";empty.style.display="none";empty.textContent=' . json_encode((string)__('没有匹配语言')) . ';panel.appendChild(empty);}return empty;}';
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
            $html[] = 'function buildLangHref(lang){var currentPath=window.location.pathname+window.location.search;if(typeof window.urlWithLang==="function"){return window.urlWithLang(currentPath,lang);}if(typeof window.inject_path==="function"){var po=currentPath.split("?")[0];var s=currentPath.indexOf("?")>-1?currentPath.split("?")[1]:"";return window.inject_path(po,lang,"lang")+(s?("?"+s):"");}return"";}';
            $html[] = 'root.querySelectorAll("[data-language-option]").forEach(function(opt){';
            $html[] = 'var code=opt.getAttribute("data-lang")||"";if(!code){return;}';
            $html[] = 'var href=buildLangHref(code);if(href){opt.setAttribute("href",href);}';
            $html[] = 'if(opt.dataset.welineLangBound==="1"){return;}opt.dataset.welineLangBound="1";';
            $html[] = 'opt.addEventListener("click",function(event){';
            $html[] = 'event.preventDefault();';
            $html[] = 'if(typeof window.select_language==="function"){window.select_language(code);return;}';
            $html[] = 'var target=opt.getAttribute("href")||href||buildLangHref(code);if(target){window.location.href=target;}';
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
                $html[] = 'function buildLangHref(lang){var currentPath=window.location.pathname+window.location.search;if(typeof window.urlWithLang==="function"){return window.urlWithLang(currentPath,lang);}if(typeof window.inject_path==="function"){var po=currentPath.split("?")[0];var s=currentPath.indexOf("?")>-1?currentPath.split("?")[1]:"";return window.inject_path(po,lang,"lang")+(s?("?"+s):"");}return"";}';
                $html[] = 'function hasDiff(lang){var currentEl=root.querySelector(".current-language");var should=toShort(lang);var active=root.querySelector("[data-language-option].active,.language-option.active,a[data-lang].active");var activeLang=active?(active.getAttribute("data-lang")||active.dataset.lang||""):"";if(currentEl&&String(currentEl.textContent||"").trim()!==should){return true;}if(activeLang!==lang){return true;}return false;}';
                $html[] = 'function rerender(lang){var currentEl=root.querySelector(".current-language");if(currentEl){currentEl.textContent=toShort(lang);}var opts=root.querySelectorAll("[data-language-option],.language-option,a[data-lang]");opts.forEach(function(opt){var code=opt.getAttribute("data-lang")||opt.dataset.lang||"";if(!code){return;}if(code===lang){opt.classList.add("active");}else{opt.classList.remove("active");}var href=buildLangHref(code);if(href){opt.setAttribute("href",href);}});}';
                $html[] = 'function reconcileOnce(){var lang=detectLang();if(hasDiff(lang)){rerender(lang);}}';
                $html[] = 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){setTimeout(reconcileOnce,120);},{once:true});}else{setTimeout(reconcileOnce,120);}';
                $html[] = '})();</script>';
            }

            return implode("\n", $html);
        };
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
