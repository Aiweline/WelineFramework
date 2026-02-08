<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 模板管理后端控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management', '模板管理', 'mdi mdi-palette', '管理页面样式模板')]
class Template extends BackendController
{
    private Style $styleModel;
    private PageModel $pageModel;
    private LocalDescription $localDescriptionModel;

    public function __construct(
        Style $styleModel,
        PageModel $pageModel,
        LocalDescription $localDescriptionModel
    ) {
        $this->styleModel = $styleModel;
        $this->pageModel = $pageModel;
        $this->localDescriptionModel = $localDescriptionModel;
    }
    
    /**
     * 确保模板测试页面存在且不允许发布
     * 这个方法会在每次访问模板管理功能时自动调用，确保测试页面始终存在且状态正确
     * 
     * @return PageModel 测试页面对象
     */
    private function ensureTestPageExists(): PageModel
    {
        $testPage = clone $this->pageModel;
        $testPage->clear()
            ->where('handle', 'template-test-page')
            ->find()
            ->fetch();
        
        $needSave = false;
        
        if (!$testPage->getId()) {
            // 创建系统test页面
            $testPage->clearData()
                ->setData('handle', 'template-test-page')
                ->setData('name', '模板测试页面（系统）')
                ->setData('title', 'Template Test Page')
                ->setData('type', 'test_page')
                ->setData('status', 0) // 设置为草稿状态，不允许发布
                ->setData('content', '')
                ->setData('meta_title', 'Template Test Page')
                ->setData('meta_description', 'Template test page for preview')
                ->save(true);
        } else {
            // 如果页面已存在，确保状态为草稿（不允许发布）
            if ($testPage->getData('status') != 0) {
                $testPage->setData('status', 0); // 强制设置为草稿状态
                $needSave = true;
            }
            
            // 确保页面类型正确
            if ($testPage->getData('type') !== 'test_page') {
                $testPage->setData('type', 'test_page');
                $needSave = true;
            }
            
            // 如果状态被修改，保存
            if ($needSave) {
                $testPage->save(true);
            }
        }
        
        return $testPage;
    }

