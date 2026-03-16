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
use GuoLaiRen\PageBuilder\Model\WebsiteUser;
use GuoLaiRen\PageBuilder\Service\LayoutService;
use GuoLaiRen\PageBuilder\Service\LayoutAssembler;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\Websites\Model\Website as WebsiteModel;
use Weline\Acl\Model\RoleAccess;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder', '页面构建器', 'mdi mdi-file-document-edit', '管理和构建页面')]
class Page extends BackendController
{
    private PageModel $pageModel;
    private LocalDescription $localDescriptionModel;
    private I18n $i18nModel;
    private Locals $localsModel;
    private Style $styleModel;
    private WebsiteUser $websiteUserModel;
    private WebsiteModel $websiteModel;
    
    /**
     * 缓存的用户权限列表
     */
    private static ?array $userPermissionsCache = null;

    public function __construct(
        PageModel $pageModel,
        LocalDescription $localDescriptionModel,
        I18n $i18nModel,
        Locals $localsModel,
        Style $styleModel,
        WebsiteUser $websiteUserModel,
        WebsiteModel $websiteModel
    ) {
        $this->pageModel = $pageModel->loadLocalDescription();
        $this->localDescriptionModel = $localDescriptionModel;
        $this->i18nModel = $i18nModel;
        $this->localsModel = $localsModel;
        $this->styleModel = $styleModel;
        $this->websiteUserModel = $websiteUserModel;
        $this->websiteModel = $websiteModel;
    }
    
