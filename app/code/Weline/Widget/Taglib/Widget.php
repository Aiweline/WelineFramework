<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Taglib;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Service\WidgetRegistry;
use Weline\Widget\Service\WidgetRuntimeTemplateRenderer;

/**
 * w:widget 标签实现
 *
 * 编译期只产出 slot↔widget 关系壳（{@see renderRuntimeInline}），
 * 店面执行模板时再 hydrate HTML；禁止把渲染结果烘焙进 com_*.phtml。
 *
 * 使用示例：
 * <w:widget type="header" name="default" />
 * <w:widget type="header" name="default" params='{"title":"我的网站"}' />
 */
class Widget implements TaglibInterface
{
    private const REQUEST_STATE_KEY = 'widget.taglib.render_state.v1';
    private const MAX_RENDER_DEPTH = 10;
    
    /**
     * 标签名称
     */
    public static function name(): string
    {
        return 'widget';
    }

    /**
     * 支持成对标签和自闭合标签
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * 标签属性定义
     */
    public static function attr(): array
    {
        return [
            'type' => false,         // 部件类型（与 code 二选一体系：type+name 或 code）
            'name' => false,         // 部件名称
            'code' => false,         // 部件代码（可与 type 组合；缺省 name 时等同 name）
            'module' => false,       // 可选模块名（标记用）
            'params' => false,       // 部件参数，JSON 格式（可选）
            'block-class' => false,  // 覆盖 Block 类（可选）
            'template' => false,     // 覆盖模板路径（可选）
            'id' => false,           // 部件实例 ID（可选）
            'ref' => false,          // 稳定模板引用（slot 内 CoW 用；缺省自动生成）
            'layout-source' => false, // 布局关键静态资源 Vendor_Module::path,...
            'source-postion' => false,
            'source-position' => false,
            'source' => false,       // 常用静态资源 Vendor_Module::path,...
        ];
    }

    /**
     * 不支持标签开始处理
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * 不支持标签结束处理
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * 标签处理回调
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 只处理成对标签和自闭合标签
            if ($tag_key !== 'tag' && $tag_key !== 'tag-self-close-with-attrs') {
                return '';
            }

            // 获取必需属性（支持 type+name，或 code 作为 name 别名）
            $type = (string)($attributes['type'] ?? '');
            $name = (string)($attributes['name'] ?? '');
            $code = (string)($attributes['code'] ?? '');
            if ($name === '' && $code !== '') {
                $name = $code;
            }
            if ($code === '' && $name !== '') {
                $code = $name;
            }

            if ($type === '' || $name === '') {
                return '<!-- Widget 错误: type 与 name（或 code）属性是必需的 -->';
            }

            // 获取可选属性
            $paramsJson = $attributes['params'] ?? '{}';
            $blockClass = $attributes['block-class'] ?? $attributes['blockClass'] ?? '';
            $template = $attributes['template'] ?? '';
            $widgetId = $attributes['id'] ?? '';
            $moduleAttr = (string)($attributes['module'] ?? '');
            $templateRef = trim((string)($attributes['ref'] ?? ''));

            // 解析参数
            $params = [];
            if (!empty($paramsJson)) {
                $decoded = json_decode($paramsJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $params = $decoded;
                }
            }

            try {
                // 运行时只从注册表读取，不执行扫描
                /** @var WidgetData $widgetData */
                $widgetData = ObjectManager::getInstance(WidgetData::class);
                
                // 验证部件类型
                if (!$widgetData->isValidType($type)) {
                    return "<!-- Widget 错误: 无效的部件类型 {$type} -->";
                }
                
                // 从注册表获取部件配置
                $widget = $widgetData->getWidget($type, $name);

                if (!$widget) {
                    return "<!-- Widget 错误: 未找到部件 {$type}/{$name} -->";
                }

