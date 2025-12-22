<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\CssVariableParser;
use Weline\Theme\Helper\LayoutAssetsManager;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * CSS变量配置控制器
 * 
 * 提供变量编辑界面和调色盘功能
 */
class Variables extends BackendController
{
    /**
     * 变量配置页面
     */
    public function getIndex()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', 'default');
        
        if (!$themeId) {
            return $this->fetchJson(['code' => 400, 'msg' => __('请选择主题')]);
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
        }
        
        // 获取所有变量（从Meta系统）
        $variables = $this->getVariablesList($area, $scope);
        
        // 构建变量文件列表
        $variableFiles = [];
        $configList = ThemeData::getConfigList($area, 'variables', $scope);
        
        foreach ($variables as $file => $fileData) {
            $configuredCount = 0;
            foreach ($fileData['variables'] as $var) {
                $configKey = "variables.{$file}.{$var['varName']}.value";
                if (isset($configList[$configKey]) && $configList[$configKey] !== $var['default']) {
                    $configuredCount++;
                }
            }
            
            $variableFiles[$file] = [
                'name' => $fileData['name'],
                'variables' => $fileData['variables'],
                'configured_count' => $configuredCount
            ];
        }
        
        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('variableFiles', $variableFiles);
        
        return $this->fetch('Weline_Theme::templates/backend/config/variable-file.phtml');
    }
    
    /**
     * 获取变量列表（AJAX）
     */
    public function getVariables()
    {
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', 'default');
        
        $variables = $this->getVariablesList($area, $scope);
        
        return $this->fetchJson([
            'code' => 200,
            'data' => $variables
        ]);
    }
    
    /**
     * 获取指定变量文件的变量列表（AJAX）
     */
    public function getVariableFile()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', 'default');
        $variableFile = $this->request->getParam('file'); // colors, spacing, typography等
        
        if (!$themeId || !$variableFile) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数错误')]);
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
        }
        
        // 获取变量列表
        $variables = $this->getVariablesList($area, $scope);
        $fileVariables = $variables[$variableFile] ?? ['name' => $this->getVariableFileName($variableFile), 'variables' => []];
        
        // 按分类分组
        $grouped = [];
        foreach ($fileVariables['variables'] as $var) {
            $category = $var['category'] ?? '其他';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $var;
        }
        
        $this->assign('theme', $theme);
        $this->assign('area', $area);
        $this->assign('scope', $scope);
        $this->assign('file', $variableFile);
        $this->assign('data', [
            'file' => $variableFile,
            'name' => $fileVariables['name'],
            'categories' => $grouped
        ]);
        
        return $this->fetch('Weline_Theme::templates/backend/config/variable-form.phtml');
    }
    
    /**
     * 保存单个变量值（AJAX）
     */
    public function postSaveVariable()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', 'default');
        $metaIdentify = $this->request->getParam('meta_identify');
        $value = $this->request->getParam('value');
        
        if (!$themeId || !$metaIdentify || $value === null) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数错误')]);
        }
        
        try {
            // 设置当前主题
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 获取变量元数据以进行验证
            $varMeta = ThemeData::getMeta($metaIdentify);
            $validatedValue = $value;
            if ($varMeta && !empty($varMeta['meta_data'])) {
                $varType = $varMeta['meta_data']['variable_type'] ?? 'other';
                // 验证和规范化值
                $validatedValue = CssVariableParser::normalizeValue((string)$value, $varType);
            }
            
            // 保存变量值
            $identify = "{$metaIdentify}.value";
            ThemeData::set($identify, (string)$validatedValue, $scope);
            
            // 清除CSS缓存
            $this->clearCssCache($area, $theme);
            
            // 生成预览URL
            $previewUrl = PreviewManager::refreshPreviewUrl($theme->getId(), $area);
            
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'preview_url' => $previewUrl
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => __('保存失败: ') . $e->getMessage()]);
        }
    }
    
    /**
     * 保存变量值（AJAX）- 批量保存
     */
    public function postSave()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', 'default');
        $variables = $this->request->getParam('variables', []);
        
        if (!$themeId || empty($variables)) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数错误')]);
        }
        
        try {
            // 设置当前主题
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            
            // 保存变量值（带验证）
            foreach ($variables as $varKey => $varValue) {
                // varKey格式: variables.{variableFile}.{variableName} 或 meta_identify
                if (strpos($varKey, 'theme.') === 0) {
                    // 直接是meta_identify
                    $identify = "{$varKey}.value";
                    $metaIdentify = $varKey;
                } else {
                    // 格式: variables.{variableFile}.{variableName}
                    $identify = "theme.{$area}.{$varKey}.value";
                    $metaIdentify = "theme.{$area}.{$varKey}";
                }
                
                // 获取变量元数据以进行验证
                $varMeta = ThemeData::getMeta($metaIdentify);
                if ($varMeta && !empty($varMeta['meta_data'])) {
                    $varType = $varMeta['meta_data']['variable_type'] ?? 'other';
                    // 验证和规范化值
                    $varValue = CssVariableParser::normalizeValue((string)$varValue, $varType);
                }
                
                ThemeData::set($identify, (string)$varValue, $scope);
            }
            
            // 清除CSS缓存
            $this->clearCssCache($area, $theme);
            
            // 生成预览URL
            $previewUrl = PreviewManager::refreshPreviewUrl($theme->getId(), $area);
            
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'preview_url' => $previewUrl
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => __('保存失败: ') . $e->getMessage()]);
        }
    }
    
    /**
     * 批量保存变量值（AJAX）
     */
    public function postSaveVariables()
    {
        return $this->postSave(); // 复用postSave方法
    }
    
    /**
     * 刷新预览（AJAX）
     */
    public function postRefreshPreview()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        
        if (!$themeId) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数错误')]);
        }
        
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
            
            if (!$theme->getId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('主题不存在')]);
            }
            
            // 清除预览缓存
            PreviewManager::clearPreviewCache($theme->getId(), $area);
            
            // 清除CSS缓存
            $this->clearCssCache($area, $theme);
            
            // 生成预览URL
            $previewUrl = PreviewManager::refreshPreviewUrl($theme->getId(), $area);
            
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('预览已刷新'),
                'preview_url' => $previewUrl
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => __('刷新失败: ') . $e->getMessage()]);
        }
    }
    
    /**
     * 应用色盘（AJAX）
     */
    public function postApplyPalette()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $scope = $this->request->getParam('scope', 'default');
        $paletteName = $this->request->getParam('palette');
        
        if (!$themeId || !$paletteName) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数错误')]);
        }
        
        try {
            // 设置色盘配置
            $identify = "theme.{$area}.colors.primary.value";
            ThemeData::set($identify, $paletteName, $scope);
            
            // 获取色盘的变量值并应用
            $paletteMeta = ThemeData::getMeta("theme.{$area}.colors.{$paletteName}");
            if ($paletteMeta && isset($paletteMeta['meta_data']['variables'])) {
                $paletteVars = $paletteMeta['meta_data']['variables'];
                
                /** @var WelineTheme $theme */
                $theme = ObjectManager::getInstance(WelineTheme::class);
                $theme->load($themeId);
                ThemeData::setCurrentTheme($theme);
                ThemeData::setCurrentArea($area);
                
                foreach ($paletteVars as $varName => $varValue) {
                    // 查找对应的变量identify
                    // 这里需要根据变量名查找对应的Meta记录
                    $varMetaList = ThemeData::getMetaList($area, 'variables');
                    foreach ($varMetaList as $varMeta) {
                        $metaData = $varMeta['meta_data'] ?? [];
                        if (isset($metaData['variable_name']) && $metaData['variable_name'] === $varName) {
                            $identify = $varMeta['meta_identify'] . '.value';
                            ThemeData::set($identify, (string)$varValue, $scope);
                            break;
                        }
                    }
                }
            }
            
            return $this->fetchJson(['code' => 200, 'msg' => __('色盘应用成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => __('应用失败: ') . $e->getMessage()]);
        }
    }
    
    /**
     * 获取变量列表
     * 
     * @param string $area 区域
     * @param string $scope 作用域
     * @return array 变量列表
     */
    private function getVariablesList(string $area, string $scope = 'default'): array
    {
        $variables = [];
        
        // 从Meta系统获取变量列表
        $varMetaList = ThemeData::getMetaList($area, 'variables');
        
        // 获取变量配置值
        $configList = ThemeData::getConfigList($area, 'variables', $scope);
        
        // 按变量文件分组
        $grouped = [];
        foreach ($varMetaList as $varMeta) {
            $metaData = $varMeta['meta_data'] ?? [];
            $variableFile = $metaData['file'] ?? 'other';
            $variableName = $metaData['variable_name'] ?? '';
            
            if (empty($variableName)) {
                continue;
            }
            
            // 提取变量名（不含--前缀）
            $varNameWithoutPrefix = substr($variableName, 2);
            
            // 获取配置值
            // configKey格式: variables.{file}.{varNameWithoutPrefix}.value
            $configKey = "variables.{$variableFile}.{$varNameWithoutPrefix}.value";
            $value = $configList[$configKey] ?? $metaData['default_value'] ?? '';
            
            if (!isset($grouped[$variableFile])) {
                $grouped[$variableFile] = [
                    'name' => $this->getVariableFileName($variableFile),
                    'variables' => []
                ];
            }
            
            $grouped[$variableFile]['variables'][] = [
                'name' => $variableName,
                'cssName' => $variableName,
                'varName' => $varNameWithoutPrefix,
                'value' => $value,
                'default' => $metaData['default_value'] ?? '',
                'type' => $metaData['variable_type'] ?? 'other',
                'is_color' => !empty($metaData['is_color']),
                'description' => $metaData['description'] ?? '',
                'category' => $metaData['category'] ?? '其他',
                'meta_identify' => $varMeta['meta_identify']
            ];
        }
        
        return $grouped;
    }
    
    /**
     * 获取色盘列表
     * 
     * @param string $area 区域
     * @return array 色盘列表
     */
    private function getPalettesList(string $area): array
    {
        $palettes = [];
        
        // 从Meta系统获取色盘列表
        $colorMetaList = ThemeData::getMetaList($area, 'colors');
        
        foreach ($colorMetaList as $colorMeta) {
            $metaData = $colorMeta['meta_data'] ?? [];
            $identify = $colorMeta['meta_identify'] ?? '';
            
            // 提取色盘名称
            if (preg_match('/colors\.(.+)$/', $identify, $matches)) {
                $paletteName = $matches[1];
                $palettes[] = [
                    'name' => $paletteName,
                    'displayName' => $metaData['name'] ?? ucfirst($paletteName),
                    'description' => $metaData['description'] ?? '',
                    'variables' => $metaData['variables'] ?? []
                ];
            }
        }
        
        return $palettes;
    }
    
    /**
     * 获取变量文件名显示名称
     * 
     * @param string $file 文件名
     * @return string 显示名称
     */
    private function getVariableFileName(string $file): string
    {
        $nameMap = [
            'colors' => '颜色变量',
            'spacing' => '间距变量',
            'typography' => '字体变量',
            'shadows' => '阴影变量',
            'borders' => '边框变量'
        ];
        
        return $nameMap[$file] ?? ucfirst($file);
    }
    
    /**
     * 清除CSS缓存
     * 
     * @param string $area 区域
     * @param WelineTheme $theme 主题对象
     * @return void
     */
    private function clearCssCache(string $area, WelineTheme $theme): void
    {
        try {
            // 清除布局CSS缓存（通过删除生成的CSS文件）
            /** @var LayoutAssetsManager $assetsManager */
            $assetsManager = ObjectManager::getInstance(LayoutAssetsManager::class);
            
            // 获取所有布局类型（这里简化处理，实际应该获取所有布局）
            $layoutTypes = ['homepage', 'account', 'default'];
            
            foreach ($layoutTypes as $layoutType) {
                $cssPath = $assetsManager->getGeneratedCssPath($area, $layoutType, 'default', $theme);
                if (is_file($cssPath)) {
                    @unlink($cssPath);
                }
            }
            
            // 清除主题缓存
            /** @var CacheInterface $cache */
            $cache = ObjectManager::getInstance(CacheInterface::class . 'Factory');
            $cache->clean(['theme', 'variables', 'css']);
        } catch (\Exception $e) {
            // 清除缓存失败不影响保存操作
            if (defined('DEV') && DEV) {
                error_log('清除CSS缓存失败: ' . $e->getMessage());
            }
        }
    }
}

