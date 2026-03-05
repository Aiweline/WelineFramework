<?php

declare(strict_types=1);

/*
 * WeShop CMS Module
 * 页面管理后端控制器 - 完全参照PageBuilder结构
 */

namespace WeShop\Cms\Controller\Backend;

use WeShop\Cms\Model\Page as PageModel;
use WeShop\Cms\Model\Page\LocalDescription;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

#[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page', 'CMS页面管理', 'mdi mdi-file-document-edit', '管理和构建页面')]
class Page extends BackendController
{
    private PageModel $pageModel;
    private LocalDescription $localDescriptionModel;
    private I18n $i18nModel;
    private Locals $localsModel;

    public function __construct(
        PageModel $pageModel,
        LocalDescription $localDescriptionModel,
        I18n $i18nModel,
        Locals $localsModel
    ) {
        $this->pageModel = $pageModel->loadLocalDescription();
        $this->localDescriptionModel = $localDescriptionModel;
        $this->i18nModel = $i18nModel;
        $this->localsModel = $localsModel;
    }

    #[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page_index', '页面列表', 'mdi mdi-view-list', '查看页面列表')]
    public function index()
    {
        // 设置页面标题
        $this->assign('page_title', __('CMS页面管理'));
        $this->assign('breadcrumb_parent', __('内容管理'));
        $this->assign('breadcrumb_current', __('CMS页面管理'));
        
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
            ->order(PageModel::schema_fields_CREATE_TIME, 'DESC')
            ->pagination()
            ->select()
            ->fetch();
        
        $this->assign('pages', $pages->getItems());
        $this->assign('pagination', $pages->getPagination());
        
        return $this->fetch();
    }

