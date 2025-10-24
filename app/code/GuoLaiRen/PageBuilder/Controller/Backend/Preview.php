<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
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
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/header.phtml';
            
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
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/content.phtml';
            
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
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/footer.phtml';
            
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
        $templateDefaults = [];
        try {
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(\GuoLaiRen\PageBuilder\Model\Style::fields_CODE, $styleCode)->find()->fetch();
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
            // 如果获取模板默认值失败，继续执行
        }
        
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
                ->where(\GuoLaiRen\PageBuilder\Model\Page\LocalDescription::fields_ID, $page->getId())
                ->where('local_code', $locale)
                ->find()
                ->fetch();
            
            if ($localDesc->getId()) {
                $configJson = $localDesc->getData('config');
                if ($configJson) {
                    $config = json_decode($configJson, true);
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
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_preview', '页面预览', '', '页面预览')]
    public function full()
    {
        $pageId = (int)$this->request->getGet('page_id');
        $locale = $this->request->getGet('locale');
        
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

        // 获取样式代码和配置
        $styleCode = $page->getData('style') ?: 'default';
        $currentStyleSettings = $this->extractStyleSettings($page, $styleCode, $locale);

        // 设置模板变量
        $this->assign('page', $page);
        $this->assign('style_settings', $currentStyleSettings);
        $this->assign('is_preview', true); // 标记为预览模式
        
        // 组合渲染 header、content、footer 三个模板
        // 使用模块路径格式：GuoLaiRen_PageBuilder::templates/style/{styleCode}/header.phtml
        $stylePath = "GuoLaiRen_PageBuilder::templates/style/{$styleCode}";
        
        // 渲染 header
        $headerHtml = $this->fetch("{$stylePath}/header.phtml");
        
        // 渲染 content
        $contentHtml = $this->fetch("{$stylePath}/content.phtml");
        
        // 渲染 footer
        $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
        
        // 输出组合后的完整页面
        echo $headerHtml . $contentHtml . $footerHtml;
    }

    /**
     * 自动保存配置
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_auto_save', '自动保存配置', '', '自动保存配置')]
    public function autoSave()
    {
        try {
            // 获取 JSON 请求体
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody, true);
            
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
                        $config = json_decode($configJson, true) ?: [];
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
}

