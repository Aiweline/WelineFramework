<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * CSS变量注入器
 * 
 * 从Meta系统读取变量配置，生成CSS变量定义并注入到CSS文件开头
 */
class CssVariableInjector
{
    private const MAX_CSS_VARIABLE_VALUE_LENGTH = 1024;
    private const CSS_VARIABLE_NAME_PATTERN = '/^--[A-Za-z0-9_-]+$/';
    private const CSS_VARIABLE_FORBIDDEN_VALUE_PATTERN = '/\/\*|\*\/|@import\b|url\s*\(|expression\s*\(|javascript\s*:|data\s*:/i';

    /**
     * 生成CSS变量定义
     * 
     * @param string $area 区域（frontend/backend）
     * @param WelineTheme|null $theme 主题对象，如果为null则使用当前激活主题
     * @param string $scope 作用域，默认'default'
     * @return string CSS变量定义（:root { ... }）
     */
    public function generateCssVariables(string $area, ?WelineTheme $theme = null, string $scope = 'default'): string
    {
        $cssVariables = $this->sanitizeVariables($this->collectVariables($area, $theme, $scope));
        
        if (empty($cssVariables)) {
            return '';
        }
        
        $css = ":root {\n";
        
        // 按变量文件分组
        $grouped = [];
        foreach ($cssVariables as $varName => $varValue) {
            // 从变量名推断文件（如 --color-primary 来自 colors 文件）
            $file = $this->inferVariableFile($varName);
            if (!isset($grouped[$file])) {
                $grouped[$file] = [];
            }
            $grouped[$file][] = ['name' => $varName, 'value' => $varValue];
        }
        
        // 按文件分组输出，添加注释
        foreach ($grouped as $file => $vars) {
            $categoryName = $this->getCategoryName($file);
            $css .= "    /* ========== {$categoryName} ========== */\n";
            foreach ($vars as $var) {
                $css .= "    {$var['name']}: {$var['value']};\n";
            }
            $css .= "\n";
        }
        
        $css .= "}\n";
        
        return $css;
    }

    /**
     * @param array $variables 变量数组 [变量名 => 变量值]
     * @return array 安全变量数组 [变量名 => 变量值]
     */
    private function sanitizeVariables(array $variables): array
    {
        $safeVariables = [];

        foreach ($variables as $varName => $varValue) {
            $safeName = $this->sanitizeCssVariableName((string)$varName);
            if ($safeName === null) {
                continue;
            }

            $safeValue = $this->sanitizeCssVariableValue($varValue);
            if ($safeValue === null) {
                continue;
            }

            $safeVariables[$safeName] = $safeValue;
        }

        return $safeVariables;
    }

    private function sanitizeCssVariableName(string $varName): ?string
    {
        $varName = trim($varName);

        if (preg_match(self::CSS_VARIABLE_NAME_PATTERN, $varName) !== 1) {
            return null;
        }

        return $varName;
    }

