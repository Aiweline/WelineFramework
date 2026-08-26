<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Service;

use Weline\Admin\Model\MenuAccessLog;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Backend\Api\Menu\MenuReaderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;
use Weline\Theme\Service\Ui\IconRegistry;

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
    private const FREQUENT_MENU_CACHE_TTL = 30.0;
    private const RENDER_MENU_CACHE_TTL = 60.0;

    /**
     * @var MenuAccessLog
     */
    private MenuAccessLog $menuAccessLogModel;

    private ?BackendUserContextProviderInterface $userContextProvider = null;
    private ?MenuReaderInterface $menuReader = null;

    /**
     * @var array<string, array<string, string>>
     */
    private array $moduleLocaleWords = [];

    /**
     * @var array<string, array{expires: float, data: array}>
     */
    private static array $frequentMenusCache = [];

    /**
     * @var array<string, array{expires: float, html: string}>
     */
    private static array $renderedMenuCache = [];

    /**
     * 构造函数
     * 
     * @param MenuAccessLog $menuAccessLogModel
     */
    public function __construct(
        MenuAccessLog $menuAccessLogModel
    ) {
        $this->menuAccessLogModel = $menuAccessLogModel;
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
        // 后端 URL 可按当前上下文携带货币和语言路径段，如 /backend/USD/zh_Hans_CN；
        // 默认货币/语言可能不会输出，因此不能把缺少本地化段当作异常。
        $backendKey = \Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? '';
        $expectedMinLength = strlen($backendKey) + 10; // backend + /XXX/xx_XX 至少
        if (strlen($prefix) < $expectedMinLength) {
            $lang = \w_env('user.lang') ?? '(not set)';
            $currency = \w_env('user.currency') ?? '(not set)';
            $requestUri = \w_env('request.uri') ?? '(not set)';
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
     * @return BackendUserContext|null
     */
    public function getCurrentUser(): ?BackendUserContext
    {
        if ($this->userContextProvider === null) {
            $this->userContextProvider = ObjectManager::getInstance(BackendUserContextProviderInterface::class);
        }
        return $this->userContextProvider->current();
    }

    /**
     * 获取用户菜单树
     * 
     * @return array
     */
    public function getMenuTree(): array
    {
        $user = $this->getCurrentUser();
        if (!$user || !$user->getId() || !$user->getRoleId()) {
            return [];
        }

        if ($this->menuReader === null) {
            $this->menuReader = ObjectManager::getInstance(MenuReaderInterface::class);
        }
        // The authenticated session already owns the authoritative role for this
        // request. Reloading the user by its numeric id can cross identity
        // boundaries when a long-running WLS worker and an isolated clone have
        // different user-id histories. Horizontal navigation already follows
        // this role-scoped path; keep the vertical sidebar consistent with it.
        return $this->menuReader->getMenuTreeByRoleId($user->getRoleId());
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

        $cacheKey = (int)$user->getId() . '|' . $limit . '|' . $days;
        $now = microtime(true);
        if (isset(self::$frequentMenusCache[$cacheKey]) && self::$frequentMenusCache[$cacheKey]['expires'] >= $now) {
            return self::$frequentMenusCache[$cacheKey]['data'];
        }

        $recentMenus = $this->menuAccessLogModel->getRecentMenus($user->getId(), $limit, $days);
        $frequentMenus = $this->menuAccessLogModel->getFrequentlyUsedMenus($user->getId(), $limit, $days);

        $data = [
            'recentMenus' => $recentMenus,
            'frequentMenus' => $frequentMenus,
            'hasFrequentMenus' => !empty($recentMenus) || !empty($frequentMenus)
        ];
        self::$frequentMenusCache[$cacheKey] = ['expires' => $now + self::FREQUENT_MENU_CACHE_TTL, 'data' => $data];
        return $data;
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
        if ($this->cachedCurrentUrl !== null) {
            return $this->cachedCurrentUrl;
        }

        $url = $this->getRequest()->getUrlBuilder()->getCurrentUrl();
        if (empty($url)) {
            $this->cachedCurrentUrl = '';
            return $this->cachedCurrentUrl;
        }
        $this->cachedCurrentUrl = $this->normalizeComparableUrl($url);
        return $this->cachedCurrentUrl;
    }

    private function normalizeComparableUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = explode('?', $url)[0];
        $url = explode('#', $url)[0];
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $url = $path;
        }

        $url = trim($url, '/');
        $backendPrefix = trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($backendPrefix !== '' && str_starts_with($url, $backendPrefix . '/')) {
            $url = substr($url, strlen($backendPrefix) + 1);
        }

        $segments = explode('/', $url);
        if (count($segments) > 1 && preg_match('/^[A-Za-z0-9]{16,}$/', $segments[0]) === 1) {
            $segments = array_slice($segments, 1);
        }

        $segments = $this->stripLocalizationRouteSegments($segments);

        return rtrim(implode('/', $segments), '/');
    }

    /**
     * 菜单激活比较时忽略可选的货币/语言路径段。
     *
     * 后台菜单 href 通过 getBackendUrl() 生成，默认语言/货币会被省略；
     * 浏览器地址栏仍可能保留显式 locale（如 /zh_Hans_CN/eav/...），
     * 比较前须剥掉这些段，否则侧栏无法高亮与滚动定位。
     *
     * @param array<int, string> $segments
     * @return array<int, string>
     */
    private function stripLocalizationRouteSegments(array $segments): array
    {
        if ($segments !== [] && State::isAllowedCurrencyCode((string)$segments[0])) {
            $segments = array_slice($segments, 1);
        }

        if ($segments !== [] && State::isAllowedLanguageCode((string)$segments[0])) {
            $segments = array_slice($segments, 1);
        }

        return $segments;
    }
    /**
     * 检查菜单 URL 是否匹配当前 URL
     * 
     * @param string $menuUrl 菜单 URL
     * @return bool
     */
    private function isMenuActive(string $menuUrl): bool
    {
        $menuUrl = $this->normalizeComparableUrl($menuUrl);
        if (isset($this->menuUrlActiveCache[$menuUrl])) {
            return $this->menuUrlActiveCache[$menuUrl];
        }

        $currentUrl = $this->getCurrentUrl();
        if (empty($currentUrl)) {
            return false;
        }

        if ($menuUrl === $currentUrl) {
            $this->menuUrlActiveCache[$menuUrl] = true;
            return true;
        }

        if (!empty($menuUrl) && strpos($currentUrl, $menuUrl) === 0) {
            $nextChar = substr($currentUrl, strlen($menuUrl), 1);
            if (empty($nextChar) || $nextChar === '/') {
                $this->menuUrlActiveCache[$menuUrl] = true;
                return true;
            }
        }

        if (str_ends_with($menuUrl, '/index')) {
            $controllerUrl = substr($menuUrl, 0, -strlen('/index'));
            if ($controllerUrl !== '' && strpos($currentUrl, $controllerUrl . '/') === 0) {
                $this->menuUrlActiveCache[$menuUrl] = true;
                return true;
            }
        }

        $this->menuUrlActiveCache[$menuUrl] = false;
        return false;
    }
    /**
     * 检查子菜单中是否有激活项
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

            if ($this->isMenuNodeActive($node)) {
                return true;
            }
        }

        return false;
    }

    private function isMenuNodeActive(array $node): bool
    {
        $cacheKey = $this->getMenuNodeCacheKey($node);
        if (isset($this->menuNodeActiveCache[$cacheKey])) {
            return $this->menuNodeActiveCache[$cacheKey];
        }

        $childNodes = $node['nodes'] ?? [];
        $hasChildMenus = false;
        foreach ($childNodes as $child) {
            if (($child['type'] ?? '') === 'menus') {
                $hasChildMenus = true;
                break;
            }
        }

        if (!$hasChildMenus) {
            $route = $node['route'] ?? '';
            $active = !empty($route) && $this->isMenuActive($this->formatMenuUrlCached($node));
            $this->menuNodeActiveCache[$cacheKey] = $active;
            return $active;
        }

        $active = $this->hasActiveChild($childNodes);
        $this->menuNodeActiveCache[$cacheKey] = $active;

        return $active;
    }

    private function getMenuNodeCacheKey(array $node): string
    {
        $sourceId = (string)($node['source_id'] ?? '');
        if ($sourceId !== '') {
            return $sourceId;
        }

        $route = (string)($node['route'] ?? '');
        $title = (string)($node['source_name'] ?? '');

        return md5($route . '|' . $title);
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
     * 渲染时缓存的当前 URL，避免菜单激活判断反复读取请求对象
     */
    private ?string $cachedCurrentUrl = null;

    /**
     * 菜单 URL 激活状态缓存
     *
     * @var array<string, bool>
     */
    private array $menuUrlActiveCache = [];

    /**
     * 菜单节点激活状态缓存
     *
     * @var array<string, bool>
     */
    private array $menuNodeActiveCache = [];
    
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
        $this->cachedCurrentUrl = null;
        $this->menuUrlActiveCache = [];
        $this->menuNodeActiveCache = [];

        $user = $this->getCurrentUser();
        $currentUrl = $this->getCurrentUrl();
        $cacheKey = implode('|', [
            (string)(($user && $user->getId()) ? (int)$user->getId() : 0),
            State::getLangLocal(),
            $this->cachedBackendUrlPrefix,
            $this->cachedFrontendUrlPrefix,
            $currentUrl,
            md5(json_encode($menus, JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
        ]);
        $now = microtime(true);
        if (isset(self::$renderedMenuCache[$cacheKey]) && self::$renderedMenuCache[$cacheKey]['expires'] >= $now) {
            return self::$renderedMenuCache[$cacheKey]['html'];
        }
        
        foreach ($menus as $menu) {
            if (!$this->isMenuEnabled($menu)) {
                continue;
            }
            $html .= $this->renderMenuNode($menu, true);
        }
        
        self::$renderedMenuCache[$cacheKey] = ['expires' => $now + self::RENDER_MENU_CACHE_TTL, 'html' => $html];
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
            if (($submenu['type'] ?? '') !== 'menus' || !$this->isMenuEnabled($submenu)) {
                continue;
            }
            $html .= $this->renderMenuNode($submenu, false);
        }
        return $html;
    }

    private function renderMenuNode(array $menu, bool $topLevel): string
    {
        $sourceId = htmlspecialchars((string)($menu['source_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $title = $this->translateMenuTitle(
            (string)($menu['source_name'] ?? ''),
            (string)($menu['source_id'] ?? '')
        );
        $nodes = array_values(array_filter(
            is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [],
            fn(array $node): bool => ($node['type'] ?? '') === 'menus' && $this->isMenuEnabled($node)
        ));
        $hasNodes = $nodes !== [];
        $route = trim((string)($menu['route'] ?? ''));
        $icon = $this->renderIcon((string)($menu['icon'] ?? 'circle'));

        if ($route === '' && $topLevel) {
            $html = '<li class="w-backend-nav__group" data-source="' . $sourceId . '">';
            $html .= $icon . '<span>' . $title . '</span></li>';
            return $html . ($hasNodes ? $this->renderSubMenu($nodes) : '');
        }

        $active = $route !== '' && $this->isMenuActive($this->formatMenuUrlCached($menu));
        $hasActiveChild = $hasNodes && $this->hasActiveChild($nodes);
        $current = $active ? ' aria-current="page" data-state="active"' : '';

        if (!$hasNodes) {
            if ($route === '') {
                return '<li class="w-backend-nav__entry" data-source="' . $sourceId . '"><span class="w-backend-nav__item" aria-disabled="true">'
                    . $icon . '<span>' . $title . '</span></span></li>';
            }
            $url = htmlspecialchars($this->formatMenuUrlCached($menu), ENT_QUOTES, 'UTF-8');
            return '<li class="w-backend-nav__entry" data-source="' . $sourceId . '"><a class="w-backend-nav__item" href="'
                . $url . '"' . $current . '>' . $icon . '<span>' . $title . '</span></a></li>';
        }

        $open = $active || $hasActiveChild;
        $html = '<li class="w-backend-nav__entry" data-source="' . $sourceId . '"><details class="w-backend-nav__disclosure"'
            . ($open ? ' open' : '') . '><summary class="w-backend-nav__item"' . $current . '>';
        $html .= $icon . '<span>' . $title . '</span>' . $this->renderIcon('chevron-down', 'sm');
        $html .= '</summary><ul class="w-backend-nav__list">' . $this->renderSubMenu($nodes) . '</ul></details></li>';
        return $html;
    }

    private function renderIcon(string $name, string $size = 'md'): string
    {
        return ObjectManager::getInstance(IconRegistry::class)->render(trim($name), $size);
    }

    public function translateMenuTitle(string $title, string $sourceId = ''): string
    {
        if ($title === '') {
            return '';
        }

        $moduleName = $this->extractModuleNameFromSource($sourceId);
        if ($moduleName !== '') {
            $moduleWords = $this->getModuleLocaleWords($moduleName, State::getLangLocal());
            $moduleTranslate = trim((string)($moduleWords[$title] ?? ''));
            if ($moduleTranslate !== '' && $moduleTranslate !== $title) {
                return htmlspecialchars($moduleTranslate);
            }
        }

        $generatedWords = $this->getGeneratedLocaleWords(State::getLangLocal());
        $generatedTranslate = trim((string)($generatedWords[$title] ?? ''));
        if ($generatedTranslate !== '' && $generatedTranslate !== $title) {
            return htmlspecialchars($generatedTranslate);
        }

        return htmlspecialchars((string)__($title));
    }

    private function extractModuleNameFromSource(string $sourceId): string
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '' || !str_contains($sourceId, '::')) {
            return '';
        }

        return trim(strstr($sourceId, '::', true) ?: '');
    }

    /**
     * @return array<string, string>
     */
    private function getModuleLocaleWords(string $moduleName, string $localeCode): array
    {
        $cacheKey = $moduleName . '|' . $localeCode;
        if (isset($this->moduleLocaleWords[$cacheKey])) {
            return $this->moduleLocaleWords[$cacheKey];
        }

        $this->moduleLocaleWords[$cacheKey] = [];
        $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
        $basePath = is_array($moduleInfo) ? (string)($moduleInfo['base_path'] ?? '') : '';
        if ($basePath === '') {
            return [];
        }

        $csvFile = rtrim($basePath, "\\/") . DS . 'i18n' . DS . $localeCode . '.csv';
        if (!is_file($csvFile)) {
            return [];
        }

        $handle = @fopen($csvFile, 'r');
        if ($handle === false) {
            return [];
        }

        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $word = trim((string)($row[0] ?? ''));
            $translate = trim((string)($row[1] ?? ''));
            if ($word !== '' && $translate !== '') {
                $this->moduleLocaleWords[$cacheKey][$word] = $translate;
            }
        }
        fclose($handle);

        return $this->moduleLocaleWords[$cacheKey];
    }

    /**
     * @return array<string, string>
     */
    private function getGeneratedLocaleWords(string $localeCode): array
    {
        $cacheKey = 'generated|' . $localeCode;
        if (isset($this->moduleLocaleWords[$cacheKey])) {
            return $this->moduleLocaleWords[$cacheKey];
        }

        $this->moduleLocaleWords[$cacheKey] = [];
        $localeFile = BP . DS . 'generated' . DS . 'language' . DS . $localeCode . '.php';
        if (!is_file($localeFile)) {
            return [];
        }

        $words = include $localeFile;
        if (is_array($words)) {
            $this->flattenLocaleWords($words, $this->moduleLocaleWords[$cacheKey]);
        }

        return $this->moduleLocaleWords[$cacheKey];
    }

    /**
     * @param array<mixed> $words
     * @param array<string, string> $result
     */
    private function flattenLocaleWords(array $words, array &$result): void
    {
        foreach ($words as $word => $translate) {
            if (is_array($translate)) {
                $this->flattenLocaleWords($translate, $result);
                continue;
            }
            if (is_string($word) && is_string($translate) && $word !== '' && $translate !== '') {
                $result[$word] = $translate;
            }
        }
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
        $html = '<li class="w-backend-nav__group">' . $this->renderIcon('history', 'sm')
            . '<span>' . htmlspecialchars((string)__('最近访问'), ENT_QUOTES, 'UTF-8') . '</span></li>';
        foreach ($recentMenus as $recentMenu) {
            $aclData = $recentMenu['acl_data'] ?? [];
            $menuUrl = $this->formatMenuUrl($aclData);
            $menuName = htmlspecialchars((string)__($aclData['source_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $sourceId = htmlspecialchars((string)($recentMenu['source_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<li class="w-backend-nav__entry" data-menu-source-ref="' . $sourceId . '"><a class="w-backend-nav__item" href="'
                . htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') . '">' . $this->renderIcon((string)($aclData['icon'] ?? 'circle'))
                . '<span>' . $menuName . '</span></a></li>';
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

        $html = '<li class="w-backend-nav__group">' . $this->renderIcon('fire', 'sm')
            . '<span>' . htmlspecialchars((string)__('访问最多'), ENT_QUOTES, 'UTF-8') . '</span></li>';
        foreach ($frequentMenus as $frequentMenu) {
            $aclData = $frequentMenu['acl_data'] ?? [];
            $menuUrl = $this->formatMenuUrl($aclData);
            $menuName = htmlspecialchars((string)__($aclData['source_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $sourceId = htmlspecialchars((string)($frequentMenu['source_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $accessCount = intval($frequentMenu['access_count'] ?? 0);
            $formattedCount = $this->formatAccessCount($accessCount);
            $html .= '<li class="w-backend-nav__entry" data-menu-source-ref="' . $sourceId . '"><a class="w-backend-nav__item" href="'
                . htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') . '">' . $this->renderIcon((string)($aclData['icon'] ?? 'circle'))
                . '<span>' . $menuName . '</span><span class="w-badge" title="'
                . htmlspecialchars((string)__('访问次数: ' . number_format($accessCount)), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($formattedCount, ENT_QUOTES, 'UTF-8') . '</span></a></li>';
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
        if ($recentHtml === '' && $frequentHtml === '') {
            return '';
        }
        return '<div class="w-backend-nav__frequent"><ul class="w-backend-nav__list">'
            . $recentHtml . $frequentHtml . '</ul></div>';
    }

    /**
     * ResourceTree 输出 is_enable 为 int(0|1)；兼容 bool / "1" / "0"。
     *
     * @param array<string, mixed> $menu
     */
    private function isMenuEnabled(array $menu): bool
    {
        $flag = $menu['is_enable'] ?? true;
        if (is_bool($flag)) {
            return $flag;
        }

        return (int)$flag === 1;
    }
}
