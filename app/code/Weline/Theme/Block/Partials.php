<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Block;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeDirectoryResolver;

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
     * @var array<string, array>
     */
    private static array $partialsMetaCache = [];

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
     *
     * 支持主题继承链查找：
     * 1. 当前主题的 partials
     * 2. 父主题的 partials
     * 3. Weline_Theme 默认 partials
     *
     * 路径结构优先级：
     * - {themePath}/{area}/partials/{type}/{option}.phtml (现代结构)
     * - {themePath}/theme/{area}/partials/{type}/{option}.phtml (兼容结构)
     * - {themePath}/view/partials/{area}/{type}/{option}.phtml (旧结构)
     *
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param string $defaultOption 默认选项（如果配置中没有指定）
     * @return string|null 模板路径（模块格式或绝对路径）
     */
    public function getPartialsPath(string $area, string $type, string $defaultOption = 'default'): ?string
    {
        /** @var ThemeContextService $ctx */
        $ctx = ObjectManager::getInstance(ThemeContextService::class);
        $normalizedArea = $ctx->normalizeArea($area);
        $theme = $ctx->resolveTheme($normalizedArea);

        // 如果没有活动主题，直接跳到默认主题回退逻辑
        if (!$theme || !$theme->getId()) {
            $defaultPartialsPath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $defaultOption . '.phtml';
            $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
            if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
                return $defaultPartialsPath;
            }
            return null;
        }

        $scope = $ctx->resolveCurrentScope($normalizedArea);

        // 获取配置的选项（优先 ThemeData 元配置，回退主题 config）
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($normalizedArea);
        $config = ThemeData::getPartialsConfig($normalizedArea, $scope);
        if (empty($config)) {
            $themeConfig = (array)$theme->getConfig();
            $partialsByArea = (array)($themeConfig['partials'] ?? []);
            $config = (array)($partialsByArea[$normalizedArea] ?? []);
        }
        $option = $config[$type] ?? $defaultOption;

        // 使用 ThemeDirectoryResolver 解析主题 partials 路径（支持继承链）
        /** @var ThemeDirectoryResolver $dirResolver */
        $dirResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        $partialPath = 'theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
        $resolvedPath = $dirResolver->resolveThemeTemplatePath($partialPath, $theme);

        // resolveThemeTemplatePath 返回：
        // - 找到文件时返回绝对路径
        // - 未找到时返回原始路径
        if ($resolvedPath !== $partialPath) {
            // 如果找到了文件（返回绝对路径），转换为 Weline_Theme 模块路径
            // 绝对路径判断：Windows (E:\) 或 Unix (/ 开头的绝对路径)
            $isAbsolutePath = strpos($resolvedPath, '://') === false
                && (preg_match('/^[A-Z]:/i', $resolvedPath) || strpos($resolvedPath, '/') === 0);
            if ($isAbsolutePath) {
                return 'Weline_Theme::' . $partialPath;
            }
            // 否则直接返回
            return $resolvedPath;
        }

        // 未找到文件，尝试回退

        // 最终回退：尝试 Weline_Theme 默认 partials
        $defaultPartialsPath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
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
        $tracePrefix = 'theme::partials::' . $type;

        return $this->traceCall($tracePrefix . '::render', function () use ($area, $type, $data, $defaultOption, $tracePrefix) {
            $path = $this->traceCall(
                $tracePrefix . '::resolve_path',
                fn() => $this->getPartialsPath($area, $type, $defaultOption)
            );

            if (!$path) {
                return '';
            }

            $template = Template::getInstance();
            $layoutMeta = $template->getData('meta') ?? [];
            $themeData = $template->getData('theme') ?? [];
            $colorsData = $template->getData('colors') ?? [];
            $contentTemplate = $template->getData('contentTemplate') ?? null;

            $scope = $this->resolveScope($area);
            $metaIdentify = "partials.{$type}";
            if ($defaultOption && $defaultOption !== 'default') {
                $metaIdentify .= ".{$defaultOption}";
            } else {
                $metaIdentify = "partials.{$type}.default";
            }

            $cacheKey = $area . '|' . $type . '|' . $defaultOption . '|' . $scope . '|' . $path;
            if (array_key_exists($cacheKey, self::$partialsMetaCache)) {
                $partialsMeta = self::$partialsMetaCache[$cacheKey];
            } else {
                $partialsMeta = $this->traceCall(
                    $tracePrefix . '::load_meta',
                    fn() => ThemeData::getFileParams($metaIdentify, $scope)
                );

                if (empty($partialsMeta)) {
                    $partialsMeta = $this->traceCall(
                        $tracePrefix . '::parse_meta_file',
                        fn() => $this->parsePartialsMetaFromFile($path, $area, $type, $defaultOption)
                    );
                }
                self::$partialsMetaCache[$cacheKey] = is_array($partialsMeta) ? $partialsMeta : [];
            }

            if (empty($partialsMeta)) {
                $partialsMeta = [];
            }

            $data['meta'] = $partialsMeta;
            $data['layout'] = $layoutMeta;
            $data['theme'] = $themeData;
            $data['colors'] = $colorsData;
            if ($contentTemplate) {
                $data['contentTemplate'] = $contentTemplate;
            }

            foreach ($data as $key => $value) {
                $this->assign($key, $value);
            }

            return $this->traceCall(
                $tracePrefix . '::fetch_html',
                fn() => $this->fetchHtml($path, $data)
            );
        });
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
        $ctx = ObjectManager::getInstance(ThemeContextService::class);

        return $ctx->resolveCurrentScope($ctx->normalizeArea($area));
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
    private function traceCall(string $name, callable $callback, string $category = 'theme'): mixed
    {
        if (!RequestLifecycleTrace::isEnabled()) {
            return $callback();
        }

        $start = microtime(true);
        RequestLifecycleTrace::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan($name, (microtime(true) - $start) * 1000, $category);
        }
    }
}