    private function sanitizeCssVariableValue(mixed $varValue): ?string
    {
        if (is_array($varValue) || is_object($varValue) || is_resource($varValue)) {
            return null;
        }

        $varValue = trim((string)$varValue);
        if ($varValue === '' || strlen($varValue) > self::MAX_CSS_VARIABLE_VALUE_LENGTH) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $varValue) === 1) {
            return null;
        }

        if (preg_match('/[;{}<>\\\\]/', $varValue) === 1) {
            return null;
        }

        if (preg_match(self::CSS_VARIABLE_FORBIDDEN_VALUE_PATTERN, $varValue) === 1) {
            return null;
        }

        return $varValue;
    }
    
    /**
     * 收集所有变量值
     * 
     * @param string $area 区域
     * @param WelineTheme|null $theme 主题对象
     * @param string $scope 作用域
     * @return array 变量数组 [变量名 => 变量值]
     */
    private function collectVariables(string $area, ?WelineTheme $theme, string $scope): array
    {
        $variables = [];
        
        // 1. 优先从Meta系统读取变量配置
        $metaVariables = $this->getVariablesFromMeta($area, $theme, $scope);
        $variables = array_merge($variables, $metaVariables);
        
        // 2. 从色盘配置读取变量值（如果变量属于色盘）
        $paletteVariables = $this->getVariablesFromPalette($area, $theme, $scope);
        // 色盘值覆盖Meta值（如果存在）
        foreach ($paletteVariables as $varName => $varValue) {
            $variables[$varName] = $varValue;
        }
        
        // 3. 从variables文件读取默认值（仅当Meta中不存在时）
        $defaultVariables = $this->getVariablesFromFiles($area, $theme);
        foreach ($defaultVariables as $varName => $varValue) {
            if (!isset($variables[$varName])) {
                $variables[$varName] = $varValue;
            }
        }
        
        return $variables;
    }
    
    /**
     * 从Meta系统读取变量配置
     * 
     * @param string $area 区域
     * @param WelineTheme|null $theme 主题对象
     * @param string $scope 作用域
     * @return array 变量数组
     */
    private function getVariablesFromMeta(string $area, ?WelineTheme $theme, string $scope): array
    {
        $variables = [];
        
        try {
            // 使用ThemeData获取变量配置
            $configList = ThemeData::getConfigList($area, 'variables', $scope);
            
            foreach ($configList as $configKey => $configValue) {
                // configKey格式: variables.{variableFile}.{variableName}.value
                if (preg_match('/^variables\.([^.]+)\.([^.]+)\.value$/', $configKey, $matches)) {
                    $variableFile = $matches[1];
                    $variableName = $matches[2];
                    $cssVarName = '--' . $variableName;
                    
                    // 处理配置值（可能是字符串或数组）
                    $value = is_array($configValue) ? json_encode($configValue) : (string)$configValue;
                    $variables[$cssVarName] = $value;
                }
            }
        } catch (\Exception $e) {
            // 获取失败，返回空数组
            if (defined('DEV') && DEV) {
                w_log_error('从Meta读取变量配置失败: ' . $e->getMessage());
            }
        }
        
        return $variables;
    }
    
    /**
     * 从色盘配置读取变量值
     * 
     * @param string $area 区域
     * @param WelineTheme|null $theme 主题对象
     * @param string $scope 作用域
     * @return array 变量数组
     */
    private function getVariablesFromPalette(string $area, ?WelineTheme $theme, string $scope): array
    {
        $variables = [];
        
        try {
            // 获取色盘配置
            $colorConfig = ThemeData::getColorConfig($area, $scope);
            
            if ($colorConfig) {
                // 从色盘Meta读取变量值
                $paletteMeta = ThemeData::getMeta("theme.{$area}.colors.{$colorConfig}");
                
                if ($paletteMeta && isset($paletteMeta['meta_data']['variables'])) {
                    $paletteVars = $paletteMeta['meta_data']['variables'];
                    foreach ($paletteVars as $varName => $varValue) {
                        $cssVarName = '--' . $varName;
                        $variables[$cssVarName] = (string)$varValue;
                    }
                }
            }
        } catch (\Exception $e) {
            // 获取失败，返回空数组
            if (defined('DEV') && DEV) {
                w_log_error('从色盘读取变量配置失败: ' . $e->getMessage());
            }
        }
        
        return $variables;
    }
    
    /**
     * 从variables文件读取默认值
     * 
     * @param string $area 区域
     * @param WelineTheme|null $theme 主题对象
     * @return array 变量数组
     */
    private function getVariablesFromFiles(string $area, ?WelineTheme $theme): array
    {
        $variables = [];
        
        // 获取variables目录
        $variablesDirs = $this->getVariablesDirectories($theme, $area);
        
        foreach ($variablesDirs as $variablesDir) {
            if (!is_dir($variablesDir)) {
                continue;
            }
            
            $variableFiles = glob($variablesDir . DS . '_*.css');
            
            foreach ($variableFiles as $filePath) {
                $content = file_get_contents($filePath);
                
                // 提取CSS变量
                if (preg_match_all('/--([\w-]+)\s*:\s*([^;]+);/', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $varName = '--' . $match[1];
                        $varValue = trim($match[2]);
                        
                        // 仅当Meta中不存在时添加
                        if (!isset($variables[$varName])) {
                            $variables[$varName] = $varValue;
                        }
                    }
                }
            }
        }
        
        return $variables;
    }
    
    /**
     * 获取variables目录路径
     * 
     * @param WelineTheme|null $theme 主题对象
     * @param string $area 区域
     * @return array 目录路径数组
     */
    private function getVariablesDirectories(?WelineTheme $theme, string $area): array
    {
        $directories = [];
        
        if ($theme && $theme->getId()) {
            $themePath = $theme->getPath();
            if (!empty($themePath)) {
                $themeVariablesDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
                if (is_dir($themeVariablesDir)) {
                    $directories[] = $themeVariablesDir;
                }
            }
        }
        
        // 默认主题（Weline_Theme模块）
        $modules = Env::getInstance()->getModuleList();
        if (isset($modules['Weline_Theme'])) {
            $themeModule = $modules['Weline_Theme'];
            $defaultVariablesDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
            if (is_dir($defaultVariablesDir)) {
                $directories[] = $defaultVariablesDir;
            }
        }
        
        return $directories;
    }
    
    /**
     * 从变量名推断变量文件
     * 
     * @param string $varName CSS变量名（如 --color-primary）
     * @return string 变量文件名（如 colors）
     */
    private function inferVariableFile(string $varName): string
    {
        // 移除 -- 前缀
        $name = substr($varName, 2);
        
        // 根据前缀推断
        if (strpos($name, 'color-') === 0) {
            return 'colors';
        }
        if (strpos($name, 'spacing-') === 0 || strpos($name, 'space-') === 0) {
            return 'spacing';
        }
        if (strpos($name, 'font-') === 0 || strpos($name, 'text-') === 0 || strpos($name, 'typography-') === 0) {
            return 'typography';
        }
        if (strpos($name, 'shadow-') === 0) {
            return 'shadows';
        }
        if (strpos($name, 'border-') === 0) {
            return 'borders';
        }
        
        return 'other';
    }
    
    /**
     * 获取分类名称
     * 
     * @param string $file 变量文件名
     * @return string 分类名称
     */
    private function getCategoryName(string $file): string
    {
        $nameMap = [
            'colors' => '颜色变量',
            'spacing' => '间距变量',
            'typography' => '字体变量',
            'shadows' => '阴影变量',
            'borders' => '边框变量',
            'other' => '其他变量'
        ];
        
        return $nameMap[$file] ?? ucfirst($file);
    }
}

