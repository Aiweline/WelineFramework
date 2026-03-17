<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
use GuoLaiRen\PageBuilder\Service\LayoutAssembler;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

/**
 * 页面预览控制器
 * 用于实时预览头部、内容、页脚配置
 * 
 * 渲染流程：
 * 1. 获取页面布局配置（layout_config）
 * 2. Header/Footer 从首页获取（全局），Content 从当前页面获取
 * 3. 使用 PageRenderService 统一渲染（与前端保持一致）
 * 4. 根据 visual_editor 参数决定是否添加可视化编辑功能
 */
class Preview extends BackendController
{
    /** 预览输出完整 HTML 文档，禁止使用后台布局包裹 */
    protected ?string $layoutType = null;

    private Page $pageModel;
    private LocalDescription $localDescriptionModel;
    private Style $styleModel;
    private LayoutAssembler $layoutAssembler;
    private PageRenderService $pageRenderService;

    public function __construct(
        Page $pageModel,
        LocalDescription $localDescriptionModel,
        Style $styleModel,
        PageRenderService $pageRenderService
    ) {
        $this->pageModel = $pageModel;
        $this->localDescriptionModel = $localDescriptionModel;
        $this->styleModel = $styleModel;
        $this->layoutAssembler = ObjectManager::getInstance(LayoutAssembler::class);
        $this->pageRenderService = $pageRenderService;
    }

    /**
     * 预览头部
     */
    public function header()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            $locale = $this->request->getGet('locale');
            
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式代码和配置
            $styleCode = $page->getData('style') ?: 'default';
            $currentStyleSettings = $this->extractStyleSettings($page, $styleCode, $locale);

