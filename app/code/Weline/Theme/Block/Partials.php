<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Block;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Framework\Http\Request;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * Partials Block
 * 用于在模板中加载配置的 partials
 * 
 * 支持通过 block 标签调用：
 * <w:block class="Weline\Theme\Block\Partials" area="frontend" type="head" default-option="default"/>
 */
class Partials extends Block
{
    /**
     * 初始化 Block
     */
    public function __init()
    {
        parent::__init();
    }
    
    /**
     * 渲染 Block
     * 支持通过属性传递参数：area, type, default-option
     * 支持通过 vars 属性传递模板变量：vars="logo|logoText|navItems"
     * 
     * @return string
     */
    public function render(): string
    {
        // 从属性中获取参数
        $area = $this->getData('area') ?? 'frontend';
        $type = $this->getData('type') ?? '';
        $defaultOption = $this->getData('default-option') ?? $this->getData('defaultOption') ?? 'default';
        
        // 如果指定了 type，则渲染对应的 partials
        if ($type) {
            // 获取传递给模板的数据
            $data = [];
            
            // 处理 vars 属性：从模板变量中提取值
            $vars = $this->getData('vars') ?? [];
            if (is_array($vars) && !empty($vars)) {
                foreach ($vars as $varName => $varValue) {
                    // vars 数组的键是变量名，值是变量的引用
                    // 我们需要从当前模板上下文中获取这些变量的值
                    // 由于 Block 继承自 Template，可以通过 $this->getData() 获取
                    // 但 vars 中的变量名可能不在 Block 的 data 中，需要从父模板获取
                    // 这里我们直接使用 $varValue，因为 framework_view_process_block 已经处理了变量引用
                    if (is_string($varName)) {
                        $data[$varName] = $varValue;
                    }
                }
            }
            
            // 获取其他直接传递的数据（除了 Block 内部使用的参数）
            foreach ($this->getData() as $key => $value) {
                // 排除 Block 内部参数
                if (!in_array($key, ['area', 'type', 'default-option', 'defaultOption', 'class', 'template', 'cache', 'vars'])) {
                    $data[$key] = $value;
                }
            }
            
            return $this->renderPartials($area, $type, $data, $defaultOption);
        }
        
        // 如果没有指定 type，返回空（保持向后兼容）
        return '';
    }
    /**
     * 获取 partials 模板路径
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param string $defaultOption 默认选项（如果配置中没有指定）
     * @return string|null
     */
    public function getPartialsPath(string $area, string $type, string $defaultOption = 'default'): ?string
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        
        // 检查是否有预览主题
        $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
        $previewThemeId = $session->getData('preview_theme_id');
        if ($previewThemeId) {
            $theme->load($previewThemeId);
        } else {
            $theme->getActiveTheme();
        }
        
        if (!$theme->getId()) {
            return null;
        }
        
