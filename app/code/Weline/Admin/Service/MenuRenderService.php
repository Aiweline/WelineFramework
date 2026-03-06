<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Service;

use Weline\Acl\Model\Role;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Menu;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Http\Request;

/**
 * 菜单渲染服务
 * 
 * 遵循 SOLID 原则：
 * - 单一职责：专门负责菜单数据的获取和渲染逻辑
 * - 开闭原则：可通过扩展修改渲染行为，无需修改核心代码
 * - 依赖倒置：通过依赖注入获取所需对象，便于测试和扩展
 * 
 * @package Weline_Admin
 */
class MenuRenderService
{
    /**
     * @var Menu
     */
    private Menu $menuModel;

    /**
     * @var MenuAccessLog
     */
    private MenuAccessLog $menuAccessLogModel;

    /**
     * @var AuthenticatedSessionInterface
     */
    private AuthenticatedSessionInterface $session;

    /**
     * 构造函数
     * 
     * @param Menu $menuModel
     * @param MenuAccessLog $menuAccessLogModel
     */
    public function __construct(
        Menu $menuModel,
        MenuAccessLog $menuAccessLogModel
    ) {
        $this->menuModel = $menuModel;
        $this->menuAccessLogModel = $menuAccessLogModel;
        $this->session = SessionFactory::getInstance()->createBackendSession();
    }

