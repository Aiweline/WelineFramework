<?php
declare(strict_types=1);

namespace Weline\Cms\Controller\Backend;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\Page\LocalDescription;
use Weline\Cms\Model\Style;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 页面预览控制器
 * 用于实时预览头部、内容、页脚配置
 */
class Preview extends BackendController
{
    private Page $pageModel;
    private LocalDescription $localDescriptionModel;
    private Style $styleModel;

    public function __construct(
        Page $pageModel,
        LocalDescription $localDescriptionModel,
        Style $styleModel
    ) {
        $this->pageModel = $pageModel;
        $this->localDescriptionModel = $localDescriptionModel;
        $this->styleModel = $styleModel;
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
            $templatePath = 'Weline_Cms::Backend/Page/Preview/header.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $currentStyleSettings);
            $this->assign('is_preview', true);
            
            // 渲染模板
            $html = $this->view->getTemplateEngine()->fetchTemplate($templatePath, $this->view->getData());
            
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
            $templatePath = 'Weline_Cms::Backend/Page/Preview/content.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $currentStyleSettings);
            $this->assign('is_preview', true);
            
            // 渲染模板
            $html = $this->view->getTemplateEngine()->fetchTemplate($templatePath, $this->view->getData());
            
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
            $templatePath = 'Weline_Cms::Backend/Page/Preview/footer.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $currentStyleSettings);
            $this->assign('is_preview', true);
            
            // 渲染模板
            $html = $this->view->getTemplateEngine()->fetchTemplate($templatePath, $this->view->getData());
            
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
            $styleModel->clear()->where(\Weline\Cms\Model\Style::fields_CODE, $styleCode)->find()->fetch();
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
                ->where(\Weline\Cms\Model\Page\LocalDescription::fields_ID, $page->getId())
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
     */
    #[\Weline\Framework\Acl\Acl('Weline_Cms::page_builder_preview', '页面预览', '', '页面预览')]
    public function full()
    {
        $pageId = (int)$this->request->getGet('page_id');
        $locale = $this->request->getGet('locale');
        $tempStyleCode = $this->request->getGet('style_code'); // 临时样式代码（用于预览）
        
        if (!$pageId) {
            echo '<div style="padding: 20px; color: red;">页面ID不能为空</div>';
            return;
        }

        // 加载页面数据
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            echo '<div style="padding: 20px; color: red;">页面不存在</div>';
            return;
        }

        // 获取样式代码：优先使用临时样式代码，否则使用页面配置的样式
        $styleCode = $tempStyleCode ?: ($page->getData('style') ?: 'default');
        
        // 如果使用临时样式，获取该样式的默认配置
        if ($tempStyleCode && $tempStyleCode !== $page->getData('style')) {
            // 临时样式：使用模板默认值
            $currentStyleSettings = $this->getTemplateDefaults($tempStyleCode);
        } else {
            // 当前样式：使用页面配置
            $currentStyleSettings = $this->extractStyleSettings($page, $styleCode, $locale);
        }

        // 设置模板变量
        $this->assign('page', $page);
        $this->assign('style_settings', $currentStyleSettings);
        $this->assign('is_preview', true); // 标记为预览模式
        
        // 组合渲染 header、content、footer 三个模板
        // 使用模块路径格式：Weline_Cms::templates/style/{styleCode}/header.phtml
        $stylePath = "Weline_Cms::templates/style/{$styleCode}";
        
        // 渲染 header
        $headerHtml = $this->fetch("{$stylePath}/header.phtml");
        
        // 渲染 content
        // 预览模式下：始终使用样式模板的 content.phtml（忽略页面自定义 content）
        $contentHtml = $this->fetch("{$stylePath}/content.phtml");
        
        // 渲染 footer
        $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
        
        // 输出组合后的完整页面
        // 在预览模式下，强制在地址栏附加 preview=1 并设置全局预览标记，避免触发统计
        $previewBoot = '<script>(function(){
            try {
                window.__CMS_PREVIEW__ = true;
                var url = new URL(window.location.href);
                if (!url.searchParams.get("preview")) {
                    url.searchParams.set("preview", "1");
                    window.history.replaceState({}, document.title, url.toString());
                }
            } catch(e) {}
        })();</script>';
        echo $previewBoot . $headerHtml . $contentHtml . $footerHtml;
    }

    /**
     * 预览样式模板默认效果（无需页面ID）
     * 仅使用模板的默认配置渲染，用于在选择样式时快速预览模板效果
     * 路由：cms/backend_preview/stylePreview?style_code=marketing-landing
     */
    #[\Weline\Framework\Acl\Acl('Weline_Cms::page_builder_preview', '样式模板预览', '', '样式模板预览')]
    public function stylePreview()
    {
        $styleCode = $this->request->getGet('style_code');
        $locale = $this->request->getGet('locale', 'zh_Hans_CN'); // 默认语言
        
        if (!$styleCode) {
            echo '<div style="padding: 20px; color: red;">样式代码不能为空</div>';
            echo '<p style="padding: 0 20px;">请使用 ?style_code=样式代码 参数访问</p>';
            echo '<p style="padding: 0 20px;">例如: ?style_code=marketing-landing</p>';
            return;
        }

        // 为确保样式列表最新，先强制扫描一次
        try {
            \Weline\Cms\Model\Style::forceScan();
        } catch (\Throwable $e) {
            // 忽略扫描异常，继续按现有数据处理
        }

        // 验证样式是否存在
        $styleModel = clone $this->styleModel;
        $styleModel->clear()->where(\Weline\Cms\Model\Style::fields_CODE, $styleCode)->find()->fetch();
        
        if (!$styleModel->getId()) {
            echo '<div style="padding: 20px; color: red;">样式模板不存在：' . htmlspecialchars($styleCode ?? '') . '</div>';
            return;
        }

        // 获取模板的默认配置
        $templateDefaults = $this->getTemplateDefaults($styleCode);
        
        // 创建一个空的页面对象，用于模板渲染
        $dummyPage = clone $this->pageModel;
        $dummyPage->setData([
            'id' => 0,
            'title' => '样式模板预览 - ' . $styleModel->getData('name'),
            'handle' => 'template-preview-' . $styleCode,
            'style' => $styleCode,
            'status' => 1,
            'content' => '',
            'description' => '预览模式：使用模板默认配置'
        ]);

        // 设置模板变量
        $this->assign('page', $dummyPage);
        $this->assign('style_settings', $templateDefaults);
        $this->assign('is_preview', true);
        $this->assign('is_template_preview', true); // 标记为模板预览模式
        $this->assign('locale', $locale);
        
        // 组合渲染 header、content、footer 三个模板
        $stylePath = "Weline_Cms::templates/style/{$styleCode}";
        
        try {
            // 渲染 header
            $headerHtml = $this->fetch("{$stylePath}/header.phtml");
            
            // 渲染 content
            $contentHtml = $this->fetch("{$stylePath}/content.phtml");
            
            // 渲染 footer
            $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
            
            // 输出组合后的完整页面
            echo $headerHtml . $contentHtml . $footerHtml;
        } catch (\Exception $e) {
            echo '<div style="padding: 20px; color: red;">';
            echo '<h3>模板渲染错误</h3>';
            echo '<p>样式代码：' . htmlspecialchars($styleCode ?? '') . '</p>';
            echo '<p>错误信息：' . htmlspecialchars((($e->getMessage() ?? '')) ?? '') . '</p>';
            echo '<p>模板路径：' . htmlspecialchars($stylePath ?? '') . '</p>';
            echo '</div>';
        }
    }

    /**
     * 自动保存配置
     */
    #[\Weline\Framework\Acl\Acl('Weline_Cms::page_builder_auto_save', '自动保存配置', '', '自动保存配置', 'Weline_Cms::page_builder')]
    public function autoSave()
    {
        try {
            // 获取 JSON 请求体
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody ?? '', true);
            
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
                    ->where(LocalDescription::fields_ID, $pageId)
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
                        ->setData(LocalDescription::fields_ID, $pageId)
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
                
                // 合并配置
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
    #[\Weline\Framework\Acl\Acl('Weline_Cms::page_builder_reset_fields', '重置字段为默认值', '', '批量重置配置字段为模板默认值', 'Weline_Cms::page_builder')]
    public function resetFieldsToDefault()
    {
        try {
            // 调试日志
            error_log('🔵 resetFieldsToDefault 接口被调用');
            
            // 获取 JSON 请求体
            $rawBody = file_get_contents('php://input');
            error_log('🔵 请求体: ' . $rawBody);
            
            $data = json_decode($rawBody ?? '', true); // 第二个参数 true 表示返回数组而不是对象
            error_log('🔵 解析后数据: ' . json_encode($data));
            
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
            
            error_log('🔵 pageId: ' . $pageId);
            error_log('🔵 configKeys: ' . json_encode($configKeys));
            error_log('🔵 locale: ' . $locale);
            
            if (!$pageId) {
                error_log('❌ 页面ID不能为空');
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }
            
            if (empty($configKeys) || !is_array($configKeys)) {
                error_log('❌ 配置键列表不能为空');
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('配置键列表不能为空')
                ]);
            }

            error_log('🔵 验证通过，开始加载页面...');
            
            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            error_log('🔵 页面加载完成，ID: ' . $page->getId());
            
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
                    ->where(LocalDescription::fields_ID, $pageId)
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
                
                error_log('✅ 重置成功（LocalDescription），字段数: ' . count($configKeys));
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
                
                error_log('✅ 重置成功（Page.style_setting），字段数: ' . count($configKeys));
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('已重置 %{1} 个字段为默认值', count($configKeys)),
                    'locale' => $locale ?: 'default',
                    'storage' => 'Page.style_setting',
                    'reset_count' => count($configKeys)
                ]);
            }
        } catch (\Exception $e) {
            error_log('❌ 异常: ' . $e->getMessage());
            error_log('❌ 异常跟踪: ' . $e->getTraceAsString());
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}

