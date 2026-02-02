<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Theme\Cache\ThemeCache;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局数据服务
 * 
 * 负责收集和缓存所有主题的布局类型
 * 在系统更新/模块重装时自动扫描布局目录
 */
class LayoutDataService
{
    /**
     * 缓存时间（7天）
     */
    private const CACHE_TIME = 604800;

    /**
     * 布局类型缓存键
     */
    private const CACHE_KEY_LAYOUT_TYPES = 'layout_types_all';

    /**
     * 主题布局缓存键前缀
     */
    private const CACHE_KEY_THEME_LAYOUTS = 'layout_types_theme_';

    private ThemeCache $cache;
    private WelineTheme $welineTheme;

    public function __construct(
        ThemeCache $cache,
        WelineTheme $welineTheme
    ) {
        $this->cache = $cache;
        $this->welineTheme = $welineTheme;
    }

    /**
     * 收集所有布局类型
     * 
     * 扫描默认主题和所有已注册主题的布局目录
     * 结果存入缓存
     *
     * @param bool $force 强制重新扫描（忽略缓存）
     * @return array 所有布局类型 ['layout_code' => '布局名称', ...]
     */
    public function collectLayouts(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cache->get(self::CACHE_KEY_LAYOUT_TYPES);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }

        $layoutTypes = [];

        // 1. 扫描默认主题（Weline_Theme 模块）的布局
        $defaultLayouts = $this->scanDefaultThemeLayouts();
        foreach ($defaultLayouts as $type => $options) {
            if (!isset($layoutTypes[$type])) {
                $layoutTypes[$type] = $this->getLayoutTypeName($type, $options);
            }
        }

        // 2. 扫描所有已注册主题的布局
        $themes = $this->getAllThemes();
        foreach ($themes as $theme) {
            $themeLayouts = LayoutScanner::scanLayouts($theme, 'frontend', false);
            foreach ($themeLayouts as $type => $options) {
                if (!isset($layoutTypes[$type])) {
                    $layoutTypes[$type] = $this->getLayoutTypeName($type, $options);
                }
            }
        }

        // 按键名排序
        ksort($layoutTypes);

        // 存入缓存
        $this->cache->set(self::CACHE_KEY_LAYOUT_TYPES, $layoutTypes, self::CACHE_TIME);

