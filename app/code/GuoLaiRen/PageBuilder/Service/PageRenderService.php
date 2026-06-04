<?php

declare(strict_types=1);

/**
 * 椤甸潰娓叉煋鏈嶅姟
 * 
 * 缁熶竴娓叉煋閫昏緫锛岀‘淇濆彲瑙嗗寲缂栬緫鍣ㄩ瑙堝拰姝ｅ紡涓婄嚎椤甸潰鏁堟灉涓€鑷?
 * 
 * 娓叉煋妯″紡锛?
 * - visual: 鍙鍖栫紪杈戞ā寮忥紙甯︽嫋鎷芥彃妲藉鍣ㄣ€佺粍浠跺寘瑁呭櫒锛?
 * - preview: 棰勮妯″紡锛堢函鍑€娓叉煋锛屽彲鏌ョ湅鏈彂甯冮〉闈紝甯﹂瑙堟爣璁拌剼鏈級
 * - live: 姝ｅ紡涓婄嚎妯″紡锛堢函鍑€娓叉煋锛屼粎宸插彂甯冮〉闈級
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use GuoLaiRen\PageBuilder\Service\Component\ComponentResolver;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;

class PageRenderService
{
    /** 娓叉煋妯″紡甯搁噺 */
    public const MODE_VISUAL = 'visual';   // 鍙鍖栫紪杈戞ā寮?
    public const MODE_PREVIEW = 'preview'; // 棰勮妯″紡
    public const MODE_LIVE = 'live';       // 姝ｅ紡涓婄嚎妯″紡
    
    private LayoutAssembler $layoutAssembler;
    private LayoutOwnerResolver $layoutOwnerResolver;
    private Page $pageModel;
    private Style $styleModel;
    private LocalDescription $localDescriptionModel;
    private TemplatePathResolver $pathResolver;
    private ComponentResolver $componentResolver;
    private LayoutConfigNormalizer $configNormalizer;
    private ?Request $request = null;
    private ?Template $template = null;
    
    /** @var array 妯℃澘鍙橀噺 */
    private array $templateVars = [];
    
    /** @var array 缁勪欢鏂囦欢鏄犲皠缂撳瓨 */
    private static array $componentFilesCache = [];
    
    public function __construct(
        LayoutAssembler $layoutAssembler,
        LayoutOwnerResolver $layoutOwnerResolver,
        Page $pageModel,
        Style $styleModel,
        LocalDescription $localDescriptionModel,
        ?TemplatePathResolver $pathResolver = null,
        ?ComponentResolver $componentResolver = null,
        ?LayoutConfigNormalizer $configNormalizer = null
    ) {
        $this->layoutAssembler = $layoutAssembler;
        $this->layoutOwnerResolver = $layoutOwnerResolver;
        $this->pageModel = $pageModel;
        $this->styleModel = $styleModel;
        $this->localDescriptionModel = $localDescriptionModel;
        $this->pathResolver = $pathResolver ?? TemplatePathResolver::getInstance();
        $this->componentResolver = $componentResolver ?? ComponentResolver::getInstance();
        $this->configNormalizer = $configNormalizer ?? LayoutConfigNormalizer::getInstance();
    }
    
    /**
     * 璁剧疆璇锋眰瀵硅薄锛堢敤浜庤幏鍙栧弬鏁帮級
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }
    
    /**
     * 鑾峰彇 Template 瀹炰緥
     */
    private function getTemplate(): Template
    {
        if ($this->template === null) {
            $this->template = Template::getInstance();
        }
        return $this->template;
    }
    
    /**
     * 璁剧疆妯℃澘鍙橀噺
     */
    private function assign(string $key, $value): void
    {
        $this->templateVars[$key] = $value;
        $this->getTemplate()->assign($key, $value);
    }
    
    /**
     * 娓叉煋妯℃澘
     */
    private function fetch(string $templatePath): string
    {
        $result = $this->getTemplate()->fetch($templatePath, $this->templateVars);
        return is_string($result) ? $result : '';
    }
    
    /**
     * 娓叉煋椤甸潰
     * 
     * @param Page $page 椤甸潰瀵硅薄
     * @param string $mode 娓叉煋妯″紡 (visual/preview/live)
     * @param string|null $locale 璇█浠ｇ爜
     * @param string|null $tempStyleCode 涓存椂鏍峰紡浠ｇ爜锛堢敤浜庨瑙堬級
     * @return string 娓叉煋鍚庣殑 HTML
     */
    public function render(
        Page $page,
        string $mode = self::MODE_LIVE,
        ?string $locale = null,
        ?string $tempStyleCode = null,
        ?int $virtualThemeId = null
    ): string {
        $profileStart = \microtime(true);
        $profileLast = $profileStart;
        $profile = [
            'page_id' => (int)$page->getId(),
            'mode' => $mode,
            'locale' => (string)($locale ?? ''),
            'render_mode' => (string)$page->getData(Page::schema_fields_RENDER_MODE),
            'style' => (string)($tempStyleCode ?: ($page->getData('style') ?: 'default')),
        ];
        $profileMark = static function (string $name) use (&$profile, &$profileLast, $profileStart): void {
            $now = \microtime(true);
            $profile[$name . '_ms'] = \round(($now - $profileLast) * 1000, 2);
            $profileLast = $now;
            $profile['elapsed_ms'] = \round(($now - $profileStart) * 1000, 2);
        };

        // 閲嶇疆妯℃澘鍙橀噺
        $this->templateVars = [];
        if ($virtualThemeId !== null && $virtualThemeId > 0) {
            $page->setData('virtual_theme_id', $virtualThemeId);
        }
        
        // 鑾峰彇鏍峰紡浠ｇ爜
        $styleCode = $tempStyleCode ?: ($page->getData('style') ?: 'default');
        
        // 鑾峰彇褰撳墠璇█銆傚彂甯冮〉娌℃湁鏄惧紡 locale 鏃讹紝浠ラ〉闈㈤粯璁よ瑷€涓哄噯锛岄伩鍏嶅悗鍙?Cookie 姹℃煋璁垮绔欑偣銆?        $pageDefaultLocale = \trim((string)$page->getData(Page::schema_fields_DEFAULT_LOCALE));
        $currentLocale = $locale ?: ($pageDefaultLocale !== '' ? $pageDefaultLocale : \Weline\Framework\Http\Cookie::getLang());
        
        // 鏋勫缓鏍峰紡閰嶇疆
        $finalSettings = $this->buildStyleSettings($page, $styleCode, $currentLocale, $tempStyleCode);
        $profileMark('style_settings');
        
        // 妫€鏌ユ槸鍚︿负铏氭嫙椤甸潰锛坕d=0锛岀敤浜庢ā鏉块瑙堢瓑鍦烘櫙锛?
        $isVirtualPage = !$page->getId();
        
        // 鑾峰彇甯冨眬閰嶇疆锛堥€氳繃 LayoutOwnerResolver 缁熶竴澶勭悊 layout_page_id 鍜?header/footer 缁ф壙锛?
        // 鍙鍖栫紪杈戞ā寮忎笅鍏佽璁块棶鑽夌鐘舵€侀椤电殑 header/footer
        // 棰勮鏃朵紶鍏?tempStyleCode锛屼娇鈥滄棤鑷畾涔夊竷灞€鈥濇椂鎸夊綋鍓嶉瑙堟牱寮忓姞杞介粯璁?header/footer锛岄伩鍏嶉〉闈?DB 涓?default 鏃朵粛鏄剧ず default 澶撮儴
        $forBackend = ($mode === self::MODE_VISUAL);
        $layoutConfig = $this->layoutOwnerResolver->getFullLayoutConfig($page, $forBackend, $tempStyleCode);
        $profileMark('layout_config');
        
        // 鑾峰彇甯冨眬鎷ユ湁鑰呴〉闈D锛堢敤浜庡彲瑙嗗寲缂栬緫鏃朵紶閫掔粰鑴氭湰锛?
        $layoutOwnerPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageId($page);
        $this->assign('layout_owner_page_id', $layoutOwnerPageId);
        
        // 鑾峰彇甯冨眬椤甸潰淇℃伅锛堝鏋滀娇鐢ㄥ閮ㄥ竷灞€椤甸潰锛?
        $layoutPageInfo = $isVirtualPage ? null : $this->layoutOwnerResolver->getLayoutPageInfo($page);
        $this->assign('layout_page_info', $layoutPageInfo);
        $profileMark('layout_owner_info');
        
        // 鑾峰彇鏈湴鍖栧唴瀹癸紙铏氭嫙椤甸潰璺宠繃鏁版嵁搴撴煡璇級
        $localizedContent = $isVirtualPage ? null : $this->getLocalizedContent($page, $currentLocale);
        $profileMark('localized_content');
        
        // 璁剧疆妯℃澘鍙橀噺
        $this->assign('page', $page);
        $this->assign('style_settings', $finalSettings);
        $this->assign('style', $finalSettings);
        $this->assign('is_preview', $mode !== self::MODE_LIVE);
        $this->assign('is_virtual_page', $isVirtualPage); // 鏍囪涓鸿櫄鎷熼〉闈?
        $this->assign('lang', $currentLocale);
        $this->assign('lang_local', $currentLocale);
        $this->assign('current_locale', $currentLocale);
        $this->assign('layout_config', $layoutConfig);
        $this->assign('render_mode', $mode);
        
        // 鑾峰彇瀵艰埅椤甸潰锛堢敤浜?header 缁勪欢锛?
        // 铏氭嫙椤甸潰杩斿洖绌烘暟缁勶紝閬垮厤鏁版嵁搴撴煡璇?
        $navigationPages = $isVirtualPage ? [] : $page->getNavigationPages([], 10);
        $this->assign('navigation_pages', $navigationPages);
        $profileMark('navigation_pages');
        
        // 濡傛灉鏄崥瀹㈢被鍨嬮〉闈㈡垨甯冨眬涓寘鍚崥瀹㈢粍浠讹紝鍔犺浇鍗氬鏁版嵁
        // 铏氭嫙椤甸潰璺宠繃鍗氬鏁版嵁鍔犺浇
        $hasBlogComponent = $this->hasBlogComponent($layoutConfig);
        if (!$isVirtualPage && ($page->isBlogType() || $hasBlogComponent)) {
            $this->loadBlogData($page);
        }
        $profileMark('blog_data');
        
        // 娓叉煋 header/content/footer
        $stylePath = "GuoLaiRen_PageBuilder::templates/style/{$styleCode}";
        
        // 鑾峰彇椤甸潰绫诲瀷
        $pageType = $page->getData(Page::schema_fields_TYPE);
        
        // 鑾峰彇椤甸潰绫诲瀷瀵瑰簲鐨勫竷灞€淇℃伅
        $layoutInfo = $this->getLayoutInfoForPageType($styleCode, $pageType);
        $this->assign('page_type', $pageType);
        $this->assign('layout_info', $layoutInfo);
        $profileMark('layout_info');
        
        // 濡傛灉椤甸潰娌℃湁鑷畾涔夊竷灞€閰嶇疆锛屽姞杞借椤甸潰绫诲瀷鐨勯粯璁ゅ竷灞€閰嶇疆
        // 娉ㄦ剰锛氶渶瑕佹鏌ュ尯鍩熸槸鍚︾湡鐨勬湁鏈夋晥缁勪欢锛岃€屼笉浠呬粎鏄潪绌烘暟缁?
        $hasCustomHeader = $this->regionHasValidComponents($layoutConfig['header'] ?? null);
        $hasCustomContent = $this->regionHasValidComponents($layoutConfig['content'] ?? null);
        $hasCustomFooter = $this->regionHasValidComponents($layoutConfig['footer'] ?? null);
        $hasCustomLayout = $hasCustomHeader || $hasCustomContent || $hasCustomFooter;
        
        // 濡傛灉 header銆乧ontent 鎴?footer 娌℃湁鏈夋晥缁勪欢锛屽皾璇曚粠榛樿閰嶇疆鍔犺浇
        if (!$hasCustomHeader || !$hasCustomContent || !$hasCustomFooter) {
            $defaultLayoutConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
            
            // 濡傛灉 header 涓虹┖锛屼娇鐢ㄩ粯璁?header
            if (!$hasCustomHeader && !empty($defaultLayoutConfig['header'])) {
                $layoutConfig['header'] = $defaultLayoutConfig['header'];
                $this->assign('using_default_header', true);
            }
            
            // 濡傛灉 content 涓虹┖锛屼娇鐢ㄩ粯璁?content
            if (!$hasCustomContent && !empty($defaultLayoutConfig['content'])) {
                $layoutConfig['content'] = $defaultLayoutConfig['content'];
                $this->assign('using_default_content', true);
            }
            
            // 濡傛灉 footer 涓虹┖锛屼娇鐢ㄩ粯璁?footer
            if (!$hasCustomFooter && !empty($defaultLayoutConfig['footer'])) {
                $layoutConfig['footer'] = $defaultLayoutConfig['footer'];
                $this->assign('using_default_footer', true);
            }
            
            // 鏇存柊甯冨眬閰嶇疆
            $this->assign('layout_config', $layoutConfig);
        }
        $profileMark('default_layout_merge');
        
        if (!$hasCustomLayout && $pageType) {
            // 鍔犺浇椤甸潰绫诲瀷鐨勯粯璁ゅ竷灞€閰嶇疆
            $defaultLayoutConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
            
            if (!empty($defaultLayoutConfig['header']) || 
                !empty($defaultLayoutConfig['content']) || 
                !empty($defaultLayoutConfig['footer'])) {
                $layoutConfig = $defaultLayoutConfig;
                $this->assign('layout_config', $layoutConfig);
                $this->assign('using_default_layout', true);
            }
        }
        $profileMark('default_layout_fallback');
        
        // 妫€鏌ユ槸鍚︿娇鐢ㄧ粍浠跺寲娓叉煋
        $useComponentRendering = !empty($layoutConfig) && (
            !empty($layoutConfig['header']) || 
            !empty($layoutConfig['content']) || 
            !empty($layoutConfig['footer'])
        );
        
        // 璋冭瘯淇℃伅
        $debugInfo = $this->buildDebugInfo($useComponentRendering, $layoutConfig);
        $profileMark('debug_info');
        
        if ($useComponentRendering) {
            // 浣跨敤缁勪欢鍖栨覆鏌?
            $headerHtml = $this->renderRegion('header', $layoutConfig, $styleCode, $page, $finalSettings, $stylePath, $mode);
            $profileMark('render_header');
            $contentHtml = $this->renderRegion('content', $layoutConfig, $styleCode, $page, $finalSettings, $stylePath, $mode, $localizedContent);
            $profileMark('render_content');
            $footerHtml = $this->renderRegion('footer', $layoutConfig, $styleCode, $page, $finalSettings, $stylePath, $mode);
            $profileMark('render_footer');
        } else {
            // 浣跨敤浼犵粺娓叉煋鏂瑰紡
            $headerHtml = $this->fetch("{$stylePath}/header.phtml");
            $profileMark('render_header');
            $contentHtml = $this->renderTraditionalContent($page, $stylePath, $localizedContent);
            $profileMark('render_content');
            $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
            $profileMark('render_footer');
        }
        
        // 鎻掑叆鑷畾涔変唬鐮?
        $headerHtml = $this->injectHeaderCustomCode($headerHtml, $page);
        $footerHtml = $this->injectFooterCustomCode($footerHtml, $page);
        $profileMark('custom_code');
        
        // 鏍规嵁妯″紡澶勭悊杈撳嚭
        $html = $this->finalizeOutput($headerHtml, $contentHtml, $footerHtml, $debugInfo, $page, $styleCode, $mode, $virtualThemeId);
        $profileMark('finalize_output');
        $profile['total_ms'] = \round((\microtime(true) - $profileStart) * 1000, 2);
        $profile['html_bytes'] = \strlen($html);
        $profile['component_counts'] = [
            'header' => $this->countRegionComponents($layoutConfig['header'] ?? []),
            'content' => $this->countRegionComponents($layoutConfig['content'] ?? []),
            'footer' => $this->countRegionComponents($layoutConfig['footer'] ?? []),
        ];
        if (\class_exists(RequestContext::class, false) && RequestContext::isInitialized()) {
            RequestContext::set('pagebuilder.render.profile', $profile);
        }
        if (($profile['total_ms'] ?? 0) >= 250) {
            $this->logSlowRenderProfile($profile);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function logSlowRenderProfile(array $profile): void
    {
        $stageMs = [];
        foreach ($profile as $key => $value) {
            if (!\is_scalar($value) || !\str_ends_with((string)$key, '_ms')) {
                continue;
            }
            $ms = (float)$value;
            if ($ms < 5.0) {
                continue;
            }
            $stageMs[(string)$key] = $ms;
        }
        \arsort($stageMs);

        $payload = [
            'page_id' => (int)($profile['page_id'] ?? 0),
            'mode' => (string)($profile['mode'] ?? ''),
            'locale' => (string)($profile['locale'] ?? ''),
            'render_mode' => (string)($profile['render_mode'] ?? ''),
            'style' => (string)($profile['style'] ?? ''),
            'total_ms' => (float)($profile['total_ms'] ?? 0),
            'html_bytes' => (int)($profile['html_bytes'] ?? 0),
            'component_counts' => $profile['component_counts'] ?? [],
            'slowest' => \array_slice($stageMs, 0, 10, true),
        ];

        \error_log('[PageBuilderRenderPerf] render ' . \json_encode(
            $payload,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    private function countRegionComponents(mixed $regionConfig): int
    {
        return \count($this->normalizeRegionConfig('', $regionConfig));
    }
    
    /**
     * 鑾峰彇椤甸潰绫诲瀷瀵瑰簲鐨勫竷灞€妯℃澘璺緞
     * 
     * 鏍规嵁鏍峰紡浠ｇ爜鍜岄〉闈㈢被鍨嬶紝鏌ユ壘瀵瑰簲鐨勫竷灞€妯℃澘鏂囦欢
     * 鏄犲皠鍏崇郴瀹氫箟鍦?style/{styleCode}/layouts/layouts.json 涓?
     * 
     * @param string $styleCode 鏍峰紡浠ｇ爜
     * @param string|null $pageType 椤甸潰绫诲瀷
     * @return string|null 甯冨眬妯℃澘璺緞锛屼笉瀛樺湪鍒欒繑鍥?null
     */
    private function getLayoutTemplateForPageType(string $styleCode, ?string $pageType): ?string
    {
        if (empty($pageType)) {
            return null;
        }
        
        // 璇诲彇甯冨眬閰嶇疆鏂囦欢
        $layoutsJsonPath = $this->pathResolver->getLayoutsJsonPath($styleCode);
        
        if (!file_exists($layoutsJsonPath)) {
            return null;
        }
        
        $layoutsConfig = json_decode(file_get_contents($layoutsJsonPath), true);
        
        if (empty($layoutsConfig['layouts'][$pageType])) {
            // 濡傛灉娌℃湁瀵瑰簲鐨勫竷灞€锛屾鏌ユ槸鍚︽湁 fallback
            $fallback = $layoutsConfig['fallback_layout'] ?? null;
            if ($fallback && !empty($layoutsConfig['layouts'][$fallback])) {
                $layoutFile = $layoutsConfig['layouts'][$fallback]['file'] ?? null;
            } else {
                return null;
            }
        } else {
            $layoutFile = $layoutsConfig['layouts'][$pageType]['file'] ?? null;
        }
        
        if (empty($layoutFile)) {
            return null;
        }
        
        // 鏋勫缓甯冨眬妯℃澘瀹屾暣璺緞
        $templatePath = "GuoLaiRen_PageBuilder::style/{$styleCode}/layouts/{$layoutFile}";
        
        // 楠岃瘉妯℃澘鏂囦欢鏄惁瀛樺湪
        $layoutsPath = $this->pathResolver->getLayoutsPath($styleCode);
        $fullPath = $layoutsPath . '/' . $layoutFile;
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        return $templatePath;
    }
    
    /**
     * 鑾峰彇椤甸潰绫诲瀷瀵瑰簲鐨勫竷灞€閰嶇疆淇℃伅
     * 
     * @param string $styleCode 鏍峰紡浠ｇ爜
     * @param string|null $pageType 椤甸潰绫诲瀷
     * @return array|null 甯冨眬閰嶇疆淇℃伅
     */
    public function getLayoutInfoForPageType(string $styleCode, ?string $pageType): ?array
    {
        if (empty($pageType)) {
            return null;
        }
        
        // 璇诲彇甯冨眬閰嶇疆鏂囦欢
        $layoutsJsonPath = $this->pathResolver->getLayoutsJsonPath($styleCode);
        
        if (!file_exists($layoutsJsonPath)) {
            return null;
        }
        
        $layoutsConfig = json_decode(file_get_contents($layoutsJsonPath), true);
        
        if (empty($layoutsConfig['layouts'][$pageType])) {
            // 濡傛灉娌℃湁瀵瑰簲鐨勫竷灞€锛屼娇鐢?fallback
            $fallback = $layoutsConfig['fallback_layout'] ?? null;
            if ($fallback && !empty($layoutsConfig['layouts'][$fallback])) {
                return [
                    'page_type' => $fallback,
                    'layout_info' => $layoutsConfig['layouts'][$fallback],
                    'is_fallback' => true,
                ];
            }
            return null;
        }
        
        return [
            'page_type' => $pageType,
            'layout_info' => $layoutsConfig['layouts'][$pageType],
            'is_fallback' => false,
        ];
    }
    
    /**
     * 鑾峰彇椤甸潰绫诲瀷鐨勯粯璁ゅ竷灞€閰嶇疆
     * 
     * 绠€鍖栭€昏緫锛氱洿鎺ヤ娇鐢ㄩ〉闈㈢被鍨嬩唬鐮佷綔涓烘枃浠跺悕
     * 渚嬪锛歜log_post 鈫?layouts/default/blog_post.json
     * 
     * @param string $styleCode 鏍峰紡浠ｇ爜
     * @param string|null $pageType 椤甸潰绫诲瀷
     * @return array 榛樿甯冨眬閰嶇疆 ['header' => [], 'content' => [], 'footer' => []]
     */
    public function getDefaultLayoutConfigForPageType(string $styleCode, ?string $pageType): array
    {
        $defaultConfig = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];
        
        if (empty($pageType)) {
            return $defaultConfig;
        }
        
        // 鐩存帴浣跨敤椤甸潰绫诲瀷浠ｇ爜浣滀负閰嶇疆鏂囦欢鍚?
        $configFilePath = $this->pathResolver->getLayoutConfigPath($styleCode, $pageType);
        
        if (!file_exists($configFilePath)) {
            // fallback 鍒?custom_page
            $configFilePath = $this->pathResolver->getLayoutConfigPath($styleCode, 'custom_page');
            if (!file_exists($configFilePath)) {
                return $defaultConfig;
            }
        }
        
        $configData = json_decode(file_get_contents($configFilePath), true);
        
        if (empty($configData['layout_config'])) {
            return $defaultConfig;
        }
        
        $pageConfig = $configData['layout_config'];
        
        // 澶勭悊缁ф壙锛坔eader/footer 浠庨椤电户鎵匡級
        $inheritRegions = $configData['inherit_regions'] ?? [];
        
        foreach (['header', 'footer'] as $region) {
            // 濡傛灉璇ュ尯鍩熶负绌烘暟缁勪笖闇€瑕佺户鎵?
            if (empty($pageConfig[$region]) && isset($inheritRegions[$region])) {
                $inheritFrom = $inheritRegions[$region];
                $inheritConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $inheritFrom);
                $pageConfig[$region] = $inheritConfig[$region] ?? [];
            }
        }
        
        return [
            'header' => $pageConfig['header'] ?? [],
            'content' => $pageConfig['content'] ?? [],
            'footer' => $pageConfig['footer'] ?? [],
        ];
    }
    
    /**
     * 妫€鏌ュ尯鍩熼厤缃槸鍚﹀寘鍚湁鏁堢殑缁勪欢
     * 
     * 澶勭悊涓ょ鏍煎紡锛?
     * 1. 鏁扮粍鏍煎紡锛歔{code: ..., enabled: ...}, ...]
     * 2. PageLayout 瀵煎嚭鏍煎紡锛歿component: ..., config: ...}
     * 
     * @param mixed $regionConfig 鍖哄煙閰嶇疆
     * @return bool 鏄惁鏈夋湁鏁堢粍浠?
     */
    private function regionHasValidComponents($regionConfig): bool
    {
        if (empty($regionConfig)) {
            return false;
        }
        
        // 濡傛灉鏄暟缁勬牸寮?[{code: ...}, ...]
        if (is_array($regionConfig) && isset($regionConfig[0])) {
            foreach ($regionConfig as $component) {
                if (!empty($component['code']) || !empty($component['component'])) {
                    return true;
                }
            }
            return false;
        }
        
        // 濡傛灉鏄?PageLayout 瀵煎嚭鏍煎紡 {component: ..., config: ...}
        if (is_array($regionConfig) && array_key_exists('component', $regionConfig)) {
            return !empty($regionConfig['component']);
        }
        
        // 濡傛灉鏄崟缁勪欢鏍煎紡 {code: ...}
        if (is_array($regionConfig) && isset($regionConfig['code'])) {
            return !empty($regionConfig['code']);
        }
        
        return false;
    }
    
    /**
     * 妫€鏌ュ竷灞€閰嶇疆涓槸鍚﹀寘鍚崥瀹㈢粍浠?
     */
    private function hasBlogComponent(array $layoutConfig): bool
    {
        $blogComponents = ['blog-list', 'blog-detail', 'blog-content', 'blog-sidebar'];
        
        foreach (['header', 'content', 'footer'] as $region) {
            if (!empty($layoutConfig[$region])) {
                foreach ($layoutConfig[$region] as $component) {
                    $code = $component['code'] ?? '';
                    if (in_array($code, $blogComponents) || strpos($code, 'blog') !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * 鏋勫缓鏍峰紡閰嶇疆
     */
    private function buildStyleSettings(Page $page, string $styleCode, string $currentLocale, ?string $tempStyleCode): array
    {
        $finalSettings = [];
        
        // 妫€鏌ユ槸鍚︿负铏氭嫙椤甸潰
        $isVirtualPage = !$page->getId();
        
        // 鍔犺浇鏍峰紡妯″瀷鑾峰彇榛樿閰嶇疆
        $style = clone $this->styleModel;
        $style->clear()
            ->where(Style::schema_fields_CODE, $styleCode)
            ->find()
            ->fetch();
        
        // 绗竴姝ワ細浣跨敤妯℃澘榛樿閰嶇疆鍊硷紙鏈€浣庝紭鍏堢骇锛?
        if ($style->getId()) {
            $parsed = $style->parseStyleConfig();
            $styleConfigs = $parsed['configs'] ?? [];
            
            foreach ($styleConfigs as $key => $config) {
                if (isset($config['default'])) {
                    $finalSettings[$key] = $config['default'];
                }
            }
        }
        
        // 绗簩姝ワ細鐢ㄩ〉闈繚瀛樼殑閰嶇疆瑕嗙洊锛堜腑绛変紭鍏堢骇锛?
        // 濡傛灉浣跨敤涓存椂鏍峰紡锛岃烦杩囬〉闈㈤厤缃紙鍙娇鐢ㄦā鏉块粯璁ゅ€硷級
        // 铏氭嫙椤甸潰涔熻烦杩囨姝ラ
        if (!$isVirtualPage && (!$tempStyleCode || $tempStyleCode === $page->getData('style'))) {
            $allStyleSettings = $page->getStyleSetting();
            if ($styleCode && isset($allStyleSettings[$styleCode])) {
                $rawSettings = $allStyleSettings[$styleCode];
                // 娓呯悊鍙兘瀛樺湪鐨勪笁灞傜粨鏋勶紝鍙繚鐣欐爣閲忓€?
                foreach ($rawSettings as $key => $value) {
                    if (!is_array($value)) {
                        $finalSettings[$key] = $value;
                    }
                }
            }
        }
        
        // 绗笁姝ワ細鐢ㄧ炕璇戠殑閰嶇疆瑕嗙洊锛堟渶楂樹紭鍏堢骇锛?
        // 铏氭嫙椤甸潰璺宠繃鏁版嵁搴撴煡璇?
        if (!$isVirtualPage) {
            $localizedContent = $this->getLocalizedContent($page, $currentLocale);
            if ($localizedContent && !empty($localizedContent['config'])) {
                $translatedConfig = is_string($localizedContent['config']) 
                    ? json_decode($localizedContent['config'] ?? '', true) 
                    : $localizedContent['config'];
                
                if (isset($translatedConfig['style_config']) && is_array($translatedConfig['style_config'])) {
                    foreach ($translatedConfig['style_config'] as $key => $value) {
                        $finalSettings[$key] = $value;
                    }
                }
            }
        }
        
        return $finalSettings;
    }
    
    /**
     * 鑾峰彇鏈湴鍖栧唴瀹?
     */
    private function getLocalizedContent(Page $page, string $locale): ?array
    {
        if (!$locale) {
            return null;
        }
        
        $localDesc = clone $this->localDescriptionModel;
        $localDesc->clear()
            ->where(LocalDescription::schema_fields_ID, $page->getId())
            ->where('local_code', $locale)
            ->find()
            ->fetch();
        
        if ($localDesc->getId()) {
            return [
                'content' => $localDesc->getData('content'),
                'config' => $localDesc->getData('config')
            ];
        }
        
        return null;
    }
    
    /**
     * 涓哄崥瀹㈢被鍨嬮〉闈㈠姞杞藉崥瀹㈡暟鎹?
     * 
     * 浼樺厛浣跨敤宸查€氳繃 Template::getInstance()->assign() 棰勮鐨勬暟鎹?
     * 濡傛灉娌℃湁棰勮鏁版嵁锛屽垯鑷姩鍔犺浇
     */
    private function loadBlogData(Page $page): void
    {
        $pageType = $page->getData(Page::schema_fields_TYPE);
        $template = $this->getTemplate();
        
        // 妫€鏌ユ槸鍚﹀凡鏈夐璁剧殑鍗氬鏁版嵁锛堢敱鎺у埗鍣ㄩ鍏堣缃級
        $existingBlogPosts = $template->getData('blog_posts');
        $existingCategories = $template->getData('blog_categories');
        $existingCurrentPost = $template->getData('current_post');
        $existingRelatedPosts = $template->getData('related_posts');
        $existingRecentPosts = $template->getData('recent_posts');
        
        // 鍗氬鏂囩珷鍒楄〃锛氫紭鍏堜娇鐢ㄩ璁炬暟鎹?
        if (!empty($existingBlogPosts)) {
            $this->assign('blog_posts', $existingBlogPosts);
        } else {
            $blogPosts = $page->getBlogPosts(20, 'published_at', 'DESC');
            $this->assign('blog_posts', $blogPosts);
        }
        
        // 鍗氬鍒嗙被锛氫紭鍏堜娇鐢ㄩ璁炬暟鎹?
        if (!empty($existingCategories)) {
            $this->assign('blog_categories', $existingCategories);
        } else {
            $blogCategories = $page->getBlogCategories();
            $this->assign('blog_categories', $blogCategories);
        }
        
        // 濡傛灉鏄崥瀹㈡枃绔犺鎯呴〉锛岃幏鍙栧綋鍓嶆枃绔?
        if ($pageType === Page::TYPE_BLOG || !empty($existingCurrentPost)) {
            if (!empty($existingCurrentPost)) {
                $this->assign('current_post', $existingCurrentPost);
                
                // 鐩稿叧鏂囩珷锛氫紭鍏堜娇鐢ㄩ璁炬暟鎹?
                if (!empty($existingRelatedPosts)) {
                    $this->assign('related_posts', $existingRelatedPosts);
                } elseif ($existingCurrentPost) {
                    $relatedPosts = $this->getRelatedBlogPosts($existingCurrentPost, 6);
                    $this->assign('related_posts', $relatedPosts);
                }
            } else {
                // 浠?URL 鍙傛暟鑾峰彇鏂囩珷 slug
                $slug = $this->request ? $this->request->getGet('slug') : null;
                if ($slug) {
                    $currentPost = $this->getBlogPostBySlug($slug);
                    $this->assign('current_post', $currentPost);
                    
                    // 鑾峰彇鐩稿叧鏂囩珷
                    if ($currentPost) {
                        $relatedPosts = $this->getRelatedBlogPosts($currentPost, 6);
                        $this->assign('related_posts', $relatedPosts);
                    }
                }
            }
        }
        
        // 濡傛灉鏄崥瀹㈠垎绫婚〉锛岃幏鍙栧綋鍓嶅垎绫诲拰鍒嗙被涓嬬殑鏂囩珷
        if ($pageType === Page::TYPE_BLOG_CATEGORY) {
            $categorySlug = $this->request ? $this->request->getGet('category') : null;
            if ($categorySlug) {
                $currentCategory = $this->getBlogCategoryBySlug($categorySlug);
                $this->assign('current_category', $currentCategory);
                
                if ($currentCategory) {
                    $categoryPosts = $this->getBlogPostsByCategory($currentCategory['category_id'], 20);
                    $this->assign('category_posts', $categoryPosts);
                }
            }
        }
        
        // 鏈€杩戞枃绔狅紙鐢ㄤ簬渚ц竟鏍忥級锛氫紭鍏堜娇鐢ㄩ璁炬暟鎹?
        if (!empty($existingRecentPosts)) {
            $this->assign('recent_posts', $existingRecentPosts);
        } else {
            $recentPosts = $page->getBlogPosts(10, 'published_at', 'DESC');
            $this->assign('recent_posts', $recentPosts);
        }
    }
    
    private function getBlogPostBySlug(string $slug): ?array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getPostBySlug', ['slug' => $slug, 'site_id' => $websiteId]);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getBlogCategoryBySlug(string $slug): ?array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getCategoryBySlug', ['slug' => $slug, 'site_id' => $websiteId]);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getBlogPostsByCategory(int $categoryId, int $limit = 20): array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            $result = w_query('blog', 'getPostList', [
                'site_id' => $websiteId,
                'category_id' => $categoryId,
                'page' => 1,
                'page_size' => $limit,
            ]);
            return $result['items'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 鑾峰彇鐩稿叧鍗氬鏂囩珷
     */
    private function getRelatedBlogPosts(array $currentPost, int $limit = 6): array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getRelatedPosts', [
                'category_id' => (int)($currentPost['category_id'] ?? 0),
                'exclude_post_id' => (int)($currentPost['post_id'] ?? 0),
                'site_id' => $websiteId,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 娓叉煋鍖哄煙
     */
    private function renderRegion(
        string $region,
        array $layoutConfig,
        string $styleCode,
        Page $page,
        array $styleSettings,
        string $stylePath,
        string $mode,
        ?array $localizedContent = null
    ): string {
        $regionConfig = $layoutConfig[$region] ?? [];
        
        // 瑙勮寖鍖栧竷灞€閰嶇疆缁撴瀯
        // PageLayout.exportConfig() 杩斿洖 header/footer 涓?{component: ..., config: ...}
        // 浣?renderRegionComponents() 鏈熸湜 [{code: ..., enabled: ..., config: ...}]
        if (!empty($regionConfig)) {
            $components = $this->normalizeRegionConfig($region, $regionConfig);
            if (!empty($components)) {
                return $this->renderRegionComponents(
                    $region, 
                    $components, 
                    $styleCode, 
                    $page, 
                    $styleSettings, 
                    $mode
                );
            }
        }
        
        // 鍥為€€鍒伴粯璁ゆā鏉挎垨鑷畾涔夊唴瀹?
        if ($region === 'content') {
            return $this->renderTraditionalContent($page, $stylePath, $localizedContent);
        }
        
        return $this->fetch("{$stylePath}/{$region}.phtml");
    }
    
    /**
     * 瑙勮寖鍖栧尯鍩熼厤缃粨鏋?
     * 
     * 灏嗕笉鍚屾牸寮忕殑閰嶇疆杞崲涓虹粺涓€鐨勭粍浠舵暟缁勬牸寮?
     * 
     * @param string $region 鍖哄煙鍚嶇О (header/content/footer)
     * @param mixed $config 鍖哄煙閰嶇疆
     * @return array 缁熶竴鏍煎紡鐨勭粍浠舵暟缁?
     */
    private function normalizeRegionConfig(string $region, $config): array
    {
        if (empty($config)) {
            return [];
        }
        
        // 濡傛灉宸茬粡鏄纭殑缁勪欢鏁扮粍鏍煎紡 [{code: ..., ...}, ...]
        if (is_array($config) && isset($config[0]) && isset($config[0]['code'])) {
            return $config;
        }
        
        // 濡傛灉鏄?PageLayout.exportConfig() 鏍煎紡鐨?header/footer: {component: ..., config: ...}
        if (is_array($config) && isset($config['component'])) {
            $component = $config['component'];
            if (empty($component)) {
                return [];
            }
            return [
                [
                    'code' => $component,
                    'enabled' => true,
                    'config' => $config['config'] ?? [],
                ]
            ];
        }
        
        // 濡傛灉鏄甫鏈?code 鐨勫崟缁勪欢閰嶇疆 {code: ..., config: ...}
        if (is_array($config) && isset($config['code'])) {
            return [$config];
        }
        
        // content 鍖哄煙鍙兘鐩存帴鏄粍浠舵暟缁勶紙涓嶉渶瑕佽浆鎹級
        if ($region === 'content' && is_array($config)) {
            // 妫€鏌ョ涓€涓厓绱犳槸鍚︽湁 code 鎴?component 閿?
            $firstItem = reset($config);
            if (is_array($firstItem)) {
                if (isset($firstItem['code'])) {
                    return $config;
                }
                if (isset($firstItem['component'])) {
                    // 杞崲鏍煎紡
                    return array_map(function($item) {
                        return [
                            'code' => $item['component'] ?? '',
                            'enabled' => $item['enabled'] ?? true,
                            'config' => $item['config'] ?? [],
                        ];
                    }, $config);
                }
            }
        }
        
        return [];
    }
    
    /**
     * 娓叉煋浼犵粺鍐呭锛堥潪缁勪欢鍖栵級
     */
    private function renderTraditionalContent(Page $page, string $stylePath, ?array $localizedContent): string
    {
        $customContent = '';
        if ($localizedContent && !empty($localizedContent['content'])) {
            $customContent = $localizedContent['content'];
        } else {
            $customContent = $page->getData(Page::schema_fields_CONTENT);
        }
        
        if (!empty($customContent)) {
            return $customContent;
        }
        
        return $this->fetch("{$stylePath}/content.phtml");
    }
    
    /**
     * 娓叉煋鍖哄煙缁勪欢
     */
    private function renderRegionComponents(
        string $region,
        array $components,
        string $styleCode,
        Page $page,
        array $styleSettings,
        string $mode
    ): string {
        if (empty($components)) {
            return '';
        }
        
        $html = '';
        $isVisualEditor = ($mode === self::MODE_VISUAL);
        
        // 缁勪欢浠ｇ爜鍒版枃浠剁殑鏄犲皠
        $componentFiles = $this->getComponentFilesMap($styleCode);
        
        $html .= "<!-- Rendering region: {$region}, styleCode: {$styleCode}, components: " . count($components) . ", mode: {$mode} -->\n";
        
        $componentIndex = 0;
        foreach ($components as $componentConfig) {
            $code = $componentConfig['code'] ?? '';
            $enabled = $componentConfig['enabled'] ?? true;
            $config = $componentConfig['config'] ?? [];
            $componentTemplateCode = $componentConfig['template_code'] ?? '';
            
            if (!$enabled || empty($code)) {
                $componentIndex++;
                continue;
            }
            
            // 纭畾浣跨敤鍝釜妯℃澘鐨勭粍浠舵枃浠?
            $useTemplateCode = $styleCode;
            
            // 鏌ユ壘缁勪欢鏂囦欢
            $componentFile = $componentFiles[$code] ?? null;
            
            // 馃敡 澶勭悊 {styleCode}-header/footer 鏍煎紡
            if (!$componentFile) {
                if ($code === $styleCode . '-header' || $code === 'header') {
                    $componentFile = $componentFiles['header-nav'] ?? null;
                } elseif ($code === $styleCode . '-footer' || $code === 'footer') {
                    $componentFile = $componentFiles['footer-links'] ?? null;
                }
            }
            
            // 馃敡 澶勭悊 Component 妯″瀷鐢熸垚鐨勭壒娈婃牸寮忥細{styleCode}_header_header, {styleCode}_footer_footer
            if (!$componentFile) {
                if (preg_match('/^' . preg_quote($styleCode, '/') . '_header_header$/i', $code)) {
                    $componentFile = $componentFiles['header-nav'] ?? null;
                } elseif (preg_match('/^' . preg_quote($styleCode, '/') . '_footer_(footer|links)$/i', $code)) {
                    $componentFile = $componentFiles['footer-links'] ?? null;
                }
            }
            
            // 馃敡 澶勭悊涓嬪垝绾挎牸寮忕殑缁勪欢浠ｇ爜锛圕omponent 妯″瀷鐢熸垚鐨勬牸寮忥級
            // 渚嬪锛歵pmst_header_nav -> header-nav
            if (!$componentFile && strpos($code, $styleCode . '_') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $codeWithDash = str_replace('_', '-', $codeWithoutPrefix);
                $componentFile = $componentFiles[$codeWithDash] ?? null;
            }
            
            // 灏濊瘯鍘绘帀妯℃澘鍓嶇紑锛堢牬鎶樺彿鏍煎紡锛?
            if (!$componentFile && strpos($code, $styleCode . '-') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
            }
            
            // 馃敡 灏濊瘯杞崲涓嬪垝绾夸负鐮存姌鍙峰悗鏌ユ壘
            if (!$componentFile && str_contains($code, '_')) {
                $codeWithDash = str_replace('_', '-', $code);
                $componentFile = $componentFiles[$codeWithDash] ?? null;
            }
            
            // 灏濊瘯浠庢寚瀹氱殑鍏朵粬妯℃澘鏌ユ壘
            if (!$componentFile && !empty($componentTemplateCode) && $componentTemplateCode !== $styleCode) {
                $otherComponentFiles = $this->getComponentFilesMap($componentTemplateCode);
                $componentFile = $otherComponentFiles[$code] ?? null;
                
                if (!$componentFile && strpos($code, $componentTemplateCode . '-') === 0) {
                    $codeWithoutPrefix = substr($code, strlen($componentTemplateCode) + 1);
                    $componentFile = $otherComponentFiles[$codeWithoutPrefix] ?? null;
                }
                
                if ($componentFile) {
                    $useTemplateCode = $componentTemplateCode;
                }
            }
            
            // 濡傛灉浠嶆湭鎵惧埌锛屽皾璇曢€氳繃 Component 妯″瀷瑙ｆ瀽锛堟敮鎸佹暟鎹簱娉ㄥ唽鐨勭粍浠讹級
            $componentPath = null;
            if (!$componentFile) {
                $modelResolution = $this->resolveComponentViaModel($code, $styleCode);
                if ($modelResolution) {
                    $componentPath = $modelResolution['path'];
                    $useTemplateCode = $modelResolution['style_code'];
                    $html .= "<!-- Component {$code} resolved via Component model -->\n";
                }
            }

            if (!$componentFile && !$componentPath) {
                $virtualThemeHtml = $this->renderVirtualThemeComponent(
                    $code,
                    $page,
                    \is_array($config) ? $config : [],
                    $styleSettings,
                    $region,
                    $componentIndex,
                    $isVisualEditor,
                    $mode
                );
                if ($virtualThemeHtml !== null) {
                    $html .= $virtualThemeHtml;
                    $componentIndex++;
                    continue;
                }
            }
            
            if (!$componentFile && !$componentPath) {
                $html .= "<!-- Component not found: {$code} (tried file-based and Component model) -->\n";
                $componentIndex++;
                continue;
            }
            
            // 鏋勫缓缁勪欢妯℃澘璺緞锛堝鏋滄湭閫氳繃 Component 妯″瀷瑙ｆ瀽锛?
            if (!$componentPath) {
                $componentPath = $this->pathResolver->getComponentTemplateReference($useTemplateCode, $componentFile);
            }
            
            // 浼犻€掓暟鎹埌缁勪欢
            $this->assign('page', $page);
            $this->assign('style', $styleSettings);
            $this->assign('style_settings', $styleSettings);
            $this->assign('component_config', $config);
            
            try {
                $componentHtml = $this->fetch($componentPath);
                
                if (empty($componentHtml)) {
                    $html .= "<!-- Component {$code} rendered but output is empty -->\n";
                } else {
                    // 鍦ㄥ彲瑙嗗寲缂栬緫鍣ㄦā寮忎笅锛屾坊鍔犵粍浠跺寘瑁呭櫒
                    if ($isVisualEditor) {
                        $escapedCode = htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                        $escapedRegion = htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                        $escapedPageType = htmlspecialchars((string)$page->getData(Page::schema_fields_TYPE), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                        // 瀛樺偍缁勪欢瀹為檯鎵€灞炵殑妯℃澘浠ｇ爜锛堢敤浜庤法妯℃澘缁勪欢缂栬緫锛?
                        $escapedStyleCode = htmlspecialchars($useTemplateCode, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                        $configScript = $this->buildVisualEditorComponentConfigScript(\is_array($config) ? $config : []);
                        $componentHtml = "<div class=\"tpmst-component-wrapper\" data-component=\"{$escapedCode}\" data-component-code=\"{$escapedCode}\" data-block-id=\"{$escapedCode}\" data-page-type=\"{$escapedPageType}\" data-region=\"{$escapedRegion}\" data-index=\"{$componentIndex}\" data-style-code=\"{$escapedStyleCode}\" tabindex=\"0\">" . $this->buildVisualEditorComponentActionsHtml() . $configScript . "{$componentHtml}</div>";
                    }
                    $html .= $componentHtml;
                    $html .= "<!-- Component {$code} rendered successfully -->\n";
                }
            } catch (\Throwable $e) {
                $html .= "<!-- Error rendering component {$code}: " . htmlspecialchars($e->getMessage()) . " -->\n";
            }
            
            $componentIndex++;
        }
        
        return $html;
    }
    
    /**
     * 鑾峰彇缁勪欢鏂囦欢鏄犲皠
     * 
     * 浣跨敤 ComponentResolver 鑾峰彇缁勪欢鏄犲皠
     */
    private function renderVirtualThemeComponent(
        string $code,
        Page $page,
        array $config,
        array $styleSettings,
        string $region,
        int $componentIndex,
        bool $isVisualEditor,
        string $mode
    ): ?string {
        $virtualThemeId = (int)$page->getData('virtual_theme_id');
        if ($virtualThemeId <= 0) {
            $virtualThemeId = $this->resolveActiveAiVirtualThemeId((int)$page->getData(Page::schema_fields_WEBSITE_ID));
        }
        if ($virtualThemeId <= 0) {
            return null;
        }

        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery()
            ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $code)
            ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
            ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        if ((int)$component->getId() <= 0) {
            return null;
        }

        $templateContent = $component->getTemplateContent();
        if (\trim($templateContent) === '') {
            return "<!-- Component {$code} resolved via Weline_Theme virtual theme but template is empty -->\n";
        }

        $defaultConfig = $component->getDefaultConfig();
        $componentConfig = $this->sanitizeSharedIdentityAssetConfig(
            $this->expandDottedComponentConfigValues($this->localizeVirtualThemeSharedComponentConfig(
                $region,
                \array_replace($defaultConfig, $config),
                $page,
                $code
            )),
            $region
        );
        $vars = \array_replace($this->templateVars, $componentConfig, [
            'page' => $page,
            'style' => $styleSettings,
            'style_settings' => $styleSettings,
            'component_config' => $componentConfig,
            'block' => $this->getTemplate(),
            'render_mode' => $mode,
            'virtual_theme_id' => $virtualThemeId,
        ]);

        try {
            $componentHtml = $this->renderPhtmlString($templateContent, $vars);
            $componentHtml = $this->applyVirtualThemeConfigOverridesToStaticHtml($componentHtml, $defaultConfig, $componentConfig);
            $componentHtml = $this->applyVirtualThemeSharedStaticLocaleGuards($componentHtml, $region, $componentConfig);
            $componentHtml = $this->applyVirtualThemeGeneratedComponentRuntimeFramework($componentHtml, $code);
        } catch (\Throwable $throwable) {
            return '<!-- Error rendering virtual theme component ' . \htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . ': ' . \htmlspecialchars($throwable->getMessage(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . " -->\n";
        }
        $marker = "<!-- Component {$code} resolved via Weline_Theme virtual theme -->\n";
        if ($isVisualEditor) {
            $escapedCode = \htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $escapedRegion = \htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $escapedPageType = \htmlspecialchars((string)$page->getData(Page::schema_fields_TYPE), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $configScript = $this->buildVisualEditorComponentConfigScript($componentConfig);
            $componentHtml = "<div class=\"tpmst-component-wrapper\" data-component=\"{$escapedCode}\" data-component-code=\"{$escapedCode}\" data-block-id=\"{$escapedCode}\" data-page-type=\"{$escapedPageType}\" data-region=\"{$escapedRegion}\" data-index=\"{$componentIndex}\" data-style-code=\"virtual-theme\" tabindex=\"0\">" . $this->buildVisualEditorComponentActionsHtml() . $configScript . "{$componentHtml}</div>";
        }

        return $marker . $componentHtml . "\n<!-- Component {$code} rendered successfully -->\n";
    }

    private function applyVirtualThemeGeneratedComponentRuntimeFramework(string $html, string $componentCode = ''): string
    {
        if ($html === '') {
            return $html;
        }

        $html = $this->flattenRenderedVirtualThemeNestedResponsiveMedia($html);
        $html = $this->applyVirtualThemeSharedHeaderMobileMenuRuntimeGuard($html, $componentCode);
        if (\stripos($html, 'data-pb-ai-action') !== false && \strpos($html, 'pb:cta') === false) {
            $html .= "\n" . $this->buildVirtualThemeAiActionBridgeScript();
        }

        return $html;
    }

    private function applyVirtualThemeSharedHeaderMobileMenuRuntimeGuard(string $html, string $componentCode): string
    {
        if ($html === '' || \strpos($html, 'data-pb-ai-header-mobile-compact') !== false) {
            return $html;
        }

        $normalizedCode = \str_replace('/', '-', \strtolower(\trim($componentCode)));
        if ($normalizedCode !== 'header-ai-site-header') {
            return $html;
        }

        if (\preg_match('/<header\b[^>]*\bid=["\']header-[^"\']+["\']/i', $html) !== 1) {
            return $html;
        }

        return $html . "\n" . <<<'HTML'
<style data-pb-ai-header-mobile-compact="1">
@media (max-width: 992px) {
    header[id^="header-"] {
        position: relative;
        max-width: 100vw !important;
        box-sizing: border-box !important;
    }

    header[id^="header-"],
    header[id^="header-"] * {
        box-sizing: border-box !important;
    }

    header[id^="header-"] :is(a, button, span, p, strong, small, div) {
        min-width: 0 !important;
        max-width: 100% !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
    }

    header[id^="header-"] :is([class*="-logo"], [class*="-brand"], [class*="-title"], [class*="-nav"], [class*="-link"], [class*="-cta"]) {
        white-space: normal !important;
    }

    header[id^="header-"] :is(nav, div)[class*="-nav"] {
        position: absolute !important;
        top: calc(100% + 8px) !important;
        left: 16px !important;
        right: 16px !important;
        bottom: auto !important;
        width: auto !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: min(70vh, 360px) !important;
        overflow-y: auto !important;
        box-sizing: border-box !important;
        border-radius: 12px !important;
        transform: translateY(-8px) !important;
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
    }

    header[id^="header-"] :is(nav, div)[class*="-nav"].active {
        transform: translateY(0) !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
    }
}
</style>
HTML;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function localizeVirtualThemeSharedComponentConfig(
        string $region,
        array $config,
        Page $page,
        string $componentCode
    ): array {
        if (!\in_array($region, ['header', 'footer'], true)) {
            return $config;
        }

        $locale = \trim((string)($this->templateVars['current_locale'] ?? $this->templateVars['lang'] ?? ''));
        if ($locale === '') {
            return $config;
        }

        try {
            /** @var AiSiteScopeCompatibilityService $scopeCompatibility */
            $scopeCompatibility = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
            $layout = [
                'header' => [
                    'component' => $region === 'header' ? $componentCode : '',
                    'config' => $region === 'header' ? $config : [],
                ],
                'content' => [],
                'footer' => [
                    'component' => $region === 'footer' ? $componentCode : '',
                    'config' => $region === 'footer' ? $config : [],
                ],
            ];
            $scope = [
                'content_locale' => $locale,
                'default_locale' => $locale,
                'website_profile' => [
                    'content_locale' => $locale,
                    'default_locale' => $locale,
                ],
            ];
            $localizedLayout = $scopeCompatibility->localizeSharedLayoutConfigForScope(
                $layout,
                $scope,
                (string)$page->getData(Page::schema_fields_TYPE)
            );
            $localizedConfig = $localizedLayout[$region]['config'] ?? null;

            return \is_array($localizedConfig) ? $localizedConfig : $config;
        } catch (\Throwable) {
            return $config;
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function applyVirtualThemeSharedStaticLocaleGuards(string $html, string $region, array $config): string
    {
        if ($html === '' || $region !== 'footer' || !$this->currentRenderLocaleIsNonCjk()) {
            return $html;
        }

        $replacement = \trim((string)($config['visitor_experience'] ?? $config['brand.description'] ?? ''));
        if ($replacement === '' || $this->hasCjkContent($replacement)) {
            return $html;
        }

        $safeReplacement = \htmlspecialchars($replacement, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $localized = \preg_replace(
            '#(<div\s+class="[^"]*?-bottom"\s*>\s*<div>\s*&copy;.*?</div>\s*)<div>[^<]*[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}][^<]*</div>#us',
            '$1<div>' . $safeReplacement . '</div>',
            $html
        );

        return \is_string($localized) ? $localized : $html;
    }

    private function currentRenderLocaleIsNonCjk(): bool
    {
        $locale = \trim((string)($this->templateVars['current_locale'] ?? $this->templateVars['lang'] ?? ''));

        return $locale !== '' && \preg_match('/^(?:zh|ja|ko)(?:[_-]|$)/i', $locale) !== 1;
    }

    private function hasCjkContent(string $value): bool
    {
        return \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value) === 1;
    }

    private function flattenRenderedVirtualThemeNestedResponsiveMedia(string $html): string
    {
        if (\stripos($html, '@media') === false) {
            return $html;
        }

        return (string)\preg_replace_callback(
            '/<style\b([^>]*)>(.*?)<\/style>/isu',
            function (array $matches): string {
                $attrs = (string)($matches[1] ?? '');
                $css = (string)($matches[2] ?? '');
                $lines = \preg_split('/\R/', $css) ?: [];
                $keptLines = [];
                $mediaBlocks = [];
                foreach ($lines as $line) {
                    if (\preg_match('/^[ \t]+((?:@media\s*\(\s*max-width\s*:\s*(?:768|420)px\s*\)\s*\{[^\r\n]*\})+)[ \t]*$/i', (string)$line, $lineMatch) === 1) {
                        $mediaBlock = \trim((string)($lineMatch[1] ?? ''));
                        if ($mediaBlock !== '') {
                            $mediaBlocks[$mediaBlock] = true;
                        }
                        continue;
                    }
                    $keptLines[] = (string)$line;
                }
                if ($mediaBlocks === []) {
                    return '<style' . $attrs . '>' . $css . '</style>';
                }

                $css = \implode("\n", $keptLines);
                $css = \rtrim($css) . "\n\n" . \implode("\n", \array_keys($mediaBlocks)) . "\n";

                return '<style' . $attrs . '>' . $css . '</style>';
            },
            $html
        );
    }

    private function buildVirtualThemeAiActionBridgeScript(): string
    {
        return <<<'HTML'
<script>
(function() {
    var script = document.currentScript;
    var root = script ? script.previousElementSibling : null;
    if (!root || !root.querySelectorAll) {
        return;
    }
    var controls = root.querySelectorAll('[data-pb-ai-action]');
    controls.forEach(function(control) {
        if (control.getAttribute('data-pb-ai-bound') === '1') {
            return;
        }
        control.setAttribute('data-pb-ai-bound', '1');
        control.addEventListener('click', function(event) {
            var action = control.getAttribute('data-pb-ai-action') || '';
            var target = control.getAttribute('data-pb-ai-target') || control.getAttribute('href') || '';
            var label = (control.textContent || '').trim();
            var detail = { action: action, target: target, label: label, source: control, originalEvent: event };
            var actionEvent;
            if (typeof CustomEvent === 'function') {
                actionEvent = new CustomEvent('pb:cta', { bubbles: true, detail: detail });
            } else {
                actionEvent = document.createEvent('CustomEvent');
                actionEvent.initCustomEvent('pb:cta', true, false, detail);
            }
            root.dispatchEvent(actionEvent);
        });
    });
})();
</script>
HTML;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function expandDottedComponentConfigValues(array $config): array
    {
        foreach ($this->virtualThemeConfigAliasGroups() as $group) {
            $value = $this->firstScalarComponentConfigValue($config, $group, true);
            if ($value === null) {
                continue;
            }
            foreach ($group as $key) {
                $config[$key] = $value;
            }
        }

        foreach ($config as $key => $value) {
            $key = (string)$key;
            if (!\str_contains($key, '.')) {
                continue;
            }
            $this->setNestedComponentConfigValue($config, $key, $value);
        }

        return $config;
    }

    /**
     * Some AI-generated virtual-theme templates still contain hard-coded copy
     * even though the editable field values are saved in layout config. Replace
     * those original literals in the rendered HTML so block edits are visible
     * immediately without regenerating the component.
     *
     * @param array<string,mixed> $referenceConfig
     * @param array<string,mixed> $currentConfig
     */
    private function applyVirtualThemeConfigOverridesToStaticHtml(
        string $html,
        array $referenceConfig,
        array $currentConfig
    ): string {
        if ($html === '') {
            return $html;
        }

        $pairs = [];
        foreach ($this->virtualThemeConfigAliasGroups() as $group) {
            $oldValue = $this->firstScalarComponentConfigValue($referenceConfig, $group, true);
            $newValue = $this->firstScalarComponentConfigValue($currentConfig, $group, true);
            $this->addVirtualThemeConfigReplacementPair($pairs, $oldValue, $newValue);
        }

        $referenceFlat = $this->flattenComponentConfig($referenceConfig);
        $currentFlat = $this->flattenComponentConfig($currentConfig);
        foreach ($currentFlat as $key => $newValue) {
            $key = (string)$key;
            if (!\str_starts_with($key, 'visible_text.')) {
                continue;
            }
            $token = \substr($key, \strlen('visible_text.'));
            $oldValue = $currentFlat['_pb_static_text_original.' . $token] ?? null;
            $this->addVirtualThemeConfigReplacementPair($pairs, $oldValue, $newValue);
        }
        foreach ($currentFlat as $key => $newValue) {
            $key = (string)$key;
            if (!\str_starts_with($key, 'visible_image.')) {
                continue;
            }
            $token = \substr($key, \strlen('visible_image.'));
            $oldValue = $currentFlat['_pb_static_image_original.' . $token] ?? null;
            $this->addVirtualThemeConfigReplacementPair($pairs, $oldValue, $newValue);
        }
        foreach ($currentFlat as $key => $newValue) {
            if (!$this->isVirtualThemeTextReplacementKey((string)$key)) {
                continue;
            }
            if (!\array_key_exists($key, $referenceFlat)) {
                continue;
            }
            $this->addVirtualThemeConfigReplacementPair($pairs, $referenceFlat[$key], $newValue);
        }

        if ($pairs === []) {
            return $html;
        }

        \uksort($pairs, static fn(string $left, string $right): int => \strlen($right) <=> \strlen($left));
        foreach ($pairs as $oldValue => $newValue) {
            $html = \str_replace($oldValue, $newValue, $html);
            $escapedOld = \htmlspecialchars($oldValue, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            if ($escapedOld !== $oldValue) {
                $html = \str_replace(
                    $escapedOld,
                    \htmlspecialchars($newValue, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                    $html
                );
            }
        }

        return $html;
    }

    /**
     * @return list<list<string>>
     */
    private function virtualThemeConfigAliasGroups(): array
    {
        return [
            ['content.title', 'content.heading', 'content.headline', 'title', 'heading', 'headline'],
            ['content.subtitle', 'content.subheading', 'subtitle', 'subheading'],
            ['content.description', 'content.body', 'description', 'body', 'text'],
            ['cta.text', 'content.cta_text', 'cta_text', 'button_text', 'button.label'],
            ['cta.url', 'content.cta_url', 'cta_url', 'button_url', 'button.url', 'button.href'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param list<string> $keys
     */
    private function firstScalarComponentConfigValue(array $config, array $keys, bool $preferNonEmpty = false): mixed
    {
        $first = null;
        $hasFirst = false;
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            if (!\is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            if (!$hasFirst) {
                $first = $value;
                $hasFirst = true;
            }
            if (!$preferNonEmpty || \trim((string)$value) !== '') {
                return $value;
            }
        }

        return $hasFirst ? $first : null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function setNestedComponentConfigValue(array &$config, string $key, mixed $value): void
    {
        $parts = \array_values(\array_filter(\explode('.', $key), static fn(string $part): bool => $part !== ''));
        if (\count($parts) < 2) {
            return;
        }

        $cursor = &$config;
        $lastIndex = \count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($index === $lastIndex) {
                $cursor[$part] = $value;
                return;
            }
            if (!isset($cursor[$part]) || !\is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function flattenComponentConfig(array $config, string $prefix = ''): array
    {
        $flat = [];
        foreach ($config as $key => $value) {
            $flatKey = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (\is_array($value)) {
                $flat = \array_replace($flat, $this->flattenComponentConfig($value, $flatKey));
                continue;
            }
            $flat[$flatKey] = $value;
        }

        return $flat;
    }

    /**
     * @param array<string,string> $pairs
     */
    private function addVirtualThemeConfigReplacementPair(array &$pairs, mixed $oldValue, mixed $newValue): void
    {
        if ((!is_scalar($oldValue) && !$oldValue instanceof \Stringable)
            || (!is_scalar($newValue) && !$newValue instanceof \Stringable)
        ) {
            return;
        }

        $oldString = (string)$oldValue;
        $newString = (string)$newValue;
        if ($oldString === $newString || \trim($oldString) === '' || \strlen($oldString) < 3) {
            return;
        }
        if (\preg_match('/^[\s\/.#_-]+$/', $oldString) === 1) {
            return;
        }

        $pairs[$oldString] = $newString;
    }

    private function isVirtualThemeTextReplacementKey(string $key): bool
    {
        $key = \strtolower(\trim($key));
        if ($key === ''
            || \str_starts_with($key, 'style.')
            || \str_starts_with($key, 'layout.')
            || \str_starts_with($key, 'navigation.')
            || \str_starts_with($key, 'nav_items.')
            || \str_starts_with($key, 'menu.')
            || \str_starts_with($key, 'links.')
            || \str_contains($key, 'color')
            || \str_contains($key, 'image')
            || \str_contains($key, 'icon')
            || \str_contains($key, '.items.')
            || \str_contains($key, '_items.')
        ) {
            return false;
        }

        foreach ([
            'content.', 'title', 'heading', 'headline', 'subtitle', 'description',
            'body', 'text', 'cta', 'button', 'label', 'badge', 'kpi', 'proof', 'stat',
        ] as $token) {
            if (\str_contains($key, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function sanitizeSharedIdentityAssetConfig(array $config, string $region): array
    {
        $region = \strtolower(\trim($region));
        if (!\in_array($region, ['header', 'footer'], true)) {
            return $config;
        }

        return $this->clearInvalidIdentityAssetReferences($config);
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function clearInvalidIdentityAssetReferences(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalizedKey = \strtolower(\trim((string)$key));
            if (\is_array($item)) {
                $value[$key] = $this->clearInvalidIdentityAssetReferences($item);
                continue;
            }
            if (!\is_string($item) || \trim($item) === '') {
                continue;
            }

            if (
                \in_array($normalizedKey, ['logo', 'logo.image', 'logo.url', 'brand.logo', 'identity.shared_logo_asset', 'shared_logo_asset'], true)
                && $this->identityAssetUrlIsInvalidForRole($item, 'logo')
            ) {
                unset($value[$key]);
                continue;
            }

            if (
                \in_array($normalizedKey, ['icon', 'favicon', 'site.icon', 'identity.shared_icon_asset', 'shared_icon_asset'], true)
                && $this->identityAssetUrlIsInvalidForRole($item, 'icon')
            ) {
                unset($value[$key]);
            }
        }

        return $value;
    }

    private function identityAssetUrlIsInvalidForRole(string $url, string $role): bool
    {
        $url = \trim($url);
        if ($url === '') {
            return false;
        }
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $url;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $lowerPath = \strtolower($path);
        $isPageBuilderGeneratedAsset = \str_contains($lowerPath, '/pub/media/page-build/')
            && \str_contains($lowerPath, '/ai-generated/');
        if (!$isPageBuilderGeneratedAsset) {
            return false;
        }

        $expectedToken = $role === 'logo' ? 'identity-website-logo' : 'identity-site-title-icon';
        if (!\str_contains($lowerPath, $expectedToken) || (!\str_ends_with($lowerPath, '.png') && !\str_ends_with($lowerPath, '.svg'))) {
            return true;
        }

        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($path, '/'));
        if (!\is_file($absolutePath)) {
            return true;
        }
        $bytes = @\file_get_contents($absolutePath);
        if (!\is_string($bytes) || $bytes === '') {
            return true;
        }

        $assetRole = $role === 'logo' ? 'logo' : 'icon';
        return !AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset(
            $bytes,
            \str_ends_with($lowerPath, '.svg') ? 'image/svg+xml' : 'image/png',
            $assetRole
        );
    }

    private function pngAppearsToHaveTransparentBackground(string $bytes): bool
    {
        if (\function_exists('imagecreatefromstring')) {
            $image = @\imagecreatefromstring($bytes);
            if ($image !== false) {
                $width = \imagesx($image);
                $height = \imagesy($image);
                $points = [
                    [0, 0],
                    [\max(0, $width - 1), 0],
                    [0, \max(0, $height - 1)],
                    [\max(0, $width - 1), \max(0, $height - 1)],
                ];
                $transparent = 0;
                foreach ($points as [$x, $y]) {
                    $alpha = (\imagecolorat($image, $x, $y) >> 24) & 0x7F;
                    if ($alpha >= 80) {
                        $transparent++;
                    }
                }
                \imagedestroy($image);

                return $transparent >= 3;
            }
        }

        $colorType = \ord($bytes[25] ?? "\0");
        return \in_array($colorType, [4, 6], true) || \str_contains($bytes, 'tRNS');
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderPhtmlString(string $templateContent, array $vars): string
    {
        $initialObLevel = \ob_get_level();
        FiberOutputBuffer::beginCapture();
        try {
            $template = $this->getTemplate();
            $renderer = \Closure::bind(
                function () use ($templateContent, $vars): void {
                    if ($vars !== []) {
                        $this->addData($vars);
                    }
                    $block = $this;
                    $this->setData('block', $this);
                    if ($this->getData()) {
                        \extract($this->getData(), \EXTR_SKIP);
                    }
                    eval('?>' . $templateContent);
                },
                $template,
                $template::class
            );
            $renderer();
        } catch (\Throwable $throwable) {
            if (\Weline\Framework\Runtime\Runtime::isPersistent()) {
                FiberOutputBuffer::discardCapture();
            } elseif (\ob_get_level() > $initialObLevel) {
                \ob_end_clean();
            }
            throw $throwable;
        }

        return FiberOutputBuffer::endCapture();
    }

    private function getComponentFilesMap(string $styleCode): array
    {
        return $this->componentResolver->getComponentFilesMap($styleCode);
    }
    
    /**
     * 閫氳繃 Component 妯″瀷瑙ｆ瀽缁勪欢妯℃澘璺緞
     * 
     * 杩欐槸涓€涓鐢ㄦ柟娉曪紝褰?component.json 涓壘涓嶅埌缁勪欢鏃朵娇鐢?
     * 鍙互鏀寔璺ㄦā鏉跨粍浠惰В鏋?
     * 
     * @param string $componentCode 缁勪欢浠ｇ爜
     * @param string $preferredStyleCode 棣栭€夋牱寮忎唬鐮?
     * @return array|null ['path' => '妯℃澘璺緞', 'style_code' => '瀹為檯浣跨敤鐨勬牱寮忎唬鐮?]
     */
    private function resolveComponentViaModel(string $componentCode, string $preferredStyleCode): ?array
    {
        try {
            $componentModelClass = '\\GuoLaiRen\\PageBuilder\\Model\\Component';
            if (!class_exists($componentModelClass)) {
                return null;
            }
            
            $componentModel = ObjectManager::getInstance($componentModelClass);
            
            // 棣栧厛灏濊瘯鍦ㄩ閫夋牱寮忎腑鏌ユ壘
            $component = clone $componentModel;
            $component->clear()
                ->where($componentModelClass::schema_fields_CODE, $componentCode)
                ->where($componentModelClass::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                $path = $component->getData($componentModelClass::schema_fields_PATH);
                $styleCode = $component->getData($componentModelClass::schema_fields_STYLE_CODE);
                
                if ($path) {
                    // 濡傛灉鏄浉瀵硅矾寰勶紝杞崲涓烘ā鏉垮紩鐢?
                    if (strpos($path, 'style/') === 0) {
                        return [
                            'path' => "GuoLaiRen_PageBuilder::templates/{$path}",
                            'style_code' => $styleCode,
                        ];
                    }
                    return [
                        'path' => $path,
                        'style_code' => $styleCode,
                    ];
                }
            }
            
            // 灏濊瘯甯︽牱寮忓墠缂€鐨勭粍浠朵唬鐮?
            $prefixedCode = $preferredStyleCode . '-' . $componentCode;
            $component2 = clone $componentModel;
            $component2->clear()
                ->where($componentModelClass::schema_fields_CODE, $prefixedCode)
                ->where($componentModelClass::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component2->getId()) {
                $path = $component2->getData($componentModelClass::schema_fields_PATH);
                $styleCode = $component2->getData($componentModelClass::schema_fields_STYLE_CODE);
                
                if ($path) {
                    if (strpos($path, 'style/') === 0) {
                        return [
                            'path' => "GuoLaiRen_PageBuilder::templates/{$path}",
                            'style_code' => $styleCode,
                        ];
                    }
                    return [
                        'path' => $path,
                        'style_code' => $styleCode,
                    ];
                }
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 鏋勫缓璋冭瘯淇℃伅
     */
    private function buildDebugInfo(bool $useComponentRendering, array $layoutConfig): string
    {
        $debugInfo = "<!-- [DEBUG] useComponentRendering: " . ($useComponentRendering ? 'true' : 'false') . " -->\n";
        $debugInfo .= "<!-- [DEBUG] layoutConfig header count: " . count($layoutConfig['header'] ?? []) . " -->\n";
        $debugInfo .= "<!-- [DEBUG] layoutConfig content count: " . count($layoutConfig['content'] ?? []) . " -->\n";
        $debugInfo .= "<!-- [DEBUG] layoutConfig footer count: " . count($layoutConfig['footer'] ?? []) . " -->\n";
        return $debugInfo;
    }
    
    /**
     * 娉ㄥ叆 Header 鑷畾涔変唬鐮?
     */
    private function injectHeaderCustomCode(string $headerHtml, Page $page): string
    {
        $headerCustomCode = $page->getData(Page::schema_fields_HEADER_CUSTOM_CODE) ?? '';
        if (!empty($headerCustomCode)) {
            $headerHtml = preg_replace(
                '/(<\/head>)/i',
                $headerCustomCode . "\n    $1",
                $headerHtml,
                1
            );
        }
        return $headerHtml;
    }
    
    /**
     * 娉ㄥ叆 Footer 鑷畾涔変唬鐮?
     */
    private function injectFooterCustomCode(string $footerHtml, Page $page): string
    {
        $footerCustomCode = $page->getData(Page::schema_fields_FOOTER_CUSTOM_CODE) ?? '';
        if (!empty($footerCustomCode)) {
            $footerHtml = preg_replace(
                '/(<\/body>)/i',
                "\n    " . $footerCustomCode . "\n$1",
                $footerHtml,
                1
            );
        }
        return $footerHtml;
    }
    
    /**
     * 鏈€缁堝鐞嗚緭鍑?
     */
    private function finalizeOutput(
        string $headerHtml,
        string $contentHtml,
        string $footerHtml,
        string $debugInfo,
        Page $page,
        string $styleCode,
        string $mode,
        ?int $virtualThemeId = null
    ): string {
        // 棰勮鏍囪鑴氭湰锛坧review 鍜?visual 妯″紡閮介渶瑕侊級
        $previewBoot = '';
        if ($mode !== self::MODE_LIVE) {
            $previewBoot = '<script>(function(){
                try {
                    window.__PAGEBUILDER_PREVIEW__ = true;
                    var url = new URL(window.location.href);
                    if (!url.searchParams.get("preview")) {
                        url.searchParams.set("preview", "1");
                        window.history.replaceState({}, document.title, url.toString());
                    }
                } catch(e) {}
            })();</script>';
        }
        
        if ($page->isAiHtmlRenderMode()) {
            $aiHtml = $this->renderAiHtmlBlockNodes($page, $mode !== self::MODE_LIVE, $mode === self::MODE_VISUAL);
            if ($aiHtml !== '') {
                if ($mode === self::MODE_VISUAL) {
                    return $this->renderVisualMode($headerHtml, $aiHtml, $footerHtml, $debugInfo, $previewBoot, $page, $styleCode);
                }

                return $this->renderAiHtmlDocument($headerHtml, $aiHtml, $footerHtml, $previewBoot, $page, $virtualThemeId);
            }
        }

        if ($mode === self::MODE_VISUAL) {
            // 鍙鍖栫紪杈戝櫒妯″紡锛氭坊鍔犳彃妲藉鍣ㄥ拰鎷栨嫿鏀寔
            return $this->renderVisualMode($headerHtml, $contentHtml, $footerHtml, $debugInfo, $previewBoot, $page, $styleCode);
        }
        
        // preview 鍜?live 妯″紡锛氱函鍑€杈撳嚭
        return $this->renderStandardDocument($headerHtml, $contentHtml, $footerHtml, $previewBoot, $page);
    }

    private function renderAiHtmlDocument(
        string $headerHtml,
        string $aiHtml,
        string $footerHtml,
        string $previewBoot,
        Page $page,
        ?int $virtualThemeId = null
    ): string
    {
        $effectiveVirtualThemeId = (int)($virtualThemeId ?? 0);
        if ($effectiveVirtualThemeId <= 0) {
            $effectiveVirtualThemeId = (int)$page->getData('virtual_theme_id');
        }
        if ($effectiveVirtualThemeId <= 0) {
            $effectiveVirtualThemeId = $this->resolveActiveAiVirtualThemeId((int)$page->getData(Page::schema_fields_WEBSITE_ID));
        }
        $themeMarker = $effectiveVirtualThemeId > 0
            ? '<!-- theme_id=' . $effectiveVirtualThemeId . ' -->'
            : '';

        return $this->renderStandardDocument($themeMarker . $headerHtml, $aiHtml, $footerHtml, $previewBoot, $page);
    }

    private function renderStandardDocument(
        string $headerHtml,
        string $contentHtml,
        string $footerHtml,
        string $previewBoot,
        Page $page
    ): string {
        $headerHtml = $this->cleanHtmlDocumentTags($headerHtml);
        $contentHtml = $this->cleanHtmlDocumentTags($contentHtml);
        $footerHtml = $this->cleanHtmlDocumentTags($footerHtml);

        $headerCustomCode = (string)($page->getData(Page::schema_fields_HEADER_CUSTOM_CODE) ?? '');
        $footerCustomCode = (string)($page->getData(Page::schema_fields_FOOTER_CUSTOM_CODE) ?? '');
        $headHtml = $this->buildSeoHeadHtml($page);
        $htmlLang = $this->resolveDocumentLanguage();

        return '<!DOCTYPE html>
<html lang="' . \htmlspecialchars($htmlLang, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    ' . $headHtml . '
    ' . $this->buildAiSiteCanvasWidthCss($page) . '
    ' . $this->buildAiSiteDesignTokenCss($page) . '
    ' . $headerCustomCode . '
</head>
<body>
    ' . $previewBoot . '
    ' . $headerHtml . '
    ' . $contentHtml . '
    ' . $footerHtml . '
    ' . $footerCustomCode . '
</body>
</html>';
    }

    private function buildAiSiteCanvasWidthCss(Page $page): string
    {
        unset($page);

        return '<style>
:root{--pb-ai-site-canvas-width:1200px;--pb-ai-site-canvas-padding:24px;}
html,body{max-width:100%;overflow-x:hidden;}
body{box-sizing:border-box;}
body > *{max-width:100%;box-sizing:border-box;}
[class^="header-"][class*="-container"],[class*=" header-"][class*="-container"],
[class^="footer-"][class*="-container"],[class*=" footer-"][class*="-container"],
.pb-c-inner{max-width:var(--pb-ai-site-canvas-width) !important;width:min(100% - calc(var(--pb-ai-site-canvas-padding) * 2), var(--pb-ai-site-canvas-width)) !important;margin-left:auto !important;margin-right:auto !important;box-sizing:border-box;}
.pb-c-root,.pb-c-root *{box-sizing:border-box;}
.pb-c-root{max-width:100%;overflow-x:clip;}
.pb-c-root :is([class*="rail"],[class*="carousel"],[class*="slider"],[class*="cards"],[class*="grid"],[class*="list"]){max-width:100%;min-width:0;}
[class^="header-"][class*="-container"],[class*=" header-"][class*="-container"],
[class^="footer-"][class*="-container"],[class*=" footer-"][class*="-container"]{padding-left:0 !important;padding-right:0 !important;}
@media (max-width:768px){:root{--pb-ai-site-canvas-padding:18px;}}
@media (max-width:420px){:root{--pb-ai-site-canvas-padding:14px;}}
</style>';
    }

    private function buildAiSiteDesignTokenCss(Page $page): string
    {
        $tokens = \is_array($page->getData('design_tokens') ?? null) ? $page->getData('design_tokens') : [];
        $themeRef = \is_array($page->getData('theme_css_ref') ?? null) ? $page->getData('theme_css_ref') : [];
        $cssParts = [];

        if ($tokens !== []) {
            $resolver = new AiSiteDesignTokenResolver();
            $cssParts[] = $resolver->buildRootCssVariables($tokens);
        }

        $themeCss = \trim((string)($themeRef['css'] ?? ''));
        if ($themeCss !== '') {
            $cssParts[] = $themeCss;
        }

        if ($cssParts === []) {
            return '';
        }

        return '<style>' . \implode("\n", $cssParts) . '</style>';
    }

    private function buildSeoHeadHtml(Page $page): string
    {
        $template = $this->getTemplate();
        $currentLocale = $this->resolveCurrentLocale();
        $canonicalUrl = $this->resolveCanonicalUrl($page);

        $seo = $template->getData('seo');
        $seo = \is_array($seo) ? $seo : [];
        if (($seo['canonical_url'] ?? '') === '' && $canonicalUrl !== '') {
            $seo['canonical_url'] = $canonicalUrl;
        }
        if (($seo['title'] ?? '') === '') {
            $seo['title'] = $this->readPageString($page, ['meta_title', 'title', 'name']);
        }
        if (($seo['description'] ?? '') === '') {
            $seo['description'] = $this->readPageString($page, ['meta_description', 'ai_description', 'description', 'excerpt']);
        }
        if (($seo['image'] ?? '') === '') {
            $seo['image'] = $this->readPageString($page, ['image', 'cover_image', 'featured_image']);
        }

        $template->assign('page', $page);
        $template->assign('seo', $seo);
        $template->assign('title', $seo['title'] ?? '');
        $template->assign('lang', $currentLocale);
        $template->assign('lang_local', $currentLocale);
        $template->assign('current_locale', $currentLocale);
        if ($canonicalUrl !== '') {
            $template->assign('canonical_url', $canonicalUrl);
        }

        try {
            $providerRegistry = ObjectManager::getInstance(\Weline\Seo\Service\Head\HeadProviderRegistry::class);
            $resolver = new \Weline\Seo\Service\Head\PageSeoContextResolver($providerRegistry);
            $renderer = new \Weline\Seo\Service\Head\HeadRenderer($resolver);
            $html = $renderer->render($template, ['slot' => 'head']);
            return \trim(\trim(\is_string($html) ? $html : '') . "\n" . $this->buildIdentityHeadHtml($page));
        } catch (\Throwable) {
            return $this->buildIdentityHeadHtml($page);
        }
    }

    private function buildIdentityHeadHtml(Page $page): string
    {
        $icon = \trim((string)($page->getData(Page::schema_fields_ICON) ?? ''));
        if ($icon === '' || $this->identityAssetUrlIsInvalidForRole($icon, 'icon')) {
            return '';
        }

        $type = \str_ends_with(\strtolower($icon), '.svg') ? 'image/svg+xml' : 'image/png';
        $href = \htmlspecialchars($icon, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $typeAttr = \htmlspecialchars($type, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<link rel="icon" href="' . $href . '" type="' . $typeAttr . '">' . "\n"
            . '<link rel="shortcut icon" href="' . $href . '" type="' . $typeAttr . '">';
    }

    private function resolveCurrentLocale(): string
    {
        $template = $this->getTemplate();
        foreach ([
            $template->getData('lang_local'),
            $template->getData('current_locale'),
            $template->getData('lang'),
            \w_env('user.lang', ''),
            $_SERVER['WELINE_USER_LANG'] ?? '',
            'zh_Hans_CN',
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'zh_Hans_CN';
    }

    private function resolveDocumentLanguage(): string
    {
        return \str_replace('_', '-', $this->resolveCurrentLocale());
    }

    private function resolveCanonicalUrl(Page $page): string
    {
        $url = '';
        try {
            if ($this->request) {
                $url = (string)$this->request->getUrlBuilder()->getCurrentUrl([], true);
            }
        } catch (\Throwable) {
        }

        if ($url === '') {
            $fullUrl = \trim((string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? ''));
            if ($fullUrl !== '' && \preg_match('/^https?:\/\//i', $fullUrl)) {
                $url = $fullUrl;
            }
        }

        if ($url === '') {
            $scheme = (string)($_SERVER['REQUEST_SCHEME'] ?? '');
            if ($scheme === '') {
                $https = \strtolower((string)($_SERVER['HTTPS'] ?? ''));
                $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
            }
            $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            if ($host === '') {
                return '';
            }
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            if ($uri === '') {
                $uri = '/';
            }
            $url = $scheme . '://' . $host . $uri;
        }

        return $this->normalizeLocaleCanonicalUrl(
            $url,
            $this->resolveCurrentLocale(),
            'zh_Hans_CN',
            $page
        );
    }

    private function normalizeLocaleCanonicalUrl(string $url, string $currentLocale, string $defaultLocale, Page $page): string
    {
        if ($url === '' || !\preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }

        $path = (string)($parts['path'] ?? '/');
        if ($this->isInternalPageBuilderPath($path)) {
            $path = $this->publicPagePath($page, $query);
        }
        $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
        $currencySegment = '';
        if ($segments !== [] && $this->isCurrencyPathSegment((string)$segments[0])) {
            $currencySegment = (string)\array_shift($segments);
        }
        $hadLocaleSegment = false;
        while ($segments !== [] && $this->isLocalePathSegment((string)$segments[0])) {
            \array_shift($segments);
            $hadLocaleSegment = true;
        }

        $currentLocale = $this->normalizeLocaleCode($currentLocale);
        $defaultLocale = $this->normalizeLocaleCode($defaultLocale) ?: 'zh_Hans_CN';
        $prefixSegments = [];
        if ($currencySegment !== '') {
            $prefixSegments[] = $currencySegment;
        }
        if ($currentLocale !== '' && ($hadLocaleSegment || ($currentLocale !== $defaultLocale && $currentLocale !== 'zh_Hans_CN'))) {
            $prefixSegments[] = $currentLocale;
        }
        $segments = \array_merge($prefixSegments, $segments);

        if ($query !== []) {
            unset(
                $query['lang'],
                $query['locale'],
                $query['page_id'],
                $query['handle'],
                $query['website_id'],
                $query['preview'],
                $query['style_code']
            );
            $query = $this->removeIgnorableCanonicalQueryParams($query);
        }

        $scheme = (string)($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . (string)$parts['port'] : '';
        $normalizedPath = $segments === [] ? '/' : '/' . \implode('/', $segments);
        $queryString = $query === [] ? '' : '?' . \http_build_query($query);

        return $scheme . '://' . (string)$parts['host'] . $port . $normalizedPath . $queryString;
    }

    private function isInternalPageBuilderPath(string $path): bool
    {
        return \strtolower('/' . \trim($path, '/')) === '/pagebuilder/frontend/page/view';
    }

    /**
     * @param array<string,mixed> $query
     */
    private function publicPagePath(Page $page, array $query): string
    {
        if ((string)$page->getData(Page::schema_fields_TYPE) === Page::TYPE_HOME) {
            return '/';
        }

        $handle = \trim((string)($page->getData(Page::schema_fields_HANDLE) ?: ($query['handle'] ?? '')));
        if ($handle === '') {
            return '/';
        }

        return '/' . \ltrim($handle, '/');
    }

    private function isLocalePathSegment(string $segment): bool
    {
        $segment = $this->normalizeLocaleCode($segment);
        return $segment !== '' && (bool)\preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(?:_[A-Z]{2})?$/', $segment);
    }

    private function isCurrencyPathSegment(string $segment): bool
    {
        return (bool)\preg_match('/^[A-Z]{3}$/', \trim($segment));
    }

    private function normalizeLocaleCode(string $locale): string
    {
        return \str_replace('-', '_', \trim($locale));
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function removeIgnorableCanonicalQueryParams(array $query): array
    {
        foreach (\array_keys($query) as $key) {
            $normalized = \strtolower(\trim((string)$key));
            if ($normalized === '_'
                || \in_array($normalized, ['ai_perf', 'browser_perf', 'codex_perf', 'fbclid', 'gbraid', 'gclid', 'igshid', 'mc_cid', 'mc_eid', 'msclkid', 'wbraid', 'yclid'], true)
                || \str_starts_with($normalized, 'utm_')
                || \str_starts_with($normalized, 'mtm_')
                || \str_starts_with($normalized, 'pk_')) {
                unset($query[$key]);
            }
        }

        return $query;
    }

    /**
     * @param string[] $keys
     */
    private function readPageString(Page $page, array $keys): string
    {
        foreach ($keys as $key) {
            $value = \trim((string)$page->getData($key));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveActiveAiVirtualThemeId(int $websiteId): int
    {
        if ($websiteId <= 0) {
            return 0;
        }
        try {
            /** @var \GuoLaiRen\PageBuilder\Model\VirtualTheme $theme */
            $theme = ObjectManager::make(\GuoLaiRen\PageBuilder\Model\VirtualTheme::class);
            $theme->clearData()->clearQuery()
                ->where(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_WEBSITE_ID, $websiteId)
                ->where(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_SOURCE, \GuoLaiRen\PageBuilder\Model\VirtualTheme::SOURCE_PAGEBUILDER_AI)
                ->where(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_IS_ACTIVE, 1)
                ->order(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            return (int)$theme->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function renderAiHtmlBlockNodes(Page $page, bool $useDraftLayout = false, bool $visualEditor = false): string
    {
        $layout = $page->resolveAiLayoutForFrontend($useDraftLayout);
        $blocks = \is_array($layout['block_nodes'] ?? null) ? $layout['block_nodes'] : [];
        if ($blocks === []) {
            return '';
        }

        $html = '';
        $pageType = \trim((string)$page->getData(Page::schema_fields_TYPE));
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (AiSiteHtmlBlockNodesBuildService::isSharedLayoutBlock($block)) {
                continue;
            }
            $blockHtml = \trim((string)($block['html'] ?? $block['config']['html_content'] ?? ''));
            if ($blockHtml === '') {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $blockType = \trim((string)($block['type'] ?? 'ai_html_block'));
            $componentCode = $this->resolveAiHtmlBlockComponentCode($block);
            $html .= '<section class="pb-ai-html-block"';
            if ($blockId !== '') {
                $html .= ' data-block-id="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            if ($componentCode !== '') {
                $html .= ' data-component-code="' . \htmlspecialchars($componentCode, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            if ($blockType !== '') {
                $html .= ' data-block-type="' . \htmlspecialchars($blockType, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            $html .= '>';
            if ($visualEditor) {
                $region = \trim((string)($block['_pb_server_region'] ?? $block['region'] ?? 'content'));
                $region = $region !== '' ? $region : 'content';
                $componentKey = $componentCode !== '' ? $componentCode : ($blockId !== '' ? $blockId : $blockType);
                $actionDispatch = \htmlspecialchars($this->getComponentActionInlineDispatchJs(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<div class="pb-component-wrapper tpmst-component-wrapper"'
                    . ' data-component="' . \htmlspecialchars($componentKey, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-page-type="' . \htmlspecialchars($pageType, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-component-code="' . \htmlspecialchars($componentCode !== '' ? $componentCode : $componentKey, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-block-id="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-region="' . \htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-index="0"'
                    . ' data-ai-block-id="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' tabindex="0">'
                    . '<div class="component-actions" aria-label="Component actions">'
                    . '<button type="button" data-pb-action="refine" onclick="' . $actionDispatch . '">Refine</button>'
                    . '<button type="button" data-pb-action="move-up" onclick="' . $actionDispatch . '">Move up</button>'
                    . '<button type="button" data-pb-action="move-down" onclick="' . $actionDispatch . '">Move down</button>'
                    . '</div>'
                    . $blockHtml
                    . '</div>';
            } else {
                $html .= $blockHtml;
            }
            $html .= '</section>';
        }

        return $html;
    }
    
    /**
     * 娓叉煋鍙鍖栫紪杈戝櫒妯″紡
     * 
     * 缁熶竴浣跨敤缁勪欢鍖栨ā寮忥細濮嬬粓鏋勫缓瀹屾暣 HTML 缁撴瀯
     * header/content/footer 缁勪欢鍙槸 HTML 鐗囨锛屼笉鍖呭惈瀹屾暣鐨?HTML 鏂囨。缁撴瀯
     */
    private function getComponentActionInlineDispatchJs(): string
    {
return <<<'JS'
return (window.__pbDispatchComponentActionFromButton ? window.__pbDispatchComponentActionFromButton(this, event) : (function(target, e) { if (!target) { return true; } if (e && typeof e.preventDefault === 'function') { e.preventDefault(); } var wrapper = target.closest ? target.closest('.pb-ai-block-wrapper, .tpmst-component-wrapper, .pb-component-wrapper') : null; if (!wrapper) { return true; } var currentPageType = ''; try { currentPageType = new URLSearchParams(window.location.search).get('page_type') || ''; } catch (err) { currentPageType = ''; } var payload = { type: 'pb-component-action', action: target.getAttribute('data-pb-action') || '', component: wrapper.getAttribute('data-component') || wrapper.getAttribute('data-component-code') || wrapper.getAttribute('data-block-id') || '', component_code: wrapper.getAttribute('data-component-code') || wrapper.getAttribute('data-component') || wrapper.getAttribute('data-block-id') || '', block_id: wrapper.getAttribute('data-block-id') || wrapper.getAttribute('data-ai-block-id') || wrapper.getAttribute('data-component') || '', page_type: wrapper.getAttribute('data-page-type') || currentPageType, region: wrapper.getAttribute('data-region') || '', index: wrapper.getAttribute('data-index') || '' }; if (payload.action === 'edit-block' && window.__pbOpenStandaloneComponentEditor && window.__pbOpenStandaloneComponentEditor(payload, wrapper, e)) { if (e && typeof e.stopPropagation === 'function') { e.stopPropagation(); } if (e && typeof e.stopImmediatePropagation === 'function') { e.stopImmediatePropagation(); } return false; } if (!window.parent || window.parent === window) { return true; } try { if (window.parent.PbAiWorkspacePreview && typeof window.parent.PbAiWorkspacePreview.handleEmbeddedPreviewAction === 'function' && window.parent.PbAiWorkspacePreview.handleEmbeddedPreviewAction(payload)) { if (e && typeof e.stopPropagation === 'function') { e.stopPropagation(); } if (e && typeof e.stopImmediatePropagation === 'function') { e.stopImmediatePropagation(); } return false; } } catch (err) {} window.parent.postMessage(payload, '*'); return false; })(this, event));
JS;
    }

    private function buildVisualEditorComponentActionsHtml(): string
    {
        $actionDispatch = \htmlspecialchars($this->getComponentActionInlineDispatchJs(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $dispatchAttrs = ' onclick="' . $actionDispatch . '"'
            . ' onpointerdown="' . $actionDispatch . '"'
            . ' onmousedown="' . $actionDispatch . '"'
            . ' ontouchstart="' . $actionDispatch . '"';

        return '<div class="component-actions" aria-label="Component actions">'
            . '<button type="button" data-pb-action="refine"' . $dispatchAttrs . '>Refine</button>'
            . '<button type="button" data-pb-action="regenerate-block"' . $dispatchAttrs . '>AI Rebuild</button>'
            . '<button type="button" data-pb-action="edit-block"' . $dispatchAttrs . '>Edit</button>'
            . '<button type="button" data-pb-action="move-up"' . $dispatchAttrs . '>Move up</button>'
            . '<button type="button" data-pb-action="move-down"' . $dispatchAttrs . '>Move down</button>'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildVisualEditorComponentConfigScript(array $config): string
    {
        try {
            $json = \json_encode(
                $config,
                \JSON_UNESCAPED_UNICODE
                | \JSON_INVALID_UTF8_SUBSTITUTE
                | \JSON_HEX_TAG
                | \JSON_HEX_APOS
                | \JSON_HEX_AMP
                | \JSON_HEX_QUOT
                | \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            $json = '{}';
        }

        return '<script type="application/json" class="pb-component-config-json">' . $json . '</script>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveAiHtmlBlockComponentCode(array $block): string
    {
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        foreach ([
            $block['_pb_server_component_code'] ?? '',
            $config['_pb_server_component_code'] ?? '',
            $block['component_code'] ?? '',
            $config['component_code'] ?? '',
            $block['section_code'] ?? '',
            $config['section_code'] ?? '',
            $block['code'] ?? '',
            $block['component'] ?? '',
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function renderVisualMode(
        string $headerHtml,
        string $contentHtml,
        string $footerHtml,
        string $debugInfo,
        string $previewBoot,
        Page $page,
        string $styleCode
    ): string {
        $dropZoneStyles = $this->getDropZoneStyles();
        
        // 鑾峰彇甯冨眬鎷ユ湁鑰呴〉闈D锛堢敤浜庡彲瑙嗗寲缂栬緫API璋冪敤锛?
        $layoutOwnerPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageId($page);
        $dropZoneScripts = $this->getDropZoneScripts((int)$page->getId(), $layoutOwnerPageId);
        
        // 娓呯悊 header/footer 涓彲鑳藉瓨鍦ㄧ殑 HTML 鏂囨。缁撴瀯鏍囩锛堝吋瀹规棦鏈夋ā鏉匡級
        $headerHtml = $this->cleanHtmlDocumentTags($headerHtml);
        $footerHtml = $this->cleanHtmlDocumentTags($footerHtml);
        
        // 缁勪欢鍖栨ā寮忥細鏋勫缓瀹屾暣 HTML
        $pageTitle = $page ? ($page->getData('title') ?: 'Preview') : 'Preview';
        $templateHelper = Template::getInstance();
        $baseCssSource = 'GuoLaiRen_PageBuilder::style/' . $styleCode . '/asset/css/home.css';
        $baseCssUrl = $templateHelper->templateStaticExists($baseCssSource)
            ? $templateHelper->fetchTemplateStatic($baseCssSource)
            : '';
        $baseCssTag = $baseCssUrl !== ''
            ? '<link rel="stylesheet" href="' . htmlspecialchars($baseCssUrl, ENT_QUOTES, 'UTF-8') . '">'
            : '';
        $htmlLang = $this->resolveDocumentLanguage();
        
        return '<!DOCTYPE html>
<html lang="' . \htmlspecialchars($htmlLang, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($pageTitle) . '</title>
    ' . $baseCssTag . '
    ' . $dropZoneStyles . '
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body>
    ' . $debugInfo . '
    ' . $previewBoot . '
    <div class="pb-slot pb-slot-header" data-region="header" data-multiple="false" data-slot-name="Header">' . $headerHtml . '</div>
    <div class="pb-slot pb-slot-content" data-region="content" data-multiple="true" data-slot-name="Content">' . $contentHtml . '</div>
    <div class="pb-slot pb-slot-footer" data-region="footer" data-multiple="false" data-slot-name="Footer">' . $footerHtml . '</div>
    ' . $dropZoneScripts . '
</body>
</html>';
    }
    
    /**
     * 娓呯悊 HTML 鏂囨。缁撴瀯鏍囩
     * 
     * 绉婚櫎缁勪欢 HTML 涓彲鑳藉瓨鍦ㄧ殑瀹屾暣鏂囨。缁撴瀯鏍囩锛?
     * 鎻愬彇缁勪欢鍐呮墍鏈?<style> 鍒扮墖娈靛墠閮紝骞朵粠鍘熶綅缃Щ闄わ紝閬垮厤 <style> 鍑虹幇鍦?nav 绛夋爣绛惧唴銆?
     * 纭繚缁勪欢鍙槸绾补鐨?HTML 鐗囨锛屼笖鏍峰紡闆嗕腑鍦ㄧ墖娈靛墠閮ㄣ€?
     */
    private function cleanHtmlDocumentTags(string $html): string
    {
        // 绉婚櫎 DOCTYPE
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        
        // 绉婚櫎 <html> 鏍囩锛堜繚鐣欏唴瀹癸級
        $html = preg_replace('/<html[^>]*>/i', '', $html);
        $html = preg_replace('/<\/html>/i', '', $html);
        
        // 鎻愬彇鎵€鏈?style 鏍囩锛堝寘鎷粍浠跺唴閮ㄧ殑锛屽 <nav><style>...</style>锛?
        $styles = '';
        if (preg_match_all('/<style[^>]*>.*?<\/style>/is', $html, $matches)) {
            $styles = implode("\n", $matches[0]);
            // 浠庣墖娈典腑绉婚櫎杩欎簺 style 鏍囩锛岄伩鍏?header 绛夌粍浠堕噷娈嬬暀 <style> 瀵艰嚧缁撴瀯娣蜂贡
            $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        }
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        
        // 绉婚櫎 <body> 鏍囩锛堜繚鐣欏唴瀹癸級
        $html = preg_replace('/<body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/body>/i', '', $html);
        
        // 灏嗘彁鍙栫殑鏍峰紡鏀惧湪鐗囨鍓嶉儴锛屾渶缁堟彃鍏ュ埌鎻掓Ы鍐呮椂鏍峰紡鍦ㄧ粍浠剁粨鏋勪箣鍓?
        if (!empty($styles)) {
            $html = $styles . "\n" . trim($html);
        }
        
        return trim($html);
    }
    
    /**
     * 鑾峰彇鎷栨嫿鍖哄煙鏍峰紡
     * 
     * 缁熶竴浣跨敤 inset box-shadow 浣滀负瑙嗚鏁堟灉锛岄伩鍏?outline 瀵艰嚧鐨勫竷灞€闂
     */
    private function getDropZoneStyles(): string
    {
        return '<style>
            /* 鎷栨嫿鎻掓Ы鍖哄煙 */
            .pb-slot {
                position: relative;
                min-height: 50px;
                transition: box-shadow 0.2s ease;
            }
            /* 绉婚櫎 pb-slot 鍜?pb-slot-content 鐨?hover 鏁堟灉 */
            .pb-slot:hover,
            .pb-slot-content:hover {
                box-shadow: none !important;
            }
            .pb-slot.drag-over {
                box-shadow: inset 0 0 0 3px #4a90d9;
                background: rgba(74, 144, 217, 0.05);
            }
            
            /* 鎻掓Ы鍚嶇О鏍囩 */
            .pb-slot::before {
                content: attr(data-slot-name);
                position: absolute;
                top: 0;
                left: 0;
                background: rgba(74, 144, 217, 0.9);
                color: white;
                padding: 2px 8px;
                font-size: 11px;
                font-weight: 500;
                border-radius: 0 0 4px 0;
                opacity: 0;
                transition: opacity 0.2s ease;
                z-index: 1000;
                pointer-events: none;
            }
            /* 绉婚櫎 hover 鏃舵樉绀烘爣绛剧殑鏁堟灉 */
            .pb-slot:hover::before,
            .pb-slot-content:hover::before {
                opacity: 0 !important;
            }
            
            /* 缁勪欢鍖呰鍣紙缁熶竴鏍峰紡锛岄€傜敤浜庢墍鏈夋ā鏉匡級 */
            .tpmst-component-wrapper,
            .pb-component-wrapper {
                position: relative !important;
                transition: box-shadow 0.2s ease;
                overflow: visible !important;
                z-index: 1 !important;
            }
            .tpmst-component-wrapper[data-region="header"],
            .tpmst-component-wrapper[data-region="footer"],
            .pb-component-wrapper[data-region="header"],
            .pb-component-wrapper[data-region="footer"] {
                z-index: 10000 !important;
            }
            .tpmst-component-wrapper:hover,
            .pb-component-wrapper:hover {
                border: 2px dashed rgba(52, 152, 219, 0.6) !important;
                box-shadow: inset 0 0 0 2px rgba(52, 152, 219, 0.3) !important;
                background: transparent !important;
                background-color: transparent !important;
                z-index: 10001 !important;
            }
            .tpmst-component-wrapper.selected,
            .pb-component-wrapper.selected {
                box-shadow: inset 0 0 0 3px #4a90d9;
            }
            
            /* 缁勪欢鎷栨嫿鐘舵€?*/
            .tpmst-component-wrapper.dragging,
            .pb-component-wrapper.dragging {
                opacity: 0.6;
                box-shadow: inset 0 0 0 2px rgba(74, 144, 217, 0.8);
            }
            
            /* 缁勪欢鎿嶄綔鎸夐挳瀹瑰櫒 */
            .tpmst-component-wrapper .component-actions,
            .pb-component-wrapper .component-actions {
                position: absolute !important;
                top: 8px !important;
                right: 8px !important;
                left: auto !important;
                bottom: auto !important;
                display: none !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: flex-end !important;
                flex-wrap: wrap !important;
                gap: 6px;
                z-index: 100000 !important;
                background: rgba(15, 23, 42, 0.88) !important;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                padding: 6px !important;
                border-radius: 999px !important;
                box-shadow: 0 10px 30px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(148, 163, 184, 0.22) !important;
                pointer-events: auto !important;
                margin: 0 !important;
                min-width: auto !important;
                width: auto !important;
                height: auto !important;
            }
            .tpmst-component-wrapper:hover .component-actions,
            .pb-component-wrapper:hover .component-actions,
            .tpmst-component-wrapper:focus-within .component-actions,
            .pb-component-wrapper:focus-within .component-actions,
            .tpmst-component-wrapper.selected .component-actions,
            .pb-component-wrapper.selected .component-actions,
            .tpmst-component-wrapper .component-actions.pb-actions-visible,
            .pb-component-wrapper .component-actions.pb-actions-visible,
            .tpmst-component-wrapper .component-actions:hover,
            .pb-component-wrapper .component-actions:hover {
                display: flex !important;
            }
            html.pb-standalone-visual-preview .tpmst-component-wrapper > .component-actions,
            html.pb-standalone-visual-preview .pb-component-wrapper > .component-actions,
            body.pb-standalone-visual-preview .tpmst-component-wrapper > .component-actions,
            body.pb-standalone-visual-preview .pb-component-wrapper > .component-actions {
                display: flex !important;
            }
            .tpmst-component-wrapper.selected > .component-actions,
            .pb-component-wrapper.selected > .component-actions {
                position: sticky !important;
                top: 8px !important;
                margin: 8px 8px 0 auto !important;
                justify-content: flex-end !important;
                width: max-content !important;
            }
            .tpmst-component-wrapper[data-region="header"] .component-actions,
            .tpmst-component-wrapper[data-region="footer"] .component-actions,
            .pb-component-wrapper[data-region="header"] .component-actions,
            .pb-component-wrapper[data-region="footer"] .component-actions {
                z-index: 100001 !important;
            }
            .tpmst-component-wrapper .component-actions [data-pb-action="move-up"],
            .tpmst-component-wrapper .component-actions [data-pb-action="move-down"],
            .pb-component-wrapper .component-actions [data-pb-action="move-up"],
            .pb-component-wrapper .component-actions [data-pb-action="move-down"] {
                display: none !important;
            }
            .tpmst-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-up"],
            .tpmst-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-down"],
            .pb-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-up"],
            .pb-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-down"] {
                display: inline-flex !important;
            }
            .tpmst-component-wrapper .component-actions button,
            .pb-component-wrapper .component-actions button {
                height: 30px;
                padding: 0 12px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 0;
                border-radius: 999px;
                cursor: pointer;
                white-space: nowrap;
                font-size: 12px;
                line-height: 1;
                font-weight: 600;
                letter-spacing: 0.01em;
                color: #e2e8f0;
                background: rgba(255, 255, 255, 0.12);
                transition: transform 0.16s ease, background-color 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
            }
            .tpmst-component-wrapper .component-actions button:hover,
            .pb-component-wrapper .component-actions button:hover {
                transform: translateY(-1px);
                color: #ffffff;
                background: rgba(255, 255, 255, 0.22);
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="refine"],
            .pb-component-wrapper .component-actions button[data-pb-action="refine"] {
                background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(37, 99, 235, 0.92));
                color: #eff6ff;
                box-shadow: 0 8px 20px rgba(37, 99, 235, 0.28);
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="refine"]:hover,
            .pb-component-wrapper .component-actions button[data-pb-action="refine"]:hover {
                background: linear-gradient(135deg, rgba(2, 132, 199, 0.98), rgba(29, 78, 216, 0.94));
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="regenerate-block"],
            .pb-component-wrapper .component-actions button[data-pb-action="regenerate-block"] {
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.98), rgba(234, 88, 12, 0.94));
                color: #fff7ed;
                box-shadow: 0 8px 20px rgba(245, 158, 11, 0.24);
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="regenerate-block"]:hover,
            .pb-component-wrapper .component-actions button[data-pb-action="regenerate-block"]:hover {
                background: linear-gradient(135deg, rgba(217, 119, 6, 0.98), rgba(194, 65, 12, 0.94));
            }
            .tpmst-component-wrapper .component-actions button:disabled,
            .pb-component-wrapper .component-actions button:disabled {
                opacity: 0.45;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
            @media (max-width: 480px) {
                .tpmst-component-wrapper .component-actions,
                .pb-component-wrapper .component-actions {
                    position: sticky !important;
                    display: flex !important;
                    top: 6px !important;
                    right: 6px !important;
                    left: 6px !important;
                    max-width: calc(100% - 12px) !important;
                    width: auto !important;
                    justify-content: flex-start !important;
                    flex-wrap: wrap !important;
                    border-radius: 18px !important;
                    gap: 5px !important;
                    padding: 5px !important;
                }
                .tpmst-component-wrapper.selected > .component-actions,
                .pb-component-wrapper.selected > .component-actions {
                    margin: 6px !important;
                    width: auto !important;
                    max-width: calc(100% - 12px) !important;
                }
                .tpmst-component-wrapper .component-actions button,
                .pb-component-wrapper .component-actions button {
                    height: 28px !important;
                    padding: 0 9px !important;
                    font-size: 11px !important;
                }
            }
            .pb-standalone-editor-overlay {
                position: fixed;
                inset: 0;
                z-index: 200000;
                display: flex;
                align-items: stretch;
                justify-content: flex-end;
                background: rgba(15, 23, 42, 0.42);
                backdrop-filter: blur(3px);
                -webkit-backdrop-filter: blur(3px);
            }
            .pb-standalone-editor-panel {
                width: min(560px, calc(100vw - 24px));
                height: calc(100vh - 24px);
                margin: 12px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                color: #111827;
                background: #ffffff;
                border-radius: 16px;
                box-shadow: 0 24px 72px rgba(15, 23, 42, 0.32);
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }
            .pb-standalone-editor-header,
            .pb-standalone-editor-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 16px;
                border-bottom: 1px solid #e5e7eb;
            }
            .pb-standalone-editor-footer {
                border-top: 1px solid #e5e7eb;
                border-bottom: 0;
                justify-content: flex-end;
            }
            .pb-standalone-editor-title {
                min-width: 0;
                margin: 0;
                font-size: 16px;
                font-weight: 700;
                line-height: 1.35;
            }
            .pb-standalone-editor-body {
                flex: 1;
                overflow: auto;
                padding: 16px;
            }
            .pb-standalone-editor-field {
                margin-bottom: 14px;
            }
            .pb-standalone-editor-label {
                display: block;
                margin-bottom: 6px;
                color: #64748b;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.02em;
            }
            .pb-standalone-editor-input,
            .pb-standalone-editor-textarea {
                width: 100%;
                box-sizing: border-box;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                padding: 9px 10px;
                color: #111827;
                background: #ffffff;
                font: inherit;
                font-size: 13px;
                line-height: 1.5;
            }
            .pb-standalone-editor-textarea {
                min-height: 92px;
                resize: vertical;
            }
            .pb-standalone-editor-close,
            .pb-standalone-editor-cancel,
            .pb-standalone-editor-save,
            .pb-standalone-editor-ai-button {
                border: 0;
                border-radius: 8px;
                padding: 9px 14px;
                cursor: pointer;
                font-weight: 700;
            }
            .pb-standalone-editor-close {
                width: 34px;
                height: 34px;
                padding: 0;
                color: #64748b;
                background: #f1f5f9;
                font-size: 20px;
                line-height: 1;
            }
            .pb-standalone-editor-cancel {
                color: #334155;
                background: #e2e8f0;
            }
            .pb-standalone-editor-save {
                color: #ffffff;
                background: #10b981;
            }
            .pb-standalone-editor-ai-button {
                color: #ffffff;
                background: #2563eb;
                white-space: nowrap;
            }
            .pb-standalone-editor-save:disabled,
            .pb-standalone-editor-ai-button:disabled {
                opacity: 0.55;
                cursor: wait;
            }
            .pb-standalone-editor-message {
                min-height: 18px;
                margin-right: auto;
                color: #64748b;
                font-size: 12px;
                font-weight: 600;
            }
            .pb-standalone-editor-message.error {
                color: #dc2626;
            }
            .pb-standalone-editor-empty {
                padding: 14px;
                color: #64748b;
                background: #f8fafc;
                border: 1px dashed #cbd5e1;
                border-radius: 10px;
                font-size: 13px;
            }
            .pb-standalone-editor-ai-panel {
                margin-bottom: 16px;
                padding: 14px;
                border: 1px solid rgba(37, 99, 235, 0.18);
                border-radius: 12px;
                background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(14, 165, 233, 0.10));
            }
            .pb-standalone-editor-ai-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 10px;
            }
            .pb-standalone-editor-ai-hint {
                color: #475569;
                font-size: 12px;
                font-weight: 700;
                line-height: 1.45;
            }
            .pb-standalone-editor-ai-prompt {
                width: 100%;
                min-height: 66px;
                box-sizing: border-box;
                margin-bottom: 10px;
                border: 1px solid #bfdbfe;
                border-radius: 8px;
                padding: 9px 10px;
                color: #111827;
                background: #ffffff;
                font: inherit;
                font-size: 13px;
                line-height: 1.5;
                resize: vertical;
            }
            .pb-standalone-editor-ai-terminal {
                overflow: hidden;
                border: 1px solid rgba(148, 163, 184, 0.35);
                border-radius: 10px;
                background: rgba(15, 23, 42, 0.94);
                color: #e2e8f0;
            }
            .pb-standalone-editor-ai-terminal-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                padding: 8px 10px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.2);
                font-size: 12px;
                font-weight: 700;
            }
            .pb-standalone-editor-ai-status {
                opacity: 0.72;
                font-weight: 600;
            }
            .pb-standalone-editor-ai-log {
                max-height: 160px;
                overflow: auto;
                padding: 10px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 12px;
                line-height: 1.55;
            }
            .pb-standalone-editor-ai-line {
                margin-bottom: 6px;
                word-break: break-word;
            }
            .pb-standalone-editor-ai-line.error {
                color: #fca5a5;
            }
            .pb-standalone-editor-ai-line.done {
                color: #86efac;
            }
            .pb-standalone-editor-generated {
                border-color: #2563eb !important;
                box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18) !important;
            }
        </style>';
    }
    
    /**
     * 鑾峰彇鎷栨嫿鍖哄煙鑴氭湰
     * 
     * @param int $pageId 褰撳墠椤甸潰ID
     * @param int $layoutOwnerPageId 甯冨眬鎷ユ湁鑰呴〉闈D锛堢敤浜嶢PI璋冪敤锛?
     */
    private function getDropZoneScripts(int $pageId, int $layoutOwnerPageId): string
    {
        return '<script>
            (function() {
                // 鍙鍖栫紪杈戝櫒鑴氭湰
                window.__PAGEBUILDER_PAGE_ID__ = ' . $pageId . ';
                // 甯冨眬鎷ユ湁鑰呴〉闈D锛圓PI璋冪敤鏃朵娇鐢ㄦID锛?
                window.__PAGEBUILDER_LAYOUT_OWNER_PAGE_ID__ = ' . $layoutOwnerPageId . ';
                var pbStandalonePreview = !window.parent || window.parent === window;
                var pbVirtualEditorEnabled = false;
                try {
                    var pbEditorQuery = new URLSearchParams(window.location.search);
                    var pbVirtualThemeId = parseInt(pbEditorQuery.get("virtual_theme_id") || "0", 10) || 0;
                    var pbHasVirtualThemeWrapper = !!document.querySelector(
                        ".tpmst-component-wrapper[data-style-code=\"virtual-theme\"], .pb-component-wrapper[data-style-code=\"virtual-theme\"]"
                    );
                    pbVirtualEditorEnabled = pbEditorQuery.get("visual_editor") === "1"
                        && (pbVirtualThemeId > 0 || pbHasVirtualThemeWrapper);
                } catch (err) {
                    pbVirtualEditorEnabled = false;
                }
                if (pbStandalonePreview && pbVirtualEditorEnabled) {
                    document.documentElement.classList.add("pb-standalone-visual-preview");
                    if (document.body) {
                        document.body.classList.add("pb-standalone-visual-preview");
                    }
                }
                try {
                    var savedScrollY = window.sessionStorage.getItem("pbStandaloneEditorScrollY");
                    if (savedScrollY !== null) {
                        window.sessionStorage.removeItem("pbStandaloneEditorScrollY");
                        window.setTimeout(function() {
                            window.scrollTo(0, parseInt(savedScrollY, 10) || 0);
                        }, 80);
                    }
                } catch (err) {
                }
                
                // 鍒濆鍖栨嫋鎷藉尯鍩?
                document.querySelectorAll(".pb-slot").forEach(function(slot) {
                    slot.addEventListener("dragover", function(e) {
                        e.preventDefault();
                        this.classList.add("drag-over");
                    });
                    slot.addEventListener("dragleave", function(e) {
                        this.classList.remove("drag-over");
                    });
                    slot.addEventListener("drop", function(e) {
                        e.preventDefault();
                        this.classList.remove("drag-over");
                        // 閫氱煡鐖剁獥鍙?
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: "pb-component-drop",
                                region: this.dataset.region,
                                data: e.dataTransfer.getData("text/plain")
                            }, "*");
                        }
                    });
                });
                
                // 缁勪欢閫夋嫨
                document.querySelectorAll(".tpmst-component-wrapper").forEach(function(wrapper) {
                    wrapper.addEventListener("click", function(e) {
                        e.stopPropagation();
                        document.querySelectorAll(".tpmst-component-wrapper.selected").forEach(function(el) {
                            el.classList.remove("selected");
                        });
                        this.classList.add("selected");
                        // 閫氱煡鐖剁獥鍙?
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: "pb-component-select",
                                component: this.dataset.component,
                                region: this.dataset.region,
                                index: this.dataset.index
                            }, "*");
                        }
                    });
                });

                function buildComponentActionPayload(target, wrapper) {
                    var currentPageType = "";
                    try {
                        currentPageType = new URLSearchParams(window.location.search).get("page_type") || "";
                    } catch (err) {
                        currentPageType = "";
                    }
                    return {
                        type: "pb-component-action",
                        action: target.getAttribute("data-pb-action") || "",
                        component: wrapper.getAttribute("data-component") || wrapper.getAttribute("data-component-code") || wrapper.getAttribute("data-block-id") || "",
                        component_code: wrapper.getAttribute("data-component-code") || wrapper.getAttribute("data-component") || wrapper.getAttribute("data-block-id") || "",
                        block_id: wrapper.getAttribute("data-block-id") || wrapper.getAttribute("data-ai-block-id") || wrapper.getAttribute("data-component") || "",
                        page_type: wrapper.getAttribute("data-page-type") || currentPageType,
                        region: wrapper.getAttribute("data-region") || "",
                        index: wrapper.getAttribute("data-index") || ""
                    };
                }

                function readWrapperComponentConfig(wrapper) {
                    var script = null;
                    var child = wrapper ? wrapper.firstElementChild : null;
                    while (child) {
                        if (child.classList && child.classList.contains("pb-component-config-json")) {
                            script = child;
                            break;
                        }
                        child = child.nextElementSibling;
                    }
                    if (!script) {
                        return {};
                    }
                    try {
                        var parsed = JSON.parse(script.textContent || "{}");
                        return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : {};
                    } catch (err) {
                        return {};
                    }
                }

                function cloneEditorConfig(value) {
                    try {
                        return JSON.parse(JSON.stringify(value || {}));
                    } catch (err) {
                        return {};
                    }
                }

                function flattenEditorScalarFields(value, prefix, fields) {
                    if (value && typeof value === "object") {
                        Object.keys(value).forEach(function(key) {
                            var path = prefix ? prefix + "." + key : key;
                            flattenEditorScalarFields(value[key], path, fields);
                        });
                        return fields;
                    }
                    if (value === null || typeof value === "undefined") {
                        return fields;
                    }
                    if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
                        fields.push({
                            key: prefix,
                            value: String(value)
                        });
                    }
                    return fields;
                }

                function isStandaloneEditableFieldKey(key) {
                    var lower = String(key || "").toLowerCase();
                    if (!lower || lower.charAt(0) === "_" || lower.indexOf("._") !== -1) {
                        return false;
                    }
                    var blockedPrefixes = ["style.", "layout.", "runtime.", "debug.", "metadata.", "system.", "visual."];
                    for (var i = 0; i < blockedPrefixes.length; i++) {
                        if (lower.indexOf(blockedPrefixes[i]) === 0) {
                            return false;
                        }
                    }
                    if (lower.indexOf("style_") === 0 || lower.indexOf("layout_") === 0 || lower.indexOf("visual_") === 0) {
                        return false;
                    }
                    var allowedTokens = [
                        "content", "title", "heading", "headline", "subtitle", "subheading", "description",
                        "body", "text", "intro", "cta", "button", "label", "badge", "proof", "stat",
                        "kpi", "feature", "card", "item", "media", "image", "photo", "picture", "icon",
                        "alt", "src", "url", "href", "logo", "avatar"
                    ];
                    for (var j = 0; j < allowedTokens.length; j++) {
                        if (lower.indexOf(allowedTokens[j]) !== -1) {
                            return true;
                        }
                    }
                    return false;
                }

                function sortStandaloneEditorFields(left, right) {
                    var order = ["title", "heading", "headline", "subtitle", "description", "body", "text", "intro", "cta", "button", "image", "media", "proof", "stat", "kpi", "feature", "card", "item"];
                    function score(field) {
                        var key = String(field.key || "").toLowerCase();
                        for (var i = 0; i < order.length; i++) {
                            if (key.indexOf(order[i]) !== -1) {
                                return i;
                            }
                        }
                        return order.length;
                    }
                    var diff = score(left) - score(right);
                    return diff !== 0 ? diff : String(left.key || "").localeCompare(String(right.key || ""));
                }

                function appendVisibleFallbackFields(wrapper, fields) {
                    var existingValues = {};
                    fields.forEach(function(field) {
                        var value = String(field.value || "").trim();
                        if (value) {
                            existingValues[value] = true;
                        }
                    });
                    var textIndex = 1;
                    var walker = document.createTreeWalker(wrapper, NodeFilter.SHOW_TEXT, {
                        acceptNode: function(node) {
                            var parent = node.parentElement;
                            if (!parent || !parent.closest) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            if (parent.closest(".component-actions, .pb-component-config-json, .pb-standalone-editor-overlay, script, style")) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            var value = String(node.nodeValue || "").replace(/\s+/g, " ").trim();
                            if (value.length < 2 || existingValues[value]) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            return NodeFilter.FILTER_ACCEPT;
                        }
                    });
                    var node;
                    while ((node = walker.nextNode())) {
                        var text = String(node.nodeValue || "").replace(/\s+/g, " ").trim();
                        var token = "text_" + textIndex;
                        fields.push({
                            key: "visible_text." + token,
                            value: text,
                            originalKey: "_pb_static_text_original." + token,
                            originalValue: text
                        });
                        existingValues[text] = true;
                        textIndex++;
                    }

                    var imageIndex = 1;
                    wrapper.querySelectorAll("img").forEach(function(image) {
                        if (image.closest && image.closest(".component-actions, .pb-standalone-editor-overlay")) {
                            return;
                        }
                        var src = String(image.getAttribute("src") || "").trim();
                        var alt = String(image.getAttribute("alt") || "").trim();
                        if (src && !existingValues[src]) {
                            fields.push({
                                key: "visible_image.image_" + imageIndex + "_url",
                                value: src,
                                originalKey: "_pb_static_image_original.image_" + imageIndex + "_url",
                                originalValue: src
                            });
                            existingValues[src] = true;
                        }
                        if (alt && !existingValues[alt]) {
                            fields.push({
                                key: "visible_image.image_" + imageIndex + "_alt",
                                value: alt,
                                originalKey: "_pb_static_image_original.image_" + imageIndex + "_alt",
                                originalValue: alt
                            });
                            existingValues[alt] = true;
                        }
                        imageIndex++;
                    });
                    return fields;
                }

                function getStandaloneEditorFields(config, wrapper) {
                    var fields = flattenEditorScalarFields(config || {}, "", [])
                        .filter(function(field) {
                            return isStandaloneEditableFieldKey(field.key);
                        });
                    fields = appendVisibleFallbackFields(wrapper, fields);
                    if (fields.length === 0) {
                        fields = flattenEditorScalarFields(config || {}, "", [])
                            .filter(function(field) {
                                return String(field.key || "").charAt(0) !== "_";
                            });
                    }
                    var seen = {};
                    fields = fields.filter(function(field) {
                        if (!field.key || seen[field.key]) {
                            return false;
                        }
                        seen[field.key] = true;
                        return true;
                    });
                    fields.sort(sortStandaloneEditorFields);
                    return fields;
                }

                function setEditorConfigValue(config, key, value) {
                    if (!config || typeof config !== "object" || !key) {
                        return;
                    }
                    config[key] = value;
                    var parts = String(key).split(".");
                    if (parts.length < 2) {
                        return;
                    }
                    var cursor = config;
                    for (var i = 0; i < parts.length; i++) {
                        var part = parts[i];
                        if (i === parts.length - 1) {
                            cursor[part] = value;
                            return;
                        }
                        if (!cursor[part] || typeof cursor[part] !== "object") {
                            var nextPart = parts[i + 1];
                            cursor[part] = String(parseInt(nextPart, 10)) === nextPart ? [] : {};
                        }
                        cursor = cursor[part];
                    }
                }

                function createStandaloneEditorEl(tag, className, text) {
                    var el = document.createElement(tag);
                    if (className) {
                        el.className = className;
                    }
                    if (typeof text !== "undefined") {
                        el.textContent = String(text);
                    }
                    return el;
                }

                function shouldUseStandaloneTextarea(key, value) {
                    var lower = String(key || "").toLowerCase();
                    return String(value || "").length > 90
                        || String(value || "").indexOf("\n") !== -1
                        || lower.indexOf("description") !== -1
                        || lower.indexOf("body") !== -1
                        || lower.indexOf("intro") !== -1
                        || lower.indexOf("html") !== -1;
                }

                function resolveStandaloneUpdateBlockUrl() {
                    var path = window.location.pathname.replace(/\/workspace-preview\/?$/, "/post-update-block-config");
                    if (path === window.location.pathname) {
                        path = window.location.pathname.replace("workspace-preview", "post-update-block-config");
                    }
                    return window.location.origin + path;
                }

                function resolveStandaloneComponentConfigStreamUrl() {
                    var path = window.location.pathname.replace(/\/pagebuilder\/backend\/ai-site-agent\/workspace-preview\/?$/, "/pagebuilder/backend/aigenerate/componentconfigstream");
                    if (path === window.location.pathname) {
                        path = window.location.pathname.replace("/pagebuilder/backend/ai-site-agent/workspace-preview", "/pagebuilder/backend/aigenerate/componentconfigstream");
                    }
                    if (path === window.location.pathname) {
                        return "";
                    }
                    return window.location.origin + path;
                }

                function collectStandaloneEditorConfig(rawConfig, body) {
                    var nextConfig = cloneEditorConfig(rawConfig);
                    body.querySelectorAll("[data-field-key]").forEach(function(input) {
                        setEditorConfigValue(nextConfig, input.getAttribute("data-field-key") || "", input.value);
                        var originalKey = input.getAttribute("data-original-key") || "";
                        if (originalKey) {
                            setEditorConfigValue(nextConfig, originalKey, input.getAttribute("data-original-value") || "");
                        }
                    });
                    return nextConfig;
                }

                function appendStandaloneAiLog(container, text, type) {
                    if (!container) {
                        return;
                    }
                    var line = createStandaloneEditorEl("div", "pb-standalone-editor-ai-line " + String(type || "info"));
                    var time = createStandaloneEditorEl("span", "", "[" + new Date().toLocaleTimeString("zh-CN", { hour12: false }) + "] ");
                    time.style.opacity = "0.58";
                    line.appendChild(time);
                    line.appendChild(document.createTextNode(String(text || "")));
                    container.appendChild(line);
                    container.scrollTop = container.scrollHeight;
                }

                function setStandaloneAiStatus(statusEl, text) {
                    if (statusEl) {
                        statusEl.textContent = String(text || "");
                    }
                }

                function findStandaloneEditorInput(body, fieldKey) {
                    var target = String(fieldKey || "");
                    var inputs = body.querySelectorAll("[data-field-key]");
                    for (var i = 0; i < inputs.length; i++) {
                        if (String(inputs[i].getAttribute("data-field-key") || "") === target) {
                            return inputs[i];
                        }
                    }
                    return null;
                }

                function stringifyStandaloneGeneratedValue(value) {
                    if (Array.isArray(value)) {
                        return value.map(function(item) {
                            return item == null ? "" : String(item);
                        }).join("\n");
                    }
                    if (value && typeof value === "object") {
                        try {
                            return JSON.stringify(value);
                        } catch (err) {
                            return "";
                        }
                    }
                    return value == null ? "" : String(value);
                }

                function applyStandaloneGeneratedConfig(body, data) {
                    var filledCount = 0;
                    Object.keys(data || {}).forEach(function(key) {
                        if (!key || String(key).charAt(0) === "_") {
                            return;
                        }
                        var input = findStandaloneEditorInput(body, key);
                        if (!input) {
                            return;
                        }
                        input.value = stringifyStandaloneGeneratedValue(data[key]);
                        input.classList.add("pb-standalone-editor-generated");
                        input.setAttribute("data-ai-generated-value", "1");
                        input.dispatchEvent(new Event("input", { bubbles: true }));
                        input.dispatchEvent(new Event("change", { bubbles: true }));
                        window.setTimeout(function() {
                            input.classList.remove("pb-standalone-editor-generated");
                        }, 1800);
                        filledCount++;
                    });
                    return filledCount;
                }

                async function submitStandaloneEditorAiGenerate(payload, wrapper, rawConfig, body, aiBtn, terminalContent, terminalStatus, aiPromptEl, retryCount) {
                    var streamUrl = resolveStandaloneComponentConfigStreamUrl();
                    var query = new URLSearchParams(window.location.search);
                    var componentCode = payload.component_code || payload.component || payload.block_id || "";
                    var currentRetryCount = parseInt(retryCount || 0, 10) || 0;
                    if (!streamUrl || !componentCode) {
                        appendStandaloneAiLog(terminalContent, "Missing component generation context.", "error");
                        return;
                    }
                    var originalText = aiBtn ? aiBtn.textContent : "";
                    if (aiBtn) {
                        aiBtn.disabled = true;
                        aiBtn.textContent = "Generating...";
                    }
                    if (terminalContent && currentRetryCount <= 0) {
                        terminalContent.innerHTML = "";
                    }
                    setStandaloneAiStatus(terminalStatus, "Connecting...");
                    appendStandaloneAiLog(terminalContent, currentRetryCount > 0 ? "Reconnecting to AI generator..." : "Connecting to AI generator...", "info");

                    var streamTimeoutId = null;
                    function clearStreamTimeout() {
                        if (streamTimeoutId) {
                            window.clearTimeout(streamTimeoutId);
                            streamTimeoutId = null;
                        }
                    }
                    function restoreButton() {
                        if (aiBtn) {
                            aiBtn.disabled = false;
                            aiBtn.textContent = originalText || "AI Generate";
                        }
                        setStandaloneAiStatus(terminalStatus, "Disconnected");
                    }

                    try {
                        var aiPromptValue = aiPromptEl && aiPromptEl.value ? String(aiPromptEl.value).trim() : "";
                        var formData = new FormData();
                        formData.append("public_id", query.get("public_id") || "");
                        formData.append("page_type", payload.page_type || query.get("page_type") || "");
                        formData.append("style_code", wrapper.getAttribute("data-style-code") || query.get("style_code") || "virtual-theme");
                        formData.append("component_code", componentCode);
                        formData.append("region", payload.region || "content");
                        formData.append("index", String(payload.index || 0));
                        formData.append("ai_prompt", aiPromptValue);
                        formData.append("current_config", JSON.stringify(collectStandaloneEditorConfig(rawConfig, body)));

                        var abortController = typeof AbortController !== "undefined" ? new AbortController() : null;
                        streamTimeoutId = window.setTimeout(function() {
                            if (abortController) {
                                abortController.abort();
                            }
                        }, 190000);

                        var response = await fetch(streamUrl, {
                            method: "POST",
                            headers: {
                                "X-Requested-With": "XMLHttpRequest",
                                "Accept": "text/event-stream"
                            },
                            credentials: "same-origin",
                            signal: abortController ? abortController.signal : undefined,
                            body: formData
                        });
                        if (!response.ok) {
                            throw new Error(response.statusText || "AI generation request failed.");
                        }
                        if (!response.body) {
                            throw new Error("This browser cannot read the AI stream.");
                        }

                        var reader = response.body.getReader();
                        var decoder = new TextDecoder();
                        var buffer = "";
                        var completed = false;

                        function handleEvent(eventName, eventData) {
                            if (eventName) {
                                clearStreamTimeout();
                            }
                            var eventPayload = {};
                            try {
                                eventPayload = eventData ? JSON.parse(eventData) : {};
                            } catch (parseError) {
                                eventPayload = {};
                            }
                            if (eventName === "start" || eventName === "progress") {
                                if (eventPayload.message) {
                                    appendStandaloneAiLog(terminalContent, eventPayload.message, "info");
                                    setStandaloneAiStatus(terminalStatus, "Connected");
                                }
                                return;
                            }
                            if (eventName === "context" && eventPayload.display_text) {
                                appendStandaloneAiLog(terminalContent, eventPayload.display_text, "info");
                                return;
                            }
                            if ((eventName === "thinking" || eventName === "chunk") && eventPayload.content) {
                                appendStandaloneAiLog(terminalContent, eventPayload.content, "info");
                                return;
                            }
                            if (eventName === "done") {
                                if (completed && !eventPayload.data) {
                                    return;
                                }
                                completed = true;
                                var generatedData = eventPayload.data || {};
                                var filledCount = applyStandaloneGeneratedConfig(body, generatedData);
                                appendStandaloneAiLog(terminalContent, filledCount > 0 ? ("Generated values applied: " + filledCount) : "AI returned no matching fields for this block.", filledCount > 0 ? "done" : "error");
                                restoreButton();
                                return;
                            }
                            if (eventName === "error" || eventName === "stream_error") {
                                completed = true;
                                restoreButton();
                                throw new Error(String(eventPayload.message || "AI generation failed."));
                            }
                        }

                        while (true) {
                            var readResult = await reader.read();
                            if (readResult && readResult.value) {
                                buffer += decoder.decode(readResult.value, { stream: true });
                            } else if (readResult && readResult.done) {
                                buffer += decoder.decode();
                            }
                            buffer = buffer.replace(/\r\n/g, "\n");
                            var parts = buffer.split("\n\n");
                            buffer = parts.pop() || "";
                            parts.forEach(function(part) {
                                var eventName = "";
                                var eventData = "";
                                part.split("\n").forEach(function(line) {
                                    if (line.indexOf("event:") === 0) {
                                        eventName = line.replace(/^event:\s*/, "").trim();
                                    } else if (line.indexOf("data:") === 0) {
                                        var dataLine = line.replace(/^data:\s*/, "");
                                        eventData = eventData ? (eventData + "\n" + dataLine) : dataLine;
                                    }
                                });
                                if (eventName) {
                                    handleEvent(eventName, eventData);
                                }
                            });
                            if (readResult && readResult.done) {
                                break;
                            }
                        }
                        if (!completed) {
                            clearStreamTimeout();
                            restoreButton();
                            if (currentRetryCount < 1) {
                                appendStandaloneAiLog(terminalContent, "AI stream ended before completion. Retrying once...", "info");
                                return submitStandaloneEditorAiGenerate(payload, wrapper, rawConfig, body, aiBtn, terminalContent, terminalStatus, aiPromptEl, currentRetryCount + 1);
                            }
                            appendStandaloneAiLog(terminalContent, "AI stream ended before completion. Please retry.", "error");
                        }
                    } catch (error) {
                        clearStreamTimeout();
                        restoreButton();
                        appendStandaloneAiLog(terminalContent, String(error && error.message ? error.message : "AI generation request failed."), "error");
                    }
                }

                function openStandaloneComponentEditor(payload, wrapper) {
                    if (!pbVirtualEditorEnabled) {
                        return false;
                    }
                    if (!wrapper || !payload || String(payload.action || "") !== "edit-block") {
                        return false;
                    }
                    var existing = document.querySelector(".pb-standalone-editor-overlay");
                    if (existing && existing.parentNode) {
                        existing.parentNode.removeChild(existing);
                    }
                    var rawConfig = readWrapperComponentConfig(wrapper);
                    var fields = getStandaloneEditorFields(rawConfig, wrapper);
                    var overlay = createStandaloneEditorEl("div", "pb-standalone-editor-overlay");
                    var panel = createStandaloneEditorEl("div", "pb-standalone-editor-panel");
                    var header = createStandaloneEditorEl("div", "pb-standalone-editor-header");
                    var title = createStandaloneEditorEl("h2", "pb-standalone-editor-title", "Edit block field - " + (payload.component_code || payload.block_id || payload.component || ""));
                    var closeBtn = createStandaloneEditorEl("button", "pb-standalone-editor-close", "x");
                    closeBtn.type = "button";
                    header.appendChild(title);
                    header.appendChild(closeBtn);

                    var body = createStandaloneEditorEl("div", "pb-standalone-editor-body");
                    var aiPanel = createStandaloneEditorEl("div", "pb-standalone-editor-ai-panel");
                    var aiHead = createStandaloneEditorEl("div", "pb-standalone-editor-ai-head");
                    var aiHint = createStandaloneEditorEl("div", "pb-standalone-editor-ai-hint", "AI can supplement the current block fields from page context.");
                    var aiBtn = createStandaloneEditorEl("button", "pb-standalone-editor-ai-button", "AI Generate");
                    aiBtn.type = "button";
                    aiHead.appendChild(aiHint);
                    aiHead.appendChild(aiBtn);
                    var aiPrompt = createStandaloneEditorEl("textarea", "pb-standalone-editor-ai-prompt");
                    aiPrompt.rows = 2;
                    aiPrompt.placeholder = "Optional: tell AI how to adjust this block.";
                    aiPrompt.value = rawConfig && rawConfig._ai_prompt != null ? String(rawConfig._ai_prompt) : "";
                    aiPrompt.setAttribute("data-field-key", "_ai_prompt");
                    var aiTerminal = createStandaloneEditorEl("div", "pb-standalone-editor-ai-terminal");
                    var aiTerminalHead = createStandaloneEditorEl("div", "pb-standalone-editor-ai-terminal-head");
                    var aiTerminalTitle = createStandaloneEditorEl("span", "", "Generation process");
                    var aiTerminalStatus = createStandaloneEditorEl("span", "pb-standalone-editor-ai-status", "Disconnected");
                    var aiTerminalLog = createStandaloneEditorEl("div", "pb-standalone-editor-ai-log");
                    aiTerminalHead.appendChild(aiTerminalTitle);
                    aiTerminalHead.appendChild(aiTerminalStatus);
                    aiTerminal.appendChild(aiTerminalHead);
                    aiTerminal.appendChild(aiTerminalLog);
                    aiPanel.appendChild(aiHead);
                    aiPanel.appendChild(aiPrompt);
                    aiPanel.appendChild(aiTerminal);
                    body.appendChild(aiPanel);
                    if (fields.length === 0) {
                        body.appendChild(createStandaloneEditorEl("div", "pb-standalone-editor-empty", "No editable text or image fields were found for this block."));
                    }
                    fields.forEach(function(field) {
                        var wrap = createStandaloneEditorEl("div", "pb-standalone-editor-field");
                        var label = createStandaloneEditorEl("label", "pb-standalone-editor-label", field.key);
                        var input = shouldUseStandaloneTextarea(field.key, field.value)
                            ? createStandaloneEditorEl("textarea", "pb-standalone-editor-textarea")
                            : createStandaloneEditorEl("input", "pb-standalone-editor-input");
                        if (input.tagName.toLowerCase() === "input") {
                            input.type = "text";
                        } else {
                            input.rows = 4;
                        }
                        input.value = field.value;
                        input.setAttribute("data-field-key", field.key);
                        if (field.originalKey) {
                            input.setAttribute("data-original-key", field.originalKey);
                            input.setAttribute("data-original-value", field.originalValue || "");
                        }
                        wrap.appendChild(label);
                        wrap.appendChild(input);
                        body.appendChild(wrap);
                    });

                    var footer = createStandaloneEditorEl("div", "pb-standalone-editor-footer");
                    var message = createStandaloneEditorEl("div", "pb-standalone-editor-message");
                    var cancelBtn = createStandaloneEditorEl("button", "pb-standalone-editor-cancel", "Cancel");
                    var saveBtn = createStandaloneEditorEl("button", "pb-standalone-editor-save", "Save");
                    cancelBtn.type = "button";
                    saveBtn.type = "button";
                    footer.appendChild(message);
                    footer.appendChild(cancelBtn);
                    footer.appendChild(saveBtn);
                    panel.appendChild(header);
                    panel.appendChild(body);
                    panel.appendChild(footer);
                    overlay.appendChild(panel);
                    document.body.appendChild(overlay);

                    function close() {
                        if (overlay.parentNode) {
                            overlay.parentNode.removeChild(overlay);
                        }
                    }
                    closeBtn.addEventListener("click", close);
                    cancelBtn.addEventListener("click", close);
                    overlay.addEventListener("click", function(event) {
                        if (event.target === overlay) {
                            close();
                        }
                    });
                    aiBtn.addEventListener("click", function() {
                        submitStandaloneEditorAiGenerate(payload, wrapper, rawConfig, body, aiBtn, aiTerminalLog, aiTerminalStatus, aiPrompt);
                    });
                    saveBtn.addEventListener("click", function() {
                        var nextConfig = collectStandaloneEditorConfig(rawConfig, body);
                        var params = new URLSearchParams();
                        var query = new URLSearchParams(window.location.search);
                        params.set("public_id", query.get("public_id") || "");
                        params.set("page_type", payload.page_type || query.get("page_type") || "");
                        params.set("block_id", payload.block_id || payload.component || payload.component_code || "");
                        params.set("component_code", payload.component_code || payload.component || payload.block_id || "");
                        params.set("region", payload.region || "");
                        params.set("index", payload.index || "");
                        params.set("block_config", JSON.stringify(nextConfig));
                        saveBtn.disabled = true;
                        message.classList.remove("error");
                        message.textContent = "Saving...";
                        fetch(resolveStandaloneUpdateBlockUrl(), {
                            method: "POST",
                            credentials: "same-origin",
                            headers: {
                                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                                "X-Requested-With": "XMLHttpRequest"
                            },
                            body: params
                        }).then(function(response) {
                            return response.json();
                        }).then(function(data) {
                            if (!data || data.success === false) {
                                throw new Error(String(data && data.message ? data.message : "Save failed"));
                            }
                            try {
                                window.sessionStorage.setItem("pbStandaloneEditorScrollY", String(window.scrollY || 0));
                            } catch (err) {
                            }
                            window.location.reload();
                        }).catch(function(error) {
                            saveBtn.disabled = false;
                            message.classList.add("error");
                            message.textContent = String(error && error.message ? error.message : "Save failed");
                        });
                    });
                    return true;
                }

                window.__pbOpenStandaloneComponentEditor = openStandaloneComponentEditor;

                function dispatchComponentAction(target, e) {
                    if (!target) {
                        return true;
                    }
                    if (e && typeof e.preventDefault === "function") {
                        e.preventDefault();
                    }
                    var wrapper = target.closest(".pb-ai-block-wrapper, .tpmst-component-wrapper, .pb-component-wrapper");
                    if (!wrapper) {
                        return true;
                    }
                    var payload = buildComponentActionPayload(target, wrapper);
                    if (openStandaloneComponentEditor(payload, wrapper)) {
                        if (e && typeof e.stopPropagation === "function") {
                            e.stopPropagation();
                        }
                        if (e && typeof e.stopImmediatePropagation === "function") {
                            e.stopImmediatePropagation();
                        }
                        return false;
                    }
                    if (!window.parent || window.parent === window) {
                        return true;
                    }
                    try {
                        if (
                            window.parent.PbAiWorkspacePreview
                            && typeof window.parent.PbAiWorkspacePreview.handleEmbeddedPreviewAction === "function"
                            && window.parent.PbAiWorkspacePreview.handleEmbeddedPreviewAction(payload)
                        ) {
                            if (e && typeof e.stopPropagation === "function") {
                                e.stopPropagation();
                            }
                            if (e && typeof e.stopImmediatePropagation === "function") {
                                e.stopImmediatePropagation();
                            }
                            return false;
                        }
                    } catch (err) {
                    }
                    window.parent.postMessage(payload, "*");
                    return false;
                }

                window.__pbDispatchComponentActionFromButton = function(target, e) {
                    return dispatchComponentAction(target, e);
                };

                function handleComponentActionEvent(e) {
                    var source = e.target && e.target.nodeType === 3 ? e.target.parentElement : e.target;
                    var target = source && source.closest ? source.closest(".component-actions [data-pb-action]") : null;
                    dispatchComponentAction(target, e);
                }

                document.addEventListener("mousedown", handleComponentActionEvent, true);
                document.addEventListener("click", handleComponentActionEvent, true);
            })();
        </script>';
    }
    
    /**
     * 娓呴櫎缂撳瓨
     */
    public static function clearCache(): void
    {
        self::$componentFilesCache = [];
    }
}