    /**
     * 检查用户是否拥有指定权限
     * 
     * @param int $userId 用户ID
     * @param string $sourceId 权限资源ID
     * @return bool
     */
    private function hasPermission(int $userId, string $sourceId): bool
    {
        // 超管拥有所有权限
        if ($userId === 1) {
            return true;
        }
        
        if ($userId <= 0) {
            return false;
        }
        
        // 获取用户角色
        $user = $this->getLoginUser();
        if (!$user) {
            return false;
        }
        
        $role = $user->getRoleModel();
        if (!$role || !$role->getId()) {
            return false;
        }
        
        // 超管角色
        if ($role->getId() === 1) {
            return true;
        }
        
        // 使用缓存的权限列表
        $cacheKey = 'user_permissions_' . $userId;
        if (self::$userPermissionsCache === null) {
            $cache = w_cache('acl');
            $permissions = $cache->get($cacheKey);
            
            if (!$permissions) {
                /** @var RoleAccess $roleAccess */
                $roleAccess = ObjectManager::getInstance(RoleAccess::class);
                $accesses = $roleAccess->getRoleAccessListArray($role);
                $permissions = [];
                foreach ($accesses as $access) {
                    $permissions[] = $access['source_id'];
                }
                $cache->set($cacheKey, $permissions);
            }
            self::$userPermissionsCache = $permissions;
        }
        
        return in_array($sourceId, self::$userPermissionsCache);
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
        
        // 根据当前后台用户可访问的站点过滤页面（超管和拥有站点分配权限的用户可查看所有站点）
        $userId = (int)$this->getLoginUserId();
        $isSuperAdmin = ($userId === 1); // 超管ID为1
        $hasWebsiteAssignmentPermission = false;
        if ($userId > 0 && !$isSuperAdmin) {
            $hasWebsiteAssignmentPermission = $this->hasPermission($userId, 'GuoLaiRen_PageBuilder::website_assignment');
        }
        
        if ($isSuperAdmin || $hasWebsiteAssignmentPermission) {
            // 超管和拥有站点分配权限的用户可以查看所有站点，不添加过滤条件
            // 不添加任何 where 条件，也不需要检查站点分配
        } else {
            // 普通用户：需要检查站点分配情况
            $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
            if (!empty($accessibleWebsiteIds)) {
                // 只能查看被分配的站点
                $listModel->where(PageModel::schema_fields_WEBSITE_ID, $accessibleWebsiteIds, 'in');
            } else {
                // 如果没有可访问的站点，则不返回任何页面
                $listModel->where(PageModel::schema_fields_WEBSITE_ID, -1);
            }
        }
        
        // 搜索功能
        $search = $this->request->getGet('search');
        if ($search) {
            $listModel->where('name', "%$search%", 'like')
                ->where('title', "%$search%", 'like', 'or')
                ->where('handle', "%$search%", 'like', 'or');
        }
        
        // 只查询父页面（parent_id = 0）进行翻页
        // 注意：新创建的页面默认 parent_id = 0，不会是 null
        $listModel->where(PageModel::schema_fields_PARENT_ID, 0);
        
        // 获取父页面列表数据（按创建时间倒序排列）
        $parentPagesResult = $listModel
            ->order(PageModel::schema_fields_CREATE_TIME, 'DESC')
            ->pagination()
            ->select()
            ->fetch();
        
        $parentPages = $parentPagesResult->getItems();
        $parentPageIds = [];
        foreach ($parentPages as $page) {
            $parentPageIds[] = $page->getId();
        }
        
        // 单独查询所有子页面（不计入翻页）
        $childPages = [];
        if (!empty($parentPageIds)) {
            $childModel = clone $this->pageModel;
            $childModel->clear();
            
            // 应用相同的站点权限过滤
            if (!$isSuperAdmin && !$hasWebsiteAssignmentPermission) {
                $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
                if (!empty($accessibleWebsiteIds)) {
                    $childModel->where(PageModel::schema_fields_WEBSITE_ID, $accessibleWebsiteIds, 'in');
                } else {
                    $childModel->where(PageModel::schema_fields_WEBSITE_ID, -1);
                }
            }
            
            // 搜索子页面时，也匹配搜索条件
            if ($search) {
                $childModel->where('name', "%$search%", 'like')
                    ->where('title', "%$search%", 'like', 'or')
                    ->where('handle', "%$search%", 'like', 'or');
            }
            
            $childPagesResult = $childModel
                ->where(PageModel::schema_fields_PARENT_ID, $parentPageIds, 'in')
                ->order(PageModel::schema_fields_CREATE_TIME, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 按父页面ID分组
            foreach ($childPagesResult as $child) {
                $pid = $child->getData(PageModel::schema_fields_PARENT_ID);
                if (!isset($childPages[$pid])) {
                    $childPages[$pid] = [];
                }
                $childPages[$pid][] = $child;
            }
        }
        
        $this->assign('parent_pages', $parentPages);
        $this->assign('child_pages', $childPages);
        $this->assign('pagination', $parentPagesResult->getPagination());
        
        // 获取可用站点列表（用于一键建站功能）
        $websitesList = $this->getAccessibleWebsites();
        $this->assign('websites', $websitesList);
        
        return $this->fetch();
    }
    
    /**
     * 获取当前用户可访问的站点列表
     */
    private function getAccessibleWebsites(): array
    {
        $userId = (int)$this->getLoginUserId();
        $isSuperAdmin = ($userId === 1);
        $hasWebsiteAssignmentPermission = false;
        if ($userId > 0 && !$isSuperAdmin) {
            $hasWebsiteAssignmentPermission = $this->hasPermission($userId, 'GuoLaiRen_PageBuilder::website_assignment');
        }
        
        $websites = [];
        if ($isSuperAdmin || $hasWebsiteAssignmentPermission) {
            // 超管可以访问所有站点
            $websiteModel = clone $this->websiteModel;
            $allWebsites = $websiteModel->clear()
                ->order(WebsiteModel::schema_fields_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($allWebsites as $website) {
                $websites[] = [
                    'website_id' => $website->getId(),
                    'name' => $website->getData(WebsiteModel::schema_fields_NAME),
                    'code' => $website->getData(WebsiteModel::schema_fields_CODE),
                ];
            }
        } else {
            // 普通用户只能访问分配的站点
            $accessibleIds = $this->getAccessibleWebsiteIds();
            if (!empty($accessibleIds)) {
                $websiteModel = clone $this->websiteModel;
                $accessibleWebsites = $websiteModel->clear()
                    ->where(WebsiteModel::schema_fields_ID, $accessibleIds, 'in')
                    ->order(WebsiteModel::schema_fields_NAME, 'ASC')
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($accessibleWebsites as $website) {
                    $websites[] = [
                        'website_id' => $website->getId(),
                        'name' => $website->getData(WebsiteModel::schema_fields_NAME),
                        'code' => $website->getData(WebsiteModel::schema_fields_CODE),
                    ];
                }
            }
        }
        return $websites;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_create', '新建页面', 'mdi mdi-plus', '新建页面', 'GuoLaiRen_PageBuilder::page_builder')]
    public function getCreate()
    {
        // 强制扫描样式模板，确保配置是最新的
        Style::forceScan();
        
        $this->assign('page_title', __('新建页面'));
        $this->assign('breadcrumb_parent', __('页面构建器'));
        $this->assign('breadcrumb_current', __('新建页面'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/create'));
        
        // 获取所有已激活的语言（从 i18n_locals 表读取，按 code 去重，使用当前语言的显示名称）
        $currentLang = State::getLang() ?: 'zh_Hans_CN';
        $localsQuery = clone $this->localsModel;
        $allLocales = $localsQuery->clear()
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
        
        // 站点选择：根据当前后台用户可访问的站点列表
        $availableWebsites = $this->getAvailableWebsitesForCurrentUser();
        $this->assign('websites', $availableWebsites);
        
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
        
        // 如果指定了父页面，获取父页面的配置用于继承；子页面主题继承自主页（根页面），不可切换
        $parentPageData = null;
        $inheritedStyleCode = '';
        $inheritedStyleName = '';
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
                $rootPage = $parentPageModel->getRootPage();
                if ($rootPage && $rootPage->getId()) {
                    $inheritedStyleCode = (string)($rootPage->getData(PageModel::schema_fields_STYLE) ?? '');
                    if ($inheritedStyleCode !== '') {
                        $styleModel = clone $this->styleModel;
                        $styleModel->clear()->where(Style::schema_fields_CODE, $inheritedStyleCode)->find()->fetch();
                        $inheritedStyleName = $styleModel->getId() ? (string)($styleModel->getData(Style::schema_fields_NAME) ?? $inheritedStyleCode) : $inheritedStyleCode;
                    }
                }
            }
        }
        $this->assign('parent_page_data', $parentPageData);
        $this->assign('inherited_style_code', $inheritedStyleCode);
        $this->assign('inherited_style_name', $inheritedStyleName);
        
        // 读取AI功能配置
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $aiEnabled = $systemConfig->getConfig('ai_enabled', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $aiEnabled = $aiEnabled === null ? '0' : $aiEnabled; // 默认不开启
        $this->assign('ai_enabled', $aiEnabled);

        // 读取多语言功能配置
        $i18nEnabled = $systemConfig->getConfig('i18n_enabled', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $i18nEnabled = $i18nEnabled === null ? '0' : $i18nEnabled; // 默认不开启
        $this->assign('i18n_enabled', $i18nEnabled);

        // 如果多语言功能关闭，清空active_locales和selected_locales
        if ($i18nEnabled !== '1') {
            $this->assign('active_locales', []);
            $this->assign('selected_locales', []);
        }
        
        // 获取可用的布局页面列表（新建页面时为空，因为还没有website_id）
        $this->assign('layout_pages', []);
        
        return $this->fetch('form');
    }

    /**
     * 构建单个页面的 Google SEO WebPage 结构化数据
     */
    private function buildStructuredDataForPage(PageModel $page): array
    {
        // 优先使用页面关联站点的 URL
        $baseHost = '';
        $pageWebsiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
        if ($pageWebsiteId > 0) {
            try {
                $websiteModel = clone $this->websiteModel;
                $websiteModel->clear()->load($pageWebsiteId);
                if ($websiteModel->getId()) {
                    $baseHost = $websiteModel->getData(\Weline\Websites\Model\Website::schema_fields_URL) ?? '';
                }
            } catch (\Exception $e) {
                // 忽略错误，使用默认值
            }
        }
        // 如果没有站点 URL，使用当前请求的展示用 base（根域名+端口）
        if (empty($baseHost)) {
            $baseHost = $this->request ? $this->request->getDisplayBaseUrl() : '';
        }
        
        $handle = (string)$page->getData(PageModel::schema_fields_HANDLE);
        $pageUrl = '';
        if ($baseHost !== '') {
            $pageUrl = $baseHost . '/' . ltrim($handle, '/');
        }
        
        $locale = Cookie::getLang();
        $title = (string)($page->getData(PageModel::schema_fields_META_TITLE) ?: $page->getData(PageModel::schema_fields_TITLE));
        $description = (string)$page->getData(PageModel::schema_fields_META_DESCRIPTION);
        
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'url' => $pageUrl,
            'inLanguage' => $locale,
            'description' => $description,
        ];
        
        $createTime = (string)($page->getData(PageModel::schema_fields_CREATE_TIME) ?? '');
        $updateTime = (string)($page->getData(PageModel::schema_fields_UPDATE_TIME) ?? '');
        if ($createTime !== '') {
            $data['datePublished'] = $createTime;
        }
        if ($updateTime !== '') {
            $data['dateModified'] = $updateTime;
        }
        
        return $data;
    }

    /**
     * 获取当前后台用户可访问的站点ID列表
     * - 超管（ID为1）和拥有站点分配权限（GuoLaiRen_PageBuilder::website_assignment）的用户：返回空数组，表示不做限制
     * - 普通用户：返回其被分配到的站点ID列表
     */
    private function getAccessibleWebsiteIds(): array
    {
        try {
            $userId = (int)$this->getLoginUserId();
            if ($userId <= 0) {
                return [];
            }

            // 超管（ID为1）可以查看所有站点
            if ($userId === 1) {
                return [];
            }

            // 如果拥有站点分配权限，则不限制站点（查看所有站点）
            if ($this->hasPermission($userId, 'GuoLaiRen_PageBuilder::website_assignment')) {
                return [];
            }

            // 普通用户：查找其被分配到的站点
            $mapping = clone $this->websiteUserModel;
            $items = $mapping->clear()
                ->where(WebsiteUser::schema_fields_BACKEND_USER_ID, $userId)
                ->select()
                ->fetch()
                ->getItems();

            $ids = [];
            foreach ($items as $item) {
                $ids[] = (int)$item->getData(WebsiteUser::schema_fields_WEBSITE_ID);
            }
            return array_values(array_unique(array_filter($ids)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取当前用户可用的站点列表（用于新建页面时的站点选择）
     */
    private function getAvailableWebsitesForCurrentUser(): array
    {
        $accessibleIds = $this->getAccessibleWebsiteIds();

        $websiteModel = clone $this->websiteModel;
        $query = $websiteModel->clearQuery();

        if (!empty($accessibleIds)) {
            $query->where(WebsiteModel::schema_fields_ID, $accessibleIds, 'in');
        }

        return $query->select()->fetchArray();
    }

    /**
     * 获取当前用户可用的站点列表（编辑时保留当前页面站点）
     */
    private function getAvailableWebsitesForUserWithCurrentSelection(int $currentWebsiteId): array
    {
        $websites = $this->getAvailableWebsitesForCurrentUser();
        $ids = array_column($websites, 'website_id');

        // 如果当前页面的站点不在可用列表中（例如超管或站点分配刚调整），尝试补充进列表
        if ($currentWebsiteId > 0 && !in_array($currentWebsiteId, $ids, true)) {
            $extra = clone $this->websiteModel;
            $extra->clear()->load($currentWebsiteId);
            if ($extra->getWebsiteId()) {
                $websites[] = $extra->getData();
            }
        }

        return $websites;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_create_post', '新建页面请求', '', '新建页面请求', 'GuoLaiRen_PageBuilder::page_builder')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();
            
            // 处理选中的语言列表
            $selectedLocales = $this->request->getPost('locales', []);
            $defaultLocale = $this->request->getPost('default_locale', '');
            
            // 如果没有设置默认语言，使用框架当前语言
            if (empty($defaultLocale)) {
                $defaultLocale = State::getLang();
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
            
            // 检查：子页面不能分配给子页面
            // 如果parent_id指向的页面本身也是子页面，需要调整
            if ($parentId > 0) {
                $parentPageModel = clone $this->pageModel;
                $parentPageModel->clear()->load($parentId);
                if ($parentPageModel->getId()) {
                    $parentPageParentId = $parentPageModel->getData(PageModel::schema_fields_PARENT_ID);
                    
                    // 如果父页面本身也是子页面，需要调整
                    if (!empty($parentPageParentId) && $parentPageParentId > 0) {
                        // 将parent_id调整为父页面的父级页面（即顶级页面）
                        $parentId = (int)$parentPageParentId;
                        MessageManager::warning(
                            __('子页面不能分配给子页面，已自动调整为顶级页面：%{1}', $parentId)
                        );
                        // 重新加载父页面模型（因为parentId已经改变）
                        $parentPageModel->clear()->load($parentId);
                    }
                    
                    // 强制使用父页面的语言设置
                    $selectedLocales = json_decode(($parentPageModel->getData(PageModel::schema_fields_LOCALES) ?? '') ?? '', true) ?: [];
                    $defaultLocale = $parentPageModel->getData(PageModel::schema_fields_DEFAULT_LOCALE);
                    
                    // 确保默认语言在列表中
                    if ($defaultLocale && !in_array($defaultLocale, $selectedLocales)) {
                        $selectedLocales[] = $defaultLocale;
                    }
                    
                    // 显示继承信息
                    MessageManager::success(__('子页面已继承父页面配置'));
                    if (DEV) {
                        MessageManager::success(__('父页面ID：%{1}', $parentId));
                        MessageManager::success(__('继承样式：%{1}', $data['style'] ?? '无'));
                        MessageManager::success(__('继承Logo：%{1}', $data['logo'] ?? '无'));
                        MessageManager::success(__('继承默认语言：%{1}', $defaultLocale));
                    }
                }
            }
            
            // 创建页面
            $page = clone $this->pageModel;
            
            // 处理 handle：仅首页允许为空；子页面为空时用页面类型作为 handle（如 about）
            $pageHandle = trim((string)($data['handle'] ?? ''));
            $pageType = $data['type'] ?? '';
            if (empty($pageHandle)) {
                if ($pageType === PageModel::TYPE_HOME) {
                    $pageHandle = '';
                } else {
                    $pageHandle = PageModel::getDefaultHandleForType($pageType);
                }
            }
            
            // CTA 事件名称：如果为空则自动生成为 cta_{handle}_click
            $ctaEventName = $data['cta_event_name'] ?? '';
            if (empty($ctaEventName) && !empty($pageHandle)) {
                $ctaEventName = 'cta_' . $pageHandle . '_click';
            }
            
            $page->clearData()
                ->setData(PageModel::schema_fields_HANDLE, $pageHandle)
                ->setData(PageModel::schema_fields_TYPE, $data['type'])
                ->setData(PageModel::schema_fields_NAME, $data['name'])
                ->setData(PageModel::schema_fields_TITLE, $data['title'])
                ->setData(PageModel::schema_fields_CONTENT, $data['content'] ?? '')
                ->setData(PageModel::schema_fields_PARENT_ID, $parentId)
                ->setData(PageModel::schema_fields_STYLE, $data['style'] ?? '')
                ->setData(PageModel::schema_fields_STYLE_SETTING, json_encode($allStyleSettings))
                ->setData(PageModel::schema_fields_GA4_ID, $data['ga4_id'] ?? '')
                ->setData(PageModel::schema_fields_GTM_ID, $data['gtm_id'] ?? '')
                ->setData(PageModel::schema_fields_FB_PIXEL_ID, $data['fb_pixel_id'] ?? '')
                ->setData(PageModel::schema_fields_CTA_EVENT_NAME, $ctaEventName)
                ->setData(PageModel::schema_fields_LOGO, $data['logo'] ?? '')
                ->setData(PageModel::schema_fields_ICON, $data['icon'] ?? '')
                ->setData(PageModel::schema_fields_LOCALES, json_encode($selectedLocales))
                ->setData(PageModel::schema_fields_DEFAULT_LOCALE, $defaultLocale)
                ->setData(PageModel::schema_fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(PageModel::schema_fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(PageModel::schema_fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->setData(PageModel::schema_fields_REDIRECT_URL, $data['redirect_url'] ?? '')
                ->setData(PageModel::schema_fields_HEADER_CUSTOM_CODE, $data['header_custom_code'] ?? '')
                ->setData(PageModel::schema_fields_FOOTER_CUSTOM_CODE, $data['footer_custom_code'] ?? '')
                ->setData(PageModel::schema_fields_LAYOUT_PAGE_ID, !empty($data['layout_page_id']) ? (int)$data['layout_page_id'] : null)
                ->setData(PageModel::schema_fields_STATUS, $data['status'] ?? PageModel::STATUS_DRAFT);
            
            // 关联站点：仅允许设置为当前用户可访问的站点
            $websiteId = (int)($data['website_id'] ?? 0);
            $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
            if ($websiteId > 0 && (!empty($accessibleWebsiteIds) && in_array($websiteId, $accessibleWebsiteIds, true))) {
                $page->setData(PageModel::schema_fields_WEBSITE_ID, $websiteId);
            } elseif ($websiteId > 0 && empty($accessibleWebsiteIds)) {
                // 超管或未限制站点时，允许自由设置
                $page->setData(PageModel::schema_fields_WEBSITE_ID, $websiteId);
            }
            
            $page->save(true);

            // 创建时实时更新页面的重写路由（含首页空重写）
            $this->createOrUpdateUrlRewrite($page);

            MessageManager::success(__('页面创建成功！'));
            $this->redirect('*/backend/page/edit', ['id' => $page->getId()]);
        } catch (\Exception $exception) {
            MessageManager::warning(__('页面创建失败！'));
            if (DEV) {
                MessageManager::exception($exception);
            }
            $this->redirect('*/backend/page/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_edit', '编辑页面', 'mdi mdi-pencil', '编辑页面', 'GuoLaiRen_PageBuilder::page_builder')]
    public function getEdit()
    {
        // 强制扫描样式模板，确保配置是最新的
        Style::forceScan();
        
        $pageId = $this->request->getGet('id');
        if (!$pageId) {
            MessageManager::error(__('页面ID不能为空！'));
            $this->redirect('*/backend/page/index');
            return;
        }

        $page = clone $this->pageModel;
        $page->clear()->loadLocalDescription()->load($pageId);
        
        if (!$page->getId()) {
            MessageManager::error(__('页面不存在！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        // 权限校验：仅允许访问有权限管理对应站点的页面（除非拥有站点分配权限）
        $pageWebsiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
        $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
        if (!empty($accessibleWebsiteIds) && $pageWebsiteId > 0 && !in_array($pageWebsiteId, $accessibleWebsiteIds, true)) {
            MessageManager::error(__('您没有权限编辑该页面所属的站点。'));
            $this->redirect('*/backend/page/index');
            return;
        }

        $this->assign('page_title', __('编辑页面'));
        $this->assign('breadcrumb_parent', __('页面构建器'));
        $this->assign('breadcrumb_current', __('编辑页面'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/page/edit', ['id' => $pageId]));
        $this->assign('page', $page);
        
        // 获取所有已激活的语言（从 i18n_locals 表读取，按 code 去重，使用当前语言的显示名称）
        $currentLang = State::getLang() ?: 'zh_Hans_CN';
        $localsQuery = clone $this->localsModel;
        $allLocales = $localsQuery->clear()
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
        
        // 站点选择：根据当前后台用户可访问的站点列表
        $availableWebsites = $this->getAvailableWebsitesForUserWithCurrentSelection($pageWebsiteId);
        $this->assign('websites', $availableWebsites);
        
        // 获取父页面列表（排除自己）
        $parentPages = clone $this->pageModel;
        $parentPages = $parentPages->clear()
            ->where(PageModel::schema_fields_ID, $pageId, '!=')
            ->select()
            ->fetch()
            ->getItems();
        $this->assign('parent_pages', $parentPages);
        
        // 如果当前页面有父页面，获取父页面的配置用于继承锁定；子页面主题继承自主页（根页面），不可切换
        $currentParentId = $page->getData(PageModel::schema_fields_PARENT_ID);
        $parentPageData = null;
        $inheritedStyleCode = '';
        $inheritedStyleName = '';
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
            $rootPage = $page->getRootPage();
            if ($rootPage && $rootPage->getId()) {
                $inheritedStyleCode = (string)($rootPage->getData(PageModel::schema_fields_STYLE) ?? '');
                if ($inheritedStyleCode !== '') {
                    $styleModel = clone $this->styleModel;
                    $styleModel->clear()->where(Style::schema_fields_CODE, $inheritedStyleCode)->find()->fetch();
                    $inheritedStyleName = $styleModel->getId() ? (string)($styleModel->getData(Style::schema_fields_NAME) ?? $inheritedStyleCode) : $inheritedStyleCode;
                }
            }
        }
        $this->assign('parent_page_data', $parentPageData);
        $this->assign('parent_id', $currentParentId ?? 0);
        $this->assign('inherited_style_code', $inheritedStyleCode);
        $this->assign('inherited_style_name', $inheritedStyleName);
        
        // 获取已翻译的语言数据
        $localDescriptions = clone $this->localDescriptionModel;
        $translations = $localDescriptions->clear()
            ->where(LocalDescription::schema_fields_ID, $pageId)
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
                'title' => $translation->getData(LocalDescription::schema_fields_TITLE),
                'content' => $translation->getData(LocalDescription::schema_fields_CONTENT),
                'meta_title' => $translation->getData(LocalDescription::schema_fields_META_TITLE),
                'meta_description' => $translation->getData(LocalDescription::schema_fields_META_DESCRIPTION),
                'meta_keywords' => $translation->getData(LocalDescription::schema_fields_META_KEYWORDS),
            ];
        }
        $this->assign('translations', $translationsData);
        $this->assign('localized_contents', $localizedContents);
        
        // 读取多语言功能配置
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $i18nEnabled = $systemConfig->getConfig('i18n_enabled', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $i18nEnabled = $i18nEnabled === null ? '0' : $i18nEnabled; // 默认不开启
        $this->assign('i18n_enabled', $i18nEnabled);

        // 未翻译语言提示已移除（页面表单内的语言 Tab 已有未翻译标记，无需重复提示）

        // 如果多语言功能关闭，清空active_locales和selected_locales
        if ($i18nEnabled !== '1') {
            $this->assign('active_locales', []);
            $this->assign('selected_locales', []);
        }
        
        // 样式列表将通过AJAX加载，这里传空数组
        $this->assign('styles', []);
        
        // 获取当前页面的样式配置
        $currentStyle = $page->getData(PageModel::schema_fields_STYLE);
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
                ->where(PageModel::schema_fields_PARENT_ID, $currentParentId)
                ->select()
                ->fetch()
                ->getItems();
        } else {
            // 当前是父页面，获取其子页面
            $relatedPagesModel = clone $this->pageModel;
            $relatedPages = $relatedPagesModel->clear()
                ->where(PageModel::schema_fields_PARENT_ID, $pageId)
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
            $styleModel->clear()->where(Style::schema_fields_CODE, $currentStyle)->find()->fetch();
            if ($styleModel->getId()) {
                $styleConfigs = $styleModel->getConfigGroups();
            }
        }
        
        $this->assign('style_configs', $styleConfigs);
        $this->assign('style_settings', $currentStyleSettings);
        
        // 生成并分配 Google SEO JSON-LD 结构化数据（只读展示用）
        try {
            $structuredData = $this->buildStructuredDataForPage($page);
            $this->assign(
                'seo_structured_data_json',
                json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            $this->assign('seo_structured_data_json', '');
        }
        
        // 读取AI功能配置
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $aiEnabled = $systemConfig->getConfig('ai_enabled', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $aiEnabled = $aiEnabled === null ? '0' : $aiEnabled; // 默认不开启
        $this->assign('ai_enabled', $aiEnabled);
        
        // 获取可用的布局页面列表（排除当前页面）
        $layoutPages = $page->getAvailableLayoutPages((int)$pageId);
        $this->assign('layout_pages', $layoutPages);
        
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_auto_save_seo', '自动保存SEO配置', '', '通过可视化编辑器保存TDK到页面记录', 'GuoLaiRen_PageBuilder::page_builder')]
    public function autoSaveSeo()
    {
        try {
            // 获取 JSON 请求体
            $bodyParams = $this->request->getBodyParams();
            $data = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);

            if (!is_array($data) || !$data) {
                $data = [
                    'page_id' => (int)$this->request->getPost('page_id'),
                    'meta_title' => (string)($this->request->getPost('meta_title', '') ?? ''),
                    'meta_description' => (string)($this->request->getPost('meta_description', '') ?? ''),
                    'meta_keywords' => (string)($this->request->getPost('meta_keywords', '') ?? ''),
                    'locale' => (string)($this->request->getPost('locale', '') ?? ''),
                ];
            }

            $pageId = (int)($data['page_id'] ?? 0);
            if ($pageId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空！'),
                ]);
            }

            $page = clone $this->pageModel;
            $page->clear()->load($pageId);
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在！'),
                ]);
            }

            $metaTitle = (string)($data['meta_title'] ?? '');
            $metaDescription = (string)($data['meta_description'] ?? '');
            $metaKeywords = (string)($data['meta_keywords'] ?? '');
            $locale = (string)($data['locale'] ?? '');

            $defaultLocale = (string)($page->getData(PageModel::schema_fields_DEFAULT_LOCALE) ?? '');
            $isTranslationScope = $locale !== '' && $defaultLocale !== '' && $locale !== $defaultLocale;

            if ($isTranslationScope) {
                // 多语言场景：保存到 LocalDescription 表
                $localDesc = clone $this->localDescriptionModel;
                $existing = $localDesc->clear()
                    ->where(LocalDescription::schema_fields_ID, $pageId)
                    ->where('local_code', $locale)
                    ->find()
                    ->fetch();

                if ($existing && $existing->getId()) {
                    $existing
                        ->setData(LocalDescription::schema_fields_META_TITLE, $metaTitle)
                        ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $metaDescription)
                        ->setData(LocalDescription::schema_fields_META_KEYWORDS, $metaKeywords)
                        ->save();
                } else {
                    $newLocal = clone $this->localDescriptionModel;
                    $newLocal->clearData()
                        ->setData(LocalDescription::schema_fields_ID, $pageId)
                        ->setData('local_code', $locale)
                        ->setData(LocalDescription::schema_fields_NAME, $page->getData(PageModel::schema_fields_NAME))
                        ->setData(LocalDescription::schema_fields_TITLE, $page->getData(PageModel::schema_fields_TITLE))
                        ->setData(LocalDescription::schema_fields_CONTENT, $page->getData(PageModel::schema_fields_CONTENT))
                        ->setData(LocalDescription::schema_fields_META_TITLE, $metaTitle)
                        ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $metaDescription)
                        ->setData(LocalDescription::schema_fields_META_KEYWORDS, $metaKeywords)
                        ->save(true);
                }
            } else {
                // 默认语言或未指定语言：直接更新页面主表 SEO 字段
                $page
                    ->setData(PageModel::schema_fields_META_TITLE, $metaTitle)
                    ->setData(PageModel::schema_fields_META_DESCRIPTION, $metaDescription)
                    ->setData(PageModel::schema_fields_META_KEYWORDS, $metaKeywords)
                    ->save();
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('SEO 配置已保存'),
                'data' => [
                    'page_id' => $pageId,
                    'locale' => $locale,
                    'scope' => $isTranslationScope ? 'translation' : 'page',
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'meta_keywords' => $metaKeywords,
                ],
            ]);
        } catch (\Exception $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
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
            
            // 保存更新前的 handle / website_id / style，用于保存后判断是否需要清理旧重写和缓存
            $oldHandle = $page->getData(PageModel::schema_fields_HANDLE);
            $oldWebsiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
            $oldStyleCode = $page->getData(PageModel::schema_fields_STYLE) ?? '';
            
            // 权限校验：编辑时也要验证站点归属
            $pageWebsiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
            $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
            if (!empty($accessibleWebsiteIds) && $pageWebsiteId > 0 && !in_array($pageWebsiteId, $accessibleWebsiteIds, true)) {
                throw new \Exception(__('您没有权限编辑该页面所属的站点。'));
            }
            
            // 检查当前页面是否有父页面，如果有则强制继承父页面配置
            $currentParentId = $page->getData(PageModel::schema_fields_PARENT_ID);
            
            // 如果当前页面是子页面，检查其下是否有子页面，如果有则提升到父级页面
            if (!empty($currentParentId) && $currentParentId > 0) {
                $currentChildPages = $page->getChildPages();
                if (!empty($currentChildPages)) {
                    // 将当前页面的所有子页面提升到当前页面的父级页面
                    foreach ($currentChildPages as $childPage) {
                        $childPage->setData(PageModel::schema_fields_PARENT_ID, $currentParentId);
                        $childPage->save();
                    }
                    MessageManager::success(
                        __('已将 %{1} 个子页面提升到父级页面', count($currentChildPages))
                    );
                }
            }
            
            // 处理选中的语言列表
            $selectedLocales = $this->request->getPost('locales', []);
            $defaultLocale = $this->request->getPost('default_locale', '');
            
            // 去重处理 locales 数组
            if (is_array($selectedLocales)) {
                $selectedLocales = array_unique($selectedLocales);
                $selectedLocales = array_values($selectedLocales); // 重新索引数组
            } else {
                $selectedLocales = [];
            }
            
            // 如果当前页面有父页面，强制使用父页面的语言设置（不使用表单提交的值）
            if ($currentParentId && $currentParentId > 0) {
                $parentPageModel = clone $this->pageModel;
                $parentPageModel->clear()->load($currentParentId);
                if ($parentPageModel->getId()) {
                    // 强制使用父页面的语言设置
                    $selectedLocales = json_decode(($parentPageModel->getData(PageModel::schema_fields_LOCALES) ?? '') ?? '', true) ?: [];
                    $defaultLocale = $parentPageModel->getData(PageModel::schema_fields_DEFAULT_LOCALE);
                    
                    // 确保默认语言在列表中
                    if ($defaultLocale && !in_array($defaultLocale, $selectedLocales)) {
                        $selectedLocales[] = $defaultLocale;
                    }
                    
                    MessageManager::success(__('子页面配置已从父页面继承'));
                }
            } else {
                // 非子页面，正常处理语言设置
                // 如果没有设置默认语言，使用框架当前语言
                if (empty($defaultLocale)) {
                    $defaultLocale = State::getLang();
                }
                
                // 确保默认语言在支持的语言列表中
                if (!in_array($defaultLocale, $selectedLocales)) {
                    $selectedLocales[] = $defaultLocale;
                }
            }
            
            // 处理样式配置信息 - 合并保存，保留其他style的配置
            $currentStyleSettings = $this->request->getPost('style_settings', []);
            
            // 🔧 确保 style 字段正确获取（尝试多种方式）
            $currentStyleCode = $data['style'] ?? '';
            if (empty($currentStyleCode)) {
                // 尝试直接从 POST 获取
                $currentStyleCode = $this->request->getPost('style', '');
            }
            if (empty($currentStyleCode)) {
                // 如果仍然为空，尝试使用页面原有的 style
                $currentStyleCode = $page->getData(PageModel::schema_fields_STYLE) ?? '';
            }
            
            // 获取页面原有的所有样式配置
            $existingSettings = $page->getStyleSetting();
            
            // 更新当前style的配置，保留其他style的配置
            if (!empty($currentStyleCode)) {
                $existingSettings[$currentStyleCode] = $currentStyleSettings;
            }
            
            // CTA 事件名称：如果为空则自动生成为 cta_{handle}_click
            $ctaEventName = $data['cta_event_name'] ?? '';
            if (empty($ctaEventName) && !empty($data['handle'])) {
                $ctaEventName = 'cta_' . $data['handle'] . '_click';
            }
            
            // 🔧 处理 type 字段：如果为空，使用原有值或默认值
            $pageType = $data['type'] ?? '';
            if (empty($pageType)) {
                // 如果 type 为空，尝试使用页面原有的 type
                $existingType = $page->getData(PageModel::schema_fields_TYPE);
                if (!empty($existingType)) {
                    $pageType = $existingType;
                } else {
                    // 如果原有值也为空，使用默认值
                    $pageType = PageModel::TYPE_CUSTOM;
                }
            }
            
            // 🔧 确保 style 字段有值（使用处理后的值）；子页面强制继承根页面主题，不可修改
            $styleToSave = !empty($currentStyleCode) ? $currentStyleCode : ($data['style'] ?? '');
            if ($currentParentId && $currentParentId > 0) {
                $rootPage = $page->getRootPage();
                if ($rootPage && $rootPage->getId()) {
                    $rootStyle = (string)($rootPage->getData(PageModel::schema_fields_STYLE) ?? '');
                    if ($rootStyle !== '') {
                        $styleToSave = $rootStyle;
                    }
                }
            }
            
            // 处理parent_id：检查子页面不能分配给子页面
            $newParentId = (int)($data['parent_id'] ?? 0);
            $originalParentId = $page->getData(PageModel::schema_fields_PARENT_ID);
            
            // 如果新的parent_id与原来的不同，需要检查
            if ($newParentId > 0 && $newParentId != $originalParentId) {
                $newParentPageModel = clone $this->pageModel;
                $newParentPageModel->clear()->load($newParentId);
                if ($newParentPageModel->getId()) {
                    $newParentPageParentId = $newParentPageModel->getData(PageModel::schema_fields_PARENT_ID);
                    
                    // 如果新父页面本身也是子页面，需要调整
                    if (!empty($newParentPageParentId) && $newParentPageParentId > 0) {
                        // 1. 检查当前页面是否有子页面
                        $currentChildPages = $page->getChildPages();
                        
                        // 2. 将当前页面的子页面提升到新父页面的父级页面
                        $grandParentId = (int)$newParentPageParentId;
                        if (!empty($currentChildPages)) {
                            foreach ($currentChildPages as $childPage) {
                                $childPage->setData(PageModel::schema_fields_PARENT_ID, $grandParentId);
                                $childPage->save();
                            }
                            MessageManager::success(
                                __('已将 %{1} 个子页面提升到父级页面', count($currentChildPages))
                            );
                        }
                        
                        // 3. 将当前页面的parent_id也调整为顶级页面
                        $newParentId = $grandParentId;
                        MessageManager::warning(
                            __('子页面不能分配给子页面，已自动调整为顶级页面：%{1}', $newParentId)
                        );
                    }
                }
            }
            
            // 🔧 处理 handle：仅首页允许为空；子页面为空时用页面类型作为 handle（如 about）
            $pageHandle = trim((string)($data['handle'] ?? ''));
            if (empty($pageHandle)) {
                if ($pageType === PageModel::TYPE_HOME) {
                    $pageHandle = '';
                } else {
                    $pageHandle = PageModel::getDefaultHandleForType($pageType);
                }
            }
            
            // 🔧 使用条件更新（where()->update()）来保存记录，避免 PostgreSQL CASE 表达式类型不匹配问题
            // 准备更新数据（基础字段，肯定存在）
            // 准备更新数据（基础字段，肯定存在）
            $updateData = [
                PageModel::schema_fields_HANDLE => $pageHandle,
                PageModel::schema_fields_TYPE => $pageType,
                PageModel::schema_fields_NAME => $data['name'],
                PageModel::schema_fields_TITLE => $data['title'],
                PageModel::schema_fields_CONTENT => $data['content'] ?? '',
                PageModel::schema_fields_PARENT_ID => $newParentId,
                PageModel::schema_fields_STYLE => $styleToSave, // 确保 style 字段被更新
                PageModel::schema_fields_STYLE_SETTING => json_encode($existingSettings),
                PageModel::schema_fields_GA4_ID => $data['ga4_id'] ?? '',
                PageModel::schema_fields_GTM_ID => $data['gtm_id'] ?? '',
                PageModel::schema_fields_FB_PIXEL_ID => $data['fb_pixel_id'] ?? '',
                PageModel::schema_fields_LOGO => $data['logo'] ?? '',
                PageModel::schema_fields_ICON => $data['icon'] ?? '',
                PageModel::schema_fields_LOCALES => json_encode($selectedLocales),
                PageModel::schema_fields_DEFAULT_LOCALE => $defaultLocale,
                PageModel::schema_fields_META_TITLE => $data['meta_title'] ?? '',
                PageModel::schema_fields_META_DESCRIPTION => $data['meta_description'] ?? '',
                PageModel::schema_fields_META_KEYWORDS => $data['meta_keywords'] ?? '',
                PageModel::schema_fields_REDIRECT_URL => $data['redirect_url'] ?? '',
                PageModel::schema_fields_HEADER_CUSTOM_CODE => $data['header_custom_code'] ?? '',
                PageModel::schema_fields_FOOTER_CUSTOM_CODE => $data['footer_custom_code'] ?? '',
                PageModel::schema_fields_LAYOUT_PAGE_ID => !empty($data['layout_page_id']) ? (int)$data['layout_page_id'] : null,
                PageModel::schema_fields_STATUS => $data['status'] ?? PageModel::STATUS_DRAFT,
            ];
            
            // 关联站点：仅允许设置为当前用户可访问的站点
            $websiteId = (int)($data['website_id'] ?? 0);
            if ($websiteId > 0) {
                if (!empty($accessibleWebsiteIds) && in_array($websiteId, $accessibleWebsiteIds, true)) {
                    $updateData[PageModel::schema_fields_WEBSITE_ID] = $websiteId;
                } elseif (empty($accessibleWebsiteIds)) {
                    // 超管或未限制站点时，允许自由设置
                    $updateData[PageModel::schema_fields_WEBSITE_ID] = $websiteId;
                }
            }
            
            // CTA 事件名称
            $updateData[PageModel::schema_fields_CTA_EVENT_NAME] = $ctaEventName;
            
            // 🔧 确保 pageId 是整数类型
            $pageIdInt = (int)$pageId;
            $checkPage = clone $page;
            $checkPage->clear()->load($pageIdInt);
            if (!$checkPage->getId()) {
                throw new \Exception(__('页面不存在，无法更新！'));
            }
            
            // 使用条件更新：where()->update()->fetch()
            try {
                $saveResult = $page->clear()
                    ->where(PageModel::schema_fields_ID, $pageIdInt)
                    ->update($updateData, PageModel::schema_fields_ID)
                    ->fetch();
            } catch (\PDOException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw $e;
            }
            
            // 更新后重新加载数据到 Model（使用整数类型的 pageId）
            $page->clear()->load($pageIdInt);
            
            // 编辑时实时更新页面的重写路由（统一用 page_id，handle 变更时自动覆盖；首页含空重写）
            $this->createOrUpdateUrlRewrite($page);
            
            // 清理旧格式的 url_identify 条目（pagebuilder_page_{websiteId}_{handle}）
            $newHandle = $page->getData(PageModel::schema_fields_HANDLE);
            $newWebsiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
            if ($oldHandle !== '' && $oldHandle !== null) {
                try {
                    $oldUrlIdentify = "pagebuilder_page_{$oldWebsiteId}_{$oldHandle}";
                    /**@var UrlRewrite $cleanupRewrite */
                    $cleanupRewrite = ObjectManager::getInstance(UrlRewrite::class);
                    $cleanupRewrite = clone $cleanupRewrite;
                    $cleanupRewrite->clear()
                        ->where(UrlRewrite::schema_fields_URL_IDENTIFY, $oldUrlIdentify)
                        ->delete()
                        ->fetch();
                } catch (\Throwable $e) {
                    // 忽略
                }
            }
            
            // handle / website_id / style 有变更时清除路由与重写缓存
            if ($oldHandle !== $newHandle || $oldWebsiteId !== $newWebsiteId || $oldStyleCode !== $styleToSave) {
                $this->clearRouterAndPageBuilderCache();
            }

            // 若当前保存的是主页（根页面）且主题已变更，则同步更新该主页下所有子页面的主题
            if ((int)$newParentId === 0 && $page->getId() && (string)$oldStyleCode !== (string)$styleToSave) {
                $descendantIds = $page->getDescendantIds();
                if (!empty($descendantIds)) {
                    $childUpdater = clone $this->pageModel;
                    $childUpdater->clear()
                        ->where(PageModel::schema_fields_ID, $descendantIds, 'IN')
                        ->update([PageModel::schema_fields_STYLE => $styleToSave], PageModel::schema_fields_ID)
                        ->fetch();
                    MessageManager::success(
                        __('主页主题已更新，已同步 %{1} 个子页面的主题。', [count($descendantIds)])
                    );
                }
            }

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
                        ->where(LocalDescription::schema_fields_ID, $pageId)
                        ->where('local_code', $locale)
                        ->find()
                        ->fetch();
                    
                    if ($existing && $existing->getId()) {
                        // 更新现有翻译
                        $existing->setData(LocalDescription::schema_fields_NAME, $data['name'] ?? '')
                            ->setData(LocalDescription::schema_fields_TITLE, $translatedTitle)
                            ->setData(LocalDescription::schema_fields_CONTENT, $translatedContent)
                            ->setData(LocalDescription::schema_fields_META_TITLE, $translatedMetaTitle)
                            ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $translatedMetaDesc)
                            ->setData(LocalDescription::schema_fields_META_KEYWORDS, $translatedMetaKeywords)
                            ->save();
                    } else {
                        // 创建新翻译
                        $newTranslation = clone $this->localDescriptionModel;
                        $newTranslation->clearData()
                            ->setData(LocalDescription::schema_fields_ID, $pageId)
                            ->setData('local_code', $locale)
                            ->setData(LocalDescription::schema_fields_NAME, $data['name'] ?? '')
                            ->setData(LocalDescription::schema_fields_TITLE, $translatedTitle)
                            ->setData(LocalDescription::schema_fields_CONTENT, $translatedContent)
                            ->setData(LocalDescription::schema_fields_META_TITLE, $translatedMetaTitle)
                            ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $translatedMetaDesc)
                            ->setData(LocalDescription::schema_fields_META_KEYWORDS, $translatedMetaKeywords)
                            ->save();
                    }
                }
            }
            
            MessageManager::success(__('页面更新成功！'));
            
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
            $errorMessage = $exception->getMessage();
            
            // 如果是数据库约束错误，提供更友好的提示
            if (strpos($errorMessage, 'not null') !== false || strpos($errorMessage, 'NOT NULL') !== false) {
                $errorMessage = __('必填字段不能为空，请检查表单数据是否完整');
            } elseif (strpos($errorMessage, 'Duplicate entry') !== false) {
                $errorMessage = __('数据重复，请检查页面句柄是否已被使用');
            }
            
            MessageManager::warning(__('页面更新失败！'));
            if (DEV) {
                MessageManager::exception($exception);
                MessageManager::error('详细错误: ' . $errorMessage);
            } else {
                MessageManager::error($errorMessage);
            }
            
            // 检查是否为AJAX请求
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面更新失败：') . $errorMessage,
                    'debug' => DEV ? [
                        'exception' => $exception->getMessage(),
                        'trace' => $exception->getTraceAsString()
                    ] : null
                ]);
            }
            
            $this->redirect('*/backend/page/edit', ['id' => $this->request->getGet('id')]);
        }
    }

    /**
     * 发布单个页面（AJAX：POST id，返回 JSON）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_publish', '发布页面', 'mdi mdi-publish', '发布页面')]
    public function postPublish()
    {
        $bodyParams = $this->request->getBodyParams();
        $data = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : []);
        $pageId = (int)($data['id'] ?? $this->request->getPost('id') ?? 0);
        if ($pageId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('页面ID不能为空')]);
        }
        $page = clone $this->pageModel;
        $page->clear()->load($pageId);
        if (!$page->getId()) {
            return $this->fetchJson(['success' => false, 'message' => __('页面不存在')]);
        }
        $websiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
        $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
        if ($websiteId > 0 && !empty($accessibleWebsiteIds) && !in_array($websiteId, $accessibleWebsiteIds, true)) {
            return $this->fetchJson(['success' => false, 'message' => __('您没有权限发布该页面所属的站点')]);
        }
        $page->setData(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
        $page->save();
        return $this->fetchJson(['success' => true, 'message' => __('页面已发布')]);
    }

    /**
     * 一键发布本站：发布指定站点下所有页面（AJAX：POST website_id，返回 JSON）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_publish', '一键发布本站', 'mdi mdi-publish', '发布该站点下所有页面')]
    public function postPublishSite()
    {
        $bodyParams = $this->request->getBodyParams();
        $data = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : []);
        $websiteId = (int)($data['website_id'] ?? $this->request->getPost('website_id') ?? 0);
        if ($websiteId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('请选择要发布的站点')]);
        }
        $accessibleWebsiteIds = $this->getAccessibleWebsiteIds();
        if (!empty($accessibleWebsiteIds) && !in_array($websiteId, $accessibleWebsiteIds, true)) {
            return $this->fetchJson(['success' => false, 'message' => __('您没有权限发布该站点')]);
        }
        $listModel = clone $this->pageModel;
        $listModel->clear()
            ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId);
        $pages = $listModel->select()->fetch()->getItems();
        $count = 0;
        foreach ($pages as $page) {
            $page->setData(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
            $page->save();
            $count++;
        }
        return $this->fetchJson([
            'success' => true,
            'message' => __('本站已发布，共 %{count} 个页面', ['count' => $count]),
            'count' => $count,
        ]);
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
                ->where(LocalDescription::schema_fields_ID, $pageId)
                ->delete()
                ->fetch();
            
            MessageManager::success(__('页面删除成功！'));
        } catch (\Exception $exception) {
            MessageManager::warning(__('页面删除失败！'));
            if (DEV) {
                MessageManager::exception($exception);
            }
        }
        
        $this->redirect('*/backend/page/index');
    }
    