    #[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page_create', '新建页面', 'mdi mdi-plus', '新建页面')]
    public function getCreate()
    {
        $this->assign('page_title', __('新建页面'));
        $this->assign('breadcrumb_parent', __('CMS页面管理'));
        $this->assign('breadcrumb_current', __('新建页面'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/create'));
        
        // 获取所有已激活的语言（从 i18n_locals 表读取，按 code 去重，使用当前语言的显示名称）
        $currentLang = State::getLang() ?: 'zh_Hans_CN';
        $allLocales = $this->localsModel->clear()
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        
        // 按 code 去重，并使用当前语言的显示名称
        $activeLocales = [];
        $seenCodes = [];
        foreach ($allLocales as $locale) {
            $code = $locale->getData('code');
            // 如果已经处理过这个 code，跳过
            if (isset($seenCodes[$code])) {
                continue;
            }
            // 优先使用 target_code 等于当前语言的记录
            if ($locale->getData('target_code') === $currentLang) {
                $seenCodes[$code] = true;
                $activeLocales[] = $locale;
            }
        }
        // 如果还有未处理的 code，使用其他 target_code 的记录
        foreach ($allLocales as $locale) {
            $code = $locale->getData('code');
            if (!isset($seenCodes[$code])) {
                $seenCodes[$code] = true;
                // 使用 getLocaleName 方法获取当前语言的显示名称
                $localeName = $this->i18nModel->getLocaleName($code, $currentLang);
                $locale->setData('name', $localeName);
                $activeLocales[] = $locale;
            }
        }
        
        $this->assign('active_locales', $activeLocales);
        
        // 新建页面时，selected_locales 为空数组
        $this->assign('selected_locales', []);
        
        // 获取所有页面类型
        $this->assign('page_types', PageModel::getPageTypes());
        
        // 样式列表将通过AJAX加载，这里传空数组（暂时不实现样式系统）
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
        
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page_create_post', '新建页面请求', '', '新建页面请求')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();
            if (empty($data)) {
                throw new \Exception(__('提交的数据为空！'));
            }
            
            // 验证必填字段
            if (empty($data['handle'])) {
                throw new \Exception(__('页面句柄不能为空！'));
            }
            if (empty($data['type'])) {
                throw new \Exception(__('页面类型不能为空！'));
            }
            if (empty($data['name'])) {
                throw new \Exception(__('页面名称不能为空！'));
            }
            if (empty($data['title'])) {
                throw new \Exception(__('页面标题不能为空！'));
            }
            
            // 处理选中的语言列表
            $selectedLocales = $this->request->getPost('locales', []);
            if (!is_array($selectedLocales)) {
                $selectedLocales = [];
            }
            $defaultLocale = $this->request->getPost('default_locale', '');
            
            // 处理样式配置信息 - 保存当前style的配置，不影响其他style的配置
            $currentStyleSettings = $this->request->getPost('style_settings', []);
            if (!is_array($currentStyleSettings)) {
                $currentStyleSettings = [];
            }
            $currentStyleCode = $data['style'] ?? '';
            
            // 构建完整的配置存储结构：每个style独立存储其配置
            $allStyleSettings = [];
            if (!empty($currentStyleCode) && !empty($currentStyleSettings)) {
                $allStyleSettings[$currentStyleCode] = $currentStyleSettings;
            }
            
            // 安全地编码 JSON，确保不会失败
            $styleSettingJson = json_encode($allStyleSettings, JSON_UNESCAPED_UNICODE);
            if ($styleSettingJson === false) {
                $styleSettingJson = '{}';
            }
            
            $localesJson = json_encode($selectedLocales, JSON_UNESCAPED_UNICODE);
            if ($localesJson === false) {
                $localesJson = '[]';
            }
            
            // 创建页面
            $page = clone $this->pageModel;
            $page->clearData()
                ->setData(PageModel::schema_fields_HANDLE, $data['handle'])
                ->setData(PageModel::schema_fields_TYPE, $data['type'])
                ->setData(PageModel::schema_fields_NAME, $data['name'])
                ->setData(PageModel::schema_fields_TITLE, $data['title'])
                ->setData(PageModel::schema_fields_CONTENT, $data['content'] ?? '')
                ->setData(PageModel::schema_fields_PARENT_ID, $data['parent_id'] ?? 0)
                ->setData(PageModel::schema_fields_STYLE, $data['style'] ?? '')
                ->setData(PageModel::schema_fields_STYLE_SETTING, $styleSettingJson)
                ->setData(PageModel::schema_fields_GA4_ID, $data['ga4_id'] ?? '')
                ->setData(PageModel::schema_fields_GTM_ID, $data['gtm_id'] ?? '')
                ->setData(PageModel::schema_fields_FB_PIXEL_ID, $data['fb_pixel_id'] ?? '')
                ->setData(PageModel::schema_fields_LOGO, $data['logo'] ?? '')
                ->setData(PageModel::schema_fields_ICON, $data['icon'] ?? '')
                ->setData(PageModel::schema_fields_LOCALES, $localesJson)
                ->setData(PageModel::schema_fields_DEFAULT_LOCALE, $defaultLocale)
                ->setData(PageModel::schema_fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(PageModel::schema_fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(PageModel::schema_fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->setData(PageModel::schema_fields_REDIRECT_URL, $data['redirect_url'] ?? '')
                ->setData(PageModel::schema_fields_STATUS, $data['status'] ?? PageModel::STATUS_DRAFT)
                ->save(true);
            
            $this->getMessageManager()->addSuccess(__('页面创建成功！'));
            $this->redirect('*/backend/page/edit', ['id' => $page->getId()]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addError(__('页面创建失败：%1', $exception->getMessage()));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
                w_log_error('WeShop CMS postCreate Error: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());
            }
            $this->redirect('*/backend/page/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page_edit', '编辑页面', 'mdi mdi-pencil', '编辑页面')]
    public function getEdit()
    {
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
        $this->assign('breadcrumb_parent', __('CMS页面管理'));
        $this->assign('breadcrumb_current', __('编辑页面'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/edit', ['id' => $pageId]));
        $this->assign('page', $page);
        
        // 获取所有已激活的语言（从 i18n_locals 表读取，按 code 去重，使用当前语言的显示名称）
        $currentLang = State::getLang() ?: 'zh_Hans_CN';
        $allLocales = $this->localsModel->clear()
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        
        // 按 code 去重，并使用当前语言的显示名称
        $activeLocales = [];
        $seenCodes = [];
        foreach ($allLocales as $locale) {
            $code = $locale->getData('code');
            // 如果已经处理过这个 code，跳过
            if (isset($seenCodes[$code])) {
                continue;
            }
            // 优先使用 target_code 等于当前语言的记录
            if ($locale->getData('target_code') === $currentLang) {
                $seenCodes[$code] = true;
                $activeLocales[] = $locale;
            }
        }
        // 如果还有未处理的 code，使用其他 target_code 的记录
        foreach ($allLocales as $locale) {
            $code = $locale->getData('code');
            if (!isset($seenCodes[$code])) {
                $seenCodes[$code] = true;
                // 使用 getLocaleName 方法获取当前语言的显示名称
                $localeName = $this->i18nModel->getLocaleName($code, $currentLang);
                $locale->setData('name', $localeName);
                $activeLocales[] = $locale;
            }
        }
        
        $this->assign('active_locales', $activeLocales);
        
        // 获取选中的语言
        $selectedLocales = $page->getSelectedLocales();
        $this->assign('selected_locales', $selectedLocales);
        
        // 获取所有页面类型
        $this->assign('page_types', PageModel::getPageTypes());
        
        // 获取父页面列表（排除自己）
        $parentPages = clone $this->pageModel;
        $parentPages = $parentPages->clear()
            ->where(PageModel::schema_fields_ID, $pageId, '!=')
            ->select()
            ->fetch()
            ->getItems();
        $this->assign('parent_pages', $parentPages);
        
        // 获取已翻译的语言数据
        $localDescriptions = clone $this->localDescriptionModel;
        $translations = $localDescriptions->clear()
            ->where(LocalDescription::schema_fields_ID, $pageId)
            ->select()
            ->fetch()
            ->getItems();
        
        $translationsData = [];
        foreach ($translations as $translation) {
            $translationsData[$translation->getData('local_code')] = $translation;
        }
        $this->assign('translations', $translationsData);
        
        // 未翻译语言提示已移除（表单内语言 Tab 已有未翻译标记）
        
        // 样式列表将通过AJAX加载，这里传空数组（暂时不实现样式系统）
        $this->assign('styles', []);
        
        // 获取当前页面的样式配置
        $currentStyle = $page->getData(PageModel::schema_fields_STYLE);
        $allStyleSettings = $page->getStyleSetting();
        
        // 获取当前样式的配置值（从所有样式配置中提取）
        $currentStyleSettings = [];
        if ($currentStyle && isset($allStyleSettings[$currentStyle])) {
            $currentStyleSettings = $allStyleSettings[$currentStyle];
        }
        
        // 样式配置（暂时不实现样式系统）
        $this->assign('style_configs', []);
        $this->assign('style_settings', $currentStyleSettings);
        
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page_edit_post', '编辑页面请求', '', '编辑页面请求')]
    public function postEdit()
    {
        try {
            $pageId = $this->request->getGet('id');
            if (!$pageId) {
                throw new \Exception(__('页面ID不能为空！'));
            }
            
            $data = $this->request->getPost();
            if (empty($data)) {
                throw new \Exception(__('提交的数据为空！'));
            }
            
            // 验证必填字段
            if (empty($data['handle'])) {
                throw new \Exception(__('页面句柄不能为空！'));
            }
            if (empty($data['type'])) {
                throw new \Exception(__('页面类型不能为空！'));
            }
            if (empty($data['name'])) {
                throw new \Exception(__('页面名称不能为空！'));
            }
            if (empty($data['title'])) {
                throw new \Exception(__('页面标题不能为空！'));
            }
            
            $page = clone $this->pageModel;
            $page->clear()->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception(__('页面不存在！'));
            }
            
            // 处理选中的语言列表
            $selectedLocales = $this->request->getPost('locales', []);
            if (!is_array($selectedLocales)) {
                $selectedLocales = [];
            }
            $defaultLocale = $this->request->getPost('default_locale', '');
            
            // 处理样式配置信息 - 合并保存，保留其他style的配置
            $currentStyleSettings = $this->request->getPost('style_settings', []);
            if (!is_array($currentStyleSettings)) {
                $currentStyleSettings = [];
            }
            $currentStyleCode = $data['style'] ?? '';
            
            // 获取页面原有的所有样式配置
            $existingSettings = $page->getStyleSetting();
            if (!is_array($existingSettings)) {
                $existingSettings = [];
            }
            
            // 更新当前style的配置，保留其他style的配置
            if (!empty($currentStyleCode)) {
                $existingSettings[$currentStyleCode] = $currentStyleSettings;
            }
            
            // 安全地编码 JSON，确保不会失败
            $styleSettingJson = json_encode($existingSettings, JSON_UNESCAPED_UNICODE);
            if ($styleSettingJson === false) {
                $styleSettingJson = '{}';
            }
            
            $localesJson = json_encode($selectedLocales, JSON_UNESCAPED_UNICODE);
            if ($localesJson === false) {
                $localesJson = '[]';
            }
            
            // 更新页面 - 确保主键字段存在以便正确更新
            $page->setData(PageModel::schema_fields_ID, $pageId)
                ->setData(PageModel::schema_fields_HANDLE, $data['handle'])
                ->setData(PageModel::schema_fields_TYPE, $data['type'])
                ->setData(PageModel::schema_fields_NAME, $data['name'])
                ->setData(PageModel::schema_fields_TITLE, $data['title'])
                ->setData(PageModel::schema_fields_CONTENT, $data['content'] ?? '')
                ->setData(PageModel::schema_fields_PARENT_ID, $data['parent_id'] ?? 0)
                ->setData(PageModel::schema_fields_STYLE, $data['style'] ?? '')
                ->setData(PageModel::schema_fields_STYLE_SETTING, $styleSettingJson)
                ->setData(PageModel::schema_fields_GA4_ID, $data['ga4_id'] ?? '')
                ->setData(PageModel::schema_fields_GTM_ID, $data['gtm_id'] ?? '')
                ->setData(PageModel::schema_fields_FB_PIXEL_ID, $data['fb_pixel_id'] ?? '')
                ->setData(PageModel::schema_fields_LOGO, $data['logo'] ?? '')
                ->setData(PageModel::schema_fields_ICON, $data['icon'] ?? '')
                ->setData(PageModel::schema_fields_LOCALES, $localesJson)
                ->setData(PageModel::schema_fields_DEFAULT_LOCALE, $defaultLocale)
                ->setData(PageModel::schema_fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(PageModel::schema_fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(PageModel::schema_fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->setData(PageModel::schema_fields_REDIRECT_URL, $data['redirect_url'] ?? '')
                ->setData(PageModel::schema_fields_STATUS, $data['status'] ?? PageModel::STATUS_DRAFT)
                ->save();
            
            // 处理多语言内容翻译
            if (!empty($selectedLocales)) {
                foreach ($selectedLocales as $locale) {
                    // 跳过默认语言（默认语言内容已经保存在主表中）
                    if ($locale == $defaultLocale) {
                        continue;
                    }
                    
                    // 获取该语言的内容，如果没有则使用默认语言的内容
                    $contentKey = 'content_' . $locale;
                    $translatedContent = $data[$contentKey] ?? $data['content'] ?? '';
                    
                    // 查找或创建翻译记录
                    $localDesc = clone $this->localDescriptionModel;
                    $existing = $localDesc->clear()
                        ->where(LocalDescription::schema_fields_ID, $pageId)
                        ->where('local_code', $locale)
                        ->find()
                        ->fetch();
                    
                    if ($existing && $existing->getId()) {
                        // 更新现有翻译
                        $existing->setData(LocalDescription::schema_fields_NAME, $data['name'] ?? '')
                            ->setData(LocalDescription::schema_fields_TITLE, $data['title'] ?? '')
                            ->setData(LocalDescription::schema_fields_CONTENT, $translatedContent)
                            ->setData(LocalDescription::schema_fields_META_TITLE, $data['meta_title'] ?? '')
                            ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                            ->setData(LocalDescription::schema_fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                            ->save();
                    } else {
                        // 创建新翻译，使用默认语言的值作为基础
                        $newTranslation = clone $this->localDescriptionModel;
                        $newTranslation->clearData()
                            ->setData(LocalDescription::schema_fields_ID, $pageId)
                            ->setData('local_code', $locale)
                            ->setData(LocalDescription::schema_fields_NAME, $data['name'] ?? '')
                            ->setData(LocalDescription::schema_fields_TITLE, $data['title'] ?? '')
                            ->setData(LocalDescription::schema_fields_CONTENT, $translatedContent)
                            ->setData(LocalDescription::schema_fields_META_TITLE, $data['meta_title'] ?? '')
                            ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                            ->setData(LocalDescription::schema_fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                            ->save();
                    }
                }
            }
            
            $this->getMessageManager()->addSuccess(__('页面更新成功！'));
            $this->redirect('*/backend/page/edit', ['id' => $pageId]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addError(__('页面更新失败：%1', $exception->getMessage()));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
                w_log_error('WeShop CMS postEdit Error: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());
            }
            $pageId = $this->request->getGet('id');
            if ($pageId) {
                $this->redirect('*/backend/page/edit', ['id' => $pageId]);
            } else {
                $this->redirect('*/backend/page/index');
            }
        }
    }

    #[\Weline\Framework\Acl\Acl('WeShop_Cms::cms_page_delete', '删除页面', 'mdi mdi-delete', '删除页面')]
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
                ->where(LocalDescription::schema_fields_ID, $pageId)
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
}
