<?php
declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
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
            $welineLanguages = $i18n->getLocalesWithFlagsDisplaySelf(Cookie::getLangLocal(), 24, 18, true, true);

            $websiteId = 0;
            try {
                /** @var Request $request */
                $request = ObjectManager::getInstance(Request::class);
                $websiteId = (int)($request->getData('website_id') ?? 0);
            } catch (\Throwable) {
                $websiteId = 0;
            }

            if ($websiteId > 0) {
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

            $currentCode = Cookie::getLang();
            $welineCurrentLanguage = ['code' => 'zh_Hans_CN', 'name' => '中文', 'flag' => ''];
            if (isset($welineLanguages[$currentCode])) {
                $welineCurrentLanguage = $welineLanguages[$currentCode];
                $welineCurrentLanguage['code'] = $currentCode;
            }

            $currentCode = (string)($welineCurrentLanguage['code'] ?? '');
            $currentName = htmlspecialchars((string)($welineCurrentLanguage['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $currentFlag = (string)($welineCurrentLanguage['flag'] ?? '');
            $renderFor = strtolower(trim((string)($attributes['for'] ?? '')));
            $switcherId = 'weline-i18n-switcher-' . substr(md5((string)$websiteId . '|' . $currentCode . '|' . json_encode(array_keys($welineLanguages))), 0, 12);
            // #region agent log
            try {
                $debugLogFile = dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'debug-27e05e.log';
                $debugPayload = [
                    'sessionId' => '27e05e',
                    'runId' => 'run1',
                    'hypothesisId' => 'H5',
                    'location' => 'Weline/I18n/Taglib/LanguageSwitcher.php:callback',
                    'message' => 'server-render-switcher',
                    'data' => [
                        'switcherId' => $switcherId,
                        'renderFor' => $renderFor,
                        'websiteId' => $websiteId,
                    ],
                    'timestamp' => (int)(microtime(true) * 1000),
                ];
                file_put_contents($debugLogFile, json_encode($debugPayload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
            } catch (\Throwable) {
            }
            // #endregion
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
            $html[] = '// #region agent log';
            $html[] = 'function dbg(hypothesisId,message,data){fetch(\'http://127.0.0.1:7273/ingest/516ee328-a532-4da8-911d-8a8358883f7f\',{method:\'POST\',headers:{\'Content-Type\':\'application/json\',\'X-Debug-Session-Id\':\'27e05e\'},body:JSON.stringify({sessionId:\'27e05e\',runId:\'run1\',hypothesisId:hypothesisId,location:\'Weline/I18n/Taglib/LanguageSwitcher.php:script-native\',message:message,data:data||{},timestamp:Date.now()})}).catch(function(){});}';
            $html[] = '// #endregion';
            $html[] = 'dbg("H0","init",{switcherId:root.getAttribute("data-i18n-switcher-id"),hasToggle:!!toggle,hasPanel:!!panel});';
            $html[] = 'function ensureEmpty(){var empty=root.querySelector("#"+emptyId);if(!empty){empty=document.createElement("div");empty.id=emptyId;empty.className="dropdown-item text-muted small";empty.style.display="none";empty.textContent=' . json_encode((string)__('没有匹配语言')) . ';panel.appendChild(empty);}return empty;}';
            $html[] = 'function filter(){if(!input){return;}var kw=String(input.value||"").trim().toLowerCase();var visible=0;options().forEach(function(opt){var search=(opt.getAttribute("data-search")||"").toLowerCase();var ok=(kw==="")||search.indexOf(kw)!==-1;opt.style.display=ok?"":"none";if(ok){visible++;}});var empty=ensureEmpty();empty.style.display=visible===0?"":"none";}';
            $html[] = 'function openMenu(){panel.classList.add("show");toggle.setAttribute("aria-expanded","true");if(input){input.value="";filter();setTimeout(function(){try{input.focus();}catch(e){}},0);}}';
            $html[] = 'function closeMenu(reason){dbg("H4","closeMenu",{reason:reason||"unknown",isOpen:panel.classList.contains("show")});panel.classList.remove("show");toggle.setAttribute("aria-expanded","false");if(input){input.value="";filter();}}';
            $html[] = 'function isOpen(){return panel.classList.contains("show");}';
            $html[] = 'toggle.addEventListener("click",function(e){e.stopPropagation();var before=isOpen();dbg("H1","toggle-click",{beforeOpen:before,targetTag:(e.target&&e.target.tagName)||""});if(before){closeMenu("toggle-click");}else{openMenu();dbg("H1","openMenu-by-toggle",{afterOpen:isOpen()});}});';
            $html[] = 'function stopInside(e){e.stopPropagation();}';
            $html[] = 'panel.addEventListener("pointerdown",function(e){dbg("H2","panel-pointerdown",{targetTag:(e.target&&e.target.tagName)||"",targetClass:(e.target&&e.target.className)||""});stopInside(e);});';
            $html[] = 'panel.addEventListener("mousedown",stopInside);';
            $html[] = 'panel.addEventListener("click",stopInside);';
            $html[] = 'if(input){input.addEventListener("input",function(){dbg("H2","input-change",{valueLength:String(input.value||"").length});filter();});input.addEventListener("pointerdown",stopInside);input.addEventListener("mousedown",stopInside);input.addEventListener("click",stopInside);}';
            $html[] = 'function isFromRoot(ev){if(!ev){return false;}if(typeof ev.composedPath==="function"){var path=ev.composedPath();return Array.isArray(path)&&path.indexOf(root)!==-1;}return root.contains(ev.target);}';
            $html[] = 'document.addEventListener("pointerdown",function(ev){if(!isOpen()){return;}var fromRoot=isFromRoot(ev);dbg("H3","doc-pointerdown",{fromRoot:fromRoot,targetTag:(ev.target&&ev.target.tagName)||"",targetClass:(ev.target&&ev.target.className)||""});if(fromRoot){return;}closeMenu("doc-pointerdown-outside");},true);';
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