    /**
     * 一键删除站点（AJAX接口，需要POST请求）
     * 删除首页及其所有子页面
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_delete', '删除站点', 'mdi mdi-delete-sweep', '删除站点及所有子页面')]
    public function postDeleteSite()
    {
        try {
            // 获取JSON请求数据
            $bodyParams = $this->request->getBodyParams();
            $data = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
            $pageId = $data['id'] ?? null;
            
            if (empty($pageId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }
            
            // 加载页面
            $page = clone $this->pageModel;
            $page->clear()->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }
            
            // 检查是否为首页类型的顶级页面
            $isHomePage = $page->getData(PageModel::schema_fields_TYPE) === PageModel::TYPE_HOME;
            $isTopLevel = empty($page->getData(PageModel::schema_fields_PARENT_ID)) || $page->getData(PageModel::schema_fields_PARENT_ID) == 0;
            
            if (!$isHomePage || !$isTopLevel) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('只能删除首页类型的顶级页面')
                ]);
            }
            
            // 递归获取所有要删除的页面
            $pagesToDelete = $this->getAllPagesToDelete($page);
            $deletedCount = 0;
            
            // 从子页面开始删除（避免外键约束问题）
            $pagesToDelete = array_reverse($pagesToDelete);
            
            foreach ($pagesToDelete as $pageToDelete) {
                $deletePageId = $pageToDelete->getId();
                
                // 重新加载页面确保数据完整
                $pageToDeleteObj = clone $this->pageModel;
                $pageToDeleteObj->clear()->load($deletePageId);
                
                if (!$pageToDeleteObj->getId()) {
                    continue; // 页面已被删除，跳过
                }
                
                // 1. 删除翻译数据
                $localDescriptions = clone $this->localDescriptionModel;
                $localDescriptions->clear()
                    ->where(LocalDescription::schema_fields_ID, $deletePageId)
                    ->delete()
                    ->fetch();
                
                // 2. 删除URL重写
                $this->deleteUrlRewrite($pageToDeleteObj);
                
                // 3. 删除页面本身
                $pageToDeleteObj->clear()
                    ->where(PageModel::schema_fields_ID, $deletePageId)
                    ->delete()
                    ->fetch();
                
                $deletedCount++;
            }
            
            // 清除路由缓存（仅使用框架 CacheManager，不使用不存在的 UrlRewriteCache）
            $this->clearRouterAndPageBuilderCache();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('站点删除成功，共删除 %{1} 个页面', [$deletedCount])
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 递归获取所有要删除的页面
     */
    private function getAllPagesToDelete(PageModel $page): array
    {
        $pages = [$page];
        
        $childPages = $page->getChildPages();
        foreach ($childPages as $childPage) {
            $pages = array_merge($pages, $this->getAllPagesToDelete($childPage));
        }
        
        return $pages;
    }
    