        return $layoutTypes;
    }

    /**
     * 获取所有布局类型
     * 
     * 运行时使用，优先从缓存读取
     *
     * @return array 所有布局类型 ['layout_code' => '布局名称', ...]
     */
    public function getAllLayoutTypes(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY_LAYOUT_TYPES);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // 缓存不存在，执行收集
        return $this->collectLayouts(true);
    }

    /**
     * 获取指定主题可用的布局类型
     * 
     * 考虑主题继承关系
     *
     * @param int $themeId 主题ID
     * @return array 布局类型数组
     */
    public function getLayoutTypesForTheme(int $themeId): array
    {
        $cacheKey = self::CACHE_KEY_THEME_LAYOUTS . $themeId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $layoutTypes = [];

        // 加载主题
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);

        if (!$theme->getId()) {
            // 主题不存在，返回默认布局
            return $this->getAllLayoutTypes();
        }

        // 扫描主题布局（包含继承）
        $themeLayouts = LayoutScanner::scanLayouts($theme, 'frontend', true);
        foreach ($themeLayouts as $type => $options) {
            $layoutTypes[$type] = $this->getLayoutTypeName($type, $options);
        }

        // 按键名排序
        ksort($layoutTypes);

        // 存入缓存
        $this->cache->set($cacheKey, $layoutTypes, self::CACHE_TIME);

        return $layoutTypes;
    }

    /**
     * 清除布局缓存
     */
    public function clearCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_LAYOUT_TYPES);
        
        // 清除所有主题的布局缓存
        $themes = $this->getAllThemes();
        foreach ($themes as $theme) {
            $this->cache->delete(self::CACHE_KEY_THEME_LAYOUTS . $theme->getId());
        }
    }

    /**
     * 扫描默认主题（Weline_Theme 模块）的布局
     *
     * @return array
     */
    private function scanDefaultThemeLayouts(): array
    {
        $layouts = [];
        $modules = Env::getInstance()->getModuleList();

        if (!isset($modules['Weline_Theme'])) {
            return $layouts;
        }

        $themeModule = $modules['Weline_Theme'];
        $defaultLayoutsDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'layouts';

        if (is_dir($defaultLayoutsDir)) {
            $layouts = $this->scanLayoutsFromDir($defaultLayoutsDir);
        }

        return $layouts;
    }

    /**
     * 从目录扫描布局类型
     *
     * @param string $layoutsDir 布局目录路径
     * @return array
     */
    private function scanLayoutsFromDir(string $layoutsDir): array
    {
        $layouts = [];

        if (!is_dir($layoutsDir)) {
            return $layouts;
        }

        // 扫描一级子目录（每个子目录代表一个布局类型）
        $dirs = glob($layoutsDir . DS . '*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $layoutType = basename($dir);
            
            // 扫描该布局类型下的所有 .phtml 文件
            $files = glob($dir . DS . '*.phtml');
            if (!empty($files)) {
                $options = [];
                foreach ($files as $file) {
                    $fileName = basename($file, '.phtml');
                    $meta = LayoutScanner::extractLayoutMeta($file, 'frontend');
                    $options[] = [
                        'value' => $fileName,
                        'meta' => $meta,
                        'file' => $fileName . '.phtml'
                    ];
                }
                $layouts[$layoutType] = $options;
            }
        }

        return $layouts;
    }

    /**
     * 获取布局类型的显示名称
     *
     * @param string $type 布局类型代码
     * @param array $options 布局选项数组
     * @return string
     */
    private function getLayoutTypeName(string $type, array $options): string
    {
        // 优先使用 meta 中的名称
        foreach ($options as $option) {
            if (!empty($option['meta']['name'])) {
                // 如果 meta.name 与 option.value 相同（如 "Default"），则使用类型名
                if (strtolower($option['meta']['name']) !== strtolower($option['value'])) {
                    return $option['meta']['name'];
                }
            }
        }

        // 使用预定义的名称映射
        $nameMap = [
            'homepage' => __('首页'),
            'category' => __('分类页'),
            'product' => __('产品页'),
            'product_list' => __('产品列表页'),
            'cms_page' => __('CMS页面'),
            'cart' => __('购物车'),
            'checkout' => __('结算页'),
            'checkout_success' => __('结算成功页'),
            'checkout_failer' => __('结算失败页'),
            'account' => __('账户中心'),
            'account_auth' => __('账户认证'),
            'account_logout' => __('退出登录'),
            'account_orders' => __('订单列表'),
            'account_profile' => __('个人资料'),
            'search' => __('搜索页'),
            'default' => __('默认布局'),
            'policy' => __('政策页面'),
            'activity' => __('活动页'),
            'test' => __('测试页'),
        ];

        if (isset($nameMap[$type])) {
            return $nameMap[$type];
        }

        // 默认：将下划线转换为空格，首字母大写
        return ucwords(str_replace('_', ' ', $type));
    }

    /**
     * 获取所有已注册的主题
     *
     * @return WelineTheme[]
     */
    private function getAllThemes(): array
    {
        $themes = [];
        
        try {
            $themeModel = clone $this->welineTheme;
            $themeModel->clearData()->clearQuery();
            $items = $themeModel->select()->fetchArray();
            
            if (is_array($items)) {
                foreach ($items as $item) {
                    $theme = clone $this->welineTheme;
                    $theme->clearData()->setData($item);
                    $themes[] = $theme;
                }
            }
        } catch (\Exception $e) {
            // 数据库可能未初始化，忽略错误
        }

        return $themes;
    }
}
