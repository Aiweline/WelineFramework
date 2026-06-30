<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\ThemeModeResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemeVirtualLayoutService;

final class ControllerFetchFileBeforeRequestCacheState
{
    public array $themeByAreaCache = [];
    public array $layoutConfigCache = [];
    public array $colorsCache = [];
    public array $resolvedLayoutPathCache = [];
    public array $layoutParamsRequestCache = [];
}

/**
 * 控制器模板获取前观察者
 * 根据控制器的 layoutType 自动加载对应的主题布局
 */
class ControllerFetchFileBefore implements ObserverInterface
{
    private const RUNTIME_CACHE_TTL = 300;

    private static ?ControllerFetchFileBeforeRequestCacheState $mainRequestCache = null;
    /** @var \WeakMap<\Fiber, ControllerFetchFileBeforeRequestCacheState>|null */
    private static ?\WeakMap $fiberRequestCaches = null;
    private static bool $stateManagerRegistered = false;
    /** @var array<string, array{expires_at: float, value: mixed}> */
    private static array $runtimeCache = [];

    private WelineTheme $welineTheme;

    private ThemeContextService $themeContext;

    private ThemePageTypeResolver $pageTypeResolver;

    public function __construct(
        WelineTheme $welineTheme,
        ThemeContextService $themeContext,
        ThemePageTypeResolver $pageTypeResolver
    )
    {
        $this->welineTheme = $welineTheme;
        $this->themeContext = $themeContext;
        $this->pageTypeResolver = $pageTypeResolver;
    }

    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }
        if (class_exists(StateManager::class)) {
            StateManager::registerResetCallback('Theme_ControllerFetchFileBefore', [self::class, 'resetRequestCache']);
            self::$stateManagerRegistered = true;
        }
    }

    /** WLS 请求结束后清空请求级缓存 */
    public static function resetRequestCache(): void
    {
        self::resetCurrentRequestCacheState();
    }

    public static function clearRuntimeCache(): void
    {
        self::$runtimeCache = [];
        self::resetCurrentRequestCacheState();
    }

    private function resolveFastAccountAuthLayout(DataObject $eventData, Template $template, string $contentTemplateFileName): void
    {
        $layoutTemplate = 'Weline_Theme::theme/frontend/layouts/account/auth.phtml';
        $eventData->setData('contentTemplate', $contentTemplateFileName);
        $eventData->setData('layoutTemplate', $layoutTemplate);
        $eventData->setData('fileName', $contentTemplateFileName);
        $eventData->setData('layoutType', 'account');
        $eventData->setData('layoutOption', 'auth');

        $template->setData('contentTemplate', $contentTemplateFileName);
        $template->setData('layoutTemplate', $layoutTemplate);
        $template->setData('fileName', $contentTemplateFileName);
        $meta = $template->getData('meta');
        if (!\is_array($meta)) {
            $meta = [];
        }
        $meta['layoutType'] = 'account';
        $meta['layoutOption'] = 'auth';
        $meta['showHeader'] = false;
        $meta['showFooter'] = false;
        $template->setData('meta', $meta);
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject $eventData */
        $eventData = $event->getData('data');
        
        if (!$eventData instanceof DataObject) {
            return;
        }

        $layoutType = $eventData->getData('layoutType');
        if (empty($layoutType)) {
            return;
        }

        $request = ObjectManager::getInstance(Request::class);
        $controller = $eventData->getData('controller');
        $isBackendRequest = $this->isBackendRequest($request, $controller);
        $fileName = $eventData->getData('fileName');
        $contentTemplateFileName = $fileName;
        if ((string)$layoutType === 'account.auth') {
            $this->resolveFastAccountAuthLayout($eventData, Template::getInstance(), (string)$contentTemplateFileName);
            return;
        }
        $contentTemplateFileName = $fileName; // 统一用初始控制器模板路径作为内容模板

        // 判断区域（frontend/backend）
        $area = 'frontend';
        $editorArea = '';
        if ($request && $isBackendRequest) {
            $editorArea = (string)$request->getParam('editor_area', '');
            if ($editorArea === '') {
                $editorArea = (string)$request->getParam('preview_area', '');
            }
            $editorArea = strtolower(trim($editorArea));

            $currentPath = strtolower(trim((string)$request->getUrlPath()));
            $isPageBuilderPreviewRoute = str_contains($currentPath, '/pagebuilder/backend/preview/');
            $isThemeEditorInnerPreviewRoute = str_contains($currentPath, '/theme/backend/theme-editor/layout-preview');
            $isVirtualThemePreview = (int)$request->getParam('virtual_theme_id', 0) > 0
                || (string)$request->getParam('visual_editor', '') === '1';
            if ($isPageBuilderPreviewRoute && $isVirtualThemePreview) {
                // PageBuilder 预览链路必须按 frontend 主题渲染，避免后端 layout 覆盖。
                $area = 'frontend';
                if ($editorArea === '') {
                    $editorArea = 'frontend';
                }
            } elseif ($isThemeEditorInnerPreviewRoute && $editorArea === 'frontend') {
                // ThemeEditor 的内层预览 iframe 允许按 preview_area/editor_area 切到 frontend；
                // 但 backend 编辑器外壳页本身必须保持 backend，避免污染后台布局与静态资源。
                $area = 'frontend';
            } elseif ($editorArea === 'backend') {
                $area = 'backend';
            } else {
                $area = 'backend';
            }
        }
        
        // 设置主题相关数据到 theme 对象中（由Helper处理业务逻辑，不在模板中处理）
        $template = Template::getInstance();
        $welineThemeColorMode = ThemeModeResolver::getThemeMode($area);
        $requestCache = self::requestCacheState();
        
        // 获取当前主题：预览 / 激活由 ThemeContextService 统一解析
        // 主题编辑器预览链路（含 backend 预览）也必须允许读取 preview 主题上下文，
        // 否则会错误回退到当前激活主题，导致 backend 预览显示成 frontend 主题结果。
        $requestPath = strtolower(trim((string)($request?->getUrlPath() ?? '')));
        $isThemeEditorPreviewRoute = $requestPath !== ''
            && (
                str_contains($requestPath, '/theme/backend/theme-editor/')
                || str_contains($requestPath, '/theme/backend/config/layout/preview')
                || str_contains($requestPath, '/theme/backend/index/preview')
            );
        $allowPreviewTheme = !$isBackendRequest
            || $editorArea === 'frontend'
            || ($editorArea === 'backend' && $isThemeEditorPreviewRoute);
        $theme = $this->resolveThemeForLayout($area, $allowPreviewTheme);
        $requestUriForDebug = (string)($request?->getServer('REQUEST_URI') ?? $request?->getUri() ?? '');
        if (false && $requestUriForDebug !== ''
            && \str_contains($requestUriForDebug, 'pagebuilder/backend/ai-site-agent')) {
            $debugPayload = [
                'uri' => $requestUriForDebug,
                'is_backend_request' => $isBackendRequest,
                'area' => $area,
                'editor_area' => $editorArea,
                'allow_preview_theme' => $allowPreviewTheme,
                'server_area' => (string)($request?->getServer('WELINE_AREA') ?? ''),
                'server_is_backend' => $request?->getServer('WELINE_IS_BACKEND'),
                'context_area' => Context::getCurrent()?->get('route.area', ''),
                'context_is_backend' => Context::getCurrent()?->get('route.is_backend', null),
                'theme_id' => $theme->getId(),
                'theme_path' => $theme->getOriginPath(),
                'theme_cache_key' => $this->buildThemeCacheKey($area),
            ];
            try {
                \Weline\Server\Log\WlsLogger::warning_('[ThemeResolve] ai-site-agent layout resolve', $debugPayload);
            } catch (\Throwable) {
            }

            try {
                $request->getResponse()->setHeader('X-Weline-Debug-Theme-Area', $area);
                $request->getResponse()->setHeader('X-Weline-Debug-Theme-Is-Backend', $isBackendRequest ? '1' : '0');
                $request->getResponse()->setHeader('X-Weline-Debug-Theme-Editor-Area', $editorArea !== '' ? $editorArea : '(empty)');
                $request->getResponse()->setHeader('X-Weline-Debug-Theme-Allow-Preview', $allowPreviewTheme ? '1' : '0');
                $request->getResponse()->setHeader('X-Weline-Debug-Theme-Id', (string)$theme->getId());
                $request->getResponse()->setHeader(
                    'X-Weline-Debug-Context-Area',
                    (string)(Context::getCurrent()?->get('route.area', '') ?: '(empty)')
                );
            } catch (\Throwable) {
            }
        }

        // 如果没有指定 layoutType，使用默认值（确保布局信息始终存在）
        $originalLayoutType = $layoutType;

        // // 如果文件名包含 '::'，说明是模块路径，不处理（保持原有逻辑）
        // if (strpos($fileName, '::') !== false) {
        //     return;
        // }
        try {

            // 解析布局类型和选项
            // 支持格式：'account.auth' (布局类型.布局选项) 或 'account' (仅布局类型)
            $layoutOption = null;
            $explicitLayoutOption = $this->resolveExplicitLayoutOption($eventData, $request);
            
            // 检查是否包含点号
            $dotPos = strpos($layoutType, '.');
            if ($dotPos !== false) {
                // 包含点号，分割为布局类型和布局选项
                $parts = explode('.', $layoutType, 2);
                
                $layoutType = trim($parts[0]);  // 布局类型：account
                $layoutOption = isset($parts[1]) && !empty(trim($parts[1])) ? trim($parts[1]) : null; // 布局选项：auth（代码中明确指定，优先级最高）
            } elseif ($explicitLayoutOption !== '') {
                $layoutOption = $explicitLayoutOption;
            }

            // 先解析 scope 和 configCacheKey，再按需 performanceLoad（有 layoutConfig 缓存则跳过）
            $scope = $this->themeContext->resolveCurrentScope(
                $area,
                $this->resolveExplicitScopeParam($request, $area)
            );

            self::registerStateManager();
            $themeId = $theme->getId() ?: 0;
            $configCacheKey = "{$themeId}_{$area}_{$scope}";
            $runtimeCacheAllowed = $this->isRuntimeCacheAllowed($request, $isBackendRequest);
            $didPerformanceLoad = false;
            if (isset($requestCache->layoutConfigCache[$configCacheKey])) {
                $layoutConfig = $requestCache->layoutConfigCache[$configCacheKey];
            } elseif ($runtimeCacheAllowed && ($runtimeLayoutConfig = self::runtimeCacheGet('layout_config|' . $configCacheKey))[0]) {
                $layoutConfig = is_array($runtimeLayoutConfig[1]) ? $runtimeLayoutConfig[1] : [];
                $requestCache->layoutConfigCache[$configCacheKey] = $layoutConfig;
            } else {
                ThemeData::setCurrentTheme($theme);
                ThemeData::setCurrentArea($area);
                ThemeData::performanceLoad();
                $didPerformanceLoad = true;
                $layoutConfig = ThemeData::getLayoutConfig($area, $scope);
                $requestCache->layoutConfigCache[$configCacheKey] = $layoutConfig;
                if ($runtimeCacheAllowed) {
                    self::runtimeCacheSet('layout_config|' . $configCacheKey, $layoutConfig);
                }
            }

            // 配置来自元数据配置的布局：仅当控制器未显式传入「类型.选项」时才用 theme 的 layoutConfig 同步 option。
            // 否则会把 default.blank 强行改回 layoutConfig['default']（多为 default），导致 iframe/offcanvas 仍套 default.default。
            $hadExplicitLayoutSpec = str_contains((string)$originalLayoutType, '.') || $explicitLayoutOption !== '';
            if (!$hadExplicitLayoutSpec && isset($layoutConfig[$layoutType]) && $layoutOption !== $layoutConfig[$layoutType]) {
                $layoutOption = $layoutConfig[$layoutType];
            }

            // 如果代码中没有指定布局选项，则从配置中获取；
            // 当配置缺少该布局键时，保留请求的 layoutType，仅回退 option=default。
            if ($layoutOption === null || $layoutOption === '') {
                if (isset($layoutConfig[$layoutType])) {
                    // 从配置中获取布局选项
                    $layoutOption = $layoutConfig[$layoutType] ?? 'default';
                } else {
                    // 配置里没有当前布局键时，不应篡改 layoutType（避免 homepage 被错误降级为 default）
                    $layoutOption = 'default';
                }
            }
            
            // 无论是否找到布局模板，都要设置布局信息到 theme 对象中（供 head partial 使用）
            // 将所有主题相关数据统一放到 theme 对象中（包括主题对象本身）
            $themeData = [
                'area' => $area,
                'colorMode' => $welineThemeColorMode,
                'layoutType' => $layoutType,
                'layoutOption' => $layoutOption,
                'theme' => $theme, // 主题对象本身，供模板直接使用
            ];
            $template->setData('theme', $themeData);
            $template->setData('layoutType', $layoutType);
            $template->setData('layoutOption', $layoutOption);

            if (isset($requestCache->colorsCache[$configCacheKey])) {
                $colors = $requestCache->colorsCache[$configCacheKey];
            } elseif ($runtimeCacheAllowed && ($runtimeColors = self::runtimeCacheGet('colors|' . $configCacheKey))[0]) {
                $colors = is_array($runtimeColors[1]) ? $runtimeColors[1] : [];
                $requestCache->colorsCache[$configCacheKey] = $colors;
            } else {
                $colors = self::loadThemeColors($area, $scope, $theme);
                $requestCache->colorsCache[$configCacheKey] = $colors;
                if ($runtimeCacheAllowed) {
                    self::runtimeCacheSet('colors|' . $configCacheKey, $colors);
                }
            }
            $template->setData('colors', $colors);

            $virtualLayout = $this->resolveVirtualLayoutForRequest($request, $themeId, $area, $scope, (string)$layoutType, (string)$layoutOption);
            $virtualLayoutFilePath = null;
            if (is_array($virtualLayout)) {
                $resolvedLayoutPath = (string)$virtualLayout['module_path'];
                $virtualLayoutFilePath = (string)$virtualLayout['file_path'];
                $template->setData('themeVirtualLayout', $virtualLayout);
            } else {
                $layoutPath = LayoutPathResolver::buildLayoutPath($fileName, $area, $layoutType, $layoutOption);
                $pathCacheKey = "{$layoutPath}|{$themeId}|{$area}";
                if (array_key_exists($pathCacheKey, $requestCache->resolvedLayoutPathCache)) {
                    $resolvedLayoutPath = $requestCache->resolvedLayoutPathCache[$pathCacheKey];
                } elseif ($runtimeCacheAllowed && ($runtimeResolvedPath = self::runtimeCacheGet('layout_path|' . $pathCacheKey))[0]) {
                    $resolvedLayoutPath = $runtimeResolvedPath[1];
                    $requestCache->resolvedLayoutPathCache[$pathCacheKey] = $resolvedLayoutPath;
                } else {
                    $resolvedLayoutPath = LayoutPathResolver::resolveLayoutTemplate($layoutPath, $theme, $area);
                    $requestCache->resolvedLayoutPathCache[$pathCacheKey] = $resolvedLayoutPath;
                    if ($runtimeCacheAllowed) {
                        self::runtimeCacheSet('layout_path|' . $pathCacheKey, $resolvedLayoutPath);
                    }
                }
            }
            if ($resolvedLayoutPath) {
                $paramsCacheKey = "{$configCacheKey}|{$layoutType}|{$layoutOption}";
                if (is_array($virtualLayout)) {
                    $paramsCacheKey .= '|virtual:' . (int)($virtualLayout['asset_id'] ?? 0) . ':' . (int)($virtualLayout['version_id'] ?? 0);
                }
                // 优化：编译文件存在且源文件未修改则不再做重负载（不重复 performanceLoad/colors/meta）
                $sourcePath = $virtualLayoutFilePath ?: LayoutPathResolver::getLayoutFilePath($resolvedLayoutPath, $theme, $area);
                $lang = class_exists(Cookie::class) ? State::getLang() : 'zh_Hans_CN';
                $compiledPath = LayoutPathResolver::getCompiledLayoutPath($resolvedLayoutPath, $lang);
                $compiledLayoutFresh = $sourcePath && $compiledPath && is_file($sourcePath) && is_file($compiledPath)
                    && filemtime($sourcePath) <= filemtime($compiledPath);
                $runtimeParamsCacheKey = null;
                $cachedLayoutParams = null;
                if ($compiledLayoutFresh && isset($requestCache->layoutParamsRequestCache[$paramsCacheKey])) {
                    $cachedLayoutParams = $this->sanitizeRuntimeLayoutParams($requestCache->layoutParamsRequestCache[$paramsCacheKey]);
                } elseif ($compiledLayoutFresh && $runtimeCacheAllowed) {
                    $runtimeParamsCacheKey = 'layout_params|' . $paramsCacheKey
                        . '|' . filemtime((string)$sourcePath)
                        . '|' . filemtime((string)$compiledPath);
                    $runtimeParams = self::runtimeCacheGet($runtimeParamsCacheKey);
                    if ($runtimeParams[0] && is_array($runtimeParams[1])) {
                        $cachedLayoutParams = $this->sanitizeRuntimeLayoutParams($runtimeParams[1]);
                        $requestCache->layoutParamsRequestCache[$paramsCacheKey] = $cachedLayoutParams;
                    }
                }
                if (is_array($cachedLayoutParams)) {
                    ThemeData::setCurrentTheme($theme);
                    ThemeData::setCurrentArea($area);
                    $themeData = [
                        'area' => $area,
                        'colorMode' => $welineThemeColorMode,
                        'layoutType' => $layoutType,
                        'layoutOption' => $layoutOption,
                        'theme' => $theme,
                    ];
                    $template->setData('theme', $themeData);
                    $template->setData('layoutType', $layoutType);
                    $template->setData('layoutOption', $layoutOption);
                    $template->setData('colors', $requestCache->colorsCache[$configCacheKey] ?? []);
                    $existingMeta = $template->getData('meta');
                    if (!is_array($existingMeta)) {
                        $existingMeta = [];
                    }
                    $existingMeta = $this->preserveAssignedTitleInMeta($existingMeta, $template, $request);
                    $template->setData('meta', array_merge(
                        $cachedLayoutParams,
                        $existingMeta
                    ));
                    $eventData->setData('contentTemplate', $fileName);
                    $eventData->setData('layoutTemplate', $resolvedLayoutPath);
                    $eventData->setData('fileName', $fileName);
                    $eventData->setData('layoutType', $layoutType);
                    $eventData->setData('layoutOption', $layoutOption);
                    $template->setData('contentTemplate', $fileName);
                    $template->setData('layoutTemplate', $resolvedLayoutPath);
                    $template->setData('fileName', $fileName);
                    if ($this->shouldUseMetaTitle($template, $request) && !empty($cachedLayoutParams['title'])) {
                        $template->assign('title', $cachedLayoutParams['title']);
                    }
                    $this->logThemeLayoutResolved($eventData, (string)$fileName, (string)$resolvedLayoutPath, (string)$fileName, $controller);
                    return;
                }

                ThemeData::setCurrentTheme($theme);
                ThemeData::setCurrentArea($area);
                if (!$didPerformanceLoad) {
                    ThemeData::performanceLoad();
                }

                // 将原模板路径保存为变量，供布局模板使用
                // 布局模板可以通过 $this->getData('contentTemplate') 获取原模板路径
                // 然后使用 $this->fetch($contentTemplate) 渲染原模板内容
                $eventData->setData('contentTemplate', $contentTemplateFileName);
                $eventData->setData('layoutTemplate', $resolvedLayoutPath);
                $eventData->setData('fileName', $fileName);
                $eventData->setData('layoutType', $layoutType);
                $eventData->setData('layoutOption', $layoutOption);
                
                // 同时将 contentTemplate 传递给模板数据，方便布局模板直接使用
                $template->setData('contentTemplate', $contentTemplateFileName);
                $template->setData('layoutTemplate', $resolvedLayoutPath);
                $template->setData('fileName', $fileName);
                $template->setData('layoutType', $layoutType);
                $template->setData('layoutOption', $layoutOption);

                // 加载布局文件的参数配置（自动读取 @param 定义的参数）
                // 构建 meta_identify：layouts.{layoutType} 或 layouts.{layoutType}.{layoutOption}
                // 注意：meta_identify 格式应该是 theme.{area}.layouts.{layoutType} 或 theme.{area}.layouts.{layoutType}.{layoutOption}
                $metaIdentify = "layouts.{$layoutType}";
                if ($layoutOption && $layoutOption !== 'default') {
                    $metaIdentify .= ".{$layoutOption}";
                }else{
                    $metaIdentify = "layouts.{$layoutType}.default";
                }
                
                // 读取布局文件的参数配置值
                // 注意：getFileParams 内部会处理 identify 格式，但需要确保 ThemeData 已正确初始化
                $layoutFilePath = $virtualLayoutFilePath ?: LayoutPathResolver::getLayoutFilePath($resolvedLayoutPath, $theme, $area);
                $layoutMetaIdentity = $this->extractLayoutMetaIdentity($layoutFilePath, $resolvedLayoutPath, $area);
                $layoutParams = ThemeData::getFileParams($metaIdentify, $scope);
                
                // 如果从 Meta 表中没有读取到参数，尝试从文件直接解析
                if (empty($layoutParams)) {
                    // 获取布局文件的完整路径
                    if ($layoutFilePath && is_file($layoutFilePath)) {
                        // 使用 ComponentMetaParser 从文件解析参数定义
                        $parsedMeta = \Weline\Theme\Helper\ComponentMetaParser::parse($layoutFilePath);
                        if (!empty($parsedMeta['params']) && is_array($parsedMeta['params'])) {
                            // 格式化参数定义
                            $formattedParams = LayoutPathResolver::formatParsedParams($parsedMeta['params']);
                            // 提取默认值作为参数值
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
                                $layoutParams[$paramName] = $defaultValue;
                            }
                        }
                    }
                }
                
                // 确保即使没有参数，也至少设置一个空的 meta 数组，避免模板中访问 meta 时出错
                if (empty($layoutParams)) {
                    $layoutParams = [];
                }
                // 将所有参数统一设置到 meta 数组中（供模板使用 {{meta.参数}} 语法）
                $existingMeta = $template->getData('meta');
                if (!is_array($existingMeta)) {
                    $existingMeta = [];
                }
                $existingMeta = $this->preserveAssignedTitleInMeta($existingMeta, $template, $request);
                $layoutStaticMeta = array_merge($layoutMetaIdentity, $layoutParams);
                // 关于主题的元数据传递给模板数据（performanceLoad 已在前面统一调用）
                // 注意：必须使用 getMeta() 而不是 get()
                // get() 方法用于获取 .value 格式的配置值，对于非 .value 格式会调用 MetaData::get()
                // MetaData::get() 会返回 MetaData 对象，创建对象时会进行数据库查询，可能导致阻塞
                // getMeta() 方法从性能缓存中读取，不会触发额外的数据库查询
                $themeMetaDataObj = ThemeData::getMeta("theme.{$area}.layouts.{$layoutType}");
                if ($themeMetaDataObj && !empty($themeMetaDataObj['meta_data'])) {
                    // 合并 meta_data 中的配置值到 metaData
                    $layoutStaticMeta = array_merge($layoutStaticMeta, $themeMetaDataObj['meta_data']);
                    $layoutStaticMeta = $this->mergeLayoutMetaIdentity($layoutStaticMeta, $themeMetaDataObj['meta_data']);
                }
                $layoutStaticMeta = $this->sanitizeRuntimeLayoutParams($layoutStaticMeta);
                $metaData = array_merge($layoutStaticMeta, $existingMeta);
                
                // 将 meta 数据设置到模板中（转义处理由模板自行决定）
                $template->setData('meta', $metaData);
                $requestCache->layoutParamsRequestCache[$paramsCacheKey] = $layoutStaticMeta;
                if ($runtimeCacheAllowed && $runtimeParamsCacheKey !== null) {
                    self::runtimeCacheSet($runtimeParamsCacheKey, $layoutStaticMeta);
                }

                // 如果控制器没有设置标题，则从 meta 中获取默认标题并设置
                if ($this->shouldUseMetaTitle($template, $request) && !empty($metaData['title'])) {
                    $template->assign('title', $metaData['title']);
                }
                $this->logThemeLayoutResolved(
                    $eventData,
                    (string)$contentTemplateFileName,
                    (string)$resolvedLayoutPath,
                    (string)$fileName,
                    $controller
                );
            }
            // 如果布局模板不存在，保持原路径（回退机制），但布局信息已设置到 theme 对象中
        } catch (\Throwable $e) {
            $this->logThemeLayoutResolveException($e, $eventData, $fileName, $controller);
            // 如果出现异常，至少设置基本的主题数据（包括主题对象和默认布局信息）
            // 确保模板可以正常使用主题数据
            if (empty($layoutType)) {
                $layoutType = 'default';
            }
            if (empty($layoutOption)) {
                $layoutOption = 'default';
            }
            $themeData = [
                'area' => $area,
                'colorMode' => $welineThemeColorMode,
                'layoutType' => $layoutType,
                'layoutOption' => $layoutOption,
                'theme' => $theme, // 主题对象本身，供模板直接使用
            ];
            $template->setData('theme', $themeData);
            $template->setData('layoutType', $layoutType);
            $template->setData('layoutOption', $layoutOption);

            if (!isset($scope)) {
                $scope = $this->themeContext->resolveCurrentScope($area);
            }
            // 性能极客模式：在事件中统一读取所有CSS颜色变量，转换为colors数组，供所有模板直接使用
            $colors = self::loadThemeColors($area, $scope, $theme);
            $template->setData('colors', $colors);
            
            // 保持原路径，不影响原有功能
            return;
        }
    }

    private function resolveExplicitLayoutOption(DataObject $eventData, ?Request $request): string
    {
        foreach ([
            $eventData->getData('layoutOption'),
            $request ? $this->readRequestValue($request, 'layout_option') : null,
            $request ? $this->readRequestValue($request, 'layoutOption') : null,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $layoutOption = \trim(\str_replace('\\', '/', (string)$value), '/ ');
            if ($layoutOption !== '') {
                return $layoutOption;
            }
        }

        return '';
    }

    private function resolveExplicitScopeParam(?Request $request, string $area): ?string
    {
        if ($request === null) {
            return null;
        }

        foreach (['scope_' . $area, 'scope'] as $key) {
            $value = $this->readRequestValue($request, $key);
            if (!\is_scalar($value)) {
                continue;
            }
            $scope = \trim((string)$value);
            if ($scope !== '') {
                return $scope;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveVirtualLayoutForRequest(
        ?Request $request,
        int $themeId,
        string $area,
        string $scope,
        string $layoutType,
        string $layoutOption
    ): ?array {
        $layoutType = trim($layoutType);
        $layoutOption = trim($layoutOption);
        if ($layoutType === '' || $layoutOption === '') {
            return null;
        }

        try {
            /** @var ThemeVirtualLayoutService $virtualLayoutService */
            $virtualLayoutService = ObjectManager::getInstance(ThemeVirtualLayoutService::class);
            return $virtualLayoutService->resolvePublishedRuntimeLayout(
                $layoutType,
                $layoutOption,
                $themeId,
                $area,
                $scope,
                $this->resolveVirtualLayoutTargets($request)
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{target_type:string,target_id:int}>
     */
    private function resolveVirtualLayoutTargets(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $targets = [];
        $rawChain = $this->readRequestValue($request, 'theme_layout_target_chain');
        if (is_string($rawChain) && trim($rawChain) !== '') {
            $decoded = json_decode($rawChain, true);
            $rawChain = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (is_array($rawChain)) {
            foreach ($rawChain as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $this->appendVirtualLayoutTarget(
                    $targets,
                    (string)($target['target_type'] ?? ''),
                    (int)($target['target_id'] ?? 0)
                );
            }
        }

        $this->appendVirtualLayoutTarget(
            $targets,
            (string)$this->readRequestValue($request, 'theme_layout_source_target_type'),
            (int)$this->readRequestValue($request, 'theme_layout_source_target_id')
        );
        $this->appendVirtualLayoutTarget(
            $targets,
            (string)$this->readRequestValue($request, 'theme_layout_target_type'),
            (int)$this->readRequestValue($request, 'theme_layout_target_id')
        );

        return array_values($targets);
    }

    private function readRequestValue(Request $request, string $key): mixed
    {
        $value = null;
        try {
            $value = $request->getData($key);
        } catch (\Throwable) {
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        try {
            $value = $request->getParam($key, null);
        } catch (\Throwable) {
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        try {
            return $request->getGet($key, '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<string, array{target_type:string,target_id:int}> $targets
     */
    private function appendVirtualLayoutTarget(array &$targets, string $targetType, int $targetId): void
    {
        $targetType = strtolower(trim($targetType));
        if (!in_array($targetType, ['product', 'category', 'category_product_default'], true) || $targetId <= 0) {
            return;
        }

        $targets[$targetType . ':' . $targetId] = [
            'target_type' => $targetType,
            'target_id' => $targetId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractLayoutMetaIdentity(?string $layoutFilePath, string $resolvedLayoutPath = '', string $area = 'frontend'): array
    {
        $identity = $this->extractLayoutMetaIdentityFromFile($layoutFilePath);
        if (!empty($identity['layout_name'])) {
            return $identity;
        }

        if (strpos($resolvedLayoutPath, '::') !== false) {
            [, $relativePath] = explode('::', $resolvedLayoutPath, 2);
            $defaultPath = LayoutPathResolver::getDefaultLayoutPath($relativePath, $area);
            $defaultIdentity = $this->extractLayoutMetaIdentityFromFile($defaultPath);
            if (!empty($defaultIdentity['layout_name'])) {
                return array_merge($defaultIdentity, $identity);
            }
        }

        return $identity;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractLayoutMetaIdentityFromFile(?string $layoutFilePath): array
    {
        if (!$layoutFilePath || !is_file($layoutFilePath)) {
            return [];
        }

        try {
            $parsed = \Weline\Theme\Helper\ComponentMetaParser::parse($layoutFilePath);
        } catch (\Throwable) {
            return [];
        }

        return $this->mergeLayoutMetaIdentity([], $parsed['meta'] ?? []);
    }

    /**
     * @param array<string, mixed> $metaData
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function mergeLayoutMetaIdentity(array $metaData, array $source): array
    {
        $name = $this->normalizeMetaAttribute($source['name'] ?? null);
        if ($name !== '') {
            $metaData['layout_name'] = $name;
            $metaData['name'] = $name;
        }

        $description = $this->normalizeMetaAttribute($source['description'] ?? null);
        if ($description !== '') {
            $metaData['layout_description'] = $description;
            if (empty($metaData['description']) || is_array($metaData['description'])) {
                $metaData['description'] = $description;
            }
        }

        return $metaData;
    }

    private function normalizeMetaAttribute(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['default', 'name', 'value', 'label'] as $key) {
                if (isset($value[$key]) && trim((string)$value[$key]) !== '') {
                    return trim((string)$value[$key]);
                }
            }
            return '';
        }

        return trim((string)$value);
    }

    /**
     * Layout parameter caches are process-level in WLS. Keep only layout/static
     * values there; controller/request data must be merged fresh per request.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeRuntimeLayoutParams(array $params): array
    {
        foreach ([
            'sidebar',
            'user',
            'content',
            'contentTemplate',
            'contentRenderKey',
            'controller',
            'request',
            'req',
            'session',
            'child_html',
        ] as $key) {
            unset($params[$key]);
        }

        return $params;
    }

    /**
     * Keep a controller-assigned page title in meta before layout rendering
     * reinitializes Template::title to the module fallback.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function preserveAssignedTitleInMeta(array $meta, Template $template, ?Request $request): array
    {
        if ($this->hasMetaTitle($meta)) {
            return $meta;
        }

        $title = trim((string)$template->getData('title'));
        if ($title === '' || $this->isModuleDefaultTitle($title, $request)) {
            return $meta;
        }

        $meta['controller_title'] = $meta['controller_title'] ?? $title;
        $meta['title'] = $title;
        return $meta;
    }

    private function shouldUseMetaTitle(Template $template, ?Request $request): bool
    {
        $title = trim((string)$template->getData('title'));
        return $title === '' || $this->isModuleDefaultTitle($title, $request);
    }

    private function isModuleDefaultTitle(string $title, ?Request $request): bool
    {
        $moduleTitle = trim((string)($request?->getModuleName() ?? ''));
        return $moduleTitle !== '' && $title === $moduleTitle;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function hasMetaTitle(array $meta): bool
    {
        foreach (['title', 'meta_title'] as $key) {
            if (array_key_exists($key, $meta) && trim((string)$meta[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * deploy=dev 时记录本次请求解析到的布局，便于对照「实际命中的 layout」与 header/footer 链路。
     */
    private function logThemeLayoutResolved(
        DataObject $eventData,
        string $contentTemplate,
        string $layoutTemplate,
        string $fileName,
        mixed $controller
    ): void {
        if (!$this->isThemeLayoutDebugEnabled()) {
            return;
        }
        $uri = '';
        try {
            $req = ObjectManager::getInstance(Request::class);
            $uri = (string)($req->getServer('REQUEST_URI') ?? $req->getUri() ?? '');
        } catch (\Throwable) {
        }
        $payload = [
            'uri' => $uri,
            'controller' => is_object($controller) ? $controller::class : (is_string($controller) ? $controller : ''),
            'layoutType' => (string)$eventData->getData('layoutType'),
            'layoutOption' => (string)($eventData->getData('layoutOption') ?? ''),
            'contentTemplate' => $contentTemplate,
            'layoutTemplate' => $layoutTemplate,
            'fileName' => $fileName,
        ];
        try {
            Env::getInstance()->getLogger()?->debug('[Theme Layout Resolve]', $payload);
        } catch (\Throwable) {
        }
    }

    private function logThemeLayoutResolveException(\Throwable $e, DataObject $eventData, mixed $fileName, mixed $controller): void
    {
        if (!$this->isThemeLayoutDebugEnabled()) {
            return;
        }
        $uri = '';
        try {
            $req = ObjectManager::getInstance(Request::class);
            $uri = (string)($req->getServer('REQUEST_URI') ?? $req->getUri() ?? '');
        } catch (\Throwable) {
        }
        try {
            Env::getInstance()->getLogger()?->warning('[Theme Layout Resolve Failed]', [
                'uri' => $uri,
                'controller' => is_object($controller) ? $controller::class : (is_string($controller) ? $controller : ''),
                'layoutType' => $eventData->getData('layoutType'),
                'fileName' => $fileName,
                'exception' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
        }
    }

    private function isThemeLayoutDebugEnabled(): bool
    {
        if (defined('ENV_TEST') && constant('ENV_TEST')) {
            return false;
        }
        try {
            return (Env::system('deploy') ?? '') === 'dev';
        } catch (\Throwable) {
            return false;
        }
    }

    private function isBackendRequest(?Request $request, mixed $controller = null): bool
    {
        if ($this->isBackendController($controller, $request)) {
            return true;
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            $area = (string)$context->get('route.area', '');
            if ($area !== '') {
                return $area === 'backend' || $area === 'rest_backend';
            }

            $isBackend = $context->get('route.is_backend', null);
            if ($isBackend !== null) {
                return (bool)$isBackend;
            }
        }

        if ($request === null) {
            return false;
        }

        $serverFlag = $request->getServer('WELINE_IS_BACKEND');
        if ($serverFlag !== null) {
            return (bool)$serverFlag;
        }

        $area = (string)($request->getServer('WELINE_AREA') ?? '');
        if ($area !== '') {
            return $area === 'backend' || $area === 'rest_backend';
        }

        $uri = (string)($context?->get('input.uri', $request->getServer('REQUEST_URI') ?? \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '')) ?? '');
        if ($uri !== '') {
            try {
                $backendPrefix = (string)\Weline\Framework\App\Env::getAreaRoutePrefix('backend');
            } catch (\Throwable) {
                $backendPrefix = '';
            }

            $backendPrefix = \trim($backendPrefix, '/');
            if ($backendPrefix !== '') {
                $normalizedUri = '/' . \ltrim($uri, '/');
                if (\preg_match('#^/' . \preg_quote($backendPrefix, '#') . '(?:/|$)#', $normalizedUri) === 1) {
                    return true;
                }
            }

            // 兜底：某些代理/前缀场景下 route 上下文尚未就绪，仍需识别典型后台路由。
            $normalizedUri = '/' . \ltrim($uri, '/');
            if (\str_contains($normalizedUri, '/theme/backend/')
                || \str_contains($normalizedUri, '/backend/theme/')
                || \str_contains($normalizedUri, '/backend/theme-editor')
            ) {
                return true;
            }
        }

        return $request->isBackend();
    }

    private function isBackendController(mixed $controller, ?Request $request): bool
    {
        if (\is_object($controller)) {
            $controllerClass = $controller::class;
            if (\str_contains($controllerClass, '\\Controller\\Backend\\')) {
                return true;
            }
        }

        if ($request === null) {
            return false;
        }

        $controllerName = (string)($request->getRouterData('class/controller_name') ?? '');
        if ($controllerName !== '' && \str_contains(\str_replace('\\', '/', $controllerName), 'Backend/')) {
            return true;
        }

        $controllerClass = (string)($request->getRouterData('class/name') ?? '');
        return $controllerClass !== '' && \str_contains($controllerClass, '\\Controller\\Backend\\');
    }

    /**
     * 解析布局用主题：优先预览主题（URL 参数或 Session），否则激活主题
     */
    private function resolveThemeForLayout(string $area, bool $allowPreview = true): WelineTheme
    {
        self::registerStateManager();
        $cacheKey = $this->buildThemeCacheKey($area);
        $requestCache = self::requestCacheState();
        if (isset($requestCache->themeByAreaCache[$cacheKey])) {
            return $requestCache->themeByAreaCache[$cacheKey];
        }
        $theme = $this->themeContext->resolveTheme($area, null, $allowPreview);
        if ($theme === null || !$theme->getId()) {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery();
            $theme->getActiveTheme($area);
        }
        $requestCache->themeByAreaCache[$cacheKey] = $theme;
        return $theme;
    }

    /**
     * 生成请求级主题缓存键，避免预览请求误复用普通请求（或其它主题）的缓存。
     */
    private function buildThemeCacheKey(string $area): string
    {
        $request = null;
        try {
            $request = ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
        }

        if (!$request) {
            return $area . '|default';
        }

        $frontendThemeId = (int)$request->getParam('frontend_theme_id', 0);
        $backendThemeId = (int)$request->getParam('backend_theme_id', 0);
        $legacyThemeId = (int)$request->getParam('preview_theme', 0);
        $previewThemeId = $area === 'backend'
            ? ($backendThemeId > 0 ? $backendThemeId : $legacyThemeId)
            : ($frontendThemeId > 0 ? $frontendThemeId : $legacyThemeId);
        $previewToken = (string)$request->getParam('weline_preview_token', '');

        return implode('|', [
            $area,
            'editor_area:' . strtolower((string)$request->getParam('editor_area', '')),
            'preview_theme:' . $previewThemeId,
            'preview_token:' . substr($previewToken, 0, 24),
        ]);
    }

    private function isRuntimeCacheAllowed(?Request $request, bool $isBackendRequest): bool
    {
        if ($isBackendRequest || $request === null) {
            return false;
        }

        foreach ([
            'visual_editor',
            'preview',
            'preview_theme',
            'frontend_theme_id',
            'backend_theme_id',
            'virtual_theme_id',
            'weline_preview_token',
            'scope',
            'scope_frontend',
        ] as $param) {
            $value = $request->getParam($param, null);
            if ($value !== null && trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private static function runtimeCacheGet(string $key): array
    {
        $entry = self::$runtimeCache[$key] ?? null;
        if (!is_array($entry)) {
            return [false, null];
        }

        if (($entry['expires_at'] ?? 0.0) < microtime(true)) {
            unset(self::$runtimeCache[$key]);
            return [false, null];
        }

        return [true, $entry['value'] ?? null];
    }

    private static function runtimeCacheSet(string $key, mixed $value): void
    {
        self::$runtimeCache[$key] = [
            'expires_at' => microtime(true) + self::runtimeCacheTtl(),
            'value' => $value,
        ];
    }

    private static function runtimeCacheTtl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('theme.runtime_data_ttl', self::RUNTIME_CACHE_TTL);
        } catch (\Throwable) {
            return self::RUNTIME_CACHE_TTL;
        }
    }

    private static function currentFiber(): ?\Fiber
    {
        if (!class_exists(\Weline\Framework\Runtime\Runtime::class)) {
            return null;
        }

        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    private static function requestCacheState(): ControllerFetchFileBeforeRequestCacheState
    {
        $fiber = self::currentFiber();
        if ($fiber === null) {
            self::$mainRequestCache ??= new ControllerFetchFileBeforeRequestCacheState();
            return self::$mainRequestCache;
        }

        self::$fiberRequestCaches ??= new \WeakMap();
        if (!isset(self::$fiberRequestCaches[$fiber])) {
            self::$fiberRequestCaches[$fiber] = new ControllerFetchFileBeforeRequestCacheState();
        }

        return self::$fiberRequestCaches[$fiber];
    }

    private static function resetCurrentRequestCacheState(): void
    {
        $fiber = self::currentFiber();
        if ($fiber === null) {
            self::$mainRequestCache = new ControllerFetchFileBeforeRequestCacheState();
            return;
        }

        self::$fiberRequestCaches ??= new \WeakMap();
        self::$fiberRequestCaches[$fiber] = new ControllerFetchFileBeforeRequestCacheState();
    }

    /**
     * 加载主题颜色变量并转换为colors数组（性能极客模式）
     * 在事件中统一处理，避免每个模板都重复读取
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域
     * @param WelineTheme|null $theme 主题对象
     * @return array colors数组，格式：['primary' => '#ff9900', 'bgTeriary' => '#e7e7e7', ...]
     */
    private static function loadThemeColors(string $area, string $scope, ?WelineTheme $theme): array
    {
        $colors = [];
        
        try {
            // 设置当前主题和区域（ThemeData会自动初始化）
            if ($theme && $theme->getId()) {
                ThemeData::setCurrentTheme($theme);
            }
            ThemeData::setCurrentArea($area);
            
            // 1. 从变量配置读取
            $configList = ThemeData::getConfigList($area, 'variables', $scope);
            foreach ($configList as $configKey => $configValue) {
                // configKey格式: {variableFile}.{variableName}.value（getConfigList已移除variables前缀）
                // 例如: colors.color-primary.value -> variableName = color-primary
                if (preg_match('/^([^.]+)\.([^.]+)\.value$/', $configKey, $matches)) {
                    $variableFile = $matches[1];
                    $variableName = $matches[2];
                    
                    // 只处理颜色相关的变量（以color-开头）
                    if (strpos($variableName, 'color-') === 0) {
                        // 移除color-前缀，转换为驼峰命名
                        // color-primary -> primary
                        // color-bg-tertiary -> bgTeriary
                        // color-text-primary -> textPrimary
                        $colorKey = self::cssVarToCamelCase($variableName);
                        
                        // 处理配置值（可能是字符串或数组）
                        $value = is_array($configValue) ? json_encode($configValue) : (string)$configValue;
                        $colors[$colorKey] = $value;
                    }
                }
            }
            
            // 2. 从色盘配置读取变量值（覆盖变量配置）
            $colorConfig = ThemeData::getColorConfig($area, $scope);
            if ($colorConfig) {
                $paletteMeta = ThemeData::getMeta("theme.{$area}.colors.{$colorConfig}");
                if ($paletteMeta && isset($paletteMeta['meta_data']['variables'])) {
                    $paletteVars = $paletteMeta['meta_data']['variables'];
                    foreach ($paletteVars as $varName => $varValue) {
                        // 处理CSS变量名格式（可能是--color-primary或color-primary）
                        $normalizedVarName = str_replace('--', '', $varName);
                        if (strpos($normalizedVarName, 'color-') === 0) {
                            $colorKey = self::cssVarToCamelCase($normalizedVarName);
                            $colors[$colorKey] = (string)$varValue;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果获取失败，返回空数组（模板中使用默认值）
        }
        
        return $colors;
    }
    
    /**
     * 将CSS变量名转换为驼峰命名
     * --color-primary -> primary
     * --color-bg-tertiary -> bgTeriary
     * --color-text-primary -> textPrimary
     * 
     * @param string $cssVarName CSS变量名（如color-primary或--color-primary）
     * @return string 驼峰命名的键名
     */
    private static function cssVarToCamelCase(string $cssVarName): string
    {
        // 移除--前缀和color-前缀
        $name = str_replace('--', '', $cssVarName);
        if (strpos($name, 'color-') === 0) {
            $name = substr($name, 6); // 移除color-前缀
        }
        
        // 将连字符分隔的字符串转换为驼峰命名
        // bg-tertiary -> bgTeriary
        // text-primary -> textPrimary
        $parts = explode('-', $name);
        $camelCase = $parts[0]; // 第一部分保持小写
        for ($i = 1; $i < count($parts); $i++) {
            $camelCase .= ucfirst($parts[$i]);
        }
        
        return $camelCase;
    }

}
