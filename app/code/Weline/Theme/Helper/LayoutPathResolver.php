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
use Weline\Theme\Model\WelineTheme;

/**
 * 布局路径解析器
 * 
 * 负责布局模板路径的构建、解析和转换
 */
class LayoutPathResolver
{
    /**
     * 构建布局模板路径
     * 
     * @param string $originalPath 原模板路径
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @return string 布局模板路径
     */
    public static function buildLayoutPath(string $originalPath, string $area, string $layoutType, string $layoutOption): string
    {
        // 构建布局模板路径
        // theme/{area}/layouts/{layoutType}/{layoutOption}.phtml
        return 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
    }

    /**
     * 解析布局模板路径（支持主题继承）
     * 
     * @param string $layoutPath 布局模板路径
     * @param WelineTheme $theme 当前主题
     * @param string $area 区域
     * @return string|null 解析后的布局模板路径，如果不存在返回 null
     */
    public static function resolveLayoutTemplate(string $layoutPath, WelineTheme $theme, string $area): ?string
    {
        // 构建完整路径
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }

        // 构建主题布局文件路径
        $fullPath = rtrim($themePath, DS) . DS . 'view' . DS . $layoutPath;
        $fullPath = str_replace('\\', DS, $fullPath);

        // 检查当前主题是否存在
        if (is_file($fullPath)) {
            // 转换为模块路径格式，供 Template 使用
            return self::convertToModulePath($fullPath, $area);
        }

        // 如果当前主题不存在，尝试父主题
        $parentId = $theme->getParentId();
        if ($parentId) {
            try {
                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                $parentTheme->load($parentId);

                if ($parentTheme->getId()) {
                    return self::resolveLayoutTemplate($layoutPath, $parentTheme, $area);
                }
            } catch (\Exception $e) {
                // 父主题加载失败，继续查找
            }
        }

        // 如果主题继承链中都没有，尝试默认主题路径
        $defaultPath = self::getDefaultLayoutPath($layoutPath, $area);
        if ($defaultPath && is_file($defaultPath)) {
            return self::convertToModulePath($defaultPath, $area);
        }

        return null;
    }

    /**
     * 获取默认布局路径（从 Weline_Theme 模块）
     * 
     * @param string $layoutPath 布局模板路径
     * @param string $area 区域
     * @return string|null 默认布局路径
     */
    public static function getDefaultLayoutPath(string $layoutPath, string $area): ?string
    {
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme'])) {
            return null;
        }

        $themeModule = $modules['Weline_Theme'];
        $defaultPath = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . $layoutPath;

        return $defaultPath;
    }

    /**
     * 将文件系统路径转换为模块路径格式
     * 
     * @param string $fullPath 完整文件路径
     * @param string $area 区域
     * @return string 模块路径格式（Weline_Theme::theme/...）
     */
    public static function convertToModulePath(string $fullPath, string $area): string
    {
        // 查找 view/theme/ 的位置
        $themePos = strpos($fullPath, DS . 'view' . DS . 'theme' . DS);
        if ($themePos === false) {
            return $fullPath;
        }

        // 提取 view/theme/ 之后的部分
        $themeRelativePath = substr($fullPath, $themePos + strlen(DS . 'view' . DS . 'theme' . DS));
        $themeRelativePath = str_replace('\\', '/', $themeRelativePath);

        // 转换为模块路径格式：Weline_Theme::theme/{area}/...
        return 'Weline_Theme::theme/' . $themeRelativePath;
    }

    /**
     * 获取布局文件的完整文件系统路径
     * 
     * @param string $modulePath 模块路径（如 Weline_Theme::theme/frontend/layouts/homepage/default.phtml）
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return string|null 文件系统路径
     */
    public static function getLayoutFilePath(string $modulePath, WelineTheme $theme, string $area): ?string
    {
        // 解析模块路径
        if (strpos($modulePath, '::') === false) {
            return null;
        }
        
        [$moduleCode, $relativePath] = explode('::', $modulePath, 2);
        
        // 如果是 Weline_Theme 模块，使用模块路径
        if ($moduleCode === 'Weline_Theme') {
            $modules = Env::getInstance()->getModuleList();
            if (!isset($modules['Weline_Theme'])) {
                return null;
            }
            $themeModule = $modules['Weline_Theme'];
            return rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . str_replace('/', DS, $relativePath);
        }
        
        // 如果是主题路径，从主题目录查找
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }
        
        // 提取 theme/ 之后的部分
        if (strpos($relativePath, 'theme/') === 0) {
            $relativePath = substr($relativePath, 6); // 去掉 'theme/'
        }
        
        $fullPath = rtrim($themePath, DS) . DS . 'view' . DS . str_replace('/', DS, $relativePath);
        if (is_file($fullPath)) {
            return $fullPath;
        }
        
        return null;
    }

    /**
     * 格式化解析的参数定义
     * 
     * @param array $parsedParams ComponentMetaParser 解析的参数
     * @return array 格式化后的参数
     */
    public static function formatParsedParams(array $parsedParams): array
    {
        $params = [];
        foreach ($parsedParams as $param) {
            $key = $param['name'] ?? null;
            if (!$key) {
                continue;
            }
            $params[$key] = [
                'name' => $param['name_label'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? '',
                'type' => $param['type'] ?? 'text',
                'required' => (bool)($param['required'] ?? false),
            ];
        }
        return $params;
    }
}