                foreach (['layout-source' => '_layout_source', 'source' => '_source'] as $attribute => $key) {
                    if (isset($attributes[$attribute])) { $params[$key] = (string)$attributes[$attribute]; }
                }
                if (isset($attributes['source-postion']) || isset($attributes['source-position'])) {
                    $params['_source_position'] = (string)($attributes['source-postion'] ?? $attributes['source-position']);
                }

                $module = $moduleAttr !== '' ? $moduleAttr : (string)($widget['module'] ?? '');
                if ($templateRef === '') {
                    $templateRef = self::buildTemplateRef($type, $code !== '' ? $code : $name, $module, $params);
                }

                // 固化/Taglib 编译只写入 slot↔widget 关系壳，禁止把某次请求的渲染 HTML
                // 烘焙进 view/tpl com_*.phtml。HTML 一律在店面执行模板时 hydrate。
                // 规格只携带标签显式参数；注册表默认值在运行时再合并。
                $spec = [
                    'type' => $type,
                    'name' => $name,
                    'code' => $code !== '' ? $code : $name,
                    'module' => $module,
                    'params' => $params,
                    'template_ref' => $templateRef,
                    'block_class' => (string)$blockClass,
                    'template' => (string)$template,
                    'widget_id' => (string)$widgetId,
                ];