            // 构建头部模板路径
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/header.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $currentStyleSettings);
            $this->assign('is_preview', true);
            
            // 渲染模板
            $html = $this->fetch($templatePath);
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'style_code' => $styleCode
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 预览内容
     */
    public function content()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            $locale = $this->request->getGet('locale');
            
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式代码和配置
            $styleCode = $page->getData('style') ?: 'default';
            $currentStyleSettings = $this->extractStyleSettings($page, $styleCode, $locale);

            // 构建内容模板路径
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/content.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $currentStyleSettings);
            $this->assign('is_preview', true);
            
            // 渲染模板
            $html = $this->fetch($templatePath);
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'style_code' => $styleCode
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 预览页脚
     */
    public function footer()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            $locale = $this->request->getGet('locale');
            
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式代码和配置
            $styleCode = $page->getData('style') ?: 'default';
            $currentStyleSettings = $this->extractStyleSettings($page, $styleCode, $locale);

            // 构建页脚模板路径
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/footer.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $currentStyleSettings);
            $this->assign('is_preview', true);
            
            // 渲染模板
            $html = $this->fetch($templatePath);
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'style_code' => $styleCode
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取模板默认配置
     * 
     * @param string $styleCode 样式代码
     * @return array 默认配置数组
     */
    private function getTemplateDefaults(string $styleCode): array
    {
        $templateDefaults = [];
        try {
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(\GuoLaiRen\PageBuilder\Model\Style::schema_fields_CODE, $styleCode)->find()->fetch();
            if ($styleModel->getId()) {
                $configGroups = $styleModel->getConfigGroups();
                // 遍历所有配置项，提取默认值
                foreach ($configGroups as $fileKey => $fileGroup) {
                    if (isset($fileGroup['groups'])) {
                        foreach ($fileGroup['groups'] as $groupKey => $group) {
                            if (isset($group['configs'])) {
                                foreach ($group['configs'] as $configKey => $config) {
                                    if (isset($config['default']) && $config['default'] !== '') {
                                        $templateDefaults[$configKey] = $config['default'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果获取模板默认值失败，返回空数组
        }
        return $templateDefaults;
    }

    /**
     * 提取样式配置（支持多语言）
     * 优先级：翻译配置 > 页面配置 > 模板默认值
     * 
     * @param Page $page 页面对象
     * @param string $styleCode 样式代码
     * @param string|null $locale 语言代码
     * @return array 配置数组
     */
    private function extractStyleSettings($page, string $styleCode, ?string $locale = null): array
    {
        $defaultLocale = $page->getData('default_locale') ?: '';
        
        // 1. 获取模板默认值
        $templateDefaults = $this->getTemplateDefaults($styleCode);
        
        // 2. 获取页面保存的配置（主表配置）
        $allSettings = $page->getStyleSetting();
        $mainSettings = isset($allSettings[$styleCode]) ? $allSettings[$styleCode] : [];
        
        // 清理可能存在的三层结构（只保留配置项）
        $cleanMainSettings = [];
        foreach ($mainSettings as $key => $value) {
            if (!is_array($value)) {
                $cleanMainSettings[$key] = $value;
            }
        }
        
        // 合并：页面配置覆盖模板默认值
        $finalSettings = array_merge($templateDefaults, $cleanMainSettings);
        
        // 3. 如果是非默认语言，尝试从LocalDescription获取覆盖配置
        if ($locale && $locale !== $defaultLocale) {
            $localDesc = clone $this->localDescriptionModel;
            $localDesc->clear()
                ->where(\GuoLaiRen\PageBuilder\Model\Page\LocalDescription::schema_fields_ID, $page->getId())
                ->where('local_code', $locale)
                ->find()
                ->fetch();
            
            if ($localDesc->getId()) {
                $configJson = $localDesc->getData('config');
                if ($configJson) {
                    $config = json_decode($configJson ?? '', true);
                    if (isset($config['style_config']) && is_array($config['style_config'])) {
                        // 语言特定配置覆盖之前的配置
                        $finalSettings = array_merge($finalSettings, $config['style_config']);
                    }
                }
            }
        }
        
        // 返回最终配置：模板默认值 + 页面配置 + 翻译配置（优先级递增）
        return $finalSettings;
    }

    /**
     * 完整预览（头部+内容+页脚）
     * 组合 style/{styleCode}/header.phtml、content.phtml、footer.phtml 三个模板
     * 支持临时切换样式（用于样式选择器预览）
     * 
     * 【重要】使用 PageRenderService 统一渲染逻辑，确保与前端 Page.php::view() 完全一致
     * 
     * 渲染模式：
     * - visual_editor=1: 可视化编辑模式（带拖拽插槽容器、组件包装器）
     * - 无 visual_editor: 预览模式（纯净渲染，与正式上线效果一致）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_preview', '页面预览', '', '页面预览')]
    public function full()
    {
        // 预览禁止缓存，确保拖拽添加组件后刷新能立即看到最新内容
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');

        $pageId = (int)$this->request->getGet('page_id');
        $locale = $this->request->getGet('locale');
        $tempStyleCode = $this->request->getGet('style_code'); // 临时样式代码（用于预览）
        
        if (!$pageId) {
            echo '<div style="padding: 20px; color: red;">页面ID不能为空</div>';
            return;
        }

        // 加载页面数据（强制刷新，避免缓存）
        $page = clone $this->pageModel;
        $page->clearData(); // 清除可能存在的缓存数据
        $page->load($pageId);
        
        if (!$page->getId()) {
            echo '<div style="padding: 20px; color: red;">页面不存在</div>';
            return;
        }

        // 获取当前语言（从Cookie或URL参数）
        $currentLocale = $locale ?: State::getLang();
        
        // 检测渲染模式
        $isVisualEditor = $this->request->getGet('visual_editor') === '1';
        $renderMode = $isVisualEditor ? PageRenderService::MODE_VISUAL : PageRenderService::MODE_PREVIEW;
        
        // 使用 PageRenderService 统一渲染
        // 这确保了可视化编辑器预览和前端正式页面的渲染逻辑完全一致
        $html = $this->pageRenderService->render(
            $page,
            $renderMode,
            $currentLocale,
            $tempStyleCode
        );
        if ($isVisualEditor) {
            $html = $this->injectVisualEditorNavLinks($html, $page);
        }
        // 通过终止异常直接输出完整 HTML，避免被主题/布局包裹导致 header 等组件被塞进后台 layout 的 body
        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Accel-Expires' => '0',
        ];
        throw new ResponseTerminateException(200, $html, $headers);
    }

    /**
     * 获取当前页对应的「本站页面」列表（用于可视化编辑内 nav 链接转预览地址）
     * 逻辑与 AiGenerate::getExistingSitePagesList 一致：主页=子页面+首页自身，子页=同级+父页首页
     *
     * @return list<array{page_id: int, handle: string, url: string, title: string}>
     */
    private function getNavPagesForVisualEditor(Page $page): array
    {
        $parentId = (int)$page->getData(Page::schema_fields_PARENT_ID);
        try {
            if ($parentId === 0) {
                $list = $page->getChildPagesForNav(50);
                if ($page->getId() && $page->getData(Page::schema_fields_TYPE) === Page::TYPE_HOME) {
                    $h = $page->getData(Page::schema_fields_HANDLE);
                    $hStr = $h === null || $h === '' ? '' : (string)$h;
                    array_unshift($list, [
                        'title' => $page->getData(Page::schema_fields_TITLE) ?: $page->getData(Page::schema_fields_NAME),
                        'handle' => $hStr,
                        'url' => '/', // 首页直接用域名，不拼 handle
                        'type' => Page::TYPE_HOME,
                        'page_id' => $page->getId(),
                    ]);
                }
            } else {
                $list = $page->getSiblingPagesForNav(50);
            }
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $handle = $item['handle'] ?? '';
            $type = $item['type'] ?? '';
            $url = $item['url'] ?? '';
            if ($type === Page::TYPE_HOME) {
                $url = '/';
            } elseif ($url === '' && ($handle === '' || $handle === null)) {
                $url = '/';
            } elseif ($url === '' && $handle !== '') {
                $url = '/' . $handle;
            }
            $out[] = [
                'page_id' => (int)($item['page_id'] ?? 0),
                'handle' => $handle === '' || $handle === null ? '' : (string)$handle,
                'url' => $url,
                'title' => $item['title'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * 可视化编辑模式下注入脚本：将 header/footer 内站内链接转为 postMessage 通知父窗口跳转预览地址，避免离开编辑器
     */
    private function injectVisualEditorNavLinks(string $html, Page $page): string
    {
        $pages = $this->getNavPagesForVisualEditor($page);
        if (empty($pages)) {
            return $html;
        }
        $pagesJson = json_encode($pages, JSON_UNESCAPED_UNICODE);
        $script = <<<SCRIPT
<script>
(function(){
  var pages = {$pagesJson};
  function findPageIdByHref(href) {
    if (!href || href.charAt(0) === '#') return null;
    var path = href.replace(/^https?:\\/\\/[^/]*/, '').replace(/\\?.*\$/, '').split('#')[0] || '/';
    if (path === '') path = '/';
    for (var i = 0; i < pages.length; i++) {
      if (pages[i].url === path) return pages[i].page_id;
    }
    return null;
  }
  var sel = 'header a[href^="/"], footer a[href^="/"], [data-region="header"] a[href^="/"], [data-region="footer"] a[href^="/"], nav a[href^="/"]';
  var links = document.querySelectorAll(sel);
  for (var j = 0; j < links.length; j++) {
    var a = links[j];
    var href = a.getAttribute('href');
    var pageId = findPageIdByHref(href);
    if (pageId != null) {
      a.setAttribute('data-ve-page-id', String(pageId));
      a.setAttribute('href', '#');
      a.addEventListener('click', function(e) {
        e.preventDefault();
        var id = this.getAttribute('data-ve-page-id');
        if (id && window.parent !== window) {
          window.parent.postMessage({ type: 'PageBuilderVisualEditor', action: 'navigate', page_id: parseInt(id, 10) }, '*');
        }
      });
    }
  }
})();
</script>
SCRIPT;
        $pos = strripos($html, '</body>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . $script . "\n" . substr($html, $pos);
        }
        return $html . $script;
    }

    /**
     * 预览样式模板默认效果（无需页面ID）
     * 使用 PageRenderService 统一渲染，确保与正式页面渲染逻辑一致
     * 路由：pagebuilder/backend_preview/stylePreview?style_code=marketing-landing
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_preview', '样式模板预览', '', '样式模板预览')]
    public function stylePreview()
    {
        // 预览禁止缓存
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');

        $styleCode = $this->request->getGet('style_code');
        $locale = $this->request->getGet('locale', 'zh_Hans_CN'); // 默认语言
        $pageType = $this->request->getGet('page_type', Page::TYPE_HOME); // 默认首页类型
        
        if (!$styleCode) {
            echo '<div style="padding: 20px; color: red;">样式代码不能为空</div>';
            echo '<p style="padding: 0 20px;">请使用 ?style_code=样式代码 参数访问</p>';
            echo '<p style="padding: 0 20px;">例如: ?style_code=marketing-landing</p>';
            return;
        }

        // 为确保样式列表最新，先强制扫描一次
        try {
            \GuoLaiRen\PageBuilder\Model\Style::forceScan();
        } catch (\Throwable $e) {
            // 忽略扫描异常，继续按现有数据处理
        }

        // 验证样式是否存在
        $styleModel = clone $this->styleModel;
        $styleModel->clear()->where(\GuoLaiRen\PageBuilder\Model\Style::schema_fields_CODE, $styleCode)->find()->fetch();
        
        if (!$styleModel->getId()) {
            echo '<div style="padding: 20px; color: red;">样式模板不存在：' . htmlspecialchars($styleCode ?? '') . '</div>';
            return;
        }

        // 创建一个虚拟页面对象用于渲染
        // 使用 ObjectManager::make() 确保获得干净的实例
        // 注意：必须设置所有整数字段的默认值，避免 PostgreSQL 整数类型错误
        $dummyPage = ObjectManager::make(Page::class);
        $dummyPage->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => 0,
            Page::schema_fields_PARENT_ID => 0,
            Page::schema_fields_LAYOUT_PAGE_ID => null,
            Page::schema_fields_STATUS => 1,
            Page::schema_fields_TITLE => '样式模板预览 - ' . $styleModel->getData('name'),
            Page::schema_fields_NAME => '样式模板预览',
            Page::schema_fields_HANDLE => 'template-preview-' . $styleCode,
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType, // 设置页面类型，用于加载对应的默认布局配置
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_DESCRIPTION => '预览模式：使用模板默认配置',
            Page::schema_fields_LOCALES => '[]',
            Page::schema_fields_DEFAULT_LOCALE => $locale,
            Page::schema_fields_STYLE_SETTING => '{}',
            Page::schema_fields_LAYOUT_CONFIG => '{}',
        ]);

        try {
            // 使用 PageRenderService 统一渲染
            // 这确保了样式预览与正式页面渲染使用相同的逻辑
            $html = $this->pageRenderService->render(
                $dummyPage,
                PageRenderService::MODE_PREVIEW,
                $locale,
                $styleCode // 传递样式代码
            );
            
            echo $html;
        } catch (\Exception $e) {
            echo '<div style="padding: 20px; color: red;">';
            echo '<h3>模板渲染错误</h3>';
            echo '<p>样式代码：' . htmlspecialchars($styleCode ?? '') . '</p>';
            echo '<p>页面类型：' . htmlspecialchars($pageType ?? '') . '</p>';
            echo '<p>错误信息：<b style="color:#945252">' . htmlspecialchars($e->getMessage() ?? '') . '</b></p>';
            if (method_exists($e, 'getTraceAsString')) {
                echo '<pre style="font-size:11px;overflow:auto;max-height:300px;background:#f5f5f5;padding:10px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
            echo '</div>';
        }
    }

    /**
     * 自动保存配置
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_auto_save', '自动保存配置', '', '自动保存配置', 'GuoLaiRen_PageBuilder::page_builder')]
    public function autoSave()
    {
        try {
            // 获取 JSON 请求体
            $bodyParams = $this->request->getBodyParams();
            $data = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
            
            // 如果 JSON 解析失败，尝试从 POST 获取
            if (!$data) {
                $data = [
                    'page_id' => $this->request->getPost('page_id'),
                    'style_config' => $this->request->getPost('style_config', [])
                ];
            }
            
            $pageId = (int)($data['page_id'] ?? 0);
            $locale = $data['locale'] ?? ''; // 获取语言参数
            $styleCode = $data['style_code'] ?? ''; // 获取样式代码
            
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空'),
                    'debug' => [
                        'received_data' => $data,
                        'page_id_value' => $data['page_id'] ?? null
                    ]
                ]);
            }

            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式配置
            $styleConfig = $data['style_config'] ?? [];
            
            // 过滤空字符串值，避免覆盖原有配置（保留0、false等有效值）
            // 对于有默认值的字段（如hero.banner_image_mobile），空字符串表示使用默认值，不应保存
            $filteredStyleConfig = [];
            foreach ($styleConfig as $key => $value) {
                // 如果值为空字符串，跳过不保存（保留原有配置）
                // 但保留其他类型的空值（如0、false等）
                if ($value === '' || $value === null) {
                    continue;
                }
                $filteredStyleConfig[$key] = $value;
            }
            $styleConfig = $filteredStyleConfig;
            
            // 如果没有指定样式代码，使用页面当前的样式
            if (!$styleCode) {
                $styleCode = $page->getData('style') ?: 'default';
            }
            
            // 获取页面的默认语言
            $defaultLocale = $page->getData('default_locale') ?: '';
            
            // 判断是否保存到LocalDescription（语言特定配置）
            $saveToLocaleDescription = !empty($locale) && $locale !== $defaultLocale;
            
            if ($saveToLocaleDescription) {
                // 保存到LocalDescription.config.style_config（语言特定配置）
                $localDesc = clone $this->localDescriptionModel;
                $localDesc->clear()
                    ->where(LocalDescription::schema_fields_ID, $pageId)
                    ->where('local_code', $locale)
                    ->find()
                    ->fetch();
                
                // 获取现有的config
                $config = [];
                if ($localDesc->getId()) {
                    $configJson = $localDesc->getData('config');
                    if ($configJson) {
                        $config = json_decode($configJson ?? '', true) ?: [];
                    }
                }
                
                // 确保style_config节点存在
                if (!isset($config['style_config'])) {
                    $config['style_config'] = [];
                }
                
                // 合并新配置
                $config['style_config'] = array_merge(
                    $config['style_config'],
                    $styleConfig
                );
                
                // 保存
                if ($localDesc->getId()) {
                    $localDesc->setData('config', json_encode($config))->save();
                } else {
                    // 创建新的LocalDescription记录
                    $newLocalDesc = clone $this->localDescriptionModel;
                    $newLocalDesc->clearData()
                        ->setData(LocalDescription::schema_fields_ID, $pageId)
                        ->setData('local_code', $locale)
                        ->setData('config', json_encode($config))
                        ->setData('name', $page->getData('name'))
                        ->setData('title', $page->getData('title'))
                        ->setData('content', $page->getData('content'))
                        ->save(true);
                }
                
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('语言配置已保存到 %{1}', $locale),
                    'locale' => $locale,
                    'storage' => 'LocalDescription',
                    'saved_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                // 保存到Page.style_setting（默认配置）
                $currentSettings = $page->getStyleSetting();
                if (!is_array($currentSettings)) {
                    $currentSettings = [];
                }
                
                // 确保样式代码的配置存在
                if (!isset($currentSettings[$styleCode])) {
                    $currentSettings[$styleCode] = [];
                }
                
                // 清理错误的三层结构（移除混入的语言配置）
                $cleanedStyleSettings = [];
                if (isset($currentSettings[$styleCode]) && is_array($currentSettings[$styleCode])) {
                    foreach ($currentSettings[$styleCode] as $key => $value) {
                        // 跳过语言代码（数组值），只保留配置项（标量值）
                        if (!is_array($value)) {
                            $cleanedStyleSettings[$key] = $value;
                        }
                    }
                }
                
                // 合并配置（空值已被过滤，不会覆盖原有配置）
                $currentSettings[$styleCode] = array_merge(
                    $cleanedStyleSettings,
                    $styleConfig
                );
                
                // 保存配置
                $page->setData('style_setting', json_encode($currentSettings));
                $page->save();

                return $this->fetchJson([
                    'success' => true,
                    'message' => __('默认配置已保存'),
                    'locale' => $locale ?: 'default',
                    'storage' => 'Page.style_setting',
                    'saved_at' => date('Y-m-d H:i:s')
                ]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 批量重置字段为默认值
     * 删除指定字段的自定义配置，使其回退到模板默认值
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_reset_fields', '重置字段为默认值', '', '批量重置配置字段为模板默认值', 'GuoLaiRen_PageBuilder::page_builder')]
    public function resetFieldsToDefault()
    {
        try {
            // 调试日志
            w_log_debug('🔵 resetFieldsToDefault 接口被调用');
            
            // 获取 JSON 请求体
            $rawBody = is_string($this->request->getBodyParams()) ? $this->request->getBodyParams() : json_encode($this->request->getBodyParams());
            w_log_debug('🔵 请求体: ' . $rawBody);
            
            $data = json_decode($rawBody ?? '', true); // 第二个参数 true 表示返回数组而不是对象
            w_log_debug('🔵 解析后数据: ' . json_encode($data));
            
            // 如果 JSON 解析失败，尝试从 POST 获取
            if (!$data || !is_array($data)) {
                $data = [
                    'page_id' => $this->request->getPost('page_id'),
                    'config_keys' => $this->request->getPost('config_keys', []),
                    'locale' => $this->request->getPost('locale', ''),
                    'style_code' => $this->request->getPost('style_code', '')
                ];
            }
            
            $pageId = (int)($data['page_id'] ?? 0);
            $configKeys = $data['config_keys'] ?? [];
            $locale = $data['locale'] ?? '';
            $styleCode = $data['style_code'] ?? '';
            
            w_log_debug('🔵 pageId: ' . $pageId);
            w_log_debug('🔵 configKeys: ' . json_encode($configKeys));
            w_log_debug('🔵 locale: ' . $locale);
            
            if (!$pageId) {
                w_log_error('❌ 页面ID不能为空');
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }
            
            if (empty($configKeys) || !is_array($configKeys)) {
                w_log_error('❌ 配置键列表不能为空');
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('配置键列表不能为空')
                ]);
            }

            w_log_debug('🔵 验证通过，开始加载页面...');
            
            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            w_log_debug('🔵 页面加载完成，ID: ' . $page->getId());
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 如果没有指定样式代码，使用页面当前的样式
            if (!$styleCode) {
                $styleCode = $page->getData('style') ?: 'default';
            }
            
            // 获取页面的默认语言
            $defaultLocale = $page->getData('default_locale') ?: '';
            
            // 判断是否从LocalDescription中删除（语言特定配置）
            $resetInLocaleDescription = !empty($locale) && $locale !== $defaultLocale;
            
            if ($resetInLocaleDescription) {
                // 从LocalDescription.config.style_config中删除指定的配置键
                $localDesc = clone $this->localDescriptionModel;
                $localDesc->clear()
                    ->where(LocalDescription::schema_fields_ID, $pageId)
                    ->where('local_code', $locale)
                    ->find()
                    ->fetch();
                
                if ($localDesc->getId()) {
                    // 获取现有的config
                    $configJson = $localDesc->getData('config');
                    $config = [];
                    if ($configJson) {
                        $config = json_decode($configJson ?? '', true) ?: [];
                    }
                    
                    // 从style_config中删除指定的键
                    if (isset($config['style_config']) && is_array($config['style_config'])) {
                        foreach ($configKeys as $key) {
                            unset($config['style_config'][$key]);
                        }
                        
                        // 保存更新后的配置
                        $localDesc->setData('config', json_encode($config))->save();
                    }
                }
                
                w_log_info('✅ 重置成功（LocalDescription），字段数: ' . count($configKeys));
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已重置 %{1} 个字段为默认值（语言：%{2}）', count($configKeys), $locale),
                    'locale' => $locale,
                    'storage' => 'LocalDescription',
                    'reset_count' => count($configKeys)
                ]);
            } else {
                // 从Page.style_setting中删除指定的配置键
                $currentSettings = $page->getStyleSetting();
                if (!is_array($currentSettings)) {
                    $currentSettings = [];
                }
                
                // 从指定样式的配置中删除键
                if (isset($currentSettings[$styleCode]) && is_array($currentSettings[$styleCode])) {
                    foreach ($configKeys as $key) {
                        unset($currentSettings[$styleCode][$key]);
                    }
                    
                    // 保存配置
                    $page->setData('style_setting', json_encode($currentSettings));
                    $page->save();
                }
                
                w_log_info('✅ 重置成功（Page.style_setting），字段数: ' . count($configKeys));
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已重置 %{1} 个字段为默认值', count($configKeys)),
                    'locale' => $locale ?: 'default',
                    'storage' => 'Page.style_setting',
                    'reset_count' => count($configKeys)
                ]);
            }
        } catch (\Exception $e) {
            w_log_error('❌ 异常: ' . $e->getMessage());
            w_log_error('❌ 异常跟踪: ' . $e->getTraceAsString());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取插槽容器的样式
     */
    private function getDropZoneStyles(): string
    {
        return <<<'CSS'
<style id="pb-dropzone-styles">
/* ============================================
   PageBuilder 插槽容器样式
   仅边框标记，无背景变化
   ============================================ */

/* 插槽容器基础样式 */
.pb-slot {
    position: relative;
    min-height: 50px;
}

/* 插槽标签 - 仅在拖拽时显示 */
.pb-slot::before {
    content: attr(data-slot-name);
    position: absolute;
    top: 0;
    left: 0;
    padding: 3px 10px;
    background: #3498db;
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    z-index: 9998;
    opacity: 0;
    pointer-events: none;
    border-radius: 0 0 4px 0;
}

.pb-slot.drag-over::before {
    opacity: 1;
}

/* 移除 pb-slot 和 pb-slot-content 的 hover 效果 */
.pb-slot:hover,
.pb-slot-content:hover {
    box-shadow: none !important;
}

/* 拖拽进入效果 - 内部阴影边框 */
.pb-slot.drag-over {
    box-shadow: inset 0 0 0 3px #3498db;
}
</style>
CSS;
    }
    
    /**
     * 获取插槽容器的脚本
     */
    private function getDropZoneScripts(int $pageId): string
    {
        return <<<JS
<script id="pb-dropzone-scripts">
(function() {
    'use strict';
    
    const pageId = {$pageId};
    
    // 初始化插槽
    function initDropZones() {
        document.querySelectorAll('.pb-slot').forEach(slot => {
            // 检查是否有内容
            if (slot.children.length > 0 && slot.children[0].tagName !== 'STYLE') {
                slot.classList.add('has-component');
            }
            
            // 拖拽事件
            slot.addEventListener('dragover', handleDragOver);
            slot.addEventListener('dragleave', handleDragLeave);
            slot.addEventListener('drop', handleDrop);
            slot.addEventListener('dragenter', handleDragEnter);
        });
    }
    
    function handleDragEnter(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    }
    
    function handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // 设置拖拽效果
        e.dataTransfer.dropEffect = 'copy';
        
        this.classList.add('drag-over');
    }
    
    function handleDragLeave(e) {
        e.preventDefault();
        
        // 检查是否真的离开了元素
        const rect = this.getBoundingClientRect();
        const x = e.clientX;
        const y = e.clientY;
        
        if (x < rect.left || x >= rect.right || y < rect.top || y >= rect.bottom) {
            this.classList.remove('drag-over');
        }
    }
    
    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        
        this.classList.remove('drag-over');
        
        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            const targetRegion = this.getAttribute('data-region');
            const isMultiple = this.getAttribute('data-multiple') === 'true';
            
            console.log('📦 组件放置:', data, '目标区域:', targetRegion);
            
            // 检查区域匹配
            if (data.region !== targetRegion) {
                if (window.parent.BackendToast) {
                    window.parent.BackendToast.warning('区域不匹配：此组件只能放置在 ' + data.region.toUpperCase() + ' 区域');
                }
                return;
            }
            
            // 检查唯一性
            const hasComponent = this.classList.contains('has-component');
            if (!isMultiple && hasComponent) {
                if (window.parent.BackendConfirm) {
                    window.parent.BackendConfirm.show('此区域只能放置一个组件，是否替换现有组件？', {
                        title: '替换组件？',
                        confirmText: '确认替换',
                        cancelText: '取消'
                    }).then((confirmed) => {
                        if (confirmed) {
                            addComponent(data, targetRegion, true);
                        }
                    });
                }
                return;
            }
            
            // 计算插入位置（仅 content 区域且允许多个时）
            let insertPosition = null;
            if (targetRegion === 'content' && isMultiple) {
                insertPosition = calculateInsertPosition(e, this);
            }
            
            // 添加组件
            addComponent(data, targetRegion, false, insertPosition);
            
        } catch (err) {
            console.error('❌ 拖拽处理错误:', err);
        }
    }
    
    // 计算插入位置（基于鼠标在组件上半/下半）
    function calculateInsertPosition(e, dropZone) {
        const components = Array.from(dropZone.querySelectorAll('.tpmst-component-wrapper[data-region="content"]'));
        
        if (components.length === 0) {
            return 0;
        }
        
        const dropY = e.clientY;
        
        for (let i = 0; i < components.length; i++) {
            const component = components[i];
            const rect = component.getBoundingClientRect();
            
            if (dropY >= rect.top && dropY <= rect.bottom) {
                const middle = rect.top + rect.height / 2;
                return dropY < middle ? i : i + 1;
            }
            
            if (dropY < rect.top) {
                return i;
            }
        }
        
        return components.length;
    }
    
    // 添加组件
    async function addComponent(componentData, region, replace, position = null) {
        try {
            // 显示加载提示
            if (window.parent.BackendToast) {
                window.parent.BackendToast.info('添加组件中...', 2000);
            }
            
            // 调用父窗口的添加组件函数
            if (typeof window.parent.addComponentToLayout === 'function') {
                await window.parent.addComponentToLayout(componentData, region, replace, position);
            } else {
                console.warn('⚠️ addComponentToLayout 函数不存在');
                if (window.parent.BackendToast) {
                    window.parent.BackendToast.warning('功能不可用，请刷新页面后重试');
                }
            }
        } catch (error) {
            console.error('❌ 添加组件失败:', error);
            if (window.parent.BackendToast) {
                window.parent.BackendToast.error('添加失败：' + (error.message || '未知错误'));
            }
        }
    }
    
    // 通知父窗口区域被高亮
    function notifyParentHighlight(region) {
        if (typeof window.parent.onSlotHighlight === 'function') {
            window.parent.onSlotHighlight(region);
        }
    }
    
    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropZones);
    } else {
        initDropZones();
    }
    
    // 暴露给父窗口的接口
    window.pbSlots = {
        highlight: function(region) {
            document.querySelectorAll('.pb-slot').forEach(slot => {
                if (slot.getAttribute('data-region') === region) {
                    slot.classList.add('drag-over');
                }
            });
        },
        unhighlight: function() {
            document.querySelectorAll('.pb-slot').forEach(slot => {
                slot.classList.remove('drag-over');
            });
        },
        refresh: function() {
            initDropZones();
        }
    };
})();
</script>
JS;
    }
    
    /**
     * 渲染指定区域的组件
     * 
     * @param string $region 区域名称（header/content/footer）
     * @param array $components 组件配置数组
     * @param string $styleCode 模板代码
     * @param \GuoLaiRen\PageBuilder\Model\Page $page 页面对象
     * @param array $styleSettings 样式配置
     * @return string 渲染后的 HTML
     */
    private function renderRegionComponents(string $region, array $components, string $styleCode, $page, array $styleSettings): string
    {
        if (empty($components)) {
            return '';
        }
        
        $html = '';
        
        // 调试信息
        $html .= "<!-- Rendering region: {$region}, styleCode: {$styleCode}, components: " . count($components) . " -->\n";
        
        // 组件代码到文件的映射（从 component.json 读取）
        $componentFiles = $this->getComponentFilesMap($styleCode);
        
        // 调试：输出可用的组件文件映射
        $html .= "<!-- Available component files: " . implode(', ', array_keys($componentFiles)) . " -->\n";
        
        // 检查是否在可视化编辑器模式
        $isVisualEditor = $this->request->getGet('visual_editor') === '1';
        
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
            
            // 确定使用哪个模板的组件文件
            $useTemplateCode = $styleCode;
            
            // 查找组件文件 - 优先在当前模板中查找
            $componentFile = $componentFiles[$code] ?? null;
            
            // 如果直接查找失败，尝试去掉模板前缀再查找
            // 例如：tpmst-slider -> slider, tpmst-advantages -> advantages
            if (!$componentFile && strpos($code, $styleCode . '-') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
                $html .= "<!-- Trying without prefix: {$codeWithoutPrefix} -->\n";
            }
            
            if (!$componentFile && !empty($componentTemplateCode) && $componentTemplateCode !== $styleCode) {
                // 尝试从指定的其他模板查找
                $otherComponentFiles = $this->getComponentFilesMap($componentTemplateCode);
                $componentFile = $otherComponentFiles[$code] ?? null;
                
                // 同样尝试去掉前缀
                if (!$componentFile && strpos($code, $componentTemplateCode . '-') === 0) {
                    $codeWithoutPrefix = substr($code, strlen($componentTemplateCode) + 1);
                    $componentFile = $otherComponentFiles[$codeWithoutPrefix] ?? null;
                }
                
                if ($componentFile) {
                    $useTemplateCode = $componentTemplateCode;
                }
            }
            
            if (!$componentFile) {
                $html .= "<!-- Component not found: {$code} in template {$styleCode} (tried with/without prefix) -->\n";
                $componentIndex++;
                continue;
            }
            
            // 构建组件模板路径（使用 templates/style/ 与其他模板路径保持一致）
            $componentPath = "GuoLaiRen_PageBuilder::templates/style/{$useTemplateCode}/components/{$componentFile}";
            
            // 传递数据到组件
            $this->assign('page', $page);
            $this->assign('style', $styleSettings);
            $this->assign('style_settings', $styleSettings);
            $this->assign('component_config', $config);
            
            try {
                // 使用 fetch() 方法渲染组件（与控制器其他地方一致）
                $componentHtml = $this->fetch($componentPath);
                
                if (empty($componentHtml)) {
                    $html .= "<!-- Component {$code} rendered but output is empty -->\n";
                } else {
                    // 在可视化编辑器模式下，为组件添加包装器以支持编辑和删除操作
                    if ($isVisualEditor) {
                        $escapedCode = htmlspecialchars($code);
                        $escapedRegion = htmlspecialchars($region);
                        $componentHtml = "<div class=\"tpmst-component-wrapper\" data-component=\"{$escapedCode}\" data-region=\"{$escapedRegion}\" data-index=\"{$componentIndex}\">{$componentHtml}</div>";
                    }
                    $html .= $componentHtml;
                    $html .= "<!-- Component {$code} rendered successfully -->\n";
                }
            } catch (\Exception $e) {
                $html .= "<!-- Error rendering component {$code}: " . htmlspecialchars($e->getMessage() ?? 'Unknown error') . " -->\n";
                $html .= "<!-- Stack trace: " . htmlspecialchars($e->getTraceAsString()) . " -->\n";
            } catch (\Throwable $e) {
                $html .= "<!-- Fatal error rendering component {$code}: " . htmlspecialchars($e->getMessage() ?? 'Unknown error') . " -->\n";
            }
            
            $componentIndex++;
        }
        
        return $html;
    }
    
    /**
     * 获取模板的组件文件映射
     * 
     * @param string $styleCode 模板代码
     * @return array 组件代码 => 文件路径
     */
    private function getComponentFilesMap(string $styleCode): array
    {
        static $cache = [];
        
        if (isset($cache[$styleCode])) {
            return $cache[$styleCode];
        }
        
        $componentJsonPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/component.json";
        
        if (!file_exists($componentJsonPath)) {
            $cache[$styleCode] = [];
            return [];
        }
        
        $jsonContent = file_get_contents($componentJsonPath);
        $jsonConfig = json_decode($jsonContent, true);
        
        if (!$jsonConfig || !isset($jsonConfig['components'])) {
            $cache[$styleCode] = [];
            return [];
        }
        
        $map = [];
        foreach ($jsonConfig['components'] as $code => $config) {
            $map[$code] = $config['file'] ?? ($code . '.phtml');
        }
        
        $cache[$styleCode] = $map;
        return $map;
    }
}

