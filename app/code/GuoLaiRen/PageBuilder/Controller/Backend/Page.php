<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 页面构建器后端控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;
use Weline\UrlManager\Model\UrlRewrite;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder', '页面构建器', 'mdi mdi-file-document-edit', '管理和构建页面')]
class Page extends BackendController
{
    private PageModel $pageModel;
    private LocalDescription $localDescriptionModel;
    private I18n $i18nModel;
    private Locals $localsModel;
    private Style $styleModel;

    public function __construct(
        PageModel $pageModel,
        LocalDescription $localDescriptionModel,
        I18n $i18nModel,
        Locals $localsModel,
        Style $styleModel
    ) {
        $this->pageModel = $pageModel->loadLocalDescription();
        $this->localDescriptionModel = $localDescriptionModel;
        $this->i18nModel = $i18nModel;
        $this->localsModel = $localsModel;
        $this->styleModel = $styleModel;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_index', '页面列表', 'mdi mdi-view-list', '查看页面列表')]
    public function index()
    {
        // 设置页面标题
        $this->assign('page_title', __('页面构建器'));
        $this->assign('breadcrumb_parent', __('内容管理'));
        $this->assign('breadcrumb_current', __('页面构建器'));
        
        // 克隆一个新的模型用于列表查询（不使用 loadLocalDescription 避免联表歧义）
        $listModel = clone $this->pageModel;
        $listModel->clear();
        
        // 搜索功能
        if ($search = $this->request->getGet('search')) {
            $listModel->where('name', "%$search%", 'like')
                ->where('title', "%$search%", 'like', 'or')
                ->where('handle', "%$search%", 'like', 'or');
        }
        
        // 获取页面列表数据（按创建时间倒序排列）
        $pages = $listModel
            ->order(PageModel::fields_CREATE_TIME, 'DESC')
            ->pagination()
            ->select()
            ->fetch();
        
        $this->assign('pages', $pages->getItems());
        $this->assign('pagination', $pages->getPagination());
        
        return $this->fetch();
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_create', '新建页面', 'mdi mdi-plus', '新建页面')]
    public function getCreate()
    {
        // 强制扫描样式模板，确保配置是最新的
        Style::forceScan();
        
        $this->assign('page_title', __('新建页面'));
        $this->assign('breadcrumb_parent', __('页面构建器'));
        $this->assign('breadcrumb_current', __('新建页面'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/create'));
        
        // 获取所有已激活的语言（从 i18n_locals 表读取，已有 name 字段）
        $localsQuery = clone $this->localsModel;
        $activeLocales = $localsQuery->clear()
            ->where(Locals::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('active_locales', $activeLocales);
        
        // 新建页面时，selected_locales 为空数组
        $this->assign('selected_locales', []);
        
        // 获取所有页面类型
        $this->assign('page_types', PageModel::getPageTypes());
        
        // 样式列表将通过AJAX加载，这里传空数组
        $this->assign('styles', []);
        
        // 样式配置信息（新建页面时为空）
        $this->assign('style_configs', []);
        $this->assign('style_settings', []);
        
        // 获取父页面列表（用于选择）
        $parentPages = clone $this->pageModel;
        $parentPages = $parentPages->clear()
            ->select()
            ->fetch()
            ->getItems();
        $this->assign('parent_pages', $parentPages);
        
        // 回填父页面ID
        $parentId = $this->request->getGet('parent_id', 0);
        $this->assign('parent_id', $parentId);
        
        // 如果指定了父页面，获取父页面的配置用于继承
        $parentPageData = null;
        if ($parentId) {
            $parentPageModel = clone $this->pageModel;
            $parentPageModel->clear()->load($parentId);
            if ($parentPageModel->getId()) {
                $parentPageData = [
                    'style' => $parentPageModel->getData('style'),
                    'locales' => $parentPageModel->getData('locales'),
                    'default_locale' => $parentPageModel->getData('default_locale'),
                    'logo' => $parentPageModel->getData('logo'),
                    'favicon' => $parentPageModel->getData('icon'), // icon 是 favicon
                    'ga4_id' => $parentPageModel->getData('ga4_id'),
                    'gtm_id' => $parentPageModel->getData('gtm_id'),
                    'fb_pixel_id' => $parentPageModel->getData('fb_pixel_id'),
                ];
            }
        }
        $this->assign('parent_page_data', $parentPageData);
        
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_create_post', '新建页面请求', '', '新建页面请求')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();
            
            // 处理选中的语言列表
            $selectedLocales = $this->request->getPost('locales', []);
            $defaultLocale = $this->request->getPost('default_locale', '');
            
            // 如果没有设置默认语言，使用框架当前语言
            if (empty($defaultLocale)) {
                $defaultLocale = \Weline\Framework\Http\Cookie::getLang();
            }
            
            // 确保默认语言在支持的语言列表中
            if (!in_array($defaultLocale, $selectedLocales)) {
                $selectedLocales[] = $defaultLocale;
            }
            
            // 处理样式配置信息 - 保存当前style的配置，不影响其他style的配置
            $currentStyleSettings = $this->request->getPost('style_settings', []);
            $currentStyleCode = $data['style'] ?? '';
            
            // 构建完整的配置存储结构：每个style独立存储其配置
            $allStyleSettings = [];
            if (!empty($currentStyleCode) && !empty($currentStyleSettings)) {
                $allStyleSettings[$currentStyleCode] = $currentStyleSettings;
            }
            
            // 从POST或URL参数获取parent_id（用于创建子页面）
            // 优先使用POST数据（表单隐藏字段），然后再尝试URL参数
            $parentId = $data['parent_id'] ?? $this->request->getGet('parent_id', 0);
            
            // 确保parent_id是有效的数字
            $parentId = (int)$parentId;
            
            // 如果有父页面，语言设置直接继承父页面（不使用表单提交的值）
            if ($parentId > 0) {
                $parentPageModel = clone $this->pageModel;
                $parentPageModel->clear()->load($parentId);
                if ($parentPageModel->getId()) {
                    // 强制使用父页面的语言设置
                    $selectedLocales = json_decode($parentPageModel->getData(PageModel::fields_LOCALES), true) ?: [];
                    $defaultLocale = $parentPageModel->getData(PageModel::fields_DEFAULT_LOCALE);
                    
                    // 确保默认语言在列表中
                    if ($defaultLocale && !in_array($defaultLocale, $selectedLocales)) {
                        $selectedLocales[] = $defaultLocale;
                    }
                    
                    // 显示继承信息
                    $this->getMessageManager()->addSuccess(__('子页面已继承父页面配置'));
                    if (DEV) {
                        $this->getMessageManager()->addSuccess(__('父页面ID：%{1}', $parentId));
                        $this->getMessageManager()->addSuccess(__('继承样式：%{1}', $data['style'] ?? '无'));
                        $this->getMessageManager()->addSuccess(__('继承Logo：%{1}', $data['logo'] ?? '无'));
                        $this->getMessageManager()->addSuccess(__('继承默认语言：%{1}', $defaultLocale));
                    }
                }
            }
            
            // 创建页面
            $page = clone $this->pageModel;
            $page->clearData()
                ->setData(PageModel::fields_HANDLE, $data['handle'])
                ->setData(PageModel::fields_TYPE, $data['type'])
                ->setData(PageModel::fields_NAME, $data['name'])
                ->setData(PageModel::fields_TITLE, $data['title'])
                ->setData(PageModel::fields_CONTENT, $data['content'] ?? '')
                ->setData(PageModel::fields_PARENT_ID, $parentId)
                ->setData(PageModel::fields_STYLE, $data['style'] ?? '')
                ->setData(PageModel::fields_STYLE_SETTING, json_encode($allStyleSettings))
                ->setData(PageModel::fields_GA4_ID, $data['ga4_id'] ?? '')
                ->setData(PageModel::fields_GTM_ID, $data['gtm_id'] ?? '')
                ->setData(PageModel::fields_FB_PIXEL_ID, $data['fb_pixel_id'] ?? '')
                ->setData(PageModel::fields_LOGO, $data['logo'] ?? '')
                ->setData(PageModel::fields_ICON, $data['icon'] ?? '')
                ->setData(PageModel::fields_LOCALES, json_encode($selectedLocales))
                ->setData(PageModel::fields_DEFAULT_LOCALE, $defaultLocale)
                ->setData(PageModel::fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(PageModel::fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(PageModel::fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->setData(PageModel::fields_REDIRECT_URL, $data['redirect_url'] ?? '')
                ->setData(PageModel::fields_STATUS, $data['status'] ?? PageModel::STATUS_DRAFT)
                ->save(true);
            
            // 自动创建 URL 重写规则
            $this->createOrUpdateUrlRewrite($page);
            
            $this->getMessageManager()->addSuccess(__('页面创建成功！'));
            $this->redirect('*/backend/page/edit', ['id' => $page->getId()]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('页面创建失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/page/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_edit', '编辑页面', 'mdi mdi-pencil', '编辑页面')]
    public function getEdit()
    {
        // 强制扫描样式模板，确保配置是最新的
        Style::forceScan();
        
        $pageId = $this->request->getGet('id');
        if (!$pageId) {
            $this->getMessageManager()->addError(__('页面ID不能为空！'));
            $this->redirect('*/backend/page/index');
            return;
        }

        $page = clone $this->pageModel;
        $page->clear()->loadLocalDescription()->load($pageId);
        
        if (!$page->getId()) {
            $this->getMessageManager()->addError(__('页面不存在！'));
            $this->redirect('*/backend/page/index');
            return;
        }

        $this->assign('page_title', __('编辑页面'));
        $this->assign('breadcrumb_parent', __('页面构建器'));
        $this->assign('breadcrumb_current', __('编辑页面'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/edit', ['id' => $pageId]));
        $this->assign('page', $page);
        
        // 获取所有已激活的语言（从 i18n_locals 表读取，已有 name 字段）
        $localsQuery = clone $this->localsModel;
        $activeLocales = $localsQuery->clear()
            ->where(Locals::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('active_locales', $activeLocales);
        
        // 获取选中的语言
        $selectedLocales = $page->getSelectedLocales();
        $this->assign('selected_locales', $selectedLocales);
        
        // 获取所有页面类型
        $this->assign('page_types', PageModel::getPageTypes());
        
        // 获取父页面列表（排除自己）
        $parentPages = clone $this->pageModel;
        $parentPages = $parentPages->clear()
            ->where(PageModel::fields_ID, $pageId, '!=')
            ->select()
            ->fetch()
            ->getItems();
        $this->assign('parent_pages', $parentPages);
        
        // 如果当前页面有父页面，获取父页面的配置用于继承锁定
        $currentParentId = $page->getData(PageModel::fields_PARENT_ID);
        $parentPageData = null;
        if ($currentParentId && $currentParentId > 0) {
            $parentPageModel = clone $this->pageModel;
            $parentPageModel->clear()->load($currentParentId);
            if ($parentPageModel->getId()) {
                $parentPageData = [
                    'style' => $parentPageModel->getData('style'),
                    'locales' => $parentPageModel->getData('locales'),
                    'default_locale' => $parentPageModel->getData('default_locale'),
                    'logo' => $parentPageModel->getData('logo'),
                    'favicon' => $parentPageModel->getData('icon'),
                    'ga4_id' => $parentPageModel->getData('ga4_id'),
                    'gtm_id' => $parentPageModel->getData('gtm_id'),
                    'fb_pixel_id' => $parentPageModel->getData('fb_pixel_id'),
                ];
            }
        }
        $this->assign('parent_page_data', $parentPageData);
        $this->assign('parent_id', $currentParentId ?? 0);
        
        // 获取已翻译的语言数据
        $localDescriptions = clone $this->localDescriptionModel;
        $translations = $localDescriptions->clear()
            ->where(LocalDescription::fields_ID, $pageId)
            ->select()
            ->fetch()
            ->getItems();
        
        $translationsData = [];
        $localizedContents = [];
        foreach ($translations as $translation) {
            $localeCode = $translation->getData('local_code');
            $translationsData[$localeCode] = $translation;
            
            // 为多语言内容编辑 Tab 准备数据
            $localizedContents[$localeCode] = [
                'title' => $translation->getData(LocalDescription::fields_TITLE),
                'content' => $translation->getData(LocalDescription::fields_CONTENT),
                'meta_title' => $translation->getData(LocalDescription::fields_META_TITLE),
                'meta_description' => $translation->getData(LocalDescription::fields_META_DESCRIPTION),
                'meta_keywords' => $translation->getData(LocalDescription::fields_META_KEYWORDS),
            ];
        }
        $this->assign('translations', $translationsData);
        $this->assign('localized_contents', $localizedContents);
        
        // 检查未翻译的语言
        $missingTranslations = [];
        foreach ($selectedLocales as $locale) {
            if (!isset($translationsData[$locale])) {
                $localeName = $this->i18nModel->getLocaleName($locale);
                $missingTranslations[] = $localeName;
            }
        }
        
        if (!empty($missingTranslations)) {
            $this->getMessageManager()->addWarning(
                __('页面还有以下语言未翻译：%{1}', implode(', ', $missingTranslations))
            );
        }
        
        // 样式列表将通过AJAX加载，这里传空数组
        $this->assign('styles', []);
        
        // 获取当前页面的样式配置
        $currentStyle = $page->getData(PageModel::fields_STYLE);
        $allStyleSettings = $page->getStyleSetting(); // 所有样式的配置
        
        // 获取当前样式的配置值（从所有样式配置中提取）
        $currentStyleSettings = [];
        if ($currentStyle && isset($allStyleSettings[$currentStyle])) {
            $currentStyleSettings = $allStyleSettings[$currentStyle];
        }
        
        // 获取子页面或同级页面数据（用于树形结构显示）
        $relatedPages = [];
        $isChildPage = false;
        if ($currentParentId && $currentParentId > 0) {
            // 当前是子页面，获取同级子页面
            $isChildPage = true;
            $relatedPagesModel = clone $this->pageModel;
            $relatedPages = $relatedPagesModel->clear()
                ->where(PageModel::fields_PARENT_ID, $currentParentId)
                ->select()
                ->fetch()
                ->getItems();
        } else {
            // 当前是父页面，获取其子页面
            $relatedPagesModel = clone $this->pageModel;
            $relatedPages = $relatedPagesModel->clear()
                ->where(PageModel::fields_PARENT_ID, $pageId)
                ->select()
                ->fetch()
                ->getItems();
        }
        $this->assign('related_pages', $relatedPages);
        $this->assign('is_child_page', $isChildPage);
        
        // 如果页面选择了样式，加载该样式的配置定义
        $styleConfigs = [];
        if ($currentStyle) {
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(Style::fields_CODE, $currentStyle)->find()->fetch();
            if ($styleModel->getId()) {
                $styleConfigs = $styleModel->getConfigGroups();
            }
        }
        
        $this->assign('style_configs', $styleConfigs);
        $this->assign('style_settings', $currentStyleSettings);
        
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_edit_post', '编辑页面请求', '', '编辑页面请求')]
    public function postEdit()
    {
        try {
            $pageId = $this->request->getGet('id');
            $data = $this->request->getPost();
            
            $page = clone $this->pageModel;
            $page->clear()->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception(__('页面不存在！'));
            }
            
            // 检查当前页面是否有父页面，如果有则强制继承父页面配置
            $currentParentId = $page->getData(PageModel::fields_PARENT_ID);
            
            // 处理选中的语言列表
            $selectedLocales = $this->request->getPost('locales', []);
            $defaultLocale = $this->request->getPost('default_locale', '');
            
            // 如果当前页面有父页面，强制使用父页面的语言设置（不使用表单提交的值）
            if ($currentParentId && $currentParentId > 0) {
                $parentPageModel = clone $this->pageModel;
                $parentPageModel->clear()->load($currentParentId);
                if ($parentPageModel->getId()) {
                    // 强制使用父页面的语言设置
                    $selectedLocales = json_decode($parentPageModel->getData(PageModel::fields_LOCALES), true) ?: [];
                    $defaultLocale = $parentPageModel->getData(PageModel::fields_DEFAULT_LOCALE);
                    
                    // 确保默认语言在列表中
                    if ($defaultLocale && !in_array($defaultLocale, $selectedLocales)) {
                        $selectedLocales[] = $defaultLocale;
                    }
                    
                    $this->getMessageManager()->addSuccess(__('子页面配置已从父页面继承'));
                }
            } else {
                // 非子页面，正常处理语言设置
                // 如果没有设置默认语言，使用框架当前语言
                if (empty($defaultLocale)) {
                    $defaultLocale = \Weline\Framework\Http\Cookie::getLang();
                }
                
                // 确保默认语言在支持的语言列表中
                if (!in_array($defaultLocale, $selectedLocales)) {
                    $selectedLocales[] = $defaultLocale;
                }
            }
            
            // 处理样式配置信息 - 合并保存，保留其他style的配置
            $currentStyleSettings = $this->request->getPost('style_settings', []);
            $currentStyleCode = $data['style'] ?? '';
            
            // 获取页面原有的所有样式配置
            $existingSettings = $page->getStyleSetting();
            
            // 更新当前style的配置，保留其他style的配置
            if (!empty($currentStyleCode)) {
                $existingSettings[$currentStyleCode] = $currentStyleSettings;
            }
            
            // 更新页面
            $page->setData(PageModel::fields_HANDLE, $data['handle'])
                ->setData(PageModel::fields_TYPE, $data['type'])
                ->setData(PageModel::fields_NAME, $data['name'])
                ->setData(PageModel::fields_TITLE, $data['title'])
                ->setData(PageModel::fields_CONTENT, $data['content'] ?? '')
                ->setData(PageModel::fields_PARENT_ID, $data['parent_id'] ?? 0)
                ->setData(PageModel::fields_STYLE, $data['style'] ?? '')
                ->setData(PageModel::fields_STYLE_SETTING, json_encode($existingSettings))
                ->setData(PageModel::fields_GA4_ID, $data['ga4_id'] ?? '')
                ->setData(PageModel::fields_GTM_ID, $data['gtm_id'] ?? '')
                ->setData(PageModel::fields_FB_PIXEL_ID, $data['fb_pixel_id'] ?? '')
                ->setData(PageModel::fields_LOGO, $data['logo'] ?? '')
                ->setData(PageModel::fields_ICON, $data['icon'] ?? '')
                ->setData(PageModel::fields_LOCALES, json_encode($selectedLocales))
                ->setData(PageModel::fields_DEFAULT_LOCALE, $defaultLocale)
                ->setData(PageModel::fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(PageModel::fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(PageModel::fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->setData(PageModel::fields_REDIRECT_URL, $data['redirect_url'] ?? '')
                ->setData(PageModel::fields_STATUS, $data['status'] ?? PageModel::STATUS_DRAFT)
                ->save();
            
            // 自动创建或更新 URL 重写规则
            $this->createOrUpdateUrlRewrite($page);
            
            // 处理多语言内容翻译
            if (!empty($selectedLocales)) {
                foreach ($selectedLocales as $locale) {
                    // 跳过默认语言（默认语言内容已经保存在主表中）
                    if ($locale == $defaultLocale) {
                        continue;
                    }
                    
                    // 从多语言 Tab 表单获取该语言的翻译内容
                    $titleKey = 'title_' . $locale;
                    $contentKey = 'content_' . $locale;
                    $metaTitleKey = 'meta_title_' . $locale;
                    $metaDescKey = 'meta_description_' . $locale;
                    $metaKeywordsKey = 'meta_keywords_' . $locale;
                    
                    $translatedTitle = $data[$titleKey] ?? '';
                    $translatedContent = $data[$contentKey] ?? '';
                    $translatedMetaTitle = $data[$metaTitleKey] ?? '';
                    $translatedMetaDesc = $data[$metaDescKey] ?? '';
                    $translatedMetaKeywords = $data[$metaKeywordsKey] ?? '';
                    
                    // 查找或创建翻译记录
                    $localDesc = clone $this->localDescriptionModel;
                    $existing = $localDesc->clear()
                        ->where(LocalDescription::fields_ID, $pageId)
                        ->where('local_code', $locale)
                        ->find()
                        ->fetch();
                    
                    if ($existing && $existing->getId()) {
                        // 更新现有翻译
                        $existing->setData(LocalDescription::fields_NAME, $data['name'] ?? '')
                            ->setData(LocalDescription::fields_TITLE, $translatedTitle)
                            ->setData(LocalDescription::fields_CONTENT, $translatedContent)
                            ->setData(LocalDescription::fields_META_TITLE, $translatedMetaTitle)
                            ->setData(LocalDescription::fields_META_DESCRIPTION, $translatedMetaDesc)
                            ->setData(LocalDescription::fields_META_KEYWORDS, $translatedMetaKeywords)
                            ->save();
                    } else {
                        // 创建新翻译
                        $newTranslation = clone $this->localDescriptionModel;
                        $newTranslation->clearData()
                            ->setData(LocalDescription::fields_ID, $pageId)
                            ->setData('local_code', $locale)
                            ->setData(LocalDescription::fields_NAME, $data['name'] ?? '')
                            ->setData(LocalDescription::fields_TITLE, $translatedTitle)
                            ->setData(LocalDescription::fields_CONTENT, $translatedContent)
                            ->setData(LocalDescription::fields_META_TITLE, $translatedMetaTitle)
                            ->setData(LocalDescription::fields_META_DESCRIPTION, $translatedMetaDesc)
                            ->setData(LocalDescription::fields_META_KEYWORDS, $translatedMetaKeywords)
                            ->save();
                    }
                }
            }
            
            $this->getMessageManager()->addSuccess(__('页面更新成功！'));
            
            // 检查是否为AJAX请求
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('页面更新成功！'),
                    'page_id' => $pageId,
                    'style' => $data['style'] ?? ''
                ]);
            }
            
            $this->redirect('*/backend/page/edit', ['id' => $pageId]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('页面更新失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            
            // 检查是否为AJAX请求
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面更新失败：') . $exception->getMessage()
                ]);
            }
            
            $this->redirect('*/backend/page/edit', ['id' => $this->request->getGet('id')]);
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_delete', '删除页面', 'mdi mdi-delete', '删除页面')]
    public function postDelete()
    {
        try {
            $pageId = $this->request->getPost('id');
            
            $page = clone $this->pageModel;
            $page->clear()->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception(__('页面不存在！'));
            }
            
            // 检查是否有子页面
            $childPages = $page->getChildPages();
            if (!empty($childPages)) {
                throw new \Exception(__('该页面存在子页面，无法删除！请先删除或移动子页面。'));
            }
            
            // 删除页面和翻译数据
            $page->delete();
            
            // 删除翻译数据
            $localDescriptions = clone $this->localDescriptionModel;
            $localDescriptions->clear()
                ->where(LocalDescription::fields_ID, $pageId)
                ->delete()
                ->fetch();
            
            $this->getMessageManager()->addSuccess(__('页面删除成功！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('页面删除失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
        }
        
        $this->redirect('*/backend/page/index');
    }
    
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_translate', '翻译页面', 'mdi mdi-translate', '翻译页面')]
    public function getTranslate()
    {
        $pageId = $this->request->getGet('id');
        $locale = $this->request->getGet('locale');
        
        if (!$pageId || !$locale) {
            $this->getMessageManager()->addError(__('参数错误！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        $page = clone $this->pageModel;
        $page->clear()->load($pageId);
        
        if (!$page->getId()) {
            $this->getMessageManager()->addError(__('页面不存在！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        $this->assign('page_title', __('翻译页面'));
        $this->assign('breadcrumb_parent', __('页面构建器'));
        $this->assign('breadcrumb_current', __('翻译页面'));
        $this->assign('page', $page);
        $this->assign('locale', $locale);
        $this->assign('locale_name', $this->i18nModel->getLocaleName($locale));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/translate', ['id' => $pageId, 'locale' => $locale]));
        
        // 获取翻译数据
        $localDescription = clone $this->localDescriptionModel;
        $localDescription->clear()
            ->where(LocalDescription::fields_ID, $pageId)
            ->where('local_code', $locale)
            ->find()
            ->fetch();
        
        $this->assign('translation', $localDescription);
        
        return $this->fetch('translate');
    }
    
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_translate_post', '翻译页面请求', '', '翻译页面请求')]
    public function postTranslate()
    {
        try {
            $pageId = $this->request->getGet('id');
            $locale = $this->request->getGet('locale');
            $data = $this->request->getPost();
            
            // 保存或更新翻译
            $localDescription = clone $this->localDescriptionModel;
            $localDescription->clear()
                ->where(LocalDescription::fields_ID, $pageId)
                ->where('local_code', $locale)
                ->find()
                ->fetch();
            
            $localDescription->setData(LocalDescription::fields_ID, $pageId)
                ->setData('local_code', $locale)
                ->setData(LocalDescription::fields_NAME, $data['name'] ?? '')
                ->setData(LocalDescription::fields_TITLE, $data['title'] ?? '')
                ->setData(LocalDescription::fields_CONTENT, $data['content'] ?? '')
                ->setData(LocalDescription::fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(LocalDescription::fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(LocalDescription::fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->save(true);
            
            $this->getMessageManager()->addSuccess(__('翻译保存成功！'));
            $this->redirect('*/backend/page/edit', ['id' => $pageId]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('翻译保存失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/page/translate', [
                'id' => $this->request->getGet('id'),
                'locale' => $this->request->getGet('locale')
            ]);
        }
    }
    
    /**
     * 获取样式列表（AJAX接口）
     * 实时扫描样式目录并返回最新的样式列表
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_get_styles', '获取样式列表', '', '获取样式列表')]
    public function getStyles()
    {
        try {
            // 强制扫描样式模板（实时获取最新数据）
            Style::forceScan();
            
            // 获取所有可用样式
            $styles = clone $this->styleModel;
            $styleList = $styles->clear()
                ->where(Style::fields_IS_ACTIVE, 1)
                ->order(Style::fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 格式化样式数据
            $formattedStyles = [];
            foreach ($styleList as $style) {
                $formattedStyles[] = [
                    'id' => $style->getId(),
                    'code' => $style->getData(Style::fields_CODE),
                    'name' => $style->getData(Style::fields_NAME),
                    'description' => $style->getData(Style::fields_DESCRIPTION),
                    'path' => $style->getData(Style::fields_PATH),
                    'preview_image' => $style->getData(Style::fields_PREVIEW_IMAGE),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $formattedStyles,
                'count' => count($formattedStyles),
                'message' => __('样式列表获取成功')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取样式配置信息（AJAX接口）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_get_style_config', '获取样式配置', '', '获取样式配置信息')]
    public function getStyleConfig()
    {
        try {
            $styleCode = $this->request->getGet('style_code');
            $pageId = (int)$this->request->getGet('page_id');
            $locale = $this->request->getGet('locale');
            
            if (empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('样式代码不能为空')
                ]);
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
                            ->where(\GuoLaiRen\PageBuilder\Model\Page\LocalDescription::fields_ID, $pageId)
                            ->where('local_code', $locale)
                            ->find()
                            ->fetch();
                        
                        if ($localDesc->getId()) {
                            $configJson = $localDesc->getData('config');
                            if ($configJson) {
                                $config = json_decode($configJson, true);
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
            // 数据结构：fileGroups[fileKey]['groups'][groupKey]['configs'][configKey]
            if (!empty($pageSettings)) {
                foreach ($configGroups as $fileKey => &$fileGroup) {
                    // 遍历文件下的分组
                    if (isset($fileGroup['groups']) && is_array($fileGroup['groups'])) {
                        foreach ($fileGroup['groups'] as $groupKey => &$group) {
                            // 遍历分组下的配置项
                            if (isset($group['configs']) && is_array($group['configs'])) {
                                foreach ($group['configs'] as $configKey => &$config) {
                                    // 如果页面有该配置的值，设置value字段
                                    if (isset($pageSettings[$configKey])) {
                                        $config['value'] = $pageSettings[$configKey];
                                    }
                                }
                            }
                        }
                    }
                }
                unset($fileGroup, $group, $config); // 清除引用
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $configGroups,
                'debug' => [ // 调试信息
                    'page_id' => $pageId,
                    'style_code' => $styleCode,
                    'locale' => $locale,
                    'default_locale' => isset($defaultLocale) ? $defaultLocale : null,
                    'is_default_locale' => isset($defaultLocale) && $locale === $defaultLocale,
                    'page_settings' => $pageSettings,
                    'main_settings' => isset($cleanMainSettings) ? $cleanMainSettings : [],
                    'has_locale_override' => isset($config['style_config']) ? true : false
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
     * 检查 handle 是否已存在（AJAX接口）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_check_handle', '检查句柄', '', '检查页面句柄是否重复')]
    public function getCheckHandle()
    {
        try {
            $handle = trim($this->request->getGet('handle', ''));
            $pageId = (int)$this->request->getGet('page_id', 0);
            
            if (empty($handle)) {
                return $this->fetchJson([
                    'success' => false,
                    'available' => false,
                    'message' => __('页面句柄不能为空')
                ]);
            }
            
            // 检查 handle 格式（只允许小写字母、数字和连字符）
            if (!preg_match('/^[a-z0-9\-]+$/', $handle)) {
                return $this->fetchJson([
                    'success' => false,
                    'available' => false,
                    'message' => __('页面句柄只能包含小写字母、数字和连字符')
                ]);
            }
            
            // 查询数据库检查是否已存在
            $existingPage = clone $this->pageModel;
            $existingPage->clear()
                ->where(PageModel::fields_HANDLE, $handle)
                ->find()
                ->fetch();
            
            // 如果存在，检查是否是当前编辑的页面
            if ($existingPage->getId()) {
                if ($pageId > 0 && $existingPage->getId() == $pageId) {
                    // 是当前编辑的页面，可用
                    return $this->fetchJson([
                        'success' => true,
                        'available' => true,
                        'message' => __('当前页面的句柄')
                    ]);
                } else {
                    // 被其他页面占用
                    return $this->fetchJson([
                        'success' => true,
                        'available' => false,
                        'message' => __('页面句柄已被使用，请使用其他句柄'),
                        'existing_page' => [
                            'id' => $existingPage->getId(),
                            'name' => $existingPage->getData(PageModel::fields_NAME),
                            'title' => $existingPage->getData(PageModel::fields_TITLE)
                        ]
                    ]);
                }
            }
            
            // handle 可用
            return $this->fetchJson([
                'success' => true,
                'available' => true,
                'message' => __('页面句柄可用')
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 预览页面（支持指定语言）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_preview', '预览页面', 'mdi mdi-eye', '预览页面')]
    public function preview()
    {
        $pageId = $this->request->getGet('id');
        $locale = $this->request->getGet('locale', \Weline\Framework\Http\Cookie::getLang());
        
        if (!$pageId) {
            $this->getMessageManager()->addError(__('页面ID不能为空！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        // 加载页面
        $page = clone $this->pageModel;
        $page->clear()->load($pageId);
        
        if (!$page->getId()) {
            $this->getMessageManager()->addError(__('页面不存在！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        // 获取前端预览URL（带语言参数和preview标记）
        $handle = $page->getData(PageModel::fields_HANDLE);
        $frontendUrl = $this->request->getUrlBuilder()->getFrontendUrl(
            'pagebuilder/frontend/page/view',
            ['handle' => $handle, 'locale' => $locale, 'preview' => '1']
        );
        
        // 直接重定向到前端页面
        header('Location: ' . $frontendUrl);
        exit;
    }
    
    /**
     * 获取样式预览图片
     * 
     * @return void 直接输出图片内容
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::style_preview_image', '样式预览图', 'mdi mdi-image', '获取样式预览图片')]
    public function stylePreviewImage()
    {
        $styleCode = $this->request->getGet('code');
        
        if (!$styleCode) {
            header('HTTP/1.1 400 Bad Request');
            echo 'Missing style code parameter';
            exit;
        }
        
        try {
            // 查找样式
            $style = clone $this->styleModel;
            $style->clear()->where('code', $styleCode)->find()->fetch();
            
            if (!$style->getId()) {
                header('HTTP/1.1 404 Not Found');
                echo 'Style not found';
                exit;
            }
            
            // 获取预览图路径
            $previewImage = $style->getData(Style::fields_PREVIEW_IMAGE);
            
            if (!$previewImage) {
                header('HTTP/1.1 404 Not Found');
                echo 'Preview image not found for this style';
                exit;
            }
            
            // 构建完整的文件路径
            $baseDir = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/';
            $filePath = $baseDir . $previewImage;
            
            if (!file_exists($filePath) || !is_file($filePath)) {
                header('HTTP/1.1 404 Not Found');
                echo 'Preview image file does not exist: ' . $previewImage;
                exit;
            }
            
            // 检测文件MIME类型
            $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'webp' => 'image/webp',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
            ];
            
            $contentType = $mimeTypes[$fileExtension] ?? 'application/octet-stream';
            
            // 设置缓存头（7天）
            $lastModified = filemtime($filePath);
            $etag = md5_file($filePath);
            
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . filesize($filePath));
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            header('ETag: "' . $etag . '"');
            header('Cache-Control: public, max-age=604800'); // 7天
            
            // 检查客户端缓存
            $ifModifiedSince = $this->request->getHeader('If-Modified-Since');
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            
            if (($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified) ||
                ($ifNoneMatch && trim($ifNoneMatch, '"') === $etag)) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
            
            // 输出图片内容
            readfile($filePath);
            exit;
            
        } catch (\Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Error: ' . $e->getMessage();
            exit;
        }
    }
    
    /**
     * 自动创建或更新页面的 URL 重写规则
     * @param PageModel $page 页面模型
     * @return void
     */
    private function createOrUpdateUrlRewrite(PageModel $page): void
    {
        try {
            $handle = $page->getData(PageModel::fields_HANDLE);
            if (empty($handle)) {
                return;
            }
            
            // 原始路径
            $originalPath = "pagebuilder/frontend/page/view?handle={$handle}";
            
            // 重写路径（使用 handle 作为友好 URL）
            $rewritePath = "/{$handle}";
            
            // URL 指纹（用于唯一标识）
            $urlIdentify = "pagebuilder_page_{$handle}";
            
            // 查找是否已存在重写规则
            /**@var UrlRewrite $urlRewriteModel */
            $urlRewriteModel = ObjectManager::getInstance(UrlRewrite::class);
            $existingRewrite = $urlRewriteModel->clear()
                ->where(UrlRewrite::fields_URL_IDENTIFY, $urlIdentify)
                ->find()
                ->fetch();
            
            if ($existingRewrite && $existingRewrite->getId()) {
                // 更新现有规则
                $existingRewrite->setData(UrlRewrite::fields_PATH, $originalPath)
                    ->setData(UrlRewrite::fields_REWRITE, $rewritePath)
                    ->save();
            } else {
                // 创建新规则
                $newRewrite = clone $urlRewriteModel;
                $newRewrite->clearData()
                    ->setData(UrlRewrite::fields_URL_ID, "pagebuilder_page_{$page->getId()}")
                    ->setData(UrlRewrite::fields_URL_IDENTIFY, $urlIdentify)
                    ->setData(UrlRewrite::fields_PATH, $originalPath)
                    ->setData(UrlRewrite::fields_REWRITE, $rewritePath)
                    ->save(true);
            }
            
        } catch (\Exception $e) {
            // 静默失败，不影响页面保存
            if (DEV) {
                error_log("Failed to create URL rewrite for page: " . $e->getMessage());
            }
        }
    }
}