                return '<?= \\Weline\\Widget\\Taglib\\Widget::renderRuntimeInline('
                    . var_export($spec, true)
                    . ') ?>';
            } catch (\Throwable $e) {
                w_log_error("Widget 标签渲染错误: " . $e->getMessage(), [], 'WidgetTaglib');
                return "<!-- Widget 错误: " . htmlspecialchars($e->getMessage()) . " -->";
            }
        };
    }

    /**
     * 渲染部件
     *
     * @param array $widget 部件配置
     * @param array $params 部件参数
     * @param string $blockClass 覆盖的 Block 类
     * @param string $template 覆盖的模板路径
     * @return string
     */
    private static function renderWidget(array $widget, array $params, string $blockClass = '', string $template = ''): string
    {
        // 生成缓存键（仅对模板渲染使用缓存，Block 类可能有动态内容）
        $cacheKey = null;
        $useCache = empty($blockClass) && empty($widget['block_class'] ?? '');
        
        if ($useCache) {
            $type = $widget['type'] ?? '';
            $name = $widget['code'] ?? $widget['name'] ?? '';
            $templatePath = $template ?: ($widget['template'] ?? '');
            if (empty($templatePath) && !empty($widget['template_content'])) {
                $templatePath = 'content:' . md5((string)$widget['template_content']);
            }
            if (empty($templatePath) && !empty($widget['path'])) {
                $module = $widget['module'] ?? '';
                $templatePath = $module . '::widgets/' . $type . '/' . $name . '.phtml';
            }
            $cacheKey = md5($type . '|' . $name . '|' . $templatePath . '|' . serialize($params));
            
            // form_key / challenge_token 随请求变化，不可复用缓存 HTML
            $renderCache = self::renderCache();
            if (isset($renderCache[$cacheKey])) {
                $cached = $renderCache[$cacheKey];
                if (self::isCacheableWidgetHtml($cached)) {
                    return $cached;
                }
            }
        }
        
        // 优先使用覆盖的 Block 类
        if (!empty($blockClass)) {
            return self::renderBlock($blockClass, $params);
        }

        // 使用部件配置中的 Block 类
        $widgetBlockClass = $widget['block_class'] ?? '';
        if (!empty($widgetBlockClass)) {
            return self::renderBlock($widgetBlockClass, $params);
        }

        // 使用覆盖的模板
        if (!empty($template)) {
            $result = self::renderTemplate($template, $params);
            if ($cacheKey !== null && self::isCacheableWidgetHtml($result)) {
                self::cacheRender($cacheKey, $result);
            }
            return $result;
        }

        // 使用部件配置中的模板
        $widgetTemplate = $widget['template'] ?? '';
        if (!empty($widgetTemplate)) {
            $result = self::renderTemplate($widgetTemplate, $params);
            if ($cacheKey !== null && self::isCacheableWidgetHtml($result)) {
                self::cacheRender($cacheKey, $result);
            }
            return $result;
        }

        // 尝试查找默认模板
        $widgetTemplateContent = (string)($widget['template_content'] ?? '');
        if ($widgetTemplateContent !== '') {
            $result = self::renderRuntimeTemplateContent($widgetTemplateContent, $params);
            if ($cacheKey !== null && self::isCacheableWidgetHtml($result)) {
                self::cacheRender($cacheKey, $result);
            }
            return $result;
        }

        $widgetPath = $widget['path'] ?? '';
        if (!empty($widgetPath)) {
            $defaultTemplate = $widgetPath . DIRECTORY_SEPARATOR . 'template.phtml';
            if (file_exists($defaultTemplate)) {
                // 构建模板路径
                $module = $widget['module'] ?? '';
                $type = $widget['type'] ?? '';
                $name = $widget['code'] ?? '';
                $templatePath = $module . '::widgets/' . $type . '/' . $name . '.phtml';
                $result = self::renderTemplate($templatePath, $params);
                if ($cacheKey !== null && self::isCacheableWidgetHtml($result)) {
                    self::cacheRender($cacheKey, $result);
                }
                return $result;
            }
        }

        return '<!-- Widget 错误: 未找到模板或 Block 类 -->';
    }

    private static function isCacheableWidgetHtml(string $html): bool
    {
        return !str_contains($html, 'name="form_key"')
            && !str_contains($html, 'name="challenge_token"')
            && !str_contains($html, 'data-w-challenge-token')
            && !str_contains($html, 'name="redirect_url"')
            && !str_contains($html, 'data-social-quick')
            && !str_contains($html, 'data-w-auth-return')
            && !str_contains($html, 'data-product-id=')
            && !str_contains($html, 'product-native-detail');
    }

    /**
     * Taglib 编译期唯一产物：运行时关系壳。布局固化 / view/tpl 只记录 slot↔widget
     * 身份与显式参数，不得烘焙任何请求态 HTML。
     *
     * @param array{
     *   type?:string,
     *   name?:string,
     *   code?:string,
     *   module?:string,
     *   params?:array<string,mixed>,
     *   template_ref?:string,
     *   block_class?:string,
     *   template?:string,
     *   widget_id?:string
     * } $spec
     */
    public static function renderRuntimeInline(array $spec): string
    {
        $type = trim((string)($spec['type'] ?? ''));
        $name = trim((string)($spec['name'] ?? ''));
        $code = trim((string)($spec['code'] ?? $name));
        if ($type === '' || $name === '') {
            return '<!-- Widget 错误: runtime inline 缺少 type/name -->';
        }

        try {
            /** @var WidgetData $widgetData */
            $widgetData = ObjectManager::getInstance(WidgetData::class);
            $widget = $widgetData->getWidget($type, $name);
            if (!$widget) {
                return '<!-- Widget 错误: 未找到部件 ' . htmlspecialchars($type . '/' . $name, ENT_QUOTES, 'UTF-8') . ' -->';
            }

            $params = is_array($spec['params'] ?? null) ? $spec['params'] : [];
            $widgetParams = $widget['params'] ?? [];
            foreach ($widgetParams as $paramName => $paramConfig) {
                if (!isset($params[$paramName])) {
                    $params[$paramName] = is_array($paramConfig) ? ($paramConfig['default'] ?? null) : null;
                }
            }

            $blockClass = (string)($spec['block_class'] ?? '');
            $template = (string)($spec['template'] ?? '');
            $html = self::renderWidget($widget, $params, $blockClass, $template);
            $assetRenderer = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\WidgetAssetRenderer::class);
            $html = $assetRenderer->wrap($html, $assetRenderer->render($widget, $params, $template));

            $widgetId = trim((string)($spec['widget_id'] ?? ''));
            if ($widgetId !== '') {
                $html = self::wrapWidgetContainer($html, $widgetId, $type, $name, $params);
            }

            $module = trim((string)($spec['module'] ?? ($widget['module'] ?? '')));
            $templateRef = trim((string)($spec['template_ref'] ?? ''));
            if ($templateRef === '') {
                $templateRef = self::buildTemplateRef($type, $code !== '' ? $code : $name, $module, $params);
            }

            return self::wrapTemplateInlineWidget($html, $templateRef, $type, $code !== '' ? $code : $name, $module, $params);
        } catch (\Throwable $e) {
            w_log_error('Widget runtime inline 渲染错误: ' . $e->getMessage(), [], 'WidgetTaglib');

            return '<!-- Widget 错误: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
        }
    }

    /**
     * @deprecated Use {@see renderRuntimeInline()}; kept as a thin alias for already-compiled templates.
     *
     * @param array<string, mixed> $spec
     */
    public static function renderDeferredInline(array $spec): string
    {
        return self::renderRuntimeInline($spec);
    }

    /**
     * 渲染 Block 类
     *
     * @param string $blockClass Block 类名
     * @param array $params 参数
     * @return string
     */
    private static function renderRuntimeTemplateContent(string $templateContent, array $params): string
    {
        try {
            /** @var WidgetRuntimeTemplateRenderer $renderer */
            $renderer = ObjectManager::getInstance(WidgetRuntimeTemplateRenderer::class);
            return $renderer->renderContent($templateContent, $params);
        } catch (\Throwable $e) {
            w_log_error("Widget 运行时模板渲染错误: " . $e->getMessage(), [], 'WidgetTaglib');
            return '<!-- Widget 错误: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }

    private static function renderBlock(string $blockClass, array $params): string
    {
        try {
            /** @var Block $block */
            $block = ObjectManager::getInstance($blockClass);
            
            // 传递参数到 Block
            foreach ($params as $key => $value) {
                $block->setData($key, $value);
            }

            // 初始化 Block
            if (method_exists($block, '__init')) {
                $block->__init();
            }

            // 渲染 Block
            if (method_exists($block, 'render')) {
                return $block->render();
            }

            return '<!-- Widget 错误: Block 类缺少 render() 方法 -->';
        } catch (\Throwable $e) {
            w_log_error("Widget Block 渲染错误: " . $e->getMessage(), [], 'WidgetTaglib');
            return '<!-- Widget 错误: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }

    /**
     * 渲染模板
     *
     * @param string $templatePath 模板路径（格式：ModuleName::path/to/template.phtml）
     * @param array $params 参数
     * @return string
     */
    private static function renderTemplate(string $templatePath, array $params): string
    {
        $state = self::requestState();
        if ($state['render_depth'] >= self::MAX_RENDER_DEPTH) {
            w_log_error("Widget 模板渲染递归深度超限: {$templatePath}", [], 'WidgetTaglib');
            return '<!-- Widget 错误: 模板渲染递归深度超限 -->';
        }
        
        // 检查循环引用
        $templateKey = md5($templatePath . serialize($params));
        if (isset($state['rendering_templates'][$templateKey])) {
            w_log_error("Widget 模板渲染检测到循环引用: {$templatePath}", [], 'WidgetTaglib');
            return '<!-- Widget 错误: 模板渲染循环引用 -->';
        }
        
        $state['render_depth']++;
        $state['rendering_templates'][$templateKey] = true;
        self::storeRequestState($state);
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            // 直接使用 fetchHtml 传递参数，避免先 assign 再 fetch 的开销
            $result = $template->fetchHtml($templatePath, $params);
            return is_string($result) ? $result : '';
        } catch (\Throwable $e) {
            w_log_error("Widget 模板渲染错误: " . $e->getMessage(), [], 'WidgetTaglib');
            return '<!-- Widget 错误: ' . htmlspecialchars($e->getMessage()) . ' -->';
        } finally {
            $state = self::requestState();
            $state['render_depth'] = max(0, $state['render_depth'] - 1);
            unset($state['rendering_templates'][$templateKey]);
            self::storeRequestState($state);
        }
    }

    /**
     * WLS 每请求重置请求级渲染缓存，避免跨请求复用错误模板输出。
     */
    public static function resetRequestState(): void
    {
        RequestContext::remove(self::REQUEST_STATE_KEY);
    }

    /** @return array<string, string> */
    private static function renderCache(): array
    {
        return self::requestState()['render_cache'];
    }

    private static function cacheRender(string $cacheKey, string $html): void
    {
        $state = self::requestState();
        $state['render_cache'][$cacheKey] = $html;
        self::storeRequestState($state);
    }

    /**
     * @return array{
     *     render_cache: array<string, string>,
     *     render_depth: int,
     *     rendering_templates: array<string, bool>
     * }
     */
    private static function requestState(): array
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }

        return [
            'render_cache' => is_array($state['render_cache'] ?? null) ? $state['render_cache'] : [],
            'render_depth' => max(0, (int)($state['render_depth'] ?? 0)),
            'rendering_templates' => is_array($state['rendering_templates'] ?? null)
                ? $state['rendering_templates']
                : [],
        ];
    }

    private static function storeRequestState(array $state): void
    {
        RequestContext::set(self::REQUEST_STATE_KEY, $state);
    }

    /**
     * 包裹部件容器（用于编辑模式）
     *
     * @param string $html 部件 HTML
     * @param string $widgetId 部件 ID
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param array $params 部件参数
     * @return string
     */
    private static function wrapWidgetContainer(string $html, string $widgetId, string $type, string $name, array $params): string
    {
        $paramsJson = htmlspecialchars(json_encode($params, JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES, 'UTF-8');
        
        return sprintf(
            '<div class="widget-container" data-widget-id="%s" data-widget-type="%s" data-widget-name="%s" data-widget-params=\'%s\'>%s</div>',
            htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            $paramsJson,
            $html
        );
    }

    /**
     * 标记为模板内嵌默认部件（slot 内 CoW：未改动不落布局，改动后按 template_ref 覆盖）。
     *
     * @param array<string,mixed> $params
     */
    private static function wrapTemplateInlineWidget(
        string $html,
        string $templateRef,
        string $type,
        string $code,
        string $module,
        array $params
    ): string {
        $paramsJson = htmlspecialchars(json_encode($params, JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES, 'UTF-8');
        return sprintf(
            '<div class="weline-template-widget widget-wrapper"'
            . ' data-weline-template-widget="1"'
            . ' data-template-ref="%s"'
            . ' data-widget-type="%s"'
            . ' data-widget-code="%s"'
            . ' data-widget-name="%s"'
            . ' data-widget-module="%s"'
            . ' data-config=\'%s\''
            . ' data-widget-params=\'%s\'>%s</div>',
            htmlspecialchars($templateRef, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($module, ENT_QUOTES, 'UTF-8'),
            $paramsJson,
            $paramsJson,
            $html
        );
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function buildTemplateRef(string $type, string $code, string $module, array $params): string
    {
        $fingerprint = sha1(json_encode([
            'module' => $module,
            'type' => $type,
            'code' => $code,
            'params' => $params,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $type . '-' . $code) ?: 'widget';
        return 'tpl:' . strtolower($slug) . ':' . substr($fingerprint, 0, 12);
    }

    /**
     * 支持自闭合标签
     */
    public static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * 自闭合标签支持属性
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    /**
     * 无父标签依赖
     */
    public static function parent(): ?string
    {
        return null;
    }

    /**
     * 标签文档
     */
    public static function document(): string
    {
        return 'Widget 部件标签，用于在模板中渲染部件。' .
               '格式：<w:widget type="header" name="default" params=\'{"title":"标题"}\' />' .
               '属性：type（必需，部件类型）、name（必需，部件名称）、params（可选，JSON 格式的参数）。';
    }
}