    /**
     * 模板列表页面
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_index', '模板列表', 'mdi mdi-view-list', '查看模板列表')]
    public function index()
    {
        // 强制扫描样式模板，确保配置是最新的
        Style::forceScan();
        
        $this->assign('page_title', __('模板管理'));
        $this->assign('breadcrumb_parent', __('页面管理'));
        $this->assign('breadcrumb_current', __('模板管理'));
        
        // 搜索功能
        $search = trim($this->request->getGet('search', ''));
        
        // 获取所有模板（包括未发布的）
        $styles = clone $this->styleModel;
        $styles->clear();
        
        if (!empty($search)) {
            // 使用OR条件组合多个字段的模糊搜索
            // 第一个条件作为起始，后续使用OR连接
            $searchValue = "%{$search}%";
            $styles->where(Style::fields_CODE, $searchValue, 'LIKE')
                ->where(Style::fields_NAME, $searchValue, 'LIKE', 'OR')
                ->where(Style::fields_DESCRIPTION, $searchValue, 'LIKE', 'OR');
        }
        
        $styleList = $styles->order(Style::fields_SORT_ORDER, 'ASC')
            ->order(Style::fields_CREATE_TIME, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('styles', $styleList);
        $this->assign('search', $search);
        
        // 确保测试页面存在且不允许发布
        $testPage = $this->ensureTestPageExists();
        $this->assign('test_page_id', $testPage->getId() ?: 0);
        // 同时传递 page 对象，供 visual_config.phtml 使用
        $this->assign('page', $testPage);
        
        // 获取所有页面列表（用于选择预览页面）
        $allPages = clone $this->pageModel;
        $pageList = $allPages->clear()
            ->order(PageModel::fields_CREATE_TIME, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('pages', $pageList);
        return $this->fetch();
    }

    /**
     * 切换模板发布状态
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_publish', '发布模板', 'mdi mdi-publish', '发布或取消发布模板')]
    public function postTogglePublish()
    {
        try {
            // 使用 getBodyParams() 兼容 JSON 请求（WLS 模式不支持 multipart/form-data）
            $bodyParams = $this->request->getBodyParams();
            if (is_string($bodyParams)) {
                $decoded = json_decode($bodyParams, true);
                $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
            } elseif (is_array($bodyParams) && !empty($bodyParams)) {
                $data = $bodyParams;
            } else {
                $data = $this->request->getParams();
            }
            
            $styleId = (int)($data['style_id'] ?? $this->request->getPost('style_id'));
            $isPublished = (int)($data['is_published'] ?? $this->request->getPost('is_published', 0));
            $skipPreviewCheck = (bool)($data['skip_preview_check'] ?? $this->request->getPost('skip_preview_check', false));
            
            if ($styleId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板ID不能为空')
                ]);
            }
            
            $style = clone $this->styleModel;
            $style->load($styleId);
            
            if (!$style->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板不存在')
                ]);
            }
            
            // 发布时检查是否有预览图
            if ($isPublished && !$skipPreviewCheck) {
                $previewImage = $style->getData(Style::fields_PREVIEW_IMAGE);
                $hasPreview = false;
                
                if ($previewImage) {
                    // 检查预览图文件是否存在
                    $possiblePaths = [
                        BP . 'pub/static/' . $previewImage,
                        BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/' . $previewImage,
                        BP . $previewImage,
                    ];
                    
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path) && is_file($path)) {
                            $hasPreview = true;
                            break;
                        }
                    }
                }
                
                // 没有预览图，需要先生成
                if (!$hasPreview) {
                    $previewPageUrl = $this->request->getUrlBuilder()->getBackendUrl(
                        'pagebuilder/backend/preview/stylePreview',
                        ['style_code' => $style->getData(Style::fields_CODE)]
                    );
                    
                    return $this->fetchJson([
                        'success' => false,
                        'needs_preview' => true,
                        'message' => __('发布前需要先生成预览图'),
                        'style_id' => $styleId,
                        'style_code' => $style->getData(Style::fields_CODE),
                        'preview_page_url' => $previewPageUrl,
                        'upload_url' => $this->request->getUrlBuilder()->getBackendUrl('pagebuilder/backend/template/uploadPreview')
                    ]);
                }
            }
            
            $style->setData(Style::fields_IS_PUBLISHED, $isPublished ? 1 : 0);
            $style->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $isPublished ? __('模板已发布') : __('模板已取消发布'),
                'is_published' => $isPublished
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 配置预览 - 使用指定页面数据预览模板
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_preview', '模板预览', 'mdi mdi-eye', '预览模板配置')]
    public function getPreview()
    {
        $styleCode = $this->request->getGet('style_code');
        $pageId = (int)$this->request->getGet('page_id', 0);
        
        if (empty($styleCode)) {
            $this->getMessageManager()->addError(__('模板代码不能为空'));
            return $this->redirect('*/backend/template/index');
        }
        
        // 检查模板是否存在
        $style = clone $this->styleModel;
        $style->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();
        
        if (!$style->getId()) {
            $this->getMessageManager()->addError(__('模板不存在'));
            return $this->redirect('*/backend/template/index');
        }
        
        // 如果没有指定页面，使用test页面
        if ($pageId <= 0) {
            $testPage = $this->ensureTestPageExists();
            $pageId = $testPage->getId();
        }
        
        // 加载页面数据
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            $this->getMessageManager()->addError(__('页面不存在'));
            return $this->redirect('*/backend/template/index');
        }
        
        // 临时设置页面使用当前模板
        $originalStyle = $page->getData('style');
        $page->setData('style', $styleCode);
        
        // 重定向到预览页面，带上模板代码参数
        $previewUrl = $this->request->getUrlBuilder()->getBackendUrl('*/backend/preview/index', [
            'page_id' => $pageId,
            'style_code' => $styleCode,
            'from_template' => 1
        ]);
        
        // 恢复原始样式（不保存）
        $page->setData('style', $originalStyle);
        
        return $this->redirect($previewUrl);
    }

    /**
     * 模板配置预览页面（独立的配置界面，避免内存问题）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_config_preview', '模板配置预览', 'mdi mdi-eye', '在独立页面中预览和配置模板')]
    public function configPreview()
    {
        $styleCode = $this->request->getGet('style_code');
        $pageId = (int)$this->request->getGet('page_id', 0);
        
        if (empty($styleCode)) {
            $this->getMessageManager()->addError(__('模板代码不能为空'));
            return $this->redirect('*/backend/template/index');
        }
        
        // 确保测试页面存在
        $testPage = $this->ensureTestPageExists();
        if ($pageId <= 0) {
            $pageId = $testPage->getId();
        }
        
        // 设置模板配置模式，并直接渲染 visual_config（避免在模板中再次包含模板导致循环嵌套）
        $this->assign('is_template_config_mode', true);
        $this->assign('page', $testPage);
        $this->assign('visual_config_page_id', $pageId);
        $this->assign('page_title', __('模板配置预览 - %1', $styleCode));
        
        return $this->fetch('GuoLaiRen_PageBuilder::templates/Backend/Page/form_component/visual_config.phtml');
    }

    /**
     * 获取样式配置（用于可视化配置界面）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_config', '获取模板配置', 'mdi mdi-cog', '获取模板的可视化配置')]
    public function getStyleConfig()
    {
        try {
            $styleCode = $this->request->getGet('style_code');
            $pageId = (int)$this->request->getGet('page_id', 0);
            $locale = $this->request->getGet('locale');
            
            if (empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('样式代码不能为空')
                ]);
            }
            
            // 如果没有指定页面ID，使用测试页面
            if ($pageId <= 0) {
                $testPage = clone $this->pageModel;
                $testPage->clear()
                    ->where('handle', 'template-test-page')
                    ->find()
                    ->fetch();
                
                if ($testPage->getId()) {
                    $pageId = $testPage->getId();
                }
            }
            
            // 强制实时扫描模板配置（确保最新定义生效）
            Style::forceScan();
            
            // 获取模板配置定义
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();
            
            if (!$styleModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('样式不存在')
                ]);
            }
            
            $configGroups = $styleModel->getConfigGroups();

            // 统一色系结构为：
            // data.color_scheme.groups.color_scheme.configs.color_scheme.color_schemes
            if (isset($configGroups['color_scheme'])) {
                $cs =& $configGroups['color_scheme'];
                // 如果不存在 groups，则创建
                if (!isset($cs['groups']) || !is_array($cs['groups'])) {
                    $cs['groups'] = [];
                }
                // 确保存在分组 color_scheme
                if (!isset($cs['groups']['color_scheme']) || !is_array($cs['groups']['color_scheme'])) {
                    $cs['groups']['color_scheme'] = [
                        'key' => 'color_scheme',
                        'label' => __('色系选择'),
                        'tag' => '',
                        'help_title' => '',
                        'help_content' => __('选择不同的色系可以快速改变整个模板的颜色方案'),
                        'icon' => 'mdi-palette',
                        'configs' => []
                    ];
                }
                // 将可能散落在其他位置的 color_schemes 统一收敛到标准位置
                $colorSchemes = null;
                // 1) 标准位置已存在
                if (isset($cs['groups']['color_scheme']['configs']['color_scheme']['color_schemes'])
                    && is_array($cs['groups']['color_scheme']['configs']['color_scheme']['color_schemes'])) {
                    $colorSchemes = $cs['groups']['color_scheme']['configs']['color_scheme']['color_schemes'];
                }
                // 2) 分组级直接提供了 color_schemes
                if ($colorSchemes === null && isset($cs['groups']['color_scheme']['color_schemes']) && is_array($cs['groups']['color_scheme']['color_schemes'])) {
                    $colorSchemes = $cs['groups']['color_scheme']['color_schemes'];
                    unset($cs['groups']['color_scheme']['color_schemes']);
                }
                // 3) 文件级 configs.color_scheme
                if ($colorSchemes === null && isset($cs['configs']['color_scheme']['color_schemes']) && is_array($cs['configs']['color_scheme']['color_schemes'])) {
                    $colorSchemes = $cs['configs']['color_scheme']['color_schemes'];
                    unset($cs['configs']);
                }
                // 4) 文件级直接提供了 color_schemes
                if ($colorSchemes === null && isset($cs['color_schemes']) && is_array($cs['color_schemes'])) {
                    $colorSchemes = $cs['color_schemes'];
                    unset($cs['color_schemes']);
                }
                // 写回到标准位置
                if ($colorSchemes !== null) {
                    $cs['groups']['color_scheme']['configs']['color_scheme'] = [
                        'key' => 'color_scheme',
                        'label' => __('色系选择'),
                        'type' => 'color_scheme',
                        'default' => 'default',
                        'color_schemes' => $colorSchemes,
                        'options' => [],
                        'file' => 'color_scheme',
                        'group' => 'color_scheme',
                    ];
                }
            }

            // 处理色系的 preview_image，直接在后端解析为可访问的静态URL
            try {
                $templateHelper = \Weline\Framework\View\Template::getInstance();
                foreach ($configGroups as $fileKey => &$fileGroup) {
                    if (!isset($fileGroup['groups']) || !is_array($fileGroup['groups'])) continue;
                    foreach ($fileGroup['groups'] as $groupKey => &$group) {
                        if (!isset($group['configs']) || !is_array($group['configs'])) continue;
                        foreach ($group['configs'] as $configKey => &$config) {
                            if (($config['type'] ?? '') === 'color_scheme' && isset($config['color_schemes']) && is_array($config['color_schemes'])) {
                                foreach ($config['color_schemes'] as $schemeKey => $scheme) {
                                    $preview = $scheme['preview_image'] ?? '';
                                    if (is_string($preview) && $preview !== '') {
                                        // 支持已是绝对URL/以斜杠开头的URL：直接保留
                                        if (preg_match('#^(https?:)?//#', $preview) || str_starts_with($preview, '/')) {
                                            $resolved = $preview;
                                        } else {
                                            // 将相对 templates/ 路径转换为可访问URL
                                            $source = 'GuoLaiRen_PageBuilder::' . ltrim($preview, '/');
                                            $resolved = $templateHelper->fetchTemplateStatic($source);
                                        }
                                        // 回写为标准字段，尽量覆盖 preview_image，前端可直接使用
                                        $config['color_schemes'][$schemeKey]['preview_image'] = $resolved ?? '';
                                    }
                                }
                            }
                        }
                        unset($config);
                    }
                    unset($group);
                }
                unset($fileGroup);
            } catch (\Throwable $e) {
                // 忽略解析异常，保持原始数据返回
            }
            
            // 如果有页面ID，获取页面的配置值
            $pageSettings = [];
            if ($pageId > 0) {
                $page = clone $this->pageModel;
                $page->load($pageId);
                
                if ($page->getId()) {
                    $defaultLocale = $page->getData('default_locale') ?: '';
                    
                    // 先获取主表配置（默认配置）
                    $allSettings = $page->getStyleSetting();
                    $mainSettings = isset($allSettings[$styleCode]) ? $allSettings[$styleCode] : [];
                    
                    // 清理可能存在的三层结构（只保留配置项）
                    $cleanMainSettings = [];
                    foreach ($mainSettings as $key => $value) {
                        if (!is_array($value)) {
                            $cleanMainSettings[$key] = $value;
                        }
                    }
                    
                    // 如果是非默认语言，尝试从LocalDescription获取覆盖配置
                    if ($locale && $locale !== $defaultLocale) {
                        $localDesc = clone $this->localDescriptionModel;
                        $localDesc->clear()
                            ->where(LocalDescription::fields_ID, $pageId)
                            ->where('local_code', $locale)
                            ->find()
                            ->fetch();
                        
                        if ($localDesc->getId()) {
                            $configJson = $localDesc->getData('config');
                            if ($configJson) {
                                $config = json_decode($configJson ?? '', true);
                                if (isset($config['style_config']) && is_array($config['style_config'])) {
                                    // 语言特定配置覆盖主配置
                                    $pageSettings = array_merge($cleanMainSettings, $config['style_config']);
                                } else {
                                    $pageSettings = $cleanMainSettings;
                                }
                            } else {
                                $pageSettings = $cleanMainSettings;
                            }
                        } else {
                            $pageSettings = $cleanMainSettings;
                        }
                    } else {
                        // 默认语言直接使用主配置
                        $pageSettings = $cleanMainSettings;
                    }
                }
            }
            
            // 将页面配置值合并到配置定义中
            if (!empty($pageSettings)) {
                foreach ($configGroups as $fileKey => &$fileGroup) {
                    if (isset($fileGroup['groups']) && is_array($fileGroup['groups'])) {
                        foreach ($fileGroup['groups'] as $groupKey => &$group) {
                            if (isset($group['configs']) && is_array($group['configs'])) {
                                foreach ($group['configs'] as $configKey => &$config) {
                                    if (isset($pageSettings[$configKey])) {
                                        $savedValue = $pageSettings[$configKey];
                                        
                                        // 对于 select 类型，验证值是否在选项列表中
                                        // 如果值不在选项中，使用默认值（修复元数据解析问题）
                                        if ($config['type'] === 'select' && !empty($config['options'])) {
                                            // 检查保存的值是否在选项列表中
                                            if (!isset($config['options'][$savedValue])) {
                                                // 值不在选项中，使用默认值
                                                $config['value'] = $config['default'] ?? '';
                                            } else {
                                                // 值在选项中，使用保存的值
                                                $config['value'] = $savedValue;
                                            }
                                        } else {
                                            // 非 select 类型，直接使用保存的值
                                            $config['value'] = $savedValue;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                unset($fileGroup, $group, $config);
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $configGroups,
                'style_info' => [
                    'code' => $styleModel->getData(Style::fields_CODE),
                    'name' => $styleModel->getData(Style::fields_NAME)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 自动保存配置（用于测试页面）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_auto_save', '自动保存配置', 'mdi mdi-content-save', '保存测试页面的模板配置')]
    public function autoSave()
    {
        try {
            // 使用 getBodyParams() 兼容 WLS 和 FPM 模式（WLS 下 php://input 不可用）
            $bodyParams = $this->request->getBodyParams();
            if (is_string($bodyParams)) {
                $data = json_decode($bodyParams, true);
            } elseif (is_array($bodyParams) && !empty($bodyParams)) {
                $data = $bodyParams;
            } else {
                $data = null;
            }
            
            if (!$data) {
                $data = [
                    'page_id' => $this->request->getPost('page_id'),
                    'style_config' => $this->request->getPost('style_config', []),
                    'style_code' => $this->request->getPost('style_code')
                ];
            }
            
            $pageId = (int)($data['page_id'] ?? 0);
            $locale = $data['locale'] ?? '';
            $styleCode = $data['style_code'] ?? '';
            
            if (!$styleCode) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('样式代码不能为空')
                ]);
            }
            
            // 如果没有指定页面ID，使用测试页面
            if ($pageId <= 0) {
                $testPage = $this->ensureTestPageExists();
                $pageId = $testPage->getId();
                
                // 如果指定了样式代码，更新测试页面的样式
                if ($styleCode && $testPage->getData('style') !== $styleCode) {
                    $testPage->setData('style', $styleCode);
                    $testPage->save(true);
                }
            }
            
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
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
            
            // 确保页面使用当前模板样式
            $page->setData('style', $styleCode);
            
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
                
                $config = [];
                if ($localDesc->getId()) {
                    $configJson = $localDesc->getData('config');
                    if ($configJson) {
                        $config = json_decode($configJson ?? '', true) ?: [];
                    }
                }
                
                if (!isset($config['style_config'])) {
                    $config['style_config'] = [];
                }
                
                $config['style_config'] = array_merge(
                    $config['style_config'],
                    $styleConfig
                );
                
                if ($localDesc->getId()) {
                    $localDesc->setData('config', json_encode($config))->save();
                } else {
                    $newLocalDesc = clone $this->localDescriptionModel;
                    $newLocalDesc->clearData()
                        ->setData(LocalDescription::fields_ID, $pageId)
                        ->setData('local_code', $locale)
                        ->setData('config', json_encode($config))
                        ->setData(LocalDescription::fields_NAME, $page->getData('name'))
                        ->setData(LocalDescription::fields_TITLE, $page->getData('title'))
                        ->setData(LocalDescription::fields_CONTENT, $page->getData('content'))
                        ->save(true);
                }
            } else {
                // 保存到主表的style_setting字段（默认配置）
                $allSettings = $page->getStyleSetting();
                if (!is_array($allSettings)) {
                    $allSettings = [];
                }
                
                $allSettings[$styleCode] = array_merge(
                    $allSettings[$styleCode] ?? [],
                    $styleConfig
                );
                
                $page->setStyleSetting($allSettings);
            }
            
            // 保存页面
            $page->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('配置已保存'),
                'page_id' => $pageId
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 生成/刷新模板预览图片
     * 
     * 使用 Puppeteer/Chrome headless 或返回 URL 供前端截图
     * 
     * POST /pagebuilder/backend/template/generatePreview
     * 
     * 参数：
     * - style_code: 模板代码（必填）
     * - force: 是否强制刷新（可选，默认 false）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_generate_preview', '生成预览图', 'mdi mdi-image-plus', '生成或刷新模板预览图片')]
    public function postGeneratePreview()
    {
        try {
            // 使用 getBodyParams() 兼容 JSON 请求（WLS 模式不支持 multipart/form-data）
            $bodyParams = $this->request->getBodyParams();
            if (is_string($bodyParams)) {
                $decoded = json_decode($bodyParams, true);
                $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
            } elseif (is_array($bodyParams) && !empty($bodyParams)) {
                $data = $bodyParams;
            } else {
                $data = $this->request->getParams();
            }
            
            $styleCode = $data['style_code'] ?? $this->request->getPost('style_code');
            $force = (bool)($data['force'] ?? $this->request->getPost('force', false));
            
            if (empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板代码不能为空')
                ]);
            }
            
            // 检查模板是否存在
            $style = clone $this->styleModel;
            $style->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();
            
            if (!$style->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板不存在')
                ]);
            }
            
            // 预览图保存目录
            $previewDir = BP . 'pub/static/pagebuilder/previews/';
            if (!is_dir($previewDir)) {
                mkdir($previewDir, 0755, true);
            }
            
            // 预览图文件名（使用模板代码作为文件名）
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $styleCode);
            $previewFileName = $safeCode . '.png';
            $previewFilePath = $previewDir . $previewFileName;
            
            // 检查是否已存在预览图且不强制刷新
            if (!$force && file_exists($previewFilePath)) {
                $previewUrl = '/static/pagebuilder/previews/' . $previewFileName . '?t=' . filemtime($previewFilePath);
                
                // 更新数据库中的预览图路径
                $style->setData(Style::fields_PREVIEW_IMAGE, 'pagebuilder/previews/' . $previewFileName);
                $style->save();
                
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('预览图已存在'),
                    'preview_url' => $previewUrl,
                    'exists' => true
                ]);
            }
            
            // 返回预览页面 URL，让前端使用 html2canvas 截图后上传
            $previewPageUrl = $this->request->getUrlBuilder()->getBackendUrl(
                'pagebuilder/backend/preview/stylePreview',
                ['style_code' => $styleCode]
            );
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('请在前端截图后上传'),
                'preview_page_url' => $previewPageUrl,
                'upload_url' => $this->request->getUrlBuilder()->getBackendUrl('pagebuilder/backend/template/uploadPreview'),
                'style_code' => $styleCode,
                'needs_capture' => true
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 上传预览图片（由前端截图后调用）
     * 
     * POST /pagebuilder/backend/template/uploadPreview
     * 
     * 参数：
     * - style_code: 模板代码（必填）
     * - image_data: Base64 编码的图片数据（必填）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_upload_preview', '上传预览图', 'mdi mdi-upload', '上传模板预览图片')]
    public function postUploadPreview()
    {
        try {
            // 使用 getBodyParams() 兼容 JSON 和 FormData（WLS 模式不支持 multipart/form-data）
            $bodyParams = $this->request->getBodyParams();
            if (is_string($bodyParams)) {
                $decoded = json_decode($bodyParams, true);
                $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
            } elseif (is_array($bodyParams) && !empty($bodyParams)) {
                $data = $bodyParams;
            } else {
                $data = $this->request->getParams();
            }
            
            $styleCode = $data['style_code'] ?? $this->request->getPost('style_code');
            $imageData = $data['image_data'] ?? $this->request->getPost('image_data');
            
            if (empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板代码不能为空')
                ]);
            }
            
            if (empty($imageData)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('图片数据不能为空')
                ]);
            }
            
            // 检查模板是否存在
            $style = clone $this->styleModel;
            $style->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();
            
            if (!$style->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板不存在')
                ]);
            }
            
            // 解析 Base64 图片数据
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                $imageType = $matches[1];
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
            } else {
                $imageType = 'png';
            }
            
            $imageData = base64_decode($imageData);
            if ($imageData === false) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('图片数据解码失败')
                ]);
            }
            
            // 预览图保存目录
            $previewDir = BP . 'pub/static/pagebuilder/previews/';
            if (!is_dir($previewDir)) {
                mkdir($previewDir, 0755, true);
            }
            
            // 预览图文件名
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $styleCode);
            $previewFileName = $safeCode . '.' . $imageType;
            $previewFilePath = $previewDir . $previewFileName;
            
            // 保存图片
            if (file_put_contents($previewFilePath, $imageData) === false) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('图片保存失败')
                ]);
            }
            
            // 更新数据库中的预览图路径
            $style->setData(Style::fields_PREVIEW_IMAGE, 'pagebuilder/previews/' . $previewFileName);
            $style->save();
            
            $previewUrl = '/static/pagebuilder/previews/' . $previewFileName . '?t=' . time();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('预览图上传成功'),
                'preview_url' => $previewUrl
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 批量生成所有模板的预览图
     * 
     * POST /pagebuilder/backend/template/generateAllPreviews
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_generate_all_previews', '批量生成预览', 'mdi mdi-image-multiple', '批量生成所有模板的预览图')]
    public function postGenerateAllPreviews()
    {
        try {
            // 使用 getBodyParams() 兼容 JSON 请求（WLS 模式不支持 multipart/form-data）
            $bodyParams = $this->request->getBodyParams();
            if (is_string($bodyParams)) {
                $decoded = json_decode($bodyParams, true);
                $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
            } elseif (is_array($bodyParams) && !empty($bodyParams)) {
                $data = $bodyParams;
            } else {
                $data = $this->request->getParams();
            }
            
            $force = (bool)($data['force'] ?? $this->request->getPost('force', false));
            
            // 获取所有模板
            $styles = clone $this->styleModel;
            $styleList = $styles->clear()
                ->order(Style::fields_CODE, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            $results = [];
            foreach ($styleList as $style) {
                $styleCode = $style->getData(Style::fields_CODE);
                $previewImage = $style->getData(Style::fields_PREVIEW_IMAGE);
                
                // 检查是否已有预览图
                $hasPreview = false;
                if ($previewImage) {
                    $previewPath = BP . 'pub/static/' . $previewImage;
                    $hasPreview = file_exists($previewPath);
                }
                
                $results[] = [
                    'code' => $styleCode,
                    'name' => $style->getData(Style::fields_NAME),
                    'has_preview' => $hasPreview,
                    'needs_generate' => !$hasPreview || $force,
                    'preview_page_url' => $this->request->getUrlBuilder()->getBackendUrl(
                        'pagebuilder/backend/preview/stylePreview',
                        ['style_code' => $styleCode]
                    )
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $results,
                'upload_url' => $this->request->getUrlBuilder()->getBackendUrl('pagebuilder/backend/template/uploadPreview'),
                'total' => count($results),
                'needs_generate' => count(array_filter($results, fn($r) => $r['needs_generate']))
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 一键读取所有文字配置项
     * 
     * 用于AI翻译功能，提取模板中所有 texts 分组的配置项
     * 
     * GET /pagebuilder/backend/template/getTextConfigs
     * 
     * 参数：
     * - style_code: 模板代码（必填）
     * - page_id: 页面ID（可选，用于获取已保存的值）
     * - locale: 语言代码（可选，用于获取特定语言的配置）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::template_management_get_text_configs', '获取文字配置', 'mdi mdi-text', '获取模板中所有文字配置项用于AI翻译')]
    public function getTextConfigs()
    {
        try {
            $styleCode = $this->request->getGet('style_code');
            $pageId = (int)$this->request->getGet('page_id', 0);
            $locale = $this->request->getGet('locale');
            
            if (empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板代码不能为空')
                ]);
            }
            
            // 强制实时扫描模板配置
            Style::forceScan();
            
            // 获取模板配置定义
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();
            
            if (!$styleModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模板不存在')
                ]);
            }
            
            $configGroups = $styleModel->getConfigGroups();
            
            // 提取所有 texts 分组的配置项
            $textConfigs = [];
            foreach ($configGroups as $fileKey => $fileGroup) {
                if (!isset($fileGroup['groups']) || !is_array($fileGroup['groups'])) {
                    continue;
                }
                
                foreach ($fileGroup['groups'] as $groupKey => $group) {
                    // 只提取 texts 分组
                    if ($groupKey !== 'texts' || !isset($group['configs']) || !is_array($group['configs'])) {
                        continue;
                    }
                    
                    foreach ($group['configs'] as $configKey => $config) {
                        // 只提取文本类型的配置（text, textarea）
                        $type = $config['type'] ?? '';
                        if (!in_array($type, ['text', 'textarea'])) {
                            continue;
                        }
                        
                        $textConfigs[] = [
                            'key' => $configKey,
                            'label' => $config['label'] ?? $configKey,
                            'type' => $type,
                            'value' => $config['value'] ?? $config['default'] ?? '',
                            'default' => $config['default'] ?? '',
                            'file' => $fileKey,
                            'group' => $groupKey,
                        ];
                    }
                }
            }
            
            // 如果有页面ID，获取已保存的值
            if ($pageId > 0) {
                $page = clone $this->pageModel;
                $page->load($pageId);
                
                if ($page->getId()) {
                    $defaultLocale = $page->getData('default_locale') ?: '';
                    
                    // 获取主表配置
                    $allSettings = $page->getStyleSetting();
                    $mainSettings = isset($allSettings[$styleCode]) ? $allSettings[$styleCode] : [];
                    
                    // 清理可能存在的三层结构
                    $cleanMainSettings = [];
                    foreach ($mainSettings as $key => $value) {
                        if (!is_array($value)) {
                            $cleanMainSettings[$key] = $value;
                        }
                    }
                    
                    // 如果是非默认语言，尝试从LocalDescription获取覆盖配置
                    if ($locale && $locale !== $defaultLocale) {
                        $localDesc = clone $this->localDescriptionModel;
                        $localDesc->clear()
                            ->where(LocalDescription::fields_ID, $pageId)
                            ->where('local_code', $locale)
                            ->find()
                            ->fetch();
                        
                        if ($localDesc->getId()) {
                            $configJson = $localDesc->getData('config');
                            if ($configJson) {
                                $config = json_decode($configJson ?? '', true);
                                if (isset($config['style_config']) && is_array($config['style_config'])) {
                                    $cleanMainSettings = array_merge($cleanMainSettings, $config['style_config']);
                                }
                            }
                        }
                    }
                    
                    // 更新配置项的值
                    foreach ($textConfigs as &$textConfig) {
                        $key = $textConfig['key'];
                        if (isset($cleanMainSettings[$key])) {
                            $textConfig['value'] = $cleanMainSettings[$key];
                        }
                    }
                    unset($textConfig);
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'text_configs' => $textConfigs,
                    'total' => count($textConfigs),
                    'style_code' => $styleCode,
                    'style_name' => $styleModel->getData(Style::fields_NAME),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}

