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
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Http\Request;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
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
    /** WLS 进程内菜单 HTML 缓存上限（含 URL 激活态指纹键） */
    private const RENDERED_MENU_CACHE_MAX = 16;
    private const FREQUENT_MENU_CACHE_MAX = 32;

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
     * @var list<string>|null
     */
    private ?array $activeLocaleCodes = null;

    /** 本实例是否已对菜单标题做过跨 locale 词条预取 */
    private bool $crossLocaleWordsPrefetched = false;

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
            $hit = self::$frequentMenusCache[$cacheKey];
            unset(self::$frequentMenusCache[$cacheKey]);
            self::$frequentMenusCache[$cacheKey] = $hit;

            return $hit['data'];
        }

        $recentMenus = $this->menuAccessLogModel->getRecentMenus($user->getId(), $limit, $days);
        $frequentMenus = $this->menuAccessLogModel->getFrequentlyUsedMenus($user->getId(), $limit, $days);

        $data = [
            'recentMenus' => $recentMenus,
            'frequentMenus' => $frequentMenus,
            'hasFrequentMenus' => !empty($recentMenus) || !empty($frequentMenus)
        ];
        $this->rememberFrequentMenus($cacheKey, $data, $now);
        return $data;
    }

    /**
     * 清理 WLS 进程内菜单 HTML / 常用菜单统计缓存（供 compaction 与显式清缓存）。
     */
    public static function clearProcessCache(): void
    {
        self::$renderedMenuCache = [];
        self::$frequentMenusCache = [];
    }

    /**
     * 格式化菜单 URL（走 Url::getBackendUrl，含非默认货币/语言路径段）
     * 
     * @param array $menuData 菜单数据
     * @return string
     */
    public function formatMenuUrl(array $menuData): string
    {
        $isBackend = $menuData['is_backend'] ?? true;
        $route = trim((string)($menuData['route'] ?? ''), '/');
        if (!$isBackend) {
            $urlPrefix = rtrim($this->getFrontendUrlPrefix(), '/');

            return $route === '' ? ($urlPrefix === '' ? '/' : $urlPrefix) : $urlPrefix . '/' . $route;
        }

        $urlBuilder = $this->getRequest()->getUrlBuilder();
        if ($route === '') {
            return rtrim($urlBuilder->getBackendUrl('/'), '/');
        }

        return $urlBuilder->getBackendUrl($route);
    }
    
    /**
     * 渲染过程中用缓存的「区域根」拼接路由，与 getBackendUrl(route) 等价且避免重复解析。
     * 
     * @param array $menuData 菜单数据
     * @return string
     */
    private function formatMenuUrlCached(array $menuData): string
    {
        $isBackend = $menuData['is_backend'] ?? true;
        $route = trim((string)($menuData['route'] ?? ''), '/');
        if (!$isBackend) {
            $urlPrefix = rtrim($this->cachedFrontendUrlPrefix ?? $this->getFrontendUrlPrefix(), '/');

            return $route === '' ? ($urlPrefix === '' ? '/' : $urlPrefix) : $urlPrefix . '/' . $route;
        }

        $urlPrefix = rtrim($this->cachedBackendUrlPrefix ?? $this->getBackendUrlPrefix(), '/');
        if ($route === '') {
            return $urlPrefix;
        }

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
     * 菜单激活比较时忽略段内连字符大小写差异。
     *
     * 路由常同时注册 control-center 与 controlcenter 等别名；地址栏与菜单 href
     * 可能各用一种写法，比较前去掉 `-` 并小写，避免侧栏无法高亮与自动展开。
     */
    private function canonicalizeRouteKey(string $url): string
    {
        $segments = \array_values(\array_filter(
            \explode('/', \trim($url, '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
        $segments = \array_map(
            static fn(string $segment): string => \strtolower(\str_replace('-', '', $segment)),
            $segments,
        );

        return \implode('/', $segments);
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

        $menuKey = $this->canonicalizeRouteKey($menuUrl);
        $currentKey = $this->canonicalizeRouteKey($currentUrl);

        if ($menuKey === $currentKey) {
            $this->menuUrlActiveCache[$menuUrl] = true;
            return true;
        }

        if ($menuKey !== '' && str_starts_with($currentKey, $menuKey)) {
            $nextChar = substr($currentKey, strlen($menuKey), 1);
            if ($nextChar === '' || $nextChar === '/') {
                $this->menuUrlActiveCache[$menuUrl] = true;
                return true;
            }
        }

        if (str_ends_with($menuKey, '/index')) {
            $controllerKey = substr($menuKey, 0, -strlen('/index'));
            // 当前路由常为 …/controller（省略 /index），须与菜单 …/controller/index 对齐
            if ($controllerKey !== ''
                && ($currentKey === $controllerKey || str_starts_with($currentKey, $controllerKey . '/'))
            ) {
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
        // 激活态烘焙进 HTML：键用「激活叶指纹」代替 raw currentUrl，降低深链/别名路径基数。
        $activeFingerprint = $this->buildActiveMenuFingerprint($menus);
        $cacheKey = implode('|', [
            (string)(($user && $user->getId()) ? (int)$user->getId() : 0),
            State::getLangLocal(),
            implode(',', $this->getActiveLocaleCodes()),
            $this->cachedBackendUrlPrefix,
            $this->cachedFrontendUrlPrefix,
            $activeFingerprint,
            md5(json_encode($menus, JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
        ]);
        $now = microtime(true);
        if (isset(self::$renderedMenuCache[$cacheKey]) && self::$renderedMenuCache[$cacheKey]['expires'] >= $now) {
            $hit = self::$renderedMenuCache[$cacheKey];
            unset(self::$renderedMenuCache[$cacheKey]);
            self::$renderedMenuCache[$cacheKey] = $hit;

            return $hit['html'];
        }

        $this->prefetchCrossLocaleMenuWords($this->collectMenuTitles($menus));

        foreach ($menus as $menu) {
            if (!$this->isMenuEnabled($menu)) {
                continue;
            }
            $html .= $this->renderMenuNode($menu, true);
        }

        $this->rememberRenderedMenu($cacheKey, $html, $now);
        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     */
    private function buildActiveMenuFingerprint(array $menus): string
    {
        $activeIds = [];
        foreach ($menus as $menu) {
            if (($menu['type'] ?? '') !== 'menus') {
                continue;
            }
            $this->collectActiveMenuSourceIds($menu, $activeIds);
        }
        if ($activeIds === []) {
            return 'none';
        }
        $activeIds = array_values(array_unique($activeIds));
        sort($activeIds, SORT_STRING);

        return md5(implode('|', $activeIds));
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $activeIds
     */
    private function collectActiveMenuSourceIds(array $node, array &$activeIds): void
    {
        if (!$this->isMenuNodeActive($node)) {
            return;
        }
        $sourceId = trim((string)($node['source_id'] ?? ''));
        if ($sourceId !== '') {
            $activeIds[] = $sourceId;
        }
        foreach ($node['nodes'] ?? [] as $child) {
            if (($child['type'] ?? '') !== 'menus') {
                continue;
            }
            $this->collectActiveMenuSourceIds($child, $activeIds);
        }
    }

    private function rememberRenderedMenu(string $cacheKey, string $html, float $now): void
    {
        $this->pruneExpiredCacheEntries(self::$renderedMenuCache, $now);
        if (isset(self::$renderedMenuCache[$cacheKey])) {
            unset(self::$renderedMenuCache[$cacheKey]);
        }
        while (count(self::$renderedMenuCache) >= self::RENDERED_MENU_CACHE_MAX) {
            $oldestKey = array_key_first(self::$renderedMenuCache);
            if ($oldestKey === null) {
                break;
            }
            unset(self::$renderedMenuCache[$oldestKey]);
        }
        self::$renderedMenuCache[$cacheKey] = [
            'expires' => $now + self::RENDER_MENU_CACHE_TTL,
            'html' => $html,
        ];
    }

    /**
     * @param array{recentMenus: array, frequentMenus: array, hasFrequentMenus: bool} $data
     */
    private function rememberFrequentMenus(string $cacheKey, array $data, float $now): void
    {
        $this->pruneExpiredCacheEntries(self::$frequentMenusCache, $now);
        if (isset(self::$frequentMenusCache[$cacheKey])) {
            unset(self::$frequentMenusCache[$cacheKey]);
        }
        while (count(self::$frequentMenusCache) >= self::FREQUENT_MENU_CACHE_MAX) {
            $oldestKey = array_key_first(self::$frequentMenusCache);
            if ($oldestKey === null) {
                break;
            }
            unset(self::$frequentMenusCache[$oldestKey]);
        }
        self::$frequentMenusCache[$cacheKey] = [
            'expires' => $now + self::FREQUENT_MENU_CACHE_TTL,
            'data' => $data,
        ];
    }

    /**
     * @param array<string, array{expires: float}> $cache
     */
    private function pruneExpiredCacheEntries(array &$cache, float $now): void
    {
        foreach ($cache as $key => $entry) {
            if (($entry['expires'] ?? 0.0) < $now) {
                unset($cache[$key]);
            }
        }
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

    /** @return list<string> */
    private function collectMenuTitles(array $menus): array
    {
        $titles = [];
        foreach ($menus as $menu) {
            if (!$this->isMenuEnabled($menu)) {
                continue;
            }
            $titles[] = (string)($menu['source_name'] ?? '');
            $nodes = \array_values(\array_filter(
                \is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [],
                static fn(array $node): bool => ($node['type'] ?? '') === 'menus',
            ));
            $titles = \array_merge($titles, $this->collectMenuTitles($nodes));
        }
        return $titles;
    }

    private function renderMenuNode(array $menu, bool $topLevel): string
    {
        $rawSourceId = (string)($menu['source_id'] ?? '');
        $rawSourceName = (string)($menu['source_name'] ?? '');
        $sourceId = htmlspecialchars($rawSourceId, ENT_QUOTES, 'UTF-8');
        $title = $this->translateMenuTitle($rawSourceName, $rawSourceId);
        $searchAttr = $this->renderSearchTextAttribute($rawSourceName, $rawSourceId);
        $nodes = array_values(array_filter(
            is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [],
            fn(array $node): bool => ($node['type'] ?? '') === 'menus' && $this->isMenuEnabled($node)
        ));
        $hasNodes = $nodes !== [];
        $route = trim((string)($menu['route'] ?? ''));
        $icon = $this->renderIcon((string)($menu['icon'] ?? 'circle'));

        if ($route === '' && $topLevel) {
            if (!$hasNodes) {
                $html = '<li class="w-backend-nav__group" data-source="' . $sourceId . '"' . $searchAttr . '>';
                $html .= $icon . '<span>' . $title . '</span></li>';
                return $html;
            }
            // Top-level group with children: keep one hoverable icon in collapsed rail.
            $open = $this->hasActiveChild($nodes);
            $html = '<li class="w-backend-nav__entry w-backend-nav__entry--group" data-source="' . $sourceId . '"' . $searchAttr . '>';
            $html .= '<details class="w-backend-nav__disclosure"' . ($open ? ' open' : '') . '>';
            $html .= '<summary class="w-backend-nav__item w-backend-nav__item--group">';
            $html .= $icon . '<span>' . $title . '</span>' . $this->renderIcon('chevron-down', 'sm');
            $html .= '</summary><ul class="w-backend-nav__list">' . $this->renderSubMenu($nodes) . '</ul></details></li>';
            return $html;
        }

        $active = $route !== '' && $this->isMenuActive($this->formatMenuUrlCached($menu));
        $hasActiveChild = $hasNodes && $this->hasActiveChild($nodes);
        $current = $active ? ' aria-current="page" data-state="active"' : '';

        if (!$hasNodes) {
            if ($route === '') {
                return '<li class="w-backend-nav__entry" data-source="' . $sourceId . '"' . $searchAttr . '><span class="w-backend-nav__item" aria-disabled="true">'
                    . $icon . '<span>' . $title . '</span></span></li>';
            }
            $url = htmlspecialchars($this->formatMenuUrlCached($menu), ENT_QUOTES, 'UTF-8');
            return '<li class="w-backend-nav__entry" data-source="' . $sourceId . '"' . $searchAttr . '><a class="w-backend-nav__item" href="'
                . $url . '"' . $current . '>' . $icon . '<span>' . $title . '</span></a></li>';
        }

        $open = $active || $hasActiveChild;
        $html = '<li class="w-backend-nav__entry" data-source="' . $sourceId . '"' . $searchAttr . '><details class="w-backend-nav__disclosure"'
            . ($open ? ' open' : '') . '><summary class="w-backend-nav__item"' . $current . '>';
        $html .= $icon . '<span>' . $title . '</span>' . $this->renderIcon('chevron-down', 'sm');
        $html .= '</summary><ul class="w-backend-nav__list">' . $this->renderSubMenu($nodes) . '</ul></details></li>';
        return $html;
    }

    private function renderSearchTextAttribute(string $sourceName, string $sourceId): string
    {
        $searchText = $this->buildCrossLocaleSearchText($sourceName, $sourceId);
        if ($searchText === '') {
            return '';
        }

        return ' data-search-text="' . htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') . '"';
    }

    private function renderIcon(string $name, string $size = 'md'): string
    {
        return ObjectManager::getInstance(IconRegistry::class)->render($this->resolveMenuIconName($name), $size);
    }

    private function resolveMenuIconName(string $icon): string
    {
        $registry = ObjectManager::getInstance(IconRegistry::class);
        $icon = trim($icon);
        if ($icon !== '' && $registry->has($icon)) {
            return $icon;
        }
        if ($icon === '') {
            return 'circle';
        }

        $legacyMap = ObjectManager::getInstance(\Weline\Backend\Setup\Ui\LegacyIconNameMap::class);
        foreach ([$icon, 'mdi mdi-' . ltrim($icon, '-'), 'mdi-' . ltrim($icon, '-')] as $candidate) {
            $mapped = $legacyMap->map($candidate);
            if ($mapped !== null && $registry->has($mapped)) {
                return $mapped;
            }
        }

        return 'circle';
    }

    public function translateMenuTitle(string $title, string $sourceId = ''): string
    {
        if ($title === '') {
            return '';
        }

        return htmlspecialchars($this->resolveDisplayTitle($title, $sourceId), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 当前界面语言下的菜单展示标题（未转义）。
     */
    public function resolveDisplayTitle(string $title, string $sourceId = ''): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        $resolved = $this->resolveMenuTitleRaw($title, $sourceId, State::getLangLocal());
        if ($resolved === $title) {
            $phrase = trim((string)__($title));
            if ($phrase !== '') {
                $resolved = $phrase;
            }
        }

        return $resolved;
    }

    /**
     * 侧栏 / 顶栏共用：source 原文 + 全部已启用 locale 译文（缺译回退原文）。
     */
    public function buildCrossLocaleSearchText(string $sourceName, string $sourceId = ''): string
    {
        $sourceName = trim($sourceName);
        if ($sourceName === '') {
            return '';
        }

        $parts = [];
        $seen = [];
        $push = static function (string $text) use (&$parts, &$seen): void {
            $text = trim($text);
            if ($text === '') {
                return;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $parts[] = $text;
        };

        $push($sourceName);
        foreach ($this->getActiveLocaleCodes() as $localeCode) {
            $push($this->resolveMenuTitleRaw($sourceName, $sourceId, $localeCode));
        }
        $push($this->resolveDisplayTitle($sourceName, $sourceId));

        return implode(' ', $parts);
    }

    /**
     * 顶栏万能搜索：可导航菜单项（含跨语言 search_text）。
     *
     * @param array<int, array<string, mixed>>|null $menus
     * @return list<array{source_id:string,title:string,url:string,search_text:string}>
     */
    public function collectNavigableMenuSearchItems(?array $menus = null): array
    {
        $menus ??= $this->getMenuTree();
        $this->cachedBackendUrlPrefix = $this->getBackendUrlPrefix();
        $this->cachedFrontendUrlPrefix = $this->getFrontendUrlPrefix();
        $this->prefetchCrossLocaleMenuWords($this->collectMenuTitles($menus));

        $items = [];
        $this->walkNavigableMenuSearchItems($menus, $items);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @param list<array{source_id:string,title:string,url:string,search_text:string}> $items
     */
    private function walkNavigableMenuSearchItems(array $menus, array &$items): void
    {
        foreach ($menus as $menu) {
            if (!$this->isMenuEnabled($menu)) {
                continue;
            }

            $route = trim((string)($menu['route'] ?? ''));
            $sourceId = (string)($menu['source_id'] ?? '');
            $sourceName = (string)($menu['source_name'] ?? '');
            if ($route !== '' && $sourceId !== '') {
                $items[] = [
                    'source_id' => $sourceId,
                    'title' => $this->resolveDisplayTitle($sourceName, $sourceId),
                    'url' => $this->formatMenuUrlCached($menu),
                    'search_text' => $this->buildCrossLocaleSearchText($sourceName, $sourceId),
                ];
            }

            $nodes = array_values(array_filter(
                is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [],
                static fn(array $node): bool => ($node['type'] ?? '') === 'menus',
            ));
            if ($nodes !== []) {
                $this->walkNavigableMenuSearchItems($nodes, $items);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getActiveLocaleCodes(): array
    {
        if ($this->activeLocaleCodes !== null) {
            return $this->activeLocaleCodes;
        }

        $codes = [];
        try {
            if (class_exists(ActiveLocaleCodeProvider::class)) {
                $codes = ObjectManager::getInstance(ActiveLocaleCodeProvider::class)->getInstalledActiveCodes();
            }
        } catch (\Throwable) {
            $codes = [];
        }

        if ($codes === []) {
            $codes = array_values(array_unique(array_filter([
                State::getLangLocal(),
                'en_US',
                'zh_Hans_CN',
            ], static fn(string $code): bool => trim($code) !== '')));
        }

        return $this->activeLocaleCodes = $codes;
    }

    /**
     * 指定 locale 下的菜单标题；无译文时回退 source 原文。
     * 词典只走模块 CSV + Phrase 词条预取缓存，禁止 include 整本 generated/language。
     */
    public function resolveMenuTitleRaw(string $title, string $sourceId, string $localeCode): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        $moduleName = $this->extractModuleNameFromSource($sourceId);
        if ($moduleName !== '') {
            $moduleWords = $this->getModuleLocaleWords($moduleName, $localeCode);
            $moduleTranslate = trim((string)($moduleWords[$title] ?? ''));
            if ($moduleTranslate !== '') {
                return $moduleTranslate;
            }
        }

        $prefetched = trim((string)(Parser::getPrefetchedGlobalWord($localeCode, $title) ?? ''));
        if ($prefetched !== '') {
            return $prefetched;
        }

        return $title;
    }

    /**
     * 对全部已启用 locale 批量预取菜单标题词条（Phrase Worker/Shared），只执行一次。
     *
     * @param list<string> $titles
     */
    private function prefetchCrossLocaleMenuWords(array $titles): void
    {
        if ($this->crossLocaleWordsPrefetched) {
            return;
        }
        $this->crossLocaleWordsPrefetched = true;

        $words = \array_values(\array_unique(\array_filter(
            \array_map(static fn(mixed $word): string => \trim((string)$word), $titles),
            static fn(string $word): bool => $word !== '',
        )));
        if ($words === []) {
            return;
        }

        $locales = $this->getActiveLocaleCodes();
        if ($locales === []) {
            Parser::prefetchWords($words);

            return;
        }

        foreach ($locales as $localeCode) {
            $localeCode = \trim((string)$localeCode);
            if ($localeCode === '') {
                continue;
            }
            try {
                Parser::prefetchWords($words, $localeCode);
            } catch (\Throwable) {
                // 单 locale 预取失败不阻断菜单渲染；resolve 时回退 source。
            }
        }
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
