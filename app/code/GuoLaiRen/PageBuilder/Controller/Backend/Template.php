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
        
        // 获取或创建test页面（用于预览）
        $testPage = clone $this->pageModel;
        $testPage->clear()
            ->where('handle', 'template-test-page')
            ->find()
            ->fetch();
        
        if (!$testPage->getId()) {
            // 创建系统test页面
            $testPage->clearData()
                ->setData('handle', 'template-test-page')
                ->setData('name', '模板测试页面（系统）')
                ->setData('title', 'Template Test Page')
                ->setData('type', 'test_page')
                ->setData('status', 1)
                ->setData('content', '这是模板测试页面，用于预览和测试模板配置。')
                ->setData('meta_title', 'Template Test Page')
                ->setData('meta_description', 'Template test page for preview')
                ->save(true);
        }
        
        $this->assign('test_page_id', $testPage->getId() ?: 0);
        
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
            $styleId = (int)$this->request->getPost('style_id');
            $isPublished = (int)$this->request->getPost('is_published', 0);
            
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
            $testPage = clone $this->pageModel;
            $testPage->clear()
                ->where('handle', 'template-test-page')
                ->find()
                ->fetch();
            
            if ($testPage->getId()) {
                $pageId = $testPage->getId();
            } else {
                // 自动创建test页面
                $testPage->clearData()
                    ->setData('handle', 'template-test-page')
                    ->setData('name', '模板测试页面（系统）')
                    ->setData('title', 'Template Test Page')
                    ->setData('type', 'test_page')
                    ->setData('status', 1)
                    ->setData('content', '这是模板测试页面，用于预览和测试模板配置。')
                    ->setData('meta_title', 'Template Test Page')
                    ->setData('meta_description', 'Template test page for preview')
                    ->save(true);
                $pageId = $testPage->getId();
            }
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
                                        $config['value'] = $pageSettings[$configKey];
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
                'data' => $configGroups
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
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody ?? '', true);
            
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
                $testPage = clone $this->pageModel;
                $testPage->clear()
                    ->where('handle', 'template-test-page')
                    ->find()
                    ->fetch();
                
                if ($testPage->getId()) {
                    $pageId = $testPage->getId();
                } else {
                    // 自动创建测试页面
                    $testPage->clearData()
                        ->setData('handle', 'template-test-page')
                        ->setData('name', '模板测试页面（系统）')
                        ->setData('title', 'Template Test Page')
                        ->setData('type', 'test_page')
                        ->setData('status', 1)
                        ->setData('content', '这是模板测试页面，用于预览和测试模板配置。')
                        ->setData('meta_title', 'Template Test Page')
                        ->setData('meta_description', 'Template test page for preview')
                        ->setData('style', $styleCode)
                        ->save(true);
                    $pageId = $testPage->getId();
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
}

