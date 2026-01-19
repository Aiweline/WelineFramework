<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题配置合并助手类
 * 
 * 职责：合并主题配置，支持JSON配置文件的深度合并，处理主题继承链
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
class ConfigMerger
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;
    
    /**
     * @var ThemeChainResolverInterface
     */
    private ThemeChainResolverInterface $themeChainResolver;

    /**
     * 依赖注入：遵循依赖倒置原则 (DIP)
     * 
     * @param WelineTheme $welineTheme
     * @param ThemeChainResolverInterface $themeChainResolver
     */
    public function __construct(
        WelineTheme $welineTheme,
        ThemeChainResolverInterface $themeChainResolver
    ) {
        $this->welineTheme = $welineTheme;
        $this->themeChainResolver = $themeChainResolver;
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
        $themeChain = $this->themeChainResolver->getThemeChain($theme);

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