        // 解析 scope（优先从预览模式获取，其次从请求参数获取，最后使用 default）
        // 与 ControllerFetchFileBefore 保持一致的处理逻辑
        $scope = 'default';
        try {
            // 检查预览模式
            if (class_exists(PreviewManager::class)) {
                if (PreviewManager::isPreviewMode()) {
                    $previewScope = PreviewManager::getPreviewScope($area);
                    if ($previewScope) {
                        $scope = $previewScope;
                    }
                }
            }
            
            // 如果不在预览模式，尝试从请求参数获取
            if ($scope === 'default') {
                try {
                    /** @var Request $request */
                    $request = ObjectManager::getInstance(Request::class);
                    if ($request) {
                        $paramName = 'scope_' . $area;
                        $scopeParam = $request->getParam($paramName) ?? $request->getParam('scope');
                        if ($scopeParam) {
                            // 处理 scope 格式（可能是 frontend/default）
                            if (str_contains($scopeParam, '/')) {
                                [$maybeArea, $rest] = explode('/', $scopeParam, 2);
                                if ($maybeArea === $area) {
                                    $scope = $rest ?: 'default';
                                }
                            } else {
                                $scope = $scopeParam;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略错误，使用默认 scope
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误，使用默认 scope
        }
        
        // 获取配置的选项（支持预览配置和 scope）
        $config = LayoutScanner::getPartialsConfig($theme, $area, $scope);
        $option = $config[$type] ?? $defaultOption;
        
        // 构建部件文件路径
        $themePath = $theme->getPath();
        if (empty($themePath)) {
            return null;
        }
        
        // 构建模块路径格式：Module_Name::theme/{area}/partials/{type}/{option}.phtml
        // 首先尝试当前主题
        $themeModuleName = $theme->getModuleName();
        $partialsPath = $themeModuleName . '::theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
        
        // 检查文件是否存在（通过尝试获取绝对路径）
        $absolutePath = $this->resolveModulePath($partialsPath);
        if ($absolutePath && is_file($absolutePath)) {
            return $partialsPath; // 返回模块路径格式
        }
        
        // 如果当前主题没有，尝试父主题
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentModuleName = $parentTheme->getModuleName();
            $parentPartialsPath = $parentModuleName . '::theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
            $parentAbsolutePath = $this->resolveModulePath($parentPartialsPath);
            if ($parentAbsolutePath && is_file($parentAbsolutePath)) {
                return $parentPartialsPath;
            }
        }
        
        // 如果还是没有，尝试默认主题（Weline_Theme）
        $defaultPartialsPath = 'Weline_Theme::theme/' . $area . '/partials/' . $type . '/' . $option . '.phtml';
        $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
        if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
            return $defaultPartialsPath;
        }
        
        return null;
    }
    
    /**
     * 解析模块路径为绝对路径（用于检查文件是否存在）
     * @param string $modulePath 模块路径格式（如 Weline_Theme::theme/frontend/partials/header/default.phtml）
     * @return string|null 绝对路径，如果无法解析则返回null
     */
    private function resolveModulePath(string $modulePath): ?string
    {
        if (strpos($modulePath, '::') === false) {
            return null;
        }
        
        list($moduleName, $relativePath) = explode('::', $modulePath, 2);
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        
        if (!isset($modules[$moduleName])) {
            return null;
        }
        
        $module = $modules[$moduleName];
        $basePath = rtrim($module['base_path'], DS);
        $relativePath = str_replace('/', DS, $relativePath);
        
        return $basePath . DS . 'view' . DS . $relativePath;
    }
    
    /**
     * 渲染 partials
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param array $data 传递给模板的数据
     * @param string $defaultOption 默认选项
     * @return string
     */
    public function renderPartials(string $area, string $type, array $data = [], string $defaultOption = 'default'): string
    {
        $path = $this->getPartialsPath($area, $type, $defaultOption);
        
        if (!$path) {
            return '';
        }
        
        // 1. 获取全局 Template 实例的 meta 数据（layout 的 meta）
        $template = Template::getInstance();
        $layoutMeta = $template->getData('meta') ?? [];
        
        // 2. 加载 partials 的 meta 数据
        $scope = $this->resolveScope($area);
        $metaIdentify = "partials.{$type}";
        if ($defaultOption && $defaultOption !== 'default') {
            $metaIdentify .= ".{$defaultOption}";
        } else {
            $metaIdentify = "partials.{$type}.default";
        }
        
        $partialsMeta = ThemeData::getFileParams($metaIdentify, $scope);
        
        // 如果从 Meta 表中没有读取到参数，尝试从文件直接解析
        if (empty($partialsMeta)) {
            $partialsMeta = $this->parsePartialsMetaFromFile($path, $area, $type, $defaultOption);
        }
        
        // 确保即使没有参数，也至少设置一个空的 meta 数组
        if (empty($partialsMeta)) {
            $partialsMeta = [];
        }
        
        // 3. 设置数据到 Block 实例
        // partials 自己的 meta
        $data['meta'] = $partialsMeta;
        // layout 的 meta（供 partials 访问）
        $data['layout'] = $layoutMeta;
        
        // 4. 设置其他数据
        foreach ($data as $key => $value) {
            $this->assign($key, $value);
        }
        
        // 5. 渲染 partials
        return $this->fetchHtml($path, $data);
    }
    
    /**
     * 重写 fetchHtml 方法，直接使用 Template 的 getFetchFile，避免使用 blocks 类型
     * @param string $fileName 文件名
     * @param array $dictionary 数据字典
     * @return string
     */
    public function fetchHtml(string $fileName, array $dictionary = []): string
    {
        // 直接使用 Template 的 getFetchFile 方法，而不是 Block 的 fetchTagSource('blocks', ...)
        $comFileName = $this->getFetchFile($fileName);
        return $this->ob_file($comFileName, $dictionary);
    }
    
    /**
     * 解析 scope（优先从预览模式获取，其次从请求参数获取，最后使用 default）
     * 
     * @param string $area 区域（frontend 或 backend）
     * @return string scope 值
     */
    private function resolveScope(string $area): string
    {
        $scope = 'default';
        try {
            // 检查预览模式
            if (class_exists(PreviewManager::class)) {
                if (PreviewManager::isPreviewMode()) {
                    $previewScope = PreviewManager::getPreviewScope($area);
                    if ($previewScope) {
                        $scope = $previewScope;
                    }
                }
            }
            
            // 如果不在预览模式，尝试从请求参数获取
            if ($scope === 'default') {
                try {
                    /** @var Request $request */
                    $request = ObjectManager::getInstance(Request::class);
                    if ($request) {
                        $paramName = 'scope_' . $area;
                        $scopeParam = $request->getParam($paramName) ?? $request->getParam('scope');
                        if ($scopeParam) {
                            // 处理 scope 格式（可能是 frontend/default）
                            if (str_contains($scopeParam, '/')) {
                                [$maybeArea, $rest] = explode('/', $scopeParam, 2);
                                if ($maybeArea === $area) {
                                    $scope = $rest ?: 'default';
                                }
                            } else {
                                $scope = $scopeParam;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略错误，使用默认 scope
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误，使用默认 scope
        }
        
        return $scope;
    }
    
    /**
     * 从文件解析 partials 的 meta 数据
     * 
     * @param string $modulePath 模块路径（如 Weline_Theme::theme/frontend/partials/head/default.phtml）
     * @param string $area 区域
     * @param string $type partials 类型
     * @param string $option partials 选项
     * @return array 解析后的参数值数组
     */
    private function parsePartialsMetaFromFile(string $modulePath, string $area, string $type, string $option): array
    {
        // 解析模块路径为文件系统路径
        $filePath = $this->resolveModulePath($modulePath);
        if (!$filePath || !is_file($filePath)) {
            return [];
        }
        
        // 使用 ComponentMetaParser 从文件解析参数定义
        $parsedMeta = ComponentMetaParser::parse($filePath);
        if (empty($parsedMeta['params']) || !is_array($parsedMeta['params'])) {
            return [];
        }
        
        // 格式化参数定义
        $formattedParams = LayoutPathResolver::formatParsedParams($parsedMeta['params']);
        
        // 提取默认值作为参数值
        $params = [];
        foreach ($formattedParams as $paramName => $paramDef) {
            $defaultValue = $paramDef['default'] ?? null;
            // 处理布尔值默认值
            if ($defaultValue === 'true' || $defaultValue === true) {
                $defaultValue = true;
            } elseif ($defaultValue === 'false' || $defaultValue === false) {
                $defaultValue = false;
            }
            // 处理空字符串默认值
            if ($defaultValue === '') {
                $defaultValue = '';
            }
            $params[$paramName] = $defaultValue;
        }
        
        return $params;
    }
}