    /**
     * 清除路由与 PageBuilder 静态缓存（避免依赖不存在的 UrlRewriteCache）
     */
    private function clearRouterAndPageBuilderCache(): void
    {
        try {
            if (class_exists(\Weline\Framework\Cache\CacheManager::class)) {
                $cacheManager = ObjectManager::getInstance(\Weline\Framework\Cache\CacheManager::class);
                $routerPool = $cacheManager->pool('router');
                if (method_exists($routerPool, 'clear')) {
                    $routerPool->clear();
                }
                $rewritePool = $cacheManager->pool('url_rewrite');
                if (method_exists($rewritePool, 'clear')) {
                    $rewritePool->clear();
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }
        \GuoLaiRen\PageBuilder\Controller\Router::clearCache();
    }

    /**
     * 删除页面的URL重写规则
     * 
     * 注意：URL 重写按 website_id 隔离，删除时需要指定 website_id
     */
    private function deleteUrlRewrite(PageModel $page): void
    {
        try {
            $pageId = $page->getId();
            /**@var UrlRewrite $urlRewriteModel */
            $urlRewriteModel = ObjectManager::getInstance(UrlRewrite::class);
            
            if (!empty($pageId)) {
                $urlRewriteModel->clear()
                    ->where(UrlRewrite::schema_fields_URL_IDENTIFY, "pagebuilder_page_{$pageId}")
                    ->delete()
                    ->fetch();
                // 首页可能还有一条空重写 pagebuilder_page_{id}_root
                $urlRewriteModel->clear()
                    ->where(UrlRewrite::schema_fields_URL_IDENTIFY, "pagebuilder_page_{$pageId}_root")
                    ->delete()
                    ->fetch();
                $fallback = clone $urlRewriteModel;
                $fallback->clear()
                    ->where(UrlRewrite::schema_fields_URL_ID, "pagebuilder_page_{$pageId}")
                    ->delete()
                    ->fetch();
                return;
            }
            
            $handle = $page->getData(PageModel::schema_fields_HANDLE);
            $websiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
            if (empty($handle)) {
                return;
            }
            $oldUrlIdentify = "pagebuilder_page_{$websiteId}_{$handle}";
            $urlRewriteModel->clear()
                ->where(UrlRewrite::schema_fields_URL_IDENTIFY, $oldUrlIdentify)
                ->delete()
                ->fetch();
        } catch (\Exception $e) {
            if (DEV) {
                w_log_error("Failed to delete URL rewrite for page: " . $e->getMessage());
            }
        }
    }
    
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_translate', '翻译页面', 'mdi mdi-translate', '翻译页面')]
    public function getTranslate()
    {
        $pageId = $this->request->getGet('id');
        $locale = $this->request->getGet('locale');
        
        if (!$pageId || !$locale) {
            MessageManager::error(__('参数错误！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        $page = clone $this->pageModel;
        $page->clear()->load($pageId);
        
        if (!$page->getId()) {
            MessageManager::error(__('页面不存在！'));
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
            ->where(LocalDescription::schema_fields_ID, $pageId)
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
                ->where(LocalDescription::schema_fields_ID, $pageId)
                ->where('local_code', $locale)
                ->find()
                ->fetch();
            
            $localDescription->setData(LocalDescription::schema_fields_ID, $pageId)
                ->setData('local_code', $locale)
                ->setData(LocalDescription::schema_fields_NAME, $data['name'] ?? '')
                ->setData(LocalDescription::schema_fields_TITLE, $data['title'] ?? '')
                ->setData(LocalDescription::schema_fields_CONTENT, $data['content'] ?? '')
                ->setData(LocalDescription::schema_fields_META_TITLE, $data['meta_title'] ?? '')
                ->setData(LocalDescription::schema_fields_META_DESCRIPTION, $data['meta_description'] ?? '')
                ->setData(LocalDescription::schema_fields_META_KEYWORDS, $data['meta_keywords'] ?? '')
                ->save(true);
            
            MessageManager::success(__('翻译保存成功！'));
            $this->redirect('*/backend/page/edit', ['id' => $pageId]);
        } catch (\Exception $exception) {
            MessageManager::warning(__('翻译保存失败！'));
            if (DEV) {
                MessageManager::exception($exception);
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
     * 自动检查预览图是否存在，如果不存在则提供生成所需的信息
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_get_styles', '获取样式列表', '', '获取样式列表')]
    public function getStyles()
    {
        try {
            // 强制扫描样式模板（实时获取最新数据）
            Style::forceScan();
            
            // 获取所有可用样式（只返回已发布的模板）
            $styles = clone $this->styleModel;
            $styleList = $styles->clear()
                ->where(Style::schema_fields_IS_ACTIVE, 1)
                ->where(Style::schema_fields_IS_PUBLISHED, 1)
                ->order(Style::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 格式化样式数据
            $formattedStyles = [];
            foreach ($styleList as $style) {
                $styleCode = $style->getData(Style::schema_fields_CODE);
                $previewImage = $style->getData(Style::schema_fields_PREVIEW_IMAGE);
                $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $styleCode);
                
                // 检查预览图是否存在
                $previewUrl = '';
                $previewFound = false;
                
                // 1. 优先检查数据库中记录的路径
                if ($previewImage) {
                    $dbImagePath = BP . 'pub/static/' . ltrim($previewImage, '/');
                    if (file_exists($dbImagePath)) {
                        $previewUrl = '/static/' . ltrim($previewImage, '/');
                        $previewUrl .= '?t=' . filemtime($dbImagePath);
                        $previewFound = true;
                    }
                }
                
                // 2. 检查标准预览图目录（模板管理生成的预览图）
                if (!$previewFound) {
                    $previewDir = BP . 'pub/static/pagebuilder/previews/';
                    $extensions = ['png', 'jpg', 'webp'];
                    
                    foreach ($extensions as $ext) {
                        $previewPath = $previewDir . $safeCode . '.' . $ext;
                        if (file_exists($previewPath)) {
                            $previewUrl = '/static/pagebuilder/previews/' . $safeCode . '.' . $ext;
                            $previewUrl .= '?t=' . filemtime($previewPath);
                            $previewFound = true;
                            break;
                        }
                    }
                }
                
                // 3. 检查模板源目录中的预览图
                if (!$previewFound) {
                    $templateBasePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
                    $templatePreviews = [
                        $templateBasePath . 'preview.webp',
                        $templateBasePath . 'preview.png',
                        $templateBasePath . 'preview.jpg',
                    ];
                    
                    foreach ($templatePreviews as $previewPath) {
                        if (file_exists($previewPath)) {
                            // 使用模块静态资源路由访问
                            $previewUrl = $this->request->getUrlBuilder()->getBackendUrl(
                                'pagebuilder/backend/template/previewImage',
                                ['style_code' => $styleCode]
                            );
                            $previewFound = true;
                            break;
                        }
                    }
                }
                
                // 如果没有预览图，才需要生成（只生成一次，生成后保存）
                $needsPreview = !$previewFound;
                
                $formattedStyles[] = [
                    'id' => $style->getId(),
                    'code' => $styleCode,
                    'name' => $style->getData(Style::schema_fields_NAME),
                    'description' => $style->getData(Style::schema_fields_DESCRIPTION),
                    'path' => $style->getData(Style::schema_fields_PATH),
                    'preview_image' => $previewUrl,
                    'needs_preview' => $needsPreview,
                    'preview_page_url' => $needsPreview ? $this->request->getUrlBuilder()->getBackendUrl(
                        'pagebuilder/backend/preview/stylePreview',
                        ['style_code' => $styleCode]
                    ) : '',
                    'supported_types' => $style->getSupportedTypes(),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $formattedStyles,
                'count' => count($formattedStyles),
                'upload_url' => $this->request->getUrlBuilder()->getBackendUrl('pagebuilder/backend/template/uploadPreview'),
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
     * 一键建站（AJAX接口）
     * 批量创建站点所需的所有页面类型
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_quick_site', '一键建站', 'mdi mdi-rocket-launch', '批量创建站点所有页面')]
    public function quickSite()
    {
        try {
            // 获取参数（ParameterBag::getRequest 已自动回退 Body，FPM/WLS 均可正常读取）
            $styleCode = $this->request->getPost('style_code', '');
            $siteName = trim((string) $this->request->getPost('site_name', ''));
            $siteHandle = trim((string) $this->request->getPost('site_handle', ''));
            $websiteId = (int) $this->request->getPost('website_id', 0);
            $defaultLocale = (string) $this->request->getPost('default_locale', 'zh_Hans_CN');
            $pageTypesRaw = $this->request->getPost('page_types', '[]');
            $pageTypes = \is_array($pageTypesRaw) ? $pageTypesRaw : (json_decode((string) $pageTypesRaw, true) ?: []);
            $seoAccountId = $this->request->getPost('seo_account_id', '');
            
            // 验证参数
            if (empty($styleCode)) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择模板')]);
            }
            if (empty($siteName)) {
                return $this->fetchJson(['success' => false, 'message' => __('请输入站点名称')]);
            }
            if ($websiteId <= 0) {
                return $this->fetchJson(['success' => false, 'message' => __('请选择所属站点')]);
            }
            // URL前缀可选；填写时仅允许小写字母、数字、连字符
            if ($siteHandle !== '' && !preg_match('/^[a-z0-9-]+$/', $siteHandle)) {
                return $this->fetchJson(['success' => false, 'message' => __('URL前缀只能包含小写字母、数字和连字符')]);
            }
            
            // 确保首页在列表中
            if (!in_array(PageModel::TYPE_HOME, $pageTypes)) {
                array_unshift($pageTypes, PageModel::TYPE_HOME);
            }
            
            // 页面类型配置：一键建站使用英文（类型名首字母大写 + 网站名），有 URL 前缀时用 prefix-type
            $prefix = $siteHandle !== '' ? $siteHandle : '';
            $homeHandle = $prefix !== '' ? $prefix : '';
            $typeNames = $this->getQuickSiteEnglishTypeNames();
            $pageTypeConfigs = [
                PageModel::TYPE_HOME => [
                    'name' => $siteName,
                    'handle' => $homeHandle,
                    'title' => $siteName,
                    'is_home' => true,
                ],
                PageModel::TYPE_ABOUT => [
                    'name' => $typeNames[PageModel::TYPE_ABOUT],
                    'handle' => $prefix !== '' ? $prefix . '-about' : 'about',
                    'title' => $typeNames[PageModel::TYPE_ABOUT] . ' - ' . $siteName,
                ],
                PageModel::TYPE_CONTACT => [
                    'name' => $typeNames[PageModel::TYPE_CONTACT],
                    'handle' => $prefix !== '' ? $prefix . '-contact' : 'contact',
                    'title' => $typeNames[PageModel::TYPE_CONTACT] . ' - ' . $siteName,
                ],
                PageModel::TYPE_PRIVACY_POLICY => [
                    'name' => $typeNames[PageModel::TYPE_PRIVACY_POLICY],
                    'handle' => $prefix !== '' ? $prefix . '-privacy' : 'privacy',
                    'title' => $typeNames[PageModel::TYPE_PRIVACY_POLICY] . ' - ' . $siteName,
                ],
                PageModel::TYPE_TERMS_OF_SERVICE => [
                    'name' => $typeNames[PageModel::TYPE_TERMS_OF_SERVICE],
                    'handle' => $prefix !== '' ? $prefix . '-terms' : 'terms',
                    'title' => $typeNames[PageModel::TYPE_TERMS_OF_SERVICE] . ' - ' . $siteName,
                ],
                PageModel::TYPE_REFUND_POLICY => [
                    'name' => $typeNames[PageModel::TYPE_REFUND_POLICY],
                    'handle' => $prefix !== '' ? $prefix . '-refund' : 'refund',
                    'title' => $typeNames[PageModel::TYPE_REFUND_POLICY] . ' - ' . $siteName,
                ],
                PageModel::TYPE_SHIPPING_POLICY => [
                    'name' => $typeNames[PageModel::TYPE_SHIPPING_POLICY],
                    'handle' => $prefix !== '' ? $prefix . '-shipping' : 'shipping',
                    'title' => $typeNames[PageModel::TYPE_SHIPPING_POLICY] . ' - ' . $siteName,
                ],
                PageModel::TYPE_COOKIE_POLICY => [
                    'name' => $typeNames[PageModel::TYPE_COOKIE_POLICY],
                    'handle' => $prefix !== '' ? $prefix . '-cookies' : 'cookies',
                    'title' => $typeNames[PageModel::TYPE_COOKIE_POLICY] . ' - ' . $siteName,
                ],
                PageModel::TYPE_BLOG_LIST => [
                    'name' => $typeNames[PageModel::TYPE_BLOG_LIST],
                    'handle' => $prefix !== '' ? $prefix . '-blog' : 'blog',
                    'title' => $typeNames[PageModel::TYPE_BLOG_LIST] . ' - ' . $siteName,
                ],
                PageModel::TYPE_BLOG => [
                    'name' => $typeNames[PageModel::TYPE_BLOG],
                    'handle' => $prefix !== '' ? $prefix . '-post' : 'post',
                    'title' => $typeNames[PageModel::TYPE_BLOG] . ' - ' . $siteName,
                ],
                PageModel::TYPE_BLOG_CATEGORY => [
                    'name' => $typeNames[PageModel::TYPE_BLOG_CATEGORY],
                    'handle' => $prefix !== '' ? $prefix . '-blog-category' : 'blog-category',
                    'title' => $typeNames[PageModel::TYPE_BLOG_CATEGORY] . ' - ' . $siteName,
                ],
            ];
            
            $createdPages = [];
            $homePageId = null;
            
            // 批量创建页面
            foreach ($pageTypes as $pageType) {
                if (!isset($pageTypeConfigs[$pageType])) {
                    continue;
                }
                
                $config = $pageTypeConfigs[$pageType];
                
                // 检查当前站点下 handle 是否已存在（同一站点内唯一）
                if (!empty($config['handle'])) {
                    $existingPage = clone $this->pageModel;
                    $existingPage->clear()
                        ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(PageModel::schema_fields_HANDLE, $config['handle'])
                        ->find()
                        ->fetch();
                    
                    if ($existingPage->getId()) {
                        $config['handle'] = $config['handle'] . '-' . time();
                    }
                }
                
                // 创建页面（直接设为已发布状态，以便立即可访问）
                $page = clone $this->pageModel;
                $page->clearData();
                
                // 处理handle字段：首页handle必须不为空
                $pageHandle = $config['handle'] ?? '';
                if (empty($pageHandle) && ($config['is_home'] ?? false)) {
                    // 如果是首页且handle为空，自动生成一个
                    $pageHandle = $this->generateSlugFromName($config['name']);
                    if (empty($pageHandle)) {
                        $pageHandle = 'home-' . time();
                    }
                }
                
                // 只有当handle不为空时才设置handle字段
                if (!empty($pageHandle)) {
                    $page->setData(PageModel::schema_fields_HANDLE, $pageHandle);
                    // 更新config中的handle，以便后续使用
                    $config['handle'] = $pageHandle;
                }
                $page->setData(PageModel::schema_fields_TYPE, $pageType)
                    ->setData(PageModel::schema_fields_NAME, $config['name'])
                    ->setData(PageModel::schema_fields_TITLE, $config['title'])
                    ->setData(PageModel::schema_fields_CONTENT, '')
                    ->setData(PageModel::schema_fields_PARENT_ID, 0)
                    ->setData(PageModel::schema_fields_STYLE, $styleCode)
                    ->setData(PageModel::schema_fields_STYLE_SETTING, '{}')
                    ->setData(PageModel::schema_fields_LOCALES, json_encode([$defaultLocale]))
                    ->setData(PageModel::schema_fields_DEFAULT_LOCALE, $defaultLocale)
                    ->setData(PageModel::schema_fields_WEBSITE_ID, $websiteId)
                    ->setData(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
                
                // 如果不是首页，设置parent_id为首页
                if (!($config['is_home'] ?? false) && $homePageId) {
                    $page->setData(PageModel::schema_fields_PARENT_ID, $homePageId);
                }
                
                $page->save(true);
                
                // 确保页面已保存成功
                $savedPageId = $page->getId();
                if (empty($savedPageId)) {
                    throw new \Exception(__('页面保存失败：%{name}', ['name' => $config['name']]));
                }
                
                // 记录首页ID
                if ($config['is_home'] ?? false) {
                    $homePageId = $savedPageId;
                }
                
                // 应用该页面类型的默认布局与法律页默认内容，避免关于我们/联系我们/条款等页面空白
                $componentService = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\ComponentService::class);
                $defaultLayout = $componentService->getDefaultLayoutConfigForPageType($styleCode, $pageType);
                if ($defaultLayout && !empty($defaultLayout['layout_config']['content'])) {
                    $page->setData(PageModel::schema_fields_LAYOUT_CONFIG, json_encode($defaultLayout['layout_config'], \JSON_UNESCAPED_UNICODE));
                }
                $legalTypes = [
                    PageModel::TYPE_TERMS_OF_SERVICE,
                    PageModel::TYPE_PRIVACY_POLICY,
                    PageModel::TYPE_REFUND_POLICY,
                    PageModel::TYPE_COOKIE_POLICY,
                    PageModel::TYPE_SHIPPING_POLICY,
                ];
                if (in_array($pageType, $legalTypes, true)) {
                    $__legalType = $pageType;
                    $__legalTitle = $config['title'];
                    ob_start();
                    include BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/_shared/default-legal.phtml';
                    $defaultContent = ob_get_clean();
                    if ($defaultContent !== '' && $defaultContent !== false) {
                        $page->setData(PageModel::schema_fields_CONTENT, $defaultContent);
                    }
                }
                $page->save(true);
                
                // 创建URL重写
                $urlRewriteSuccess = $this->createOrUpdateUrlRewrite($page);
                
                $createdPages[] = [
                    'id' => $savedPageId,
                    'name' => $config['name'],
                    'handle' => $config['handle'],
                    'type' => $pageType,
                    'url_rewrite' => $urlRewriteSuccess,
                ];
            }
            
            $this->clearRouterAndPageBuilderCache();
            
            // 绑定 SEO 账户（如果提供了 seo_account_id）
            $seoAccountId = (int) $seoAccountId;
            if ($seoAccountId > 0 && $websiteId > 0) {
                try {
                    $eventsManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
                    // dispatch 的第二个参数必须是变量引用，不能直接传递数组字面量
                    $eventData = [
                        'data' => [
                            'website_id' => $websiteId,
                            'account_id' => $seoAccountId,
                            'is_auto_submit' => true,
                        ],
                    ];
                    $eventsManager->dispatch('Weline_Seo::domain::website_account_bind', $eventData);
                } catch (\Exception $e) {
                    // SEO 账户绑定失败不影响建站结果
                    w_log_error('[GuoLaiRen_PageBuilder] SEO account bind error: ' . $e->getMessage());
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('站点创建成功，共创建 %{count} 个页面', ['count' => count($createdPages)]),
                'pages' => $createdPages,
                'home_page_id' => $homePageId,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建失败：') . $e->getMessage(),
            ]);
        }
    }

    /**
     * 一键建站时使用的英文类型名（首字母大写），用于页面 name/title：类型名 - 网站名
     */
    private function getQuickSiteEnglishTypeNames(): array
    {
        return [
            PageModel::TYPE_ABOUT => 'About',
            PageModel::TYPE_CONTACT => 'Contact',
            PageModel::TYPE_PRIVACY_POLICY => 'Privacy Policy',
            PageModel::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
            PageModel::TYPE_REFUND_POLICY => 'Refund Policy',
            PageModel::TYPE_SHIPPING_POLICY => 'Shipping Policy',
            PageModel::TYPE_COOKIE_POLICY => 'Cookie Policy',
            PageModel::TYPE_BLOG_LIST => 'Blog List',
            PageModel::TYPE_BLOG => 'Blog Post',
            PageModel::TYPE_BLOG_CATEGORY => 'Blog Category',
        ];
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
            $styleModel->clear()->where(Style::schema_fields_CODE, $styleCode)->find()->fetch();
            
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
                            ->where(\GuoLaiRen\PageBuilder\Model\Page\LocalDescription::schema_fields_ID, $pageId)
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
     * 
     * 注意：handle 在同一 website_id 内必须唯一，不同 website_id 可以重复
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_check_handle', '检查句柄', '', '检查页面句柄是否重复')]
    public function getCheckHandle()
    {
        try {
            $handle = trim(rawurldecode((string)$this->request->getGet('handle', '')));
            $pageId = (int)$this->request->getGet('page_id', 0);
            // 获取 website_id：优先从请求参数获取，否则使用当前请求的网站ID
            $websiteId = $this->request->getGet('website_id');
            if ($websiteId === null || $websiteId === '') {
                $websiteId = \Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
            } else {
                $websiteId = (int)$websiteId;
            }
            
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
            
            // 查询数据库检查是否已存在（在同一 website_id 内）
            $existingPage = clone $this->pageModel;
            $existingPage->clear()
                ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PageModel::schema_fields_HANDLE, $handle)
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
                    // 被同网站的其他页面占用
                    return $this->fetchJson([
                        'success' => true,
                        'available' => false,
                        'message' => __('页面句柄已被使用，请使用其他句柄'),
                        'existing_page' => [
                            'id' => $existingPage->getId(),
                            'name' => $existingPage->getData(PageModel::schema_fields_NAME),
                            'title' => $existingPage->getData(PageModel::schema_fields_TITLE)
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
     * 通过 handle 获取页面信息（AJAX接口）
     * 用于可视化配置组件初始化
     * 
     * 注意：handle 在同一 website_id 内唯一，查询时需指定 website_id
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_get_page_by_handle', '通过句柄获取页面', '', '通过句柄获取页面信息')]
    public function getPageByHandle()
    {
        try {
            $handle = trim(rawurldecode((string)$this->request->getGet('handle', '')));
            // 获取 website_id：优先从请求参数获取，否则使用当前请求的网站ID
            $websiteId = $this->request->getGet('website_id');
            if ($websiteId === null || $websiteId === '') {
                $websiteId = \Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
            } else {
                $websiteId = (int)$websiteId;
            }
            
            if (empty($handle)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面句柄不能为空')
                ]);
            }
            
            // 查询页面（按 website_id + handle）
            $page = clone $this->pageModel;
            $page->clear()
                ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PageModel::schema_fields_HANDLE, $handle)
                ->find()
                ->fetch();
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }
            
            // 获取页面信息
            $pageId = $page->getId();
            $styleCode = $page->getData('style') ?: '';
            $defaultLocale = $page->getData('default_locale') ?: '';
            $locales = json_decode($page->getData('locales') ?? '', true) ?: [];
            
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'page_id' => $pageId,
                    'handle' => $handle,
                    'website_id' => $websiteId,
                    'style_code' => $styleCode,
                    'default_locale' => $defaultLocale,
                    'locales' => $locales,
                    'name' => $page->getData('name') ?: '',
                    'title' => $page->getData('title') ?: ''
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
     * 获取当前用户可用的站点列表（JSON，用于页面表单站点下拉刷新）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_create', '获取站点列表', '', '获取站点列表用于下拉', 'GuoLaiRen_PageBuilder::page_builder')]
    public function getWebsitesForSelect()
    {
        try {
            $list = $this->getAvailableWebsitesForCurrentUser();
            $items = [];
            foreach ($list as $row) {
                $wid = (int)($row['website_id'] ?? 0);
                if ($wid <= 0) {
                    continue;
                }
                $items[] = [
                    'website_id' => $wid,
                    'name' => $row['name'] ?? '',
                    'code' => $row['code'] ?? '',
                ];
            }
            return $this->fetchJson(['success' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'items' => [], 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * 预览页面（支持指定语言）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_preview', '预览页面', 'mdi mdi-eye', '预览页面')]
    public function preview()
    {
        $pageId = $this->request->getGet('id');
        $locale = $this->request->getGet('locale', State::getLang());
        
        if (!$pageId) {
            MessageManager::error(__('页面ID不能为空！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        // 加载页面
        $page = clone $this->pageModel;
        $page->clear()->load($pageId);
        
        if (!$page->getId()) {
            MessageManager::error(__('页面不存在！'));
            $this->redirect('*/backend/page/index');
            return;
        }
        
        // 获取前端预览URL（带语言参数和preview标记）
        $handle = $page->getData(PageModel::schema_fields_HANDLE);
        $pageType = $page->getData(PageModel::schema_fields_TYPE);
        $isHomePage = ($pageType === PageModel::TYPE_HOME);
        
        $websiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);

        // 首页类型且没有handle时，直接使用网站地址
        if ($isHomePage && empty($handle)) {
            $websiteUrl = '';
            if ($websiteId > 0) {
                try {
                    $websiteModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                    $website = clone $websiteModel;
                    $website->load($websiteId);
                    if ($website->getId()) {
                        $websiteUrl = $website->getData(\Weline\Websites\Model\Website::schema_fields_URL);
                    }
                } catch (\Exception $e) {
                    // 忽略错误
                }
            }
            
            $query = ['preview' => '1'];
            if ($websiteId > 0) {
                $query['website_id'] = (string)$websiteId;
            }
            if ($pageId > 0) {
                $query['page_id'] = (string)$pageId;
            }
            if (!empty($locale)) {
                $query['locale'] = (string)$locale;
            }

            if ($websiteUrl) {
                $frontendUrl = rtrim($websiteUrl, '/') . '?' . http_build_query($query);
            } else {
                // 回退到使用当前访问的网站地址（根域名+请求端口）
                $baseUrl = $this->request->getDisplayBaseUrl();
                $frontendUrl = $baseUrl . '?' . http_build_query($query);
            }
        } else {
            $params = ['handle' => $handle, 'locale' => $locale, 'preview' => '1'];
            // 真实预览带上 page_id，前端据此按 ID 加载页面，与可视化预览数据一致
            $params['page_id'] = $pageId;
            if ($websiteId > 0) {
                $params['website_id'] = $websiteId;
            }
            $frontendUrl = $this->request->getUrlBuilder()->getFrontendUrl(
                'pagebuilder/frontend/page/view',
                $params
            );
        }
        
        // 直接重定向到前端页面
        $this->request->getResponse()->redirect($frontendUrl);
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
            throw new \Weline\Framework\Http\ResponseTerminateException(400, 'Missing style code parameter');
        }
        
        try {
            // 查找样式
            $style = clone $this->styleModel;
            $style->clear()->where('code', $styleCode)->find()->fetch();
            
            if (!$style->getId()) {
                throw new \Weline\Framework\Http\ResponseTerminateException(404, 'Style not found');
            }
            
            // 获取预览图路径（与 getStyles 方法保持一致的查找逻辑）
            $previewImage = $style->getData(Style::schema_fields_PREVIEW_IMAGE);
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $styleCode);
            
            // 构建完整的文件路径（支持多个可能的位置）
            $filePath = null;
            $possiblePaths = [];
            
            // 1. 如果数据库有记录，优先使用
            if ($previewImage) {
                $possiblePaths[] = BP . 'pub/static/' . ltrim($previewImage, '/');
                $possiblePaths[] = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/' . $previewImage;
                $possiblePaths[] = BP . $previewImage;
            }
            
            // 2. 标准预览图目录（模板管理生成的预览图）
            $previewDir = BP . 'pub/static/pagebuilder/previews/';
            $possiblePaths[] = $previewDir . $safeCode . '.png';
            $possiblePaths[] = $previewDir . $safeCode . '.jpg';
            $possiblePaths[] = $previewDir . $safeCode . '.webp';
            
            // 3. 模板源目录中的预览图
            $templateBasePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
            $possiblePaths[] = $templateBasePath . 'preview.webp';
            $possiblePaths[] = $templateBasePath . 'preview.png';
            $possiblePaths[] = $templateBasePath . 'preview.jpg';
            
            foreach ($possiblePaths as $path) {
                if (file_exists($path) && is_file($path)) {
                    $filePath = $path;
                    break;
                }
            }
            
            if (!$filePath) {
                throw new \Weline\Framework\Http\ResponseTerminateException(404, 'Preview image file does not exist for style: ' . $styleCode);
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
            
            // 检查客户端缓存
            $ifModifiedSince = $this->request->getHeader('If-Modified-Since');
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            
            if (($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified) ||
                ($ifNoneMatch && trim($ifNoneMatch, '"') === $etag)) {
                throw new \Weline\Framework\Http\StaticFileException($filePath, $contentType, [
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                    'ETag' => '"' . $etag . '"',
                    'Cache-Control' => 'public, max-age=604800',
                ], true);
            }
            
            // 使用 StaticFileException 返回图片（不使用 exit）
            throw new \Weline\Framework\Http\StaticFileException($filePath, $contentType, [
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'ETag' => '"' . $etag . '"',
                'Cache-Control' => 'public, max-age=604800',
            ]);
            
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            // 让框架处理这些异常
            throw $e;
        } catch (\Exception $e) {
            throw new \Weline\Framework\Http\ResponseTerminateException(500, 'Error: ' . $e->getMessage());
        }
    }
    
    /**
     * 自动创建或更新页面的 URL 重写规则
     * @param PageModel $page 页面模型
     * @return bool 是否成功
     *
     * 注意：URL 重写按 website_id 隔离，同 website_id 下 (website_id, rewrite) 唯一。
     * 首页需保存两条：有 handle 时一条 handle 重写、一条空重写，否则根路径 "/" 查不到网站。
     */
    private function createOrUpdateUrlRewrite(PageModel $page): bool
    {
        try {
            $handle = $page->getData(PageModel::schema_fields_HANDLE);
            $pageId = $page->getId();
            $websiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
            $isHomePage = ($page->getData(PageModel::schema_fields_TYPE) ?? '') === PageModel::TYPE_HOME;

            if (empty($pageId)) {
                if (DEV) {
                    w_log_info("createOrUpdateUrlRewrite: Missing page ID.");
                }
                return false;
            }

            $originalPath = "pagebuilder/frontend/page/view?page_id={$pageId}";
            $rewritePath = !empty($handle) ? $handle : '';
            $urlIdentify = "pagebuilder_page_{$pageId}";

            /**@var UrlRewrite $urlRewriteModel */
            $urlRewriteModel = ObjectManager::getInstance(UrlRewrite::class);

            $this->saveOneUrlRewrite(
                $urlRewriteModel,
                $urlIdentify,
                "pagebuilder_page_{$pageId}",
                $websiteId,
                $originalPath,
                $rewritePath,
                true
            );

            // 首页且有 handle 时：再保存一条空重写，使根路径 "/" 能解析到本站首页
            if ($isHomePage && $rewritePath !== '') {
                $rootIdentify = "pagebuilder_page_{$pageId}_root";
                $this->saveOneUrlRewrite(
                    $urlRewriteModel,
                    $rootIdentify,
                    "pagebuilder_page_{$pageId}",
                    $websiteId,
                    $originalPath,
                    '',
                    false
                );
            }

            return true;
        } catch (\Exception $e) {
            if (DEV) {
                w_log_error("Failed to create URL rewrite for page [{$page->getId()}]: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 保存或更新单条 URL 重写（按 url_identify 查找，主记录可再按 url_id 回退）
     * @param bool $fallbackByUrlId 未按 url_identify 命中时是否按 url_id 再查（首页空重写条用 false）
     */
    private function saveOneUrlRewrite(
        UrlRewrite $urlRewriteModel,
        string $urlIdentify,
        string $urlId,
        int $websiteId,
        string $originalPath,
        string $rewritePath,
        bool $fallbackByUrlId = true
    ): void {
        $existingRewrite = clone $urlRewriteModel;
        $existingRewrite->clear()
            ->where(UrlRewrite::schema_fields_URL_IDENTIFY, $urlIdentify)
            ->find()
            ->fetch();

        if ($fallbackByUrlId && !$existingRewrite->getId()) {
            $existingRewrite->clear()
                ->where(UrlRewrite::schema_fields_URL_ID, $urlId)
                ->find()
                ->fetch();
        }

        if ($existingRewrite->getId()) {
            $existingRewrite
                ->setData(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(UrlRewrite::schema_fields_URL_ID, $urlId)
                ->setData(UrlRewrite::schema_fields_URL_IDENTIFY, $urlIdentify)
                ->setData(UrlRewrite::schema_fields_PATH, $originalPath)
                ->setData(UrlRewrite::schema_fields_REWRITE, $rewritePath)
                ->save();
        } else {
            $newRewrite = clone $urlRewriteModel;
            $newRewrite->clearData()
                ->setData(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(UrlRewrite::schema_fields_URL_ID, $urlId)
                ->setData(UrlRewrite::schema_fields_URL_IDENTIFY, $urlIdentify)
                ->setData(UrlRewrite::schema_fields_PATH, $originalPath)
                ->setData(UrlRewrite::schema_fields_REWRITE, $rewritePath)
                ->save(true);
        }
    }

    /**
     * 可视化配置文件上传（用于样式字段 file 类型）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_upload', '可视化字段文件上传', '', '上传文件到媒体目录', 'GuoLaiRen_PageBuilder::page_builder')]
    public function uploadAsset()
    {
        try {
            if (!isset($_FILES['file'])) {
                return $this->fetchJson(['success' => false, 'message' => '缺少文件参数']);
            }
            $file = $_FILES['file'];
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return $this->fetchJson(['success' => false, 'message' => '文件上传失败']);
            }

            $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMime, true)) {
                return $this->fetchJson(['success' => false, 'message' => '不支持的文件类型']);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: $this->guessExtByMime($mime);
            $baseDir = BP . 'pub/media/pagebuilder/uploads/';
            if (!is_dir($baseDir)) {
                @mkdir($baseDir, 0775, true);
            }
            $subDir = date('Y/m/d/');
            $targetDir = $baseDir . $subDir;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $filename = uniqid('pb_', true) . ($ext ? ('.' . strtolower($ext)) : '');
            $targetPath = $targetDir . $filename;
            if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                return $this->fetchJson(['success' => false, 'message' => '保存文件失败']);
            }
            $publicUrl = '/media/pagebuilder/uploads/' . $subDir . $filename;
            return $this->fetchJson(['success' => true, 'url' => $publicUrl]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 列出文件管理器中的资源（限制在 pub/media/page-build/ 下）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_list_assets', '列出页面资源', '', '列出页面资源')]
    public function listAssets()
    {
        try {
            $handle = trim(rawurldecode((string)($this->request->getParam('handle') ?? '')));
            $sub = trim((string)($this->request->getParam('sub') ?? ''));
            $websiteId = (int)($this->request->getParam('website_id') ?? 0);
            
            if ($handle === '') {
                throw new \Exception(__('缺少 handle'));
            }
            
            // 获取网站 URL（用于生成完整的图片地址）
            $websiteUrl = '';
            if ($websiteId > 0) {
                try {
                    $websiteModel = clone $this->websiteModel;
                    $websiteModel->clear()->load($websiteId);
                    if ($websiteModel->getId()) {
                        $websiteUrl = rtrim($websiteModel->getData(\Weline\Websites\Model\Website::schema_fields_URL) ?? '', '/');
                    }
                } catch (\Exception $e) {
                    // 忽略错误
                }
            }
            // 如果没有网站 URL，使用当前请求的展示用 base（根域名+端口）
            if (empty($websiteUrl)) {
                $websiteUrl = $this->request->getDisplayBaseUrl();
            }
            
            $baseDir = rtrim(BP . 'pub/media/page-build', '/');
            $root = $baseDir . '/' . $handle;
            if (!is_dir($root)) {
                @mkdir($root, 0777, true);
            }
            // 规范化子路径，禁止跳出 handle 根目录
            $relative = trim($sub, '/');
            $path = $root . ($relative ? ('/' . $relative) : '');
            $realRoot = realpath($root) ?: $root;
            $realPath = realpath($path) ?: $path;
            if (strpos($realPath, $realRoot) !== 0) {
                $realPath = $realRoot; // 回退到根
                $relative = '';
            }

            $items = [];
            if (is_dir($realPath)) {
                $dh = opendir($realPath);
                if ($dh) {
                    while (($file = readdir($dh)) !== false) {
                        if ($file === '.' || $file === '..') continue;
                        $full = $realPath . '/' . $file;
                        $isDir = is_dir($full);
                        // 复制地址使用完整的网站 URL
                        $relativePath = '/pub/media/page-build/' . $handle . ($relative ? '/' . $relative : '') . '/' . $file;
                        $items[] = [
                            'name' => $file,
                            'type' => $isDir ? 'dir' : 'file',
                            'size' => $isDir ? 0 : (filesize($full) ?: 0),
                            'mtime' => filemtime($full) ?: time(),
                            // 完整的 URL（用于复制）
                            'url' => $isDir ? '' : ($websiteUrl . $relativePath),
                            // 相对路径（用于预览）
                            'relative_url' => $isDir ? '' : $relativePath
                        ];
                    }
                    closedir($dh);
                }
            }

            usort($items, function ($a, $b) {
                if ($a['type'] === $b['type']) return strcmp($a['name'], $b['name']);
                return $a['type'] === 'dir' ? -1 : 1;
            });

            return $this->fetchJson([
                'success' => true,
                'handle' => $handle,
                'sub' => $relative,
                'items' => $items,
                'root' => '/media/page-build/' . $handle,
                'website_url' => $websiteUrl
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 上传文件到指定子目录（限制在 pub/media/page-build/{handle}/ 下）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_upload_asset_to', '上传页面资源(指定目录)', '', '上传页面资源(指定目录)')]
    public function uploadAssetTo()
    {
        try {
            $handle = trim(rawurldecode((string)($this->request->getParam('handle') ?? '')));
            $sub = trim((string)($this->request->getParam('sub') ?? ''));
            if ($handle === '') {
                throw new \Exception(__('缺少 handle'));
            }
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception(__('文件上传失败或未选择文件'));
            }
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (!in_array($ext, $allowed)) {
                throw new \Exception(__('只允许上传图片文件 (jpg, jpeg, png, gif, webp, svg)'));
            }

            $baseDir = rtrim(BP . 'pub/media/page-build', '/');
            $root = $baseDir . '/' . $handle;
            $relative = trim($sub, '/');
            $targetDir = $root . ($relative ? ('/' . $relative) : '');
            // 防跳出
            $realRoot = realpath($root) ?: $root;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
            $realTarget = realpath($targetDir) ?: $targetDir;
            if (strpos($realTarget, $realRoot) !== 0) {
                $realTarget = $realRoot;
            }

            $fileName = uniqid('pb_') . '.' . $ext;
            $filePath = $realTarget . '/' . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new \Exception(__('无法将文件移动到目标目录'));
            }

            $relativePath = '/pub/media/page-build/' . $handle . ($relative ? '/' . $relative : '') . '/' . $fileName;

            return $this->fetchJson([
                'success' => true,
                'url' => $relativePath,
                'path' => $relativePath
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function guessExtByMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    /**
     * 导出页面配置到Excel
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_export_config', '导出页面配置', '', '导出页面配置到Excel')]
    public function exportConfig()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            $styleCode = trim((string)$this->request->getGet('style_code'));
            
            if ($pageId <= 0 || empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('参数不完整')
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
            
            // 强制扫描样式模板
            Style::forceScan();
            
            // 获取样式配置
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(Style::schema_fields_CODE, $styleCode)->find()->fetch();
            
            if (!$styleModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('样式模板不存在')
                ]);
            }
            
            // 获取页面的布局配置（实际配置，而非模板默认配置）
            $layoutService = ObjectManager::getInstance(LayoutService::class);
            $layoutAssembler = ObjectManager::getInstance(LayoutAssembler::class);
            
            // 获取页面的完整布局配置（包含组件的实际配置值）
            $layoutConfig = $layoutService->getFullLayoutConfig($pageId);
            
            // 获取组件文件映射
            $componentFiles = $layoutAssembler->getComponentFilesMap($styleCode);
            
            // 使用PhpSpreadsheet生成Excel
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0); // 删除默认sheet
            
            // 需要导出的文件类型
            $fileTypes = ['header', 'content', 'footer'];
            
            // 设置表头样式（加粗、悬浮、垂直居中）
            $headerStyle = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E0E0E0']
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'horizontal' => Alignment::HORIZONTAL_LEFT
                ]
            ];
            
            // 设置默认单元格样式（垂直居中）
            $defaultStyle = [
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'horizontal' => Alignment::HORIZONTAL_LEFT
                ]
            ];
            
            foreach ($fileTypes as $fileType) {
                $sheet = new Worksheet($spreadsheet, ucfirst($fileType));
                $spreadsheet->addSheet($sheet);
                
                // 设置表头
                $sheet->setCellValue('A1', __('组件'));
                $sheet->setCellValue('B1', __('配置Key'));
                $sheet->setCellValue('C1', __('标签Label'));
                $sheet->setCellValue('D1', __('值（填写此处）'));
                $sheet->setCellValue('E1', __('提示Tip'));
                
                // 设置表头样式
                $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
                
                // 设置列宽
                $sheet->getColumnDimension('A')->setWidth(25);
                $sheet->getColumnDimension('B')->setWidth(30);
                $sheet->getColumnDimension('C')->setWidth(25);
                $sheet->getColumnDimension('D')->setWidth(40);
                $sheet->getColumnDimension('E')->setWidth(50);
                
                // 冻结首行
                $sheet->freezePane('A2');
                
                $row = 2;
                
                // 获取该区域的组件配置
                $regionConfig = $layoutConfig[$fileType] ?? null;
                
                if ($regionConfig === null) {
                    continue;
                }
                
                // 处理 header 和 footer（对象结构）
                if ($fileType === 'header' || $fileType === 'footer') {
                    $componentCode = $regionConfig['component'] ?? '';
                    $componentConfig = $regionConfig['config'] ?? [];
                    
                    if (!empty($componentCode)) {
                        $this->exportComponentConfig(
                            $sheet,
                            $componentCode,
                            $componentConfig,
                            $styleCode,
                            $componentFiles,
                            $layoutAssembler,
                            $row,
                            $defaultStyle
                        );
                    }
                } 
                // 处理 content（数组结构）
                elseif ($fileType === 'content' && is_array($regionConfig)) {
                    foreach ($regionConfig as $componentItem) {
                        $componentCode = $componentItem['component'] ?? $componentItem['code'] ?? '';
                        $componentConfig = $componentItem['config'] ?? [];
                        
                        if (!empty($componentCode)) {
                            $this->exportComponentConfig(
                                $sheet,
                                $componentCode,
                                $componentConfig,
                                $styleCode,
                                $componentFiles,
                                $layoutAssembler,
                                $row,
                                $defaultStyle
                            );
                        }
                    }
                }
            }
            
            // 如果没有数据，至少创建一个header sheet
            if ($spreadsheet->getSheetCount() === 0) {
                $sheet = new Worksheet($spreadsheet, 'Header');
                $spreadsheet->addSheet($sheet);
                $sheet->setCellValue('A1', __('组件'));
                $sheet->setCellValue('B1', __('配置Key'));
                $sheet->setCellValue('C1', __('标签Label'));
                $sheet->setCellValue('D1', __('值（填写此处）'));
                $sheet->setCellValue('E1', __('提示Tip'));
                $sheet->getStyle('A1:E1')->applyFromArray($headerStyle ?? []);
            }
            
            // 设置第一个sheet为活动sheet
            $spreadsheet->setActiveSheetIndex(0);
            
            // 输出Excel文件
            $writer = new Xlsx($spreadsheet);
            
            // 设置响应头
            $filename = sprintf(
                'page-config-%d-%s-%s.xlsx',
                $pageId,
                $styleCode,
                date('YmdHis')
            );
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            
            $writer->save('php://output');
            exit;
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 导出单个组件的配置到Excel
     * 
     * @param Worksheet $sheet Excel工作表
     * @param string $componentCode 组件代码
     * @param array $componentConfig 组件的实际配置值
     * @param string $styleCode 样式代码
     * @param array $componentFiles 组件文件映射
     * @param LayoutAssembler $layoutAssembler 布局组装器
     * @param int &$row 当前行号（引用传递，会修改）
     * @param array $defaultStyle 默认单元格样式
     */
    private function exportComponentConfig(
        Worksheet $sheet,
        string $componentCode,
        array $componentConfig,
        string $styleCode,
        array $componentFiles,
        LayoutAssembler $layoutAssembler,
        int &$row,
        array $defaultStyle
    ): void {
        // 获取组件文件
        $componentFile = $componentFiles[$componentCode] ?? null;
        $actualCode = $componentCode;
        
        // 特殊处理：tpmst-footer 映射到 footer-links
        if ($componentCode === 'tpmst-footer' || $componentCode === $styleCode . '-footer') {
            $actualCode = 'footer-links';
            $componentFile = $componentFiles[$actualCode] ?? null;
        }
        
        // 如果直接查找失败，尝试去掉模板前缀再查找
        if (!$componentFile && strpos($componentCode, $styleCode . '-') === 0) {
            $codeWithoutPrefix = substr($componentCode, strlen($styleCode) + 1);
            $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
            if ($componentFile) {
                $actualCode = $codeWithoutPrefix;
            }
        }
        
        if (!$componentFile) {
            return;
        }
        
        // 读取组件文件获取字段定义
        $componentPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/{$componentFile}";
        
        if (!file_exists($componentPath)) {
            return;
        }
        
        // 解析组件字段定义（使用反射调用私有方法）
        $reflection = new \ReflectionClass($layoutAssembler);
        $parseMethod = $reflection->getMethod('parseComponentFields');
        $parseMethod->setAccessible(true);
        $fields = $parseMethod->invoke($layoutAssembler, $componentPath);
        
        if (empty($fields)) {
            return;
        }
        
        // 遍历组件的所有配置字段
        foreach ($fields as $groupName => $groupData) {
            if (!isset($groupData['fields']) || !is_array($groupData['fields'])) {
                continue;
            }
            
            foreach ($groupData['fields'] as $fieldKey => $fieldConfig) {
                // 获取实际配置值（优先使用页面配置，否则使用默认值）
                $value = $componentConfig[$fieldKey] ?? $fieldConfig['default'] ?? '';
                
                // 处理数组类型的值（转换为JSON字符串）
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                
                // 获取tip（从description或help_content）
                $tip = '';
                if (!empty($fieldConfig['description'])) {
                    $tip = $fieldConfig['description'];
                } elseif (!empty($groupData['help_content'])) {
                    $tip = $groupData['help_content'];
                }
                
                // 构建配置Key（组件代码.字段Key）
                $configKey = $componentCode . '.' . $fieldKey;
                
                $sheet->setCellValue('A' . $row, $componentCode);
                $sheet->setCellValue('B' . $row, $configKey);
                $sheet->setCellValue('C' . $row, $fieldConfig['label'] ?? '');
                $sheet->setCellValue('D' . $row, $value);
                $sheet->setCellValue('E' . $row, $tip);
                
                // 设置所有列的垂直居中
                $sheet->getStyle('A' . $row . ':E' . $row)->applyFromArray($defaultStyle);
                
                // 设置第四列（值列）为红色边框、自动换行、自适应高度
                $valueCellStyle = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'FF0000']
                        ]
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'wrapText' => true
                    ]
                ];
                $sheet->getStyle('D' . $row)->applyFromArray($valueCellStyle);
                
                // 为value列设置自适应行高
                $valueStr = (string)$value;
                $colWidth = 40;
                $lineCount = 1;
                
                if (!empty($valueStr)) {
                    $displayWidth = 0;
                    $maxDisplayWidth = $colWidth * 7;
                    
                    $chars = mb_str_split($valueStr, 1, 'UTF-8');
                    foreach ($chars as $char) {
                        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
                            $displayWidth += 14;
                        } else {
                            $displayWidth += 7;
                        }
                    }
                    
                    $lineCount = max(1, ceil($displayWidth / max(1, $maxDisplayWidth)));
                    $newlineCount = substr_count($valueStr, "\n");
                    if ($newlineCount > 0) {
                        $lineCount += $newlineCount;
                    }
                }
                
                $rowHeight = max(15, min(200, 15 + ($lineCount - 1) * 15));
                $sheet->getRowDimension($row)->setRowHeight($rowHeight);
                
                $row++;
            }
        }
    }
    
    /**
     * 在布局配置中更新组件的配置值
     * 
     * @param array &$layoutConfig 布局配置（引用传递，会修改）
     * @param string $region 区域（header/content/footer）
     * @param string $componentCode 组件代码
     * @param string $fieldKey 字段Key
     * @param mixed $value 配置值
     * @return bool 是否成功更新
     */
    private function updateComponentConfigInLayout(
        array &$layoutConfig,
        string $region,
        string $componentCode,
        string $fieldKey,
        $value
    ): bool {
        if ($region === 'header' || $region === 'footer') {
            // header 和 footer 是对象结构
            $regionConfig = $layoutConfig[$region] ?? null;
            if ($regionConfig === null) {
                return false;
            }
            
            $regionComponentCode = $regionConfig['component'] ?? '';
            if ($regionComponentCode !== $componentCode) {
                return false;
            }
            
            // 更新配置
            if (!isset($layoutConfig[$region]['config'])) {
                $layoutConfig[$region]['config'] = [];
            }
            $layoutConfig[$region]['config'][$fieldKey] = $value;
            return true;
        } elseif ($region === 'content') {
            // content 是数组结构
            $contentComponents = $layoutConfig['content'] ?? [];
            if (!is_array($contentComponents)) {
                return false;
            }
            
            // 查找匹配的组件
            foreach ($contentComponents as $index => $componentItem) {
                $itemComponentCode = $componentItem['component'] ?? $componentItem['code'] ?? '';
                if ($itemComponentCode === $componentCode) {
                    // 更新配置
                    if (!isset($layoutConfig['content'][$index]['config'])) {
                        $layoutConfig['content'][$index]['config'] = [];
                    }
                    $layoutConfig['content'][$index]['config'][$fieldKey] = $value;
                    return true;
                }
            }
            
            return false;
        }
        
        return false;
    }

    /**
     * 从Excel导入页面配置
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_import_config', '导入页面配置', '', '从Excel导入页面配置')]
    public function importConfig()
    {
        try {
            $pageId = (int)$this->request->getPost('page_id');
            $styleCode = trim((string)$this->request->getPost('style_code'));
            
            // 获取上传的文件
            $uploadedFile = $this->request->getFile('config_file');
            
            // 如果没有获取到，尝试直接从 $_FILES 获取
            if (!$uploadedFile && isset($_FILES['config_file'])) {
                $uploadedFile = $_FILES['config_file'];
            }
            
            if ($pageId <= 0 || empty($styleCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('参数不完整')
                ]);
            }
            
            if (!$uploadedFile || !isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请选择要导入的Excel文件'),
                    'debug' => [
                        'has_file' => isset($_FILES['config_file']),
                        'files_keys' => array_keys($_FILES),
                        'uploaded_file' => $uploadedFile ? 'exists' : 'null'
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
            
            // 强制扫描样式模板以获取配置定义
            Style::forceScan();
            
            // 获取样式配置定义
            $styleModel = clone $this->styleModel;
            $styleModel->clear()->where(Style::schema_fields_CODE, $styleCode)->find()->fetch();
            
            if (!$styleModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('样式模板不存在')
                ]);
            }
            
            // 获取布局服务和布局配置
            $layoutService = ObjectManager::getInstance(LayoutService::class);
            $layout = $layoutService->getOrCreate($pageId);
            $layoutConfig = $layout->exportConfig();
            
            // 读取Excel文件
            $spreadsheet = IOFactory::load($uploadedFile['tmp_name']);
            
            $importCount = 0;
            $skipCount = 0;
            $errors = [];
            
            // 遍历所有sheet
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheetName = strtolower($worksheet->getTitle());
                
                // 只处理header、content、footer三个sheet
                if (!in_array($sheetName, ['header', 'content', 'footer'])) {
                    continue;
                }
                
                $highestRow = $worksheet->getHighestRow();
                
                // 从第二行开始读取（第一行是表头）
                // 新格式：A列=组件代码，B列=配置Key（组件代码.字段Key），D列=值
                for ($row = 2; $row <= $highestRow; $row++) {
                    $componentCode = trim((string)$worksheet->getCell('A' . $row)->getValue());
                    $configKey = trim((string)$worksheet->getCell('B' . $row)->getValue());
                    $value = trim((string)$worksheet->getCell('D' . $row)->getValue());
                    
                    // 跳过空行
                    if (empty($componentCode) && empty($configKey)) {
                        continue;
                    }
                    
                    // 解析配置Key（格式：组件代码.字段Key）
                    if (empty($configKey) || strpos($configKey, '.') === false) {
                        $skipCount++;
                        continue;
                    }
                    
                    $parts = explode('.', $configKey, 2);
                    if (count($parts) !== 2) {
                        $skipCount++;
                        continue;
                    }
                    
                    $parsedComponentCode = $parts[0];
                    $fieldKey = $parts[1];
                    
                    // 如果A列的组件代码为空，使用解析出的组件代码
                    if (empty($componentCode)) {
                        $componentCode = $parsedComponentCode;
                    }
                    
                    // 验证组件代码是否匹配
                    if ($componentCode !== $parsedComponentCode) {
                        $skipCount++;
                        $errors[] = sprintf(__('第%d行：组件代码不匹配（A列：%s，B列：%s）'), $row, $componentCode, $parsedComponentCode);
                        continue;
                    }
                    
                    // 处理值（尝试解析JSON）
                    $parsedValue = $value;
                    if (!empty($value)) {
                        $jsonValue = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $parsedValue = $jsonValue;
                        }
                    }
                    
                    // 更新布局配置
                    $updated = $this->updateComponentConfigInLayout(
                        $layoutConfig,
                        $sheetName,
                        $componentCode,
                        $fieldKey,
                        $parsedValue
                    );
                    
                    if ($updated) {
                        $importCount++;
                    } else {
                        $skipCount++;
                        $errors[] = sprintf(__('第%d行：未找到组件 %s 在 %s 区域'), $row, $componentCode, $sheetName);
                    }
                }
            }
            
            if ($importCount === 0) {
                $errorMsg = __('未找到有效的配置项');
                if (!empty($errors)) {
                    $errorMsg .= '。' . implode('；', array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $errorMsg .= '...';
                    }
                }
                return $this->fetchJson([
                    'success' => false,
                    'message' => $errorMsg
                ]);
            }
            
            // 保存布局配置
            $layout->importConfig($layoutConfig);
            $layout->save();
            
            $message = sprintf(
                __('成功导入 %d 个配置项，跳过 %d 个无效项'),
                $importCount,
                $skipCount
            );
            
            if (!empty($errors) && count($errors) <= 10) {
                $message .= '。' . implode('；', $errors);
            } elseif (!empty($errors)) {
                $message .= '。' . implode('；', array_slice($errors, 0, 10)) . '...';
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => $message,
                'imported_count' => $importCount,
                'skipped_count' => $skipCount
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 导出页面为静态HTML文件（ZIP格式，包含CSS和图片）
     * 导出主页面和所有子页面，以及所有静态资源
     * 注意：只有首页类型的顶级页面可以导出
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_export', '导出页面', 'mdi mdi-download', '导出页面为静态HTML文件')]
    public function export()
    {
        try {
            $pageId = (int)$this->request->getGet('id');
            
            if (!$pageId) {
                MessageManager::error(__('页面ID不能为空'));
                $this->redirect('*/backend/page/index');
                return;
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                MessageManager::error(__('页面不存在'));
                $this->redirect('*/backend/page/index');
                return;
            }
            
            // 检查：只有首页类型的顶级页面可以导出
            $pageType = $page->getData(PageModel::schema_fields_TYPE);
            $parentId = $page->getData(PageModel::schema_fields_PARENT_ID);
            
            if ($pageType !== PageModel::TYPE_HOME) {
                MessageManager::error(__('只有首页类型的页面可以导出'));
                $this->redirect('*/backend/page/index');
                return;
            }
            
            if (!empty($parentId) && $parentId != 0) {
                MessageManager::error(__('只有顶级页面可以导出'));
                $this->redirect('*/backend/page/index');
                return;
            }

            // 获取所有要导出的页面（主页面 + 所有子页面）
            $pagesToExport = $this->getAllPagesToExport($page);
            
            // 创建临时目录
            $tempDir = BP . 'var' . DS . 'temp' . DS . 'page_export' . DS . $pageId . '_' . time();
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $assetsDir = $tempDir . DS . 'assets';
            if (!is_dir($assetsDir)) {
                mkdir($assetsDir, 0755, true);
            }
            
            $cssDir = $assetsDir . DS . 'css';
            $jsDir = $assetsDir . DS . 'js';
            $imagesDir = $assetsDir . DS . 'images';
            $fontsDir = $assetsDir . DS . 'fonts';
            if (!is_dir($cssDir)) {
                mkdir($cssDir, 0755, true);
            }
            if (!is_dir($jsDir)) {
                mkdir($jsDir, 0755, true);
            }
            if (!is_dir($imagesDir)) {
                mkdir($imagesDir, 0755, true);
            }
            if (!is_dir($fontsDir)) {
                mkdir($fontsDir, 0755, true);
            }

            // 解析HTML，提取并下载资源（使用展示用 base，含请求端口）
            $baseUrl = $this->request->getDisplayBaseUrl();
            
            // 确保baseUrl是完整的URL（包含协议）
            if (!preg_match('/^https?:\/\//i', $baseUrl)) {
                $scheme = $this->request->isSecure() ? 'https' : 'http';
                $host = $this->request->getHttpHost();
                $baseUrl = $scheme . '://' . $host;
            }
            
            // 获取预览控制器来渲染完整HTML
            /** @var \GuoLaiRen\PageBuilder\Controller\Backend\Preview $previewController */
            $previewController = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Controller\Backend\Preview::class);
            
            // 获取当前语言
            $locale = $this->request->getGet('locale') ?: State::getLang() ?: 'zh_Hans_CN';
            
            // 构建页面映射（用于处理页面链接）
            $pageMap = [];
            foreach ($pagesToExport as $exportPage) {
                $pageMap[$exportPage->getId()] = $exportPage->getData('handle');
            }
            
            // 处理每个页面
            foreach ($pagesToExport as $exportPage) {
                // 设置请求参数以渲染完整页面
                $previewController->request->setGet('page_id', $exportPage->getId());
                $previewController->request->setGet('locale', $locale);
                
                // 捕获输出
                ob_start();
                $previewController->full();
                $html = ob_get_clean();
                
                if (empty($html)) {
                    continue; // 跳过无法生成的页面
                }
                
                // 处理HTML资源
                $html = $this->processHtmlResources($html, $baseUrl, $cssDir, $jsDir, $imagesDir, $fontsDir, $tempDir);
                
                // 先使用正则表达式快速替换常见的链接格式
                $html = $this->processPageLinksWithRegex($html, $pageMap, $exportPage);
                
                // 然后使用DOMDocument进行精确处理
                $html = $this->processPageLinks($html, $pageMap, $exportPage);
                
                // 确定HTML文件名
                if ($exportPage->getId() == $pageId) {
                    // 主页面保存为 index.html
                    $htmlFile = $tempDir . DS . 'index.html';
                } else {
                    // 子页面根据handle保存
                    $handle = $exportPage->getData('handle');
                    $htmlFile = $tempDir . DS . $handle . '.html';
                }
                
                file_put_contents($htmlFile, $html);
            }
            
            // 创建ZIP文件
            $zipFileName = 'page_' . $page->getData('handle') . '_' . date('YmdHis') . '.zip';
            $zipPath = BP . 'var' . DS . 'temp' . DS . $zipFileName;
            
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception(__('无法创建ZIP文件'));
            }
            
            // 添加文件到ZIP
            $this->addDirectoryToZip($zip, $tempDir, '');
            
            $zip->close();
            
            // 检查ZIP文件是否存在
            if (!file_exists($zipPath) || !is_file($zipPath)) {
                throw new \Exception(__('ZIP文件创建失败'));
            }
            
            $zipSize = filesize($zipPath);
            if ($zipSize === false || $zipSize == 0) {
                throw new \Exception(__('ZIP文件为空'));
            }
            
            // 清理临时目录（在输出文件之前清理，避免占用空间）
            $this->removeDirectory($tempDir);
            
            // 清除所有输出缓冲，确保只输出ZIP文件
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // 输出ZIP文件
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . $zipSize);
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            
            // 使用readfile输出文件
            readfile($zipPath);
            // 删除临时ZIP文件
            @unlink($zipPath);
            
            exit;
            
        } catch (\Exception $e) {
            // 如果已经发送了HTTP头，不能重定向，直接输出错误
            if (headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo __('导出失败：%{message}', ['message' => $e->getMessage()]);
                exit;
            }
            
            MessageManager::error(__('导出失败：%{message}', ['message' => $e->getMessage()]));
            $this->redirect('*/backend/page/index');
        }
    }
    
    /**
     * 递归获取所有要导出的页面（主页面 + 所有子页面）
     * 
     * @param PageModel $page 主页面
     * @return array 所有要导出的页面数组
     */
    private function getAllPagesToExport(PageModel $page): array
    {
        $pages = [$page];
        
        // 递归获取所有子页面
        $childPages = $page->getChildPages();
        foreach ($childPages as $childPage) {
            $pages = array_merge($pages, $this->getAllPagesToExport($childPage));
        }
        
        return $pages;
    }
    
    /**
     * 使用正则表达式快速处理常见的页面链接格式
     * 
     * @param string $html HTML内容
     * @param array $pageMap 页面映射数组 [page_id => handle]
     * @param PageModel $currentPage 当前页面
     * @return string 处理后的HTML
     */
    private function processPageLinksWithRegex(string $html, array $pageMap, PageModel $currentPage): string
    {
        $currentHandle = $currentPage->getData('handle');
        
        // 构建所有handle的列表和反向映射
        $allHandles = array_values($pageMap);
        $handleToPageIdMap = [];
        foreach ($pageMap as $pageId => $handle) {
            $handleToPageIdMap[$handle] = $pageId;
        }
        
        // 先处理包含页面handle的fragment链接（如 index.html?preview=1#about -> about.html）
        foreach ($allHandles as $handle) {
            if ($handle === $currentHandle) {
                continue; // 跳过当前页面
            }
            
            // 处理 index.html?preview=1#handle 格式 -> handle.html
            $html = preg_replace(
                '/(href=["\'])index\.html\?[^#"\']*#' . preg_quote($handle, '/') . '(["\'])/i',
                '$1' . $handle . '.html$2',
                $html
            );
            
            // 处理 ?preview=1#handle 格式 -> handle.html
            $html = preg_replace(
                '/(href=["\'])\?[^#"\']*#' . preg_quote($handle, '/') . '(["\'])/i',
                '$1' . $handle . '.html$2',
                $html
            );
        }
        
        // 处理 index.html?preview=1#xxx 格式 -> index.html#xxx（如果xxx不是页面handle）
        $html = preg_replace(
            '/(href=["\'])index\.html\?[^#"\']*(#([^"\']*))?(["\'])/i',
            '$1index.html$2$3',
            $html
        );
        
        // 处理 ?preview=1#xxx 格式 -> index.html#xxx（如果xxx不是页面handle）
        $html = preg_replace(
            '/(href=["\'])\?[^#"\']*(#([^"\']*))?(["\'])/i',
            '$1index.html$2$3',
            $html
        );
        
        // 处理每个子页面的链接格式
        foreach ($allHandles as $handle) {
            if ($handle === $currentHandle) {
                continue; // 跳过当前页面
            }
            
            // 处理 /handle?preview=1#xxx 或 handle?preview=1#xxx 格式 -> handle.html#xxx
            $html = preg_replace(
                '/(href=["\'])\/?' . preg_quote($handle, '/') . '(\.html)?\?[^#"\']*(#([^"\']*))?(["\'])/i',
                '$1' . $handle . '.html$4$5',
                $html
            );
            
            // 处理 handle.html?preview=1#xxx 格式 -> handle.html#xxx
            $html = preg_replace(
                '/(href=["\'])(' . preg_quote($handle, '/') . '\.html)\?[^#"\']*(#([^"\']*))?(["\'])/i',
                '$1$2$4$5',
                $html
            );
        }
        
        return $html;
    }
    
    /**
     * 处理HTML中的页面链接，将页面链接转换为相对路径
     * 
     * @param string $html HTML内容
     * @param array $pageMap 页面映射数组 [page_id => handle]
     * @param PageModel $currentPage 当前页面
     * @return string 处理后的HTML
     */
    private function processPageLinks(string $html, array $pageMap, PageModel $currentPage): string
    {
        // 使用DOMDocument解析HTML
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // 获取基础URL用于解析链接（展示用 base，含请求端口）
        $baseUrl = $this->request->getDisplayBaseUrl();
        if (!preg_match('/^https?:\/\//i', $baseUrl)) {
            $scheme = $this->request->isSecure() ? 'https' : 'http';
            $host = $this->request->getHttpHost();
            $baseUrl = $scheme . '://' . $host;
        }
        
        // 构建所有handle的反向映射（handle => page_id），用于从handle查找page_id
        $handleToPageIdMap = [];
        foreach ($pageMap as $pageId => $handle) {
            $handleToPageIdMap[$handle] = $pageId;
        }
        
        $currentHandle = $currentPage->getData('handle');
        
        // 处理所有链接
        $linkTags = $dom->getElementsByTagName('a');
        foreach ($linkTags as $link) {
            $href = $link->getAttribute('href');
            if (empty($href)) {
                continue;
            }
            
            // 跳过纯锚点链接（#xxx），保留原样
            if (strpos($href, '#') === 0 && !preg_match('/^#\?/i', $href)) {
                continue;
            }
            
            // 跳过mailto、tel、javascript等协议
            if (preg_match('/^(mailto:|tel:|javascript:)/i', $href)) {
                continue;
            }
            
            // 解析URL
            $parsedUrl = parse_url($href);
            if ($parsedUrl === false) {
                continue;
            }
            
            // 提取锚点（保留锚点）
            $fragment = $parsedUrl['fragment'] ?? '';
            
            // 检查fragment是否是有效的页面handle（用于单页应用导航）
            $fragmentAsHandle = null;
            if (!empty($fragment) && isset($handleToPageIdMap[$fragment])) {
                $fragmentAsHandle = $fragment;
            }
            
            // 检查是否是外部链接
            $isExternal = false;
            if (isset($parsedUrl['host'])) {
                $urlHost = $parsedUrl['host'];
                $baseHost = parse_url($baseUrl, PHP_URL_HOST);
                if ($urlHost !== $baseHost) {
                    $isExternal = true;
                }
            }
            
            // 如果是外部链接，跳过
            if ($isExternal) {
                continue;
            }
            
            // 检查是否是页面链接
            $pageId = null;
            $handle = null;
            $queryParams = [];
            
            // 从查询参数中获取page_id或handle
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                if (isset($queryParams['page_id'])) {
                    $pageId = (int)$queryParams['page_id'];
                } elseif (isset($queryParams['handle'])) {
                    $handle = $queryParams['handle'];
                }
            }
            
            // 从路径中提取handle
            $path = $parsedUrl['path'] ?? '';
            
            // 移除开头的斜杠
            $path = ltrim($path, '/');
            
            // 情况1：路径是 pagebuilder/frontend/page/view，从查询参数获取handle
            if (preg_match('/^pagebuilder\/frontend\/page\/view/i', $path)) {
                if (isset($queryParams['handle'])) {
                    $handle = $queryParams['handle'];
                }
            }
            // 情况2：路径直接是 handle 格式（URL重写后的格式，如 /about 或 /about.html）
            elseif (!empty($path)) {
                // 移除.html扩展名（如果存在）
                $path = preg_replace('/\.html$/i', '', $path);
                // 移除index（如果存在）
                $path = preg_replace('/^index$/i', '', $path);
                
                if (!empty($path)) {
                    // 检查是否是已知的handle
                    if (isset($handleToPageIdMap[$path])) {
                        $handle = $path;
                    } else {
                        // 尝试从路径中提取可能的handle（处理多级路径）
                        $pathParts = explode('/', $path);
                        $possibleHandle = $pathParts[0];
                        if (isset($handleToPageIdMap[$possibleHandle])) {
                            $handle = $possibleHandle;
                        }
                    }
                }
            }
            
            // 如果找到了page_id，查找对应的handle
            if ($pageId && isset($pageMap[$pageId])) {
                $handle = $pageMap[$pageId];
            }
            
            // 如果路径为空或只有查询参数，可能是当前页面的链接
            if (empty($path) && empty($handle) && empty($pageId)) {
                // 如果有handle参数，使用它
                if (isset($queryParams['handle'])) {
                    $handle = $queryParams['handle'];
                } elseif ($fragmentAsHandle) {
                    // 如果fragment是有效的页面handle，使用它
                    $handle = $fragmentAsHandle;
                    $fragment = ''; // 清除fragment，因为它是页面标识符
                } else {
                    // 否则假设是当前页面
                    $handle = $currentHandle;
                }
            }
            
            // 特殊处理：如果fragment是有效的页面handle，且没有找到其他handle，使用fragment作为页面链接
            if (empty($handle) && $fragmentAsHandle) {
                $handle = $fragmentAsHandle;
                $fragment = ''; // 清除fragment，因为它是页面标识符
            }
            
            // 如果找到了handle，转换为相对路径
            if ($handle && isset($handleToPageIdMap[$handle])) {
                $newHref = '';
                if ($handle === $currentHandle) {
                    // 当前页面，链接到自身（index.html）
                    $newHref = 'index.html';
                } else {
                    // 其他页面，链接到对应的HTML文件
                    $newHref = $handle . '.html';
                }
                
                // 如果有锚点（且不是页面handle），添加到链接末尾
                if (!empty($fragment) && !isset($handleToPageIdMap[$fragment])) {
                    $newHref .= '#' . $fragment;
                }
                
                $link->setAttribute('href', $newHref);
            } elseif (empty($path) && !empty($fragment)) {
                // 如果fragment是有效的页面handle，转换为页面链接
                if ($fragmentAsHandle) {
                    $link->setAttribute('href', $fragmentAsHandle . '.html');
                } else {
                    // 如果是当前页面的锚点链接（如 #section），保留锚点但移除查询参数
                    if (empty($handle) || $handle === $currentHandle) {
                        $link->setAttribute('href', 'index.html#' . $fragment);
                    } else {
                        $link->setAttribute('href', $handle . '.html#' . $fragment);
                    }
                }
            } elseif (empty($path) && empty($fragment) && !empty($queryParams)) {
                // 如果只有查询参数没有路径和锚点，可能是当前页面的链接，移除查询参数
                if (isset($queryParams['handle']) && isset($handleToPageIdMap[$queryParams['handle']])) {
                    $link->setAttribute('href', $queryParams['handle'] . '.html');
                } else {
                    $link->setAttribute('href', 'index.html');
                }
            }
        }
        
        // 返回处理后的HTML
        return $dom->saveHTML();
    }

    /**
     * 处理HTML中的资源（CSS、JS、图片），下载并转换为相对路径
     */
    private function processHtmlResources(string $html, string $baseUrl, string $cssDir, string $jsDir, string $imagesDir, string $fontsDir, string $baseDir): string
    {
        // 使用DOMDocument解析HTML
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // 处理CSS链接
        $linkTags = $dom->getElementsByTagName('link');
        $cssFiles = [];
        foreach ($linkTags as $link) {
            $rel = $link->getAttribute('rel');
            $href = $link->getAttribute('href');
            
            if ($rel === 'stylesheet' && !empty($href)) {
                // 先尝试直接获取本地文件路径（处理框架静态资源）
                $localPath = $this->getLocalFilePath($href);
                $cssUrl = $localPath ? null : $this->resolveUrl($href, $baseUrl);
                
                if ($localPath || $cssUrl) {
                    $cssContent = $this->downloadResource($localPath ? $href : $cssUrl);
                    if ($cssContent !== false) {
                        $sourcePath = $localPath ?: $cssUrl;
                        $cssFileName = 'style_' . md5($sourcePath) . '.css';
                        $cssFilePath = $cssDir . DS . $cssFileName;
                        file_put_contents($cssFilePath, $cssContent);
                        
                        // 处理CSS中的图片和字体引用
                        // 使用CSS文件的URL作为基准URL，以便正确解析CSS中的相对路径
                        $cssBaseUrl = $cssUrl ?: $this->resolveUrl($href, $baseUrl);
                        $cssContent = $this->processCssResources($cssContent, $cssBaseUrl, $imagesDir, $fontsDir, $baseDir);
                        file_put_contents($cssFilePath, $cssContent);
                        
                        // 更新HTML中的链接（使用正斜杠，因为这是URL路径）
                        $link->setAttribute('href', 'assets/css/' . $cssFileName);
                        $cssFiles[] = $cssFileName;
                    }
                }
            }
        }
        
        // 处理内联style标签
        $styleTags = $dom->getElementsByTagName('style');
        foreach ($styleTags as $style) {
            $cssContent = $style->nodeValue;
            $cssContent = $this->processCssResources($cssContent, $baseUrl, $imagesDir, $fontsDir, $baseDir);
            $style->nodeValue = $cssContent;
        }
        
        // 处理picture标签中的source元素的srcset属性
        $sourceTags = $dom->getElementsByTagName('source');
        foreach ($sourceTags as $source) {
            // 处理srcset属性
            $srcset = $source->getAttribute('srcset');
            if (!empty($srcset)) {
                $newSrcset = $this->processSrcset($srcset, $baseUrl, $imagesDir);
                if ($newSrcset !== $srcset) {
                    $source->setAttribute('srcset', $newSrcset);
                }
            }
            
            // 处理data-srcset属性（懒加载）
            $dataSrcset = $source->getAttribute('data-srcset');
            if (!empty($dataSrcset)) {
                $newDataSrcset = $this->processSrcset($dataSrcset, $baseUrl, $imagesDir);
                if ($newDataSrcset !== $dataSrcset) {
                    $source->setAttribute('data-srcset', $newDataSrcset);
                }
            }
        }
        
        // 处理图片
        $imgTags = $dom->getElementsByTagName('img');
        foreach ($imgTags as $img) {
            $src = $img->getAttribute('src');
            if (!empty($src)) {
                // 先尝试直接作为本地文件路径处理
                $localPath = $this->getLocalFilePath($src);
                if ($localPath) {
                    $imgContent = @file_get_contents($localPath);
                    if ($imgContent !== false) {
                        $imgFileName = 'img_' . md5($localPath) . '.' . $this->getFileExtension($src);
                        $imgFilePath = $imagesDir . DS . $imgFileName;
                        file_put_contents($imgFilePath, $imgContent);
                        
                        // 更新HTML中的图片路径（使用正斜杠，因为这是URL路径）
                        $img->setAttribute('src', 'assets/images/' . $imgFileName);
                        continue;
                    }
                }
                
                // 如果不是本地文件，尝试通过URL下载
                $imgUrl = $this->resolveUrl($src, $baseUrl);
                if ($imgUrl) {
                    $imgContent = $this->downloadResource($imgUrl);
                    if ($imgContent !== false) {
                        $imgFileName = 'img_' . md5($imgUrl) . '.' . $this->getFileExtension($imgUrl);
                        $imgFilePath = $imagesDir . DS . $imgFileName;
                        file_put_contents($imgFilePath, $imgContent);
                        
                        // 更新HTML中的图片路径（使用正斜杠，因为这是URL路径）
                        $img->setAttribute('src', 'assets/images/' . $imgFileName);
                    }
                }
            }
            
            // 处理img标签的srcset属性
            $srcset = $img->getAttribute('srcset');
            if (!empty($srcset)) {
                $newSrcset = $this->processSrcset($srcset, $baseUrl, $imagesDir);
                if ($newSrcset !== $srcset) {
                    $img->setAttribute('srcset', $newSrcset);
                }
            }
            
            // 处理data-srcset属性（懒加载）
            $dataSrcset = $img->getAttribute('data-srcset');
            if (!empty($dataSrcset)) {
                $newDataSrcset = $this->processSrcset($dataSrcset, $baseUrl, $imagesDir);
                if ($newDataSrcset !== $dataSrcset) {
                    $img->setAttribute('data-srcset', $newDataSrcset);
                }
            }
        }
        
        // 处理JavaScript文件
        $scriptTags = $dom->getElementsByTagName('script');
        foreach ($scriptTags as $script) {
            $src = $script->getAttribute('src');
            if (!empty($src)) {
                // 先尝试直接获取本地文件路径（处理框架静态资源）
                $localPath = $this->getLocalFilePath($src);
                $jsUrl = $localPath ? null : $this->resolveUrl($src, $baseUrl);
                
                if ($localPath || $jsUrl) {
                    $jsContent = $this->downloadResource($localPath ? $src : $jsUrl);
                    if ($jsContent !== false) {
                        $sourcePath = $localPath ?: $jsUrl;
                        $jsFileName = 'script_' . md5($sourcePath) . '.js';
                        $jsFilePath = $jsDir . DS . $jsFileName;
                        file_put_contents($jsFilePath, $jsContent);
                        
                        // 更新HTML中的脚本路径（使用正斜杠，因为这是URL路径）
                        $script->setAttribute('src', 'assets/js/' . $jsFileName);
                    }
                }
            }
        }
        
        // 处理背景图片和其他资源（在style属性中）
        $allElements = $dom->getElementsByTagName('*');
        foreach ($allElements as $element) {
            $styleAttr = $element->getAttribute('style');
            if (!empty($styleAttr)) {
                // 处理所有url()引用（包括背景图片、背景渐变等）
                $newStyle = preg_replace_callback('/url\(["\']?([^"\']+)["\']?\)/i', function($matches) use ($baseUrl, $imagesDir) {
                    $url = trim($matches[1]);
                    
                    // 跳过data URI
                    if (strpos($url, 'data:') === 0) {
                        return $matches[0];
                    }
                    
                    // 处理绝对URL（同域名资源）
                    if (preg_match('/^https?:\/\//i', $url)) {
                        $urlHost = parse_url($url, PHP_URL_HOST);
                        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
                        if ($urlHost === $baseHost) {
                            $bgContent = $this->downloadResource($url);
                            if ($bgContent !== false) {
                                $bgFileName = 'bg_' . md5($url) . '.' . $this->getFileExtension($url);
                                $bgFilePath = $imagesDir . DS . $bgFileName;
                                file_put_contents($bgFilePath, $bgContent);
                                return 'url(assets/images/' . $bgFileName . ')';
                            }
                        }
                        return $matches[0];
                    }
                    
                    // 处理相对路径
                    $bgUrl = $this->resolveUrl($url, $baseUrl);
                    if ($bgUrl) {
                        $bgContent = $this->downloadResource($bgUrl);
                        if ($bgContent !== false) {
                            $bgFileName = 'bg_' . md5($bgUrl) . '.' . $this->getFileExtension($bgUrl);
                            $bgFilePath = $imagesDir . DS . $bgFileName;
                            file_put_contents($bgFilePath, $bgContent);
                            return 'url(assets/images/' . $bgFileName . ')';
                        }
                    }
                    
                    return $matches[0];
                }, $styleAttr);
                
                if ($newStyle !== $styleAttr) {
                    $element->setAttribute('style', $newStyle);
                }
            }
        }
        
        // 返回处理后的HTML
        return $dom->saveHTML();
    }

    /**
     * 处理srcset属性中的图片URL
     * srcset格式可能是：
     * - 单个URL: /path/to/image.webp
     * - 多个URL（逗号分隔）: /path/to/image1.webp 1x, /path/to/image2.webp 2x
     * - 带宽度描述符: /path/to/image.webp 800w
     */
    private function processSrcset(string $srcset, string $baseUrl, string $imagesDir): string
    {
        if (empty($srcset)) {
            return $srcset;
        }
        
        // 分割srcset字符串（按逗号分割，但保留描述符）
        // srcset格式: "url1 1x, url2 2x, url3 800w"
        $parts = preg_split('/\s*,\s*/', $srcset);
        $processedParts = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            // 提取URL和描述符（如 "1x", "2x", "800w"）
            // 匹配格式: "url 描述符" 或 "url"
            if (preg_match('/^(.+?)(?:\s+([\d.]+[xw]))?$/i', $part, $matches)) {
                $url = trim($matches[1]);
                $descriptor = isset($matches[2]) ? ' ' . $matches[2] : '';
                
                // 跳过data URI
                if (strpos($url, 'data:') === 0) {
                    $processedParts[] = $part;
                    continue;
                }
                
                // 先尝试直接作为本地文件路径处理
                $localPath = $this->getLocalFilePath($url);
                if ($localPath && file_exists($localPath)) {
                    $imgContent = @file_get_contents($localPath);
                    if ($imgContent !== false) {
                        $imgFileName = 'img_' . md5($localPath) . '.' . $this->getFileExtension($url);
                        $imgFilePath = $imagesDir . DS . $imgFileName;
                        file_put_contents($imgFilePath, $imgContent);
                        $processedParts[] = 'assets/images/' . $imgFileName . $descriptor;
                        continue;
                    }
                }
                
                // 如果不是本地文件，尝试通过URL下载
                $imgUrl = $this->resolveUrl($url, $baseUrl);
                if ($imgUrl) {
                    $imgContent = $this->downloadResource($imgUrl);
                    if ($imgContent !== false) {
                        $imgFileName = 'img_' . md5($imgUrl) . '.' . $this->getFileExtension($imgUrl);
                        $imgFilePath = $imagesDir . DS . $imgFileName;
                        file_put_contents($imgFilePath, $imgContent);
                        $processedParts[] = 'assets/images/' . $imgFileName . $descriptor;
                        continue;
                    }
                }
                
                // 如果下载失败，保持原样
                $processedParts[] = $part;
            } else {
                // 无法解析，保持原样
                $processedParts[] = $part;
            }
        }
        
        return implode(', ', $processedParts);
    }

    /**
     * 处理CSS中的资源引用（包括图片、字体等）
     */
    private function processCssResources(string $css, string $baseUrl, string $imagesDir, string $fontsDir, string $baseDir): string
    {
        // 处理CSS中的url()引用（包括图片、字体、图标等所有资源）
        $css = preg_replace_callback('/url\(["\']?([^"\']+)["\']?\)/i', function($matches) use ($baseUrl, $imagesDir, $fontsDir, $baseDir) {
            $url = trim($matches[1]);
            
            // 跳过data URI
            if (strpos($url, 'data:') === 0) {
                return $matches[0];
            }
            
            // 判断是否是字体文件
            $isFont = preg_match('/\.(woff|woff2|ttf|eot|otf|svg)$/i', $url);
            $targetDir = $isFont ? $fontsDir : $imagesDir;
            $relativePath = $isFont ? '../fonts/' : '../images/';
            
            // 先尝试直接作为本地文件路径处理（处理框架静态资源）
            $localPath = $this->getLocalFilePath($url);
            if ($localPath && file_exists($localPath)) {
                $resourceContent = @file_get_contents($localPath);
                if ($resourceContent !== false) {
                    $fileName = ($isFont ? 'font_' : 'css_') . md5($localPath) . '.' . $this->getFileExtension($url);
                    $filePath = $targetDir . DS . $fileName;
                    file_put_contents($filePath, $resourceContent);
                    return 'url(' . $relativePath . $fileName . ')';
                }
            }
            
            // 处理绝对URL（同域名资源）
            if (preg_match('/^https?:\/\//i', $url)) {
                $urlHost = parse_url($url, PHP_URL_HOST);
                $baseHost = parse_url($baseUrl, PHP_URL_HOST);
                if ($urlHost === $baseHost) {
                    // 同域名资源，下载并替换
                    $resourceContent = $this->downloadResource($url);
                    if ($resourceContent !== false) {
                        $fileName = ($isFont ? 'font_' : 'css_') . md5($url) . '.' . $this->getFileExtension($url);
                        $filePath = $targetDir . DS . $fileName;
                        file_put_contents($filePath, $resourceContent);
                        return 'url(' . $relativePath . $fileName . ')';
                    }
                }
                // 外部资源，保持原样
                return $matches[0];
            }
            
            // 处理相对路径（相对于CSS文件的位置）
            $resourceUrl = $this->resolveUrl($url, $baseUrl);
            if ($resourceUrl) {
                $resourceContent = $this->downloadResource($resourceUrl);
                if ($resourceContent !== false) {
                    $fileName = ($isFont ? 'font_' : 'css_') . md5($resourceUrl) . '.' . $this->getFileExtension($resourceUrl);
                    $filePath = $targetDir . DS . $fileName;
                    file_put_contents($filePath, $resourceContent);
                    
                    return 'url(' . $relativePath . $fileName . ')';
                }
            }
            
            // 如果以上都失败，尝试直接作为本地文件路径（处理绝对路径如 /assets/fonts/font.woff2）
            if (strpos($url, '/') === 0) {
                $localPath = $this->getLocalFilePath($url);
                if ($localPath && file_exists($localPath)) {
                    $resourceContent = @file_get_contents($localPath);
                    if ($resourceContent !== false) {
                        $fileName = ($isFont ? 'font_' : 'css_') . md5($localPath) . '.' . $this->getFileExtension($url);
                        $filePath = $targetDir . DS . $fileName;
                        file_put_contents($filePath, $resourceContent);
                        return 'url(' . $relativePath . $fileName . ')';
                    }
                }
            }
            
            return $matches[0];
        }, $css);
        
        return $css;
    }

    /**
     * 解析URL（相对路径转绝对路径）
     */
    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        // 如果是绝对URL，直接返回
        if (preg_match('/^https?:\/\//i', $url)) {
            // 只处理同域名的资源
            $urlHost = parse_url($url, PHP_URL_HOST);
            $baseHost = parse_url($baseUrl, PHP_URL_HOST);
            if ($urlHost === $baseHost) {
                return $url;
            }
            return null;
        }
        
        // 如果是data URI，返回null
        if (strpos($url, 'data:') === 0) {
            return null;
        }
        
        // 如果是以 / 开头的绝对路径（如 /Weline/Admin/view/statics/... 或 /static/...）
        // 直接基于baseUrl的域名和端口构建完整URL
        if (strpos($url, '/') === 0) {
            $parsedBaseUrl = parse_url($baseUrl);
            $scheme = $parsedBaseUrl['scheme'] ?? 'http';
            $host = $parsedBaseUrl['host'] ?? '';
            $port = isset($parsedBaseUrl['port']) ? ':' . $parsedBaseUrl['port'] : '';
            
            if (empty($host)) {
                return null;
            }
            
            return $scheme . '://' . $host . $port . $url;
        }
        
        // 处理相对路径
        $basePath = parse_url($baseUrl, PHP_URL_PATH);
        if ($basePath === null || $basePath === false) {
            $basePath = '/';
        }
        
        $baseDir = dirname($basePath);
        if ($baseDir === '.' || $baseDir === '\\') {
            $baseDir = '/';
        }
        
        // 移除开头的斜杠
        $url = ltrim($url, '/');
        $baseDir = rtrim($baseDir, '/');
        
        // 构建绝对URL
        $absoluteUrl = rtrim($baseUrl, '/') . '/' . ltrim($baseDir, '/') . '/' . $url;
        
        // 规范化路径
        $absoluteUrl = str_replace(['/./', '//'], '/', $absoluteUrl);
        
        return $absoluteUrl;
    }

    /**
     * 下载资源（支持本地文件直接复制）
     */
    private function downloadResource(string $url): string|false
    {
        // 检查是否是本地文件路径
        $localPath = $this->getLocalFilePath($url);
        if ($localPath && file_exists($localPath)) {
            // 直接读取本地文件
            $content = @file_get_contents($localPath);
            return $content !== false ? $content : false;
        }
        
        // 通过HTTP下载
        $context = stream_context_create([
            'http' => [
                'timeout' => 2, // 减少超时时间到2秒，避免长时间等待
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'ignore_errors' => true // 忽略HTTP错误，继续处理
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        return $content !== false ? $content : false;
    }
    
    /**
     * 获取URL对应的本地文件路径（如果是本地上传的文件或框架静态资源）
     * 
     * @param string $url URL地址
     * @return string|null 本地文件路径，如果不是本地文件则返回null
     */
    private function getLocalFilePath(string $url): ?string
    {
        // 如果是绝对URL，解析路径
        if (preg_match('/^https?:\/\//i', $url)) {
            $parsedUrl = parse_url($url);
            if ($parsedUrl === false) {
                return null;
            }
            $path = $parsedUrl['path'] ?? '';
        } else {
            // 相对路径，直接使用
            $path = $url;
        }
        
        if (empty($path)) {
            return null;
        }
        
        // 规范化路径（移除开头的斜杠）
        $path = ltrim($path, '/');
        
        // 1. 检查是否是模板资源路径（开发环境）
        // 格式：/Vendor/ModuleName/view/templates/... 或 /Vendor/ModuleName/view/templates/style/.../asset/...
        // 实际路径：app/code/Vendor/ModuleName/view/templates/...
        if (preg_match('#^([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/templates/(.+)$#', $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $filePath = $matches[3];
            
            $localPath = BP . DS . 'app' . DS . 'code' . DS . $vendor . DS . $module . DS . 'view' . DS . 'templates' . DS . str_replace('/', DS, $filePath);
            
            // 规范化路径（处理Windows路径）
            if (defined('DS') && DS === '\\') {
                $localPath = str_replace('/', '\\', $localPath);
            } else {
                $localPath = str_replace('\\', '/', $localPath);
            }
            
            if (file_exists($localPath) && is_file($localPath)) {
                return $localPath;
            }
        }
        
        // 2. 检查是否是框架静态资源路径（开发环境）
        // 格式：/Vendor/ModuleName/view/statics/...
        // 实际路径：app/code/Vendor/ModuleName/view/statics/...
        if (preg_match('#^([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/view/statics/(.+)$#', $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $filePath = $matches[3];
            
            $localPath = BP . DS . 'app' . DS . 'code' . DS . $vendor . DS . $module . DS . 'view' . DS . 'statics' . DS . str_replace('/', DS, $filePath);
            
            // 规范化路径（处理Windows路径）
            if (defined('DS') && DS === '\\') {
                $localPath = str_replace('/', '\\', $localPath);
            } else {
                $localPath = str_replace('\\', '/', $localPath);
            }
            
            if (file_exists($localPath) && is_file($localPath)) {
                return $localPath;
            }
        }
        
        // 3. 检查是否是框架静态资源路径（生产环境）
        // 格式：/static/Vendor/ModuleName/... 或 /pub/static/Vendor/ModuleName/...
        // 实际路径：pub/static/Vendor/ModuleName/...
        if (preg_match('#^(pub/)?static/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/(.+)$#', $path, $matches)) {
            $vendor = $matches[2];
            $module = $matches[3];
            $filePath = $matches[4];
            
            // 先尝试生产环境的静态文件路径
            $localPath = BP . DS . 'pub' . DS . 'static' . DS . $vendor . DS . $module . DS . str_replace('/', DS, $filePath);
            
            // 规范化路径
            if (defined('DS') && DS === '\\') {
                $localPath = str_replace('/', '\\', $localPath);
            } else {
                $localPath = str_replace('\\', '/', $localPath);
            }
            
            if (file_exists($localPath) && is_file($localPath)) {
                return $localPath;
            }
            
            // 如果生产环境不存在，尝试从源码目录获取
            $localPath = BP . DS . 'app' . DS . 'code' . DS . $vendor . DS . $module . DS . 'view' . DS . 'statics' . DS . str_replace('/', DS, $filePath);
            
            // 规范化路径
            if (defined('DS') && DS === '\\') {
                $localPath = str_replace('/', '\\', $localPath);
            } else {
                $localPath = str_replace('\\', '/', $localPath);
            }
            
            if (file_exists($localPath) && is_file($localPath)) {
                return $localPath;
            }
        }
        
        // 4. 检查是否是媒体文件路径
        // 格式：/pub/media/... 或 /media/...
        // 实际路径：pub/media/...
        if (preg_match('#^(pub/)?media/(.+)$#', $path, $matches)) {
            $filePath = $matches[2];
            $localPath = BP . DS . 'pub' . DS . 'media' . DS . str_replace('/', DS, $filePath);
            
            // 规范化路径
            if (defined('DS') && DS === '\\') {
                $localPath = str_replace('/', '\\', $localPath);
            } else {
                $localPath = str_replace('\\', '/', $localPath);
            }
            
            if (file_exists($localPath) && is_file($localPath)) {
                return $localPath;
            }
        }
        
        // 5. 检查是否是其他静态文件路径（兼容旧代码）
        // 格式：/static/... 或 /pub/static/...
        if (preg_match('#^(pub/)?static/(.+)$#', $path, $matches)) {
            $filePath = $matches[2];
            $localPath = BP . DS . 'pub' . DS . 'static' . DS . str_replace('/', DS, $filePath);
            
            // 规范化路径
            if (defined('DS') && DS === '\\') {
                $localPath = str_replace('/', '\\', $localPath);
            } else {
                $localPath = str_replace('\\', '/', $localPath);
            }
            
            if (file_exists($localPath) && is_file($localPath)) {
                return $localPath;
            }
        }
        
        return null;
    }

    /**
     * 获取文件扩展名
     */
    private function getFileExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = $url;
        }
        
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // 如果没有扩展名，尝试从Content-Type判断
        if (empty($extension)) {
            // 常见文件类型的默认扩展名
            if (preg_match('/\.(svg|woff|woff2|ttf|eot|otf)$/i', $url)) {
                // 字体文件
                if (preg_match('/\.(woff|woff2|ttf|eot|otf)$/i', $url, $extMatches)) {
                    return strtolower($extMatches[1]);
                }
                if (preg_match('/\.svg$/i', $url)) {
                    return 'svg';
                }
            }
            // 默认返回png
            return 'png';
        }
        
        return strtolower($extension);
    }

    /**
     * 递归添加目录到ZIP
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = $zipPath . str_replace($dir . DIRECTORY_SEPARATOR, '', $filePath);
            $relativePath = str_replace('\\', '/', $relativePath);
            
            $zip->addFile($filePath, $relativePath);
        }
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
    
    /**
     * 从名称生成slug（用于生成handle）
     * 
     * @param string $name 名称
     * @return string slug格式的字符串
     */
    private function generateSlugFromName(string $name): string
    {
        if (empty($name)) {
            return '';
        }
        
        // 转换为小写
        $slug = mb_strtolower($name, 'UTF-8');
        
        // 替换中文字符为拼音（如果可用）或移除
        // 这里简化处理：只保留字母、数字和连字符
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
        
        // 移除多余的连字符
        $slug = preg_replace('/-+/', '-', $slug);
        
        // 移除开头和结尾的连字符
        $slug = trim($slug, '-');
        
        // 如果结果为空，返回空字符串
        if (empty($slug)) {
            return '';
        }
        
        // 限制长度（handle通常不超过255字符，这里限制为50）
        if (mb_strlen($slug) > 50) {
            $slug = mb_substr($slug, 0, 50);
            $slug = rtrim($slug, '-');
        }
        
        return $slug;
    }
}

