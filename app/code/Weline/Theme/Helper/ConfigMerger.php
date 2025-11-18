<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题配置合并助手类
 * 
 * 支持JSON配置文件的深度合并，处理主题继承链
 */
class ConfigMerger
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;

    public function __construct(WelineTheme $welineTheme)
    {
        $this->welineTheme = $welineTheme;
    }

    /**
     * 合并主题配置（支持继承链）
     * 
     * @param string $configFile 配置文件名称（如 theme.json, modules.json）
     * @param string $area 区域（frontend/backend）
     * @param WelineTheme|null $theme 主题对象，如果为null则使用激活的主题
     * @return array 合并后的配置数组
     */
    public function mergeConfig(string $configFile, string $area, ?WelineTheme $theme = null): array
    {
        if ($theme === null) {
            $theme = $this->welineTheme->getActiveTheme();
        }

        $config = [];

        // 1. 基础配置（Weline_Theme模块）
        $baseConfigPath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'config' . DS . $configFile;
        if (is_file($baseConfigPath)) {
            $baseConfig = $this->loadJsonFile($baseConfigPath);
            if (is_array($baseConfig)) {
                $config = $this->arrayMergeRecursive($config, $baseConfig);
            }
        }

        // 2. 获取主题继承链（从基础到当前）
        $themeChain = $this->getThemeChain($theme);

        // 3. 按继承链顺序合并配置（父主题在前，子主题在后）
        foreach ($themeChain as $chainTheme) {
            $themeConfigPath = $chainTheme->getPath() . 'Weline_Theme' . DS . 'view' . DS . 'theme' . DS . $area . DS . 'config' . DS . $configFile;
            if (is_file($themeConfigPath)) {
                $themeConfig = $this->loadJsonFile($themeConfigPath);
                if (is_array($themeConfig)) {
                    // 子主题配置覆盖父主题配置
                    $config = $this->arrayMergeRecursive($config, $themeConfig);
                }
            }
        }

        return $config;
    }

    /**
     * 获取主题继承链（从基础到当前）
     * 
     * @param WelineTheme $theme 当前主题
     * @return WelineTheme[] 主题继承链数组
     */
    private function getThemeChain(WelineTheme $theme): array
    {
        $chain = [];
        $visited = [];
        $currentTheme = $theme;

        // 递归收集父主题
        while ($currentTheme && $currentTheme->getId()) {
            $themeId = $currentTheme->getId();
            
            // 防止循环引用
            if (in_array($themeId, $visited)) {
                break;
            }
            $visited[] = $themeId;

            // 将父主题添加到链的前面（保证顺序：基础 → 父 → 子）
            array_unshift($chain, $currentTheme);

            // 获取父主题
            $parentId = $currentTheme->getParentId();
            if ($parentId) {
                try {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        $currentTheme = $parentTheme;
                    } else {
                        break;
                    }
                } catch (\Exception $e) {
                    break;
                }
            } else {
                break;
            }
        }

        return $chain;
    }

    /**
     * 加载JSON文件
     * 
     * @param string $filePath 文件路径
     * @return array|null JSON解析后的数组，失败返回null
     */
    private function loadJsonFile(string $filePath): ?array
    {
        if (!is_file($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * 递归合并数组（深度合并）
     * 
     * 子数组的值会覆盖父数组的值
     * 对于数组类型的值，会递归合并
     * 
     * @param array $base 基础数组
     * @param array $override 覆盖数组
     * @return array 合并后的数组
     */
    private function arrayMergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                // 如果两个值都是数组，递归合并
                $base[$key] = $this->arrayMergeRecursive($base[$key], $value);
            } else {
                // 否则直接覆盖
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