    /**
     * 获取当前请求对象（每次调用时从 ObjectManager 获取最新实例，避免 WLS 下状态泄漏）
     * 
     * @return Request
     */
    private function getRequest(): Request
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(Request::class);
    }

    /**
     * 获取后端 URL 前缀（每次调用时动态获取，避免 WLS 模式下状态泄漏）
     * 
     * @return string
     */
    private function getBackendUrlPrefix(): string
    {
        $prefix = rtrim($this->getRequest()->getUrlBuilder()->getBackendUrl('/'), '/');
        
        // 调试：检测异常的 URL 前缀
        // 正常的后端 URL 应该包含货币和语言路径段，如 /backend/USD/zh_Hans_CN
        // 如果只有 /backend 而没有货币语言，说明 $_SERVER 变量可能未正确设置
        $backendKey = \Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? '';
        $expectedMinLength = strlen($backendKey) + 10; // backend + /XXX/xx_XX 至少
        if (strlen($prefix) < $expectedMinLength) {
            $lang = $_SERVER['WELINE_USER_LANG'] ?? '(not set)';
            $currency = $_SERVER['WELINE_USER_CURRENCY'] ?? '(not set)';
            $requestUri = $_SERVER['REQUEST_URI'] ?? '(not set)';
            w_log_warning(
                "MenuRenderService::getBackendUrlPrefix returned short prefix: '{$prefix}', " .
                "WELINE_USER_LANG={$lang}, WELINE_USER_CURRENCY={$currency}, REQUEST_URI={$requestUri}",
                [],
                'menu_debug'
            );
        }
        
        return $prefix;
    }

    /**
     * 获取前端 URL 前缀
     * 
     * @return string
     */
    private function getFrontendUrlPrefix(): string
    {
        return '/';
    }

    /**
     * 获取当前登录用户
     * 
     * @return BackendUser|null
     */
    public function getCurrentUser(): ?BackendUser
    {
        return $this->session->getLoginUser();
    }

    /**
     * 获取用户菜单树
     * 
     * @return array
     */
    public function getMenuTree(): array
    {
        $user = $this->getCurrentUser();
        if (!$user || !$user->getId()) {
            return [];
        }

        // WLS 兼容：按当前用户的 role_id 重新加载 Role，避免 ObjectManager 复用导致菜单权限不全（如 demo 账户）
        $roleId = (int) ($user->getRole()->getRoleId() ?: 0);
        if ($roleId <= 0) {
            return [];
        }
        $role = ObjectManager::getInstance(Role::class, [], false)->load($roleId);
        if (!$role->getId()) {
            return [];
        }
        return $this->menuModel->getMenuTreeByRole($role);
    }

    /**
     * 获取常用菜单数据
     * 
     * @param int $limit 返回数量限制
     * @param int $days 统计天数
     * @return array 包含 recentMenus 和 frequentMenus 的数组
     */
    public function getFrequentMenus(int $limit = 20, int $days = 7): array
    {
        $user = $this->getCurrentUser();
        if (!$user || !$user->getId()) {
            return [
                'recentMenus' => [],
                'frequentMenus' => [],
                'hasFrequentMenus' => false
            ];
        }

        $recentMenus = $this->menuAccessLogModel->getRecentMenus($user->getId(), $limit, $days);
        $frequentMenus = $this->menuAccessLogModel->getFrequentlyUsedMenus($user->getId(), $limit, $days);

        return [
            'recentMenus' => $recentMenus,
            'frequentMenus' => $frequentMenus,
            'hasFrequentMenus' => !empty($recentMenus) || !empty($frequentMenus)
        ];
    }

    /**
     * 格式化菜单 URL
     * 
     * @param array $menuData 菜单数据
     * @return string
     */
    public function formatMenuUrl(array $menuData): string
    {
        $isBackend = $menuData['is_backend'] ?? true;
        $urlPrefix = $isBackend ? $this->getBackendUrlPrefix() : $this->getFrontendUrlPrefix();
        $urlPrefix = rtrim($urlPrefix, '/');
        $route = $menuData['route'] ?? '';
        
        return $urlPrefix . '/' . $route;
    }
    
    /**
     * 使用缓存的 URL 前缀格式化菜单 URL（仅在 renderMenu 内部使用）
     * 
     * @param array $menuData 菜单数据
     * @return string
     */
    private function formatMenuUrlCached(array $menuData): string
    {
        $isBackend = $menuData['is_backend'] ?? true;
        $urlPrefix = $isBackend ? ($this->cachedBackendUrlPrefix ?? $this->getBackendUrlPrefix()) : ($this->cachedFrontendUrlPrefix ?? $this->getFrontendUrlPrefix());
        $urlPrefix = rtrim($urlPrefix, '/');
        $route = $menuData['route'] ?? '';
        
        return $urlPrefix . '/' . $route;
    }

    /**
     * 获取当前请求 URL（去除查询参数和锚点）
     * 
     * @return string
     */
    private function getCurrentUrl(): string
    {
        // 使用 getUrlBuilder()->getCurrentUrl() 获取完整的当前 URL（包含协议、域名和路径）
        $url = $this->getRequest()->getUrlBuilder()->getCurrentUrl();
        if (empty($url)) {
            return '';
        }
        $url = explode('?', $url)[0];
        $url = explode('#', $url)[0];
        return rtrim($url, '/');
    }

    /**
     * 检查菜单 URL 是否匹配当前 URL
     * 
     * @param string $menuUrl 菜单 URL
     * @return bool
     */
    private function isMenuActive(string $menuUrl): bool
    {
        $currentUrl = $this->getCurrentUrl();
        if (empty($currentUrl)) {
            return false;
        }
        
        $menuUrl = rtrim($menuUrl, '/');
        
        // 精确匹配
        if ($menuUrl === $currentUrl) {
            return true;
        }
        
        // 路径匹配：当前 URL 以菜单 URL 开头（用于子路由）
        if (!empty($menuUrl) && strpos($currentUrl, $menuUrl) === 0) {
            $nextChar = substr($currentUrl, strlen($menuUrl), 1);
            if (empty($nextChar) || $nextChar === '/') {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 检查子菜单中是否有激活项（递归）
     * 
     * @param array $nodes 子菜单节点
     * @return bool
     */
    private function hasActiveChild(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'menus') {
                continue;
            }
            
            $childNodes = $node['nodes'] ?? [];
            $childCount = 0;
            
            foreach ($childNodes as $child) {
                if (isset($child['type']) && $child['type'] === 'menus') {
                    $childCount++;
                }
            }
            
            if ($childCount == 0) {
                $menuUrl = $this->formatMenuUrlCached($node);
                if ($this->isMenuActive($menuUrl)) {
                    return true;
                }
            } else {
                if ($this->hasActiveChild($childNodes)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 格式化访问次数显示
     * 
     * @param int $accessCount 访问次数
     * @return string
     */
    public function formatAccessCount(int $accessCount): string
    {
        if ($accessCount >= 1000000) {
            return number_format($accessCount / 1000000, 1, '.', '') . 'M';
        } elseif ($accessCount >= 1000) {
            return number_format($accessCount / 1000, 1, '.', '') . 'K';
        }
        
        return (string)$accessCount;
    }

    /**
     * 渲染时缓存的后端 URL 前缀（确保整个渲染过程中使用一致的值）
     */
    private ?string $cachedBackendUrlPrefix = null;
    
    /**
     * 渲染时缓存的前端 URL 前缀（确保整个渲染过程中使用一致的值）
     */
    private ?string $cachedFrontendUrlPrefix = null;
    
    /**
     * 渲染主菜单 HTML
     * 
     * @param array $menus 菜单数组
     * @return string HTML 字符串
     */
    public function renderMenu(array $menus): string
    {
        $html = '';
        
        // 在渲染开始时缓存 URL 前缀，确保整个渲染过程中使用一致的值
        // 这避免了 WLS 下由于状态变化导致的 URL 不一致问题
        $this->cachedBackendUrlPrefix = $this->getBackendUrlPrefix();
        $this->cachedFrontendUrlPrefix = $this->getFrontendUrlPrefix();
        
        foreach ($menus as $menu) {
            if (!isset($menu['is_enable']) || !$menu['is_enable']) {
                continue;
            }
            
            $hasNodes = isset($menu['nodes']) && !empty($menu['nodes']);
            $sourceId = htmlspecialchars($menu['source_id'] ?? '');
            $icon = htmlspecialchars($menu['icon'] ?? 'mdi mdi-circle');
            $title = __($menu['source_name'] ?? '');
            
            // 使用 formatMenuUrl 和 isMenuActive 来判断菜单是否有 URL 和是否激活
            $menuUrl = $this->formatMenuUrlCached($menu);
            $isActive = $this->isMenuActive($menuUrl);
            $route = $menu['route'] ?? '';
            $backendPrefixWithSlash = $this->cachedBackendUrlPrefix . '/';
            $frontendPrefixWithSlash = $this->cachedFrontendUrlPrefix . '/';
            
            // 严格检查：如果 route 为空，则认为没有有效的菜单 URL
            // 不依赖字符串比较，直接检查 route 是否为空
            $hasMenuUrl = !empty($route);
            
            
            // 如果有子菜单，检查子菜单中是否有激活项
            $hasActiveChild = $hasNodes ? $this->hasActiveChild($menu['nodes']) : false;
            $shouldBeActive = $isActive || $hasActiveChild;
            
            // 如果菜单有 URL，渲染为可点击的菜单项；否则渲染为分组标题
            if ($hasMenuUrl) {
                $liClass = $shouldBeActive ? 'mm-active' : '';
                $aClass = $hasNodes ? ($shouldBeActive ? 'has-arrow waves-effect mm-active' : 'has-arrow waves-effect') : ($isActive ? 'waves-effect active' : 'waves-effect');
                $subMenuClass = $hasActiveChild ? 'sub-menu mm-show' : 'sub-menu';
                $ariaExpanded = $hasActiveChild ? 'true' : 'false';
                
                $html .= "<li data-source=\"{$sourceId}\" class=\"{$liClass}\">";
                if ($hasNodes) {
                    $html .= "<a href=\"javascript: void(0);\" data-source=\"{$sourceId}\" class=\"{$aClass}\">";
                } else {
                    $menuUrl = htmlspecialchars($menuUrl);
                    $html .= "<a href=\"{$menuUrl}\" data-source=\"{$sourceId}\" class=\"{$aClass}\">";
                }
                $html .= "<i class=\"{$icon}\"></i>";
                $html .= "<span>{$title}</span>";
                $html .= "</a>";
                
                if ($hasNodes) {
                    $html .= "<ul class=\"{$subMenuClass}\" aria-expanded=\"{$ariaExpanded}\">";
                    $html .= $this->renderSubMenu($menu['nodes']);
                    $html .= "</ul>";
                }
                $html .= "</li>";
            } else {
                // 菜单分组标题（没有 URL，只是标题）
                $html .= "<li class=\"menu-title\" data-source=\"{$sourceId}\">";
                $html .= "<i class=\"{$icon}\"></i><span>{$title}</span>";
                $html .= "</li>";

                // 如果有子菜单
                if ($hasNodes) {
                    $html .= $this->renderSubMenu($menu['nodes']);
                }
            }
        }
        
        return $html;
    }

    /**
     * 渲染子菜单 HTML
     * 
     * @param array $submenus 子菜单数组
     * @return string HTML 字符串
     */
    public function renderSubMenu(array $submenus): string
    {
        $html = '';
        
        foreach ($submenus as $submenu) {
            $submenuType = $submenu['type'] ?? '';
            
            
            if ($submenuType !== 'menus') {
                continue;
            }

            $nodes = $submenu['nodes'] ?? [];
            $childCount = 0;
            
            // 计算菜单类型的子节点数量
            foreach ($nodes as $child) {
                if (isset($child['type']) && $child['type'] === 'menus') {
                    $childCount++;
                }
            }

            $sourceId = htmlspecialchars($submenu['source_id'] ?? '');
            $icon = htmlspecialchars($submenu['icon'] ?? 'mdi mdi-circle');
            $title = __($submenu['source_name'] ?? '');

            // 如果没有子菜单，渲染为普通菜单项
            if ($childCount == 0) {
                $route = $submenu['route'] ?? '';
                $hasMenuUrl = !empty($route);
                
                
                if ($hasMenuUrl) {
                    $menuUrl = $this->formatMenuUrlCached($submenu);
                    $isActive = $this->isMenuActive($menuUrl);
                    $menuUrl = htmlspecialchars($menuUrl);
                    
                    $liClass = $isActive ? 'mm-active' : '';
                    $aClass = $isActive ? 'waves-effect active' : 'waves-effect';
                    
                    $html .= "<li data-source=\"{$sourceId}\" class=\"{$liClass}\">";
                    $html .= "<a href=\"{$menuUrl}\" data-source=\"{$sourceId}\" class=\"{$aClass}\">";
                    $html .= "<i class=\"{$icon}\"></i>";
                    $html .= "<span>{$title}</span>";
                    $html .= "</a>";
                    $html .= "</li>";
                } else {
                    // 没有路由的菜单项，渲染为不可点击的展示项
                    $html .= "<li data-source=\"{$sourceId}\" class=\"\">";
                    $html .= "<a href=\"javascript: void(0);\" data-source=\"{$sourceId}\" class=\"waves-effect disabled\" style=\"cursor: default;\">";
                    $html .= "<i class=\"{$icon}\"></i>";
                    $html .= "<span>{$title}</span>";
                    $html .= "</a>";
                    $html .= "</li>";
                }
            } else {
                // 有子菜单，渲染为可展开菜单项
                $hasActiveChild = $this->hasActiveChild($nodes);
                $liClass = $hasActiveChild ? 'mm-active' : '';
                $aClass = $hasActiveChild ? 'has-arrow waves-effect mm-active' : 'has-arrow waves-effect';
                $subMenuClass = $hasActiveChild ? 'sub-menu mm-show' : 'sub-menu';
                $ariaExpanded = $hasActiveChild ? 'true' : 'false';
                
                $html .= "<li data-source=\"{$sourceId}\" class=\"{$liClass}\">";
                $html .= "<a href=\"javascript: void(0);\" data-source=\"{$sourceId}\" class=\"{$aClass}\">";
                $html .= "<i class=\"{$icon}\"></i>";
                $html .= "<span>{$title}</span>";
                $html .= "</a>";
                $html .= "<ul class=\"{$subMenuClass}\" aria-expanded=\"{$ariaExpanded}\">";
                $html .= $this->renderSubMenu($nodes);
                $html .= "</ul>";
                $html .= "</li>";
            }
        }
        
        return $html;
    }

    /**
     * 渲染常用菜单 HTML（最近访问）
     * 
     * @param array $recentMenus 最近访问的菜单列表
     * @return string HTML 字符串
     */
    public function renderRecentMenus(array $recentMenus): string
    {
        if (empty($recentMenus)) {
            return '';
        }

        $html = '';
        
        // 最近访问分组标题
        $html .= '<li class="frequent-menu-group-header-item">';
        $html .= '<div class="frequent-menu-group-header px-3 py-2">';
        $html .= '<h6 class="mb-0 fw-semibold">';
        $html .= '<i class="mdi mdi-clock-outline me-2"></i>';
        $html .= __('最近访问');
        $html .= '</h6>';
        $html .= '</div>';
        $html .= '</li>';
        
        // 最近访问菜单项
        foreach ($recentMenus as $recentMenu) {
            $aclData = $recentMenu['acl_data'] ?? [];
            $menuUrl = $this->formatMenuUrl($aclData);
            $menuName = __($aclData['source_name'] ?? '');
            $menuIcon = htmlspecialchars($aclData['icon'] ?? 'mdi mdi-circle');
            $sourceId = htmlspecialchars($recentMenu['source_id'] ?? '');
            
            $html .= '<li class="frequent-menu-item" data-source="' . $sourceId . '">';
            $html .= '<a href="' . htmlspecialchars($menuUrl) . '" class="waves-effect">';
            $html .= '<i class="' . $menuIcon . '"></i>';
            $html .= '<span>' . htmlspecialchars($menuName) . '</span>';
            $html .= '</a>';
            $html .= '</li>';
        }
        
        return $html;
    }

    /**
     * 渲染常用菜单 HTML（访问最多）
     * 
     * @param array $frequentMenus 访问最多的菜单列表
     * @return string HTML 字符串
     */
    public function renderFrequentMenus(array $frequentMenus): string
    {
        if (empty($frequentMenus)) {
            return '';
        }

        $html = '';
        
        // 访问最多分组标题
        $html .= '<li class="frequent-menu-group-header-item">';
        $html .= '<div class="frequent-menu-group-header px-3 py-2">';
        $html .= '<h6 class="mb-0 fw-semibold">';
        $html .= '<i class="mdi mdi-fire me-2"></i>';
        $html .= __('访问最多');
        $html .= '</h6>';
        $html .= '</div>';
        $html .= '</li>';
        
        // 访问最多菜单项
        foreach ($frequentMenus as $frequentMenu) {
            $aclData = $frequentMenu['acl_data'] ?? [];
            $menuUrl = $this->formatMenuUrl($aclData);
            $menuName = __($aclData['source_name'] ?? '');
            $menuIcon = htmlspecialchars($aclData['icon'] ?? 'mdi mdi-circle');
            $sourceId = htmlspecialchars($frequentMenu['source_id'] ?? '');
            $accessCount = intval($frequentMenu['access_count'] ?? 0);
            $formattedCount = $this->formatAccessCount($accessCount);
            
            $html .= '<li class="frequent-menu-item" data-source="' . $sourceId . '">';
            $html .= '<a href="' . htmlspecialchars($menuUrl) . '" class="waves-effect d-flex align-items-center justify-content-between">';
            $html .= '<span class="d-flex align-items-center">';
            $html .= '<i class="' . $menuIcon . '"></i>';
            $html .= '<span>' . htmlspecialchars($menuName) . '</span>';
            $html .= '</span>';
            if ($formattedCount) {
                $html .= '<span class="frequent-menu-count ms-2" title="' . __('访问次数: ' . number_format($accessCount)) . '">';
                $html .= htmlspecialchars($formattedCount);
                $html .= '</span>';
            }
            $html .= '</a>';
            $html .= '</li>';
        }
        
        return $html;
    }

    /**
     * 渲染常用菜单 Tab 内容 HTML
     * 
     * @param array $recentMenus 最近访问的菜单列表
     * @param array $frequentMenus 访问最多的菜单列表
     * @return string HTML 字符串
     */
    public function renderFrequentTabContent(array $recentMenus, array $frequentMenus): string
    {
        $recentHtml = $this->renderRecentMenus($recentMenus);
        $frequentHtml = $this->renderFrequentMenus($frequentMenus);
        
        if (empty($recentHtml) && empty($frequentHtml)) {
            return '';
        }

        $html = '<div class="frequent-menus-section" id="frequent-menus-section">';
        
        if (!empty($recentMenus)) {
            $html .= '<div class="frequent-menu-group">';
            $html .= '<div class="frequent-menu-group-header px-3 py-2">';
            $html .= '<h6 class="mb-0 fw-semibold">';
            $html .= '<i class="mdi mdi-clock-outline me-2"></i>';
            $html .= __('最近访问');
            $html .= '</h6>';
            $html .= '</div>';
            $html .= '<div class="frequent-menus-list" style="max-height: 200px; overflow-y: auto;">';
            $html .= '<ul class="list-unstyled mb-0">';
            foreach ($recentMenus as $recentMenu) {
                $aclData = $recentMenu['acl_data'] ?? [];
                $menuUrl = $this->formatMenuUrl($aclData);
                $menuName = __($aclData['source_name'] ?? '');
                $menuIcon = htmlspecialchars($aclData['icon'] ?? 'mdi mdi-circle');
                $sourceId = htmlspecialchars($recentMenu['source_id'] ?? '');
                
                $isActiveFrequent = $this->isMenuActive($menuUrl);
                $liClassFrequent = $isActiveFrequent ? 'frequent-menu-item mm-active' : 'frequent-menu-item';
                $aClassFrequent = $isActiveFrequent ? 'd-flex align-items-center px-3 py-2 waves-effect active' : 'd-flex align-items-center px-3 py-2 waves-effect';
                
                $html .= '<li class="' . $liClassFrequent . '">';
                $html .= '<a href="' . htmlspecialchars($menuUrl) . '" ';
                $html .= 'data-source="' . $sourceId . '" ';
                $html .= 'class="' . $aClassFrequent . '">';
                $html .= '<i class="' . $menuIcon . ' me-2"></i>';
                $html .= '<span>' . htmlspecialchars($menuName) . '</span>';
                $html .= '</a>';
                $html .= '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        if (!empty($frequentMenus)) {
            $html .= '<div class="frequent-menu-group">';
            $html .= '<div class="frequent-menu-group-header px-3 py-2">';
            $html .= '<h6 class="mb-0 fw-semibold">';
            $html .= '<i class="mdi mdi-fire me-2"></i>';
            $html .= __('访问最多');
            $html .= '</h6>';
            $html .= '</div>';
            $html .= '<div class="frequent-menus-list" style="max-height: 200px; overflow-y: auto;">';
            $html .= '<ul class="list-unstyled mb-0">';
            foreach ($frequentMenus as $frequentMenu) {
                $aclData = $frequentMenu['acl_data'] ?? [];
                $menuUrl = $this->formatMenuUrl($aclData);
                $menuName = __($aclData['source_name'] ?? '');
                $menuIcon = htmlspecialchars($aclData['icon'] ?? 'mdi mdi-circle');
                $sourceId = htmlspecialchars($frequentMenu['source_id'] ?? '');
                $accessCount = intval($frequentMenu['access_count'] ?? 0);
                $formattedCount = $this->formatAccessCount($accessCount);
                
                $isActiveFreq = $this->isMenuActive($menuUrl);
                $liClassFreq = $isActiveFreq ? 'frequent-menu-item mm-active' : 'frequent-menu-item';
                $aClassFreq = $isActiveFreq ? 'd-flex align-items-center justify-content-between px-3 py-2 waves-effect active' : 'd-flex align-items-center justify-content-between px-3 py-2 waves-effect';
                
                $html .= '<li class="' . $liClassFreq . '">';
                $html .= '<a href="' . htmlspecialchars($menuUrl) . '" ';
                $html .= 'data-source="' . $sourceId . '" ';
                $html .= 'class="' . $aClassFreq . '">';
                $html .= '<span class="d-flex align-items-center flex-grow-1">';
                $html .= '<i class="' . $menuIcon . ' me-2"></i>';
                $html .= '<span>' . htmlspecialchars($menuName) . '</span>';
                $html .= '</span>';
                $html .= '<span class="frequent-menu-count ms-2" title="' . __('访问次数: ' . number_format($accessCount)) . '">';
                $html .= htmlspecialchars($formattedCount);
                $html .= '</span>';
                $html .= '</a>';
                $html .= '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
}
