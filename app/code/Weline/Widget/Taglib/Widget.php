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

                // 全局区契约（禁止回潮）：同名片段已接管且已启用时，旧部件不再渲染，
                // 否则主题残留槽 + 已物化放置会让页面出现第二份。
                $contractCode = $code !== '' ? $code : (string)($widget['code'] ?? $name);
                if (\Weline\Theme\Region\GlobalRegion::replacesWidget($contractCode)) {
                    return '';
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
                    'cache' => \Weline\Widget\Cache\WidgetOutputCache::normalizeTtl($attributes['cache'] ?? null),
                ];
                if (\array_key_exists('share', $attributes)) {
                    $spec['share'] = \Weline\Widget\Cache\WidgetOutputCache::normalizeShare($attributes['share']);
                }

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
        if (!empty($blockClass)) {
            return self::renderBlock($blockClass, $params);
        }

        $widgetBlockClass = $widget['block_class'] ?? '';
        if (!empty($widgetBlockClass)) {
            return self::renderBlock($widgetBlockClass, $params);
        }

        if (!empty($template)) {
            return self::renderTemplate($template, $params);
        }

        $widgetTemplate = $widget['template'] ?? '';
        if (!empty($widgetTemplate)) {
            return self::renderTemplate($widgetTemplate, $params);
        }

        $widgetTemplateContent = (string)($widget['template_content'] ?? '');
        if ($widgetTemplateContent !== '') {
            return self::renderRuntimeTemplateContent($widgetTemplateContent, $params);
        }

        $widgetPath = $widget['path'] ?? '';
        if (!empty($widgetPath)) {
            $defaultTemplate = $widgetPath . DIRECTORY_SEPARATOR . 'template.phtml';
            if (file_exists($defaultTemplate)) {
                $module = $widget['module'] ?? '';
                $type = $widget['type'] ?? '';
                $name = $widget['code'] ?? '';
                $templatePath = $module . '::widgets/' . $type . '/' . $name . '.phtml';

                return self::renderTemplate($templatePath, $params);
            }
        }

        return '<!-- Widget 错误: 未找到模板或 Block 类 -->';
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
     *   widget_id?:string,
     *   cache?:int
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

            // Theme policy-document: empty sections must hydrate into data-config so the
            // visual editor ParamSchema array is editable (not preview-only Defaults).
            $widgetCode = $code !== '' ? $code : $name;
            if (
                $widgetCode === 'policy-document'
                && \class_exists(\Weline\Theme\Helper\PolicyDocumentDefaults::class)
            ) {
                $params = \Weline\Theme\Helper\PolicyDocumentDefaults::hydrateParams($params);
            }

            // 异步部件（@widget.async=true）：框架统一出空 SSR 标记，部件模板不参与首屏渲染。
            // 编辑器 / 主题预览必须看得到真实部件，强制走同步 SSR。
            $asyncModule = \trim((string)($widget['async_module'] ?? ''));
            if (self::isAsyncWidget($widget) && !self::asyncStubSuppressed()) {
                // 部件 CSS 必须随占位一起下发：部件 HTML 延后注入，样式不能等。
                return self::asyncStyleTags($widget, $params, (string)($widget['template'] ?? ''))
                    . self::renderAsyncStub(
                    $type,
                    $widgetCode,
                    \trim((string)($spec['module'] ?? ($widget['module'] ?? ''))),
                    $params,
                    $asyncModule,
                    (int)($widget['async_cache'] ?? 0),
                    ($widget['async_module_defer'] ?? true) !== false,
                );
            }

            $blockClass = (string)($spec['block_class'] ?? '');
            $template = (string)($spec['template'] ?? '');
            $instanceTtl = \Weline\Widget\Cache\WidgetOutputCache::normalizeTtl($spec['cache'] ?? null);
            $metaTtl = \Weline\Widget\Cache\WidgetOutputCache::normalizeTtl($widget['cache'] ?? null);
            $ttl = $instanceTtl > 0 ? $instanceTtl : $metaTtl;
            $share = \array_key_exists('share', $spec)
                ? \Weline\Widget\Cache\WidgetOutputCache::normalizeShare($spec['share'])
                : \Weline\Widget\Cache\WidgetOutputCache::normalizeShare($widget['share'] ?? null);
            $templatePath = $template !== '' ? $template : (string)($widget['template'] ?? '');
            $module = trim((string)($spec['module'] ?? ($widget['module'] ?? '')));
            $identity = $module . '::' . $type . '::' . ($code !== '' ? $code : $name);

            $renderCore = static function () use ($widget, $params, $blockClass, $template, $code, $name): string {
                $renderWidget = static function () use ($widget, $params, $blockClass, $template): string {
                    return self::renderWidget($widget, $params, $blockClass, $template);
                };
                $phase = null;
                if (\class_exists(\Weline\Theme\Service\ThemePdpBudgetPhases::class)) {
                    $phase = \Weline\Theme\Service\ThemePdpBudgetPhases::forWidgetCode($code !== '' ? $code : $name);
                }

                return $phase !== null
                    ? (string)\Weline\Theme\Service\ThemePdpBudgetPhases::measure(
                        $phase,
                        $renderWidget,
                        ['widget_code' => $code !== '' ? $code : $name, 'branch' => 'runtime_inline'],
                    )
                    : $renderWidget();
            };

            $productId = 0;
            $offerId = 0;
            if ($ttl > 0 && \class_exists(\Weline\Product\Helper\StorefrontOfferResolver::class)) {
                try {
                    $offer = \Weline\Product\Helper\StorefrontOfferResolver::currentOffer();
                    $productId = max(0, (int)($offer['product_id'] ?? 0));
                    $offerId = max(0, (int)($offer['offer_id'] ?? $offer['product_offer_id'] ?? 0));
                } catch (\Throwable) {
                }
            }
            if ($ttl > 0 && $productId <= 0) {
                $productId = max(0, (int)($params['card_product_id'] ?? $params['product_id'] ?? 0));
            }
            if ($ttl > 0 && $offerId <= 0) {
                $offerId = max(0, (int)($params['offer_id'] ?? $params['product_offer_id'] ?? 0));
            }

            $themeId = 0;
            if ($ttl > 0 && \class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                try {
                    $currentTheme = \Weline\Theme\Helper\ThemeData::getCurrentTheme();
                    if ($currentTheme && $currentTheme->getId()) {
                        $themeId = max(0, (int)$currentTheme->getId());
                    }
                } catch (\Throwable) {
                }
            }
            $html = \Weline\Widget\Cache\WidgetOutputCache::remember(
                $ttl,
                $ttl > 0 ? \Weline\Widget\Cache\WidgetOutputCache::buildKey([
                    'identity' => $identity,
                    'template_path' => $templatePath,
                    'node_uid' => (string)($spec['widget_id'] ?? $spec['template_ref'] ?? ''),
                    'product_id' => $productId,
                    'offer_id' => $offerId,
                    'layout_name' => \Weline\Widget\Cache\WidgetOutputCache::resolveLayoutName([], $params),
                    'share' => $share,
                    'theme_id' => $themeId,
                    'ttl' => $ttl,
                ], $params) : '',
                $renderCore,
            );
            $assetRenderer = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\WidgetAssetRenderer::class);
            $html = $assetRenderer->wrap($html, $assetRenderer->render($widget, $params, $template));

            $widgetId = trim((string)($spec['widget_id'] ?? ''));
            if ($widgetId !== '') {
                $html = self::wrapWidgetContainer($html, $widgetId, $type, $name, $params);
            }

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
     * 按部件身份渲染（部件模块统一入口，供放置层调用）：
     * 异步部件出框架占位；同步部件由部件模块按自己的模板渲染。
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public static function renderWidgetCode(
        string $type,
        string $code,
        array $config = [],
        array $context = [],
    ): string {
        $type = \trim($type) !== '' ? \trim($type) : 'content';
        $code = \trim($code);
        if ($code === '') {
            return '';
        }

        $widget = ObjectManager::getInstance(WidgetData::class)->getWidget($type, $code);
        if (!\is_array($widget)) {
            return '';
        }

        // 全局区片段本身就是异步水合：水合端点要正文，不能再套一层占位。
        $fragmentMode = (string)($context['render_mode'] ?? '') === 'fragment';
        if (!$fragmentMode && empty($context['preview_mode']) && self::isAsyncWidget($widget)) {
            return self::renderAsyncStub(
                $type,
                $code,
                (string)($widget['module'] ?? ''),
                $config,
                \trim((string)($widget['async_module'] ?? '')),
                (int)($widget['async_cache'] ?? 0),
                ($widget['async_module_defer'] ?? true) !== false,
            );
        }

        $template = (string)($widget['template'] ?? '');
        if ($template === '') {
            return '';
        }

        // 部件样式随内容一起下发（同一请求按 URL 去重）：全局区片段不再渲染片段自带的
        // <css> 声明，样式必须由部件自己的 @widget.source 补齐，否则异步/片段模式会掉样式。
        return self::asyncStyleTags($widget, $config, $template) . self::renderTemplate($template, $config);
    }

    /**
     * 部件统一渲染入口：部件自己判定是否异步，同步时再调用 Theme 渲染引擎出 HTML。
     *
     * 槽位注入 / 全局区片段 / 编辑器预览都走这里，保证「异步只在 Weline_Widget 判定」，
     * Theme 侧只负责把定义与配置交过来。
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public static function renderPlaceable(
        object $definition,
        array $config = [],
        ?object $theme = null,
        array $context = [],
    ): string {
        $type = \trim((string)($definition->type ?? ''));
        $code = \trim((string)($definition->code ?? ($definition->name ?? '')));
        $module = \trim((string)($definition->module ?? ''));
        if ($type === '' || $code === '') {
            return '';
        }

        if (empty($context['preview_mode']) && !self::asyncStubSuppressed()) {
            $widget = ObjectManager::getInstance(WidgetData::class)->getWidget($type, $code);
            if (\is_array($widget) && self::isAsyncWidget($widget)) {
                return self::renderAsyncStub(
                    $type,
                    $code,
                    $module,
                    $config,
                    \trim((string)($widget['async_module'] ?? '')),
                    (int)($widget['async_cache'] ?? 0),
                    ($widget['async_module_defer'] ?? true) !== false,
                );
            }
        }

        if (!\class_exists(\Weline\Theme\Service\ThemeComponentRenderer::class)) {
            return '';
        }

        return (string)ObjectManager::getInstance(\Weline\Theme\Service\ThemeComponentRenderer::class)
            ->render($definition, $config, $theme, $context);
    }

    /**
     * 异步片段缓存 TTL（秒）；0 = 不缓存。
     */
    public static function asyncCacheTtl(string $type, string $code): int
    {
        $widget = ObjectManager::getInstance(WidgetData::class)->getWidget(
            \trim($type) !== '' ? \trim($type) : 'content',
            \trim($code)
        );

        return \is_array($widget) ? \max(0, (int)($widget['async_cache'] ?? 0)) : 0;
    }

    /**
     * 部件是否声明异步：{@see @widget.async} 为 true 且 {@see @widget.async_module} 非空。
     * 半配置（只写 async 不写模块）按同步处理，避免首屏出空壳。
     *
     * @param array<string, mixed> $widget
     */
    public static function isAsyncWidget(array $widget): bool
    {
        return ($widget['async'] ?? false) === true
            && \trim((string)($widget['async_module'] ?? '')) !== '';
    }

    /**
     * 编辑器 / 主题预览 / 部件画布必须看到真实部件：此场景禁用异步占位，回退同步 SSR。
     */
    public static function asyncStubSuppressed(): bool
    {
        if (isset($_SERVER['WELINE_ASYNC_WIDGET_SSR']) && $_SERVER['WELINE_ASYNC_WIDGET_SSR'] === '1') {
            return true;
        }

        try {
            if (\class_exists(\Weline\Theme\Helper\PreviewManager::class)
                && \Weline\Theme\Helper\PreviewManager::isPreviewMode()
            ) {
                return true;
            }
        } catch (\Throwable) {
        }

        foreach (['editor_mode', 'preview_mode', 'weline_editor', 'shell'] as $key) {
            if (!isset($_GET[$key])) {
                continue;
            }
            $value = \strtolower(\trim((string)$_GET[$key]));
            if ($value !== '' && $value !== '0' && $value !== 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * 框架唯一异步占位结构（部件模块不得自定义）。
     *
     * @param array<string, mixed> $params
     */
    public static function renderAsyncStub(
        string $type,
        string $code,
        string $module,
        array $params,
        string $asyncModule,
        int $asyncCacheTtl,
        bool $defer,
        ?string $fragmentUrl = null,
    ): string {
        $config = [
            'type' => $type,
            'code' => $code,
            'module' => $module,
        ];
        if ($params !== []) {
            $config['params'] = $params;
        }

        $attributes = [
            'data-widget-async' => '1',
            'data-widget-code' => $code,
            'data-widget-type' => $type,
            'data-widget-module' => $module,
            'data-widget-async-module' => $asyncModule,
            'data-widget-async-defer' => $defer ? '1' : '0',
            'data-widget-async-cache' => (string)\max(0, $asyncCacheTtl),
            'data-widget-fragment' => $fragmentUrl !== null && $fragmentUrl !== ''
                ? $fragmentUrl
                : self::asyncFragmentUrl($type, $code),
            // 基类加载器随占位声明：idle 才加载，首屏关键路径不带它。
            'data-weline-load' => 'widgetAsync',
            'data-weline-load-when' => 'idle',
            'data-widget-config' => (string)\json_encode(
                $config,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
        ];

        $html = '<div';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . \htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $html . '></div>';
    }

    /**
     * 异步部件的样式随占位下发（同一请求内按 URL 去重，避免重复 <link>）。
     *
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $params
     */
    private static function asyncStyleTags(array $widget, array $params, string $templatePath): string
    {
        try {
            $assets = (string)ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\WidgetAssetRenderer::class
            )->render($widget, $params, $templatePath);
        } catch (\Throwable) {
            return '';
        }

        if ($assets === '' || !\preg_match_all('~<link\b[^>]*>~i', $assets, $matches)) {
            return '';
        }

        $tags = '';
        foreach ($matches[0] as $tag) {
            if (!\preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                continue;
            }
            $href = \html_entity_decode($hrefMatch[1], ENT_QUOTES, 'UTF-8');
            if ($href === '') {
                continue;
            }
            $tags .= $tag;
        }

        return $tags;
    }

    /**
     * 异步片段地址：发布代次作为缓存失效键（主题/部件重新发布即换 v）。
     */
    public static function asyncFragmentUrl(string $type, string $code): string
    {
        $query = [
            'type' => $type,
            'code' => $code,
        ];
        $version = self::storefrontVersionToken();
        if ($version !== '') {
            $query['v'] = $version;
        }

        return '/widget/frontend/async?' . \http_build_query($query);
    }

    private static function storefrontVersionToken(): string
    {
        try {
            if (\class_exists(\Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class)) {
                $resolved = ObjectManager::getInstance(
                    \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class
                )->resolve();
                $version = \trim((string)($resolved['themePublishedVersionId'] ?? ''));
                if ($version !== '') {
                    return $version;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * 片段端点专用：按同一套部件模板做 SSR（结构只维护一份，不写客户端模板）。
     *
     * @param array<string, mixed> $params
     */
    public static function renderAsyncFragment(string $type, string $code, array $params = []): string
    {
        /** @var WidgetData $widgetData */
        $widgetData = ObjectManager::getInstance(WidgetData::class);
        $widget = $widgetData->getWidget($type, $code);
        if (!$widget || !self::isAsyncWidget($widget)) {
            return '';
        }

        $widgetParams = \is_array($widget['params'] ?? null) ? $widget['params'] : [];
        foreach ($widgetParams as $paramName => $paramConfig) {
            if (!\array_key_exists($paramName, $params)) {
                $params[$paramName] = \is_array($paramConfig) ? ($paramConfig['default'] ?? null) : null;
            }
        }

        $previous = $_SERVER['WELINE_ASYNC_WIDGET_SSR'] ?? null;
        $_SERVER['WELINE_ASYNC_WIDGET_SSR'] = '1';
        try {
            return self::renderWidget($widget, $params, '', '');
        } finally {
            if ($previous === null) {
                unset($_SERVER['WELINE_ASYNC_WIDGET_SSR']);
            } else {
                $_SERVER['WELINE_ASYNC_WIDGET_SSR'] = $previous;
            }
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
            if ($e instanceof \Weline\Framework\Runtime\RequestExitException) {
                // 临时诊断：定位 RequestExit 在模板渲染期的真实抛出点（捕获点不含原栈）
                w_log_error(
                    "Widget 模板渲染错误: " . $e->getMessage()
                    . " origin=" . $e->getFile() . ':' . $e->getLine()
                    . ' trace=' . implode(' <- ', array_map(
                        static fn (array $f): string => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?')
                            . '#' . ($f['function'] ?? '?'),
                        array_slice($e->getTrace(), 0, 12)
                    )),
                    [],
                    'WidgetTaglib'
                );
            } else {
                w_log_error("Widget 模板渲染错误: " . $e->getMessage(), [], 'WidgetTaglib');
            }
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

    /**
     * @return array{
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
            . ' data-widget-source="template_inline"'
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
