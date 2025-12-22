<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Taglib\TaglibInterface;
use Weline\Widget\Service\WidgetScanner;

/**
 * w:widget 标签实现
 * 
 * 用于在模板中渲染部件
 * 
 * 使用示例：
 * <w:widget type="header" name="default" />
 * <w:widget type="header" name="default" params='{"title":"我的网站"}' />
 */
class Widget implements TaglibInterface
{
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
            'type' => true,          // 部件类型（必需）
            'name' => true,          // 部件名称（必需）
            'params' => false,       // 部件参数，JSON 格式（可选）
            'block-class' => false,  // 覆盖 Block 类（可选）
            'template' => false,     // 覆盖模板路径（可选）
            'id' => false            // 部件实例 ID（可选）
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

            // 获取必需属性
            $type = $attributes['type'] ?? '';
            $name = $attributes['name'] ?? '';

            if (empty($type) || empty($name)) {
                return '<!-- Widget 错误: type 和 name 属性是必需的 -->';
            }

            // 获取可选属性
            $paramsJson = $attributes['params'] ?? '{}';
            $blockClass = $attributes['block-class'] ?? $attributes['blockClass'] ?? '';
            $template = $attributes['template'] ?? '';
            $widgetId = $attributes['id'] ?? '';

            // 解析参数
            $params = [];
            if (!empty($paramsJson)) {
                $decoded = json_decode($paramsJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $params = $decoded;
                }
            }

            try {
                // 扫描部件配置
                /** @var WidgetScanner $scanner */
                $scanner = ObjectManager::getInstance(WidgetScanner::class);
                $widget = $scanner->scanWidget($type, $name);

                if (!$widget) {
                    return "<!-- Widget 错误: 未找到部件 {$type}/{$name} -->";
                }

                // 验证部件类型
                if (!$scanner->isValidType($type)) {
                    return "<!-- Widget 错误: 无效的部件类型 {$type} -->";
                }

                // 合并默认参数
                $widgetParams = $widget['params'] ?? [];
                foreach ($widgetParams as $paramName => $paramConfig) {
                    if (!isset($params[$paramName])) {
                        $params[$paramName] = $paramConfig['default'] ?? null;
                    }
                }

                // 渲染部件
                $html = self::renderWidget($widget, $params, $blockClass, $template);

                // 如果有 ID，包裹容器（用于编辑模式）
                if (!empty($widgetId)) {
                    $html = self::wrapWidgetContainer($html, $widgetId, $type, $name, $params);
                }

                return $html;
            } catch (\Exception $e) {
                error_log("Widget 标签渲染错误: " . $e->getMessage());
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
            return self::renderTemplate($template, $params);
        }

        // 使用部件配置中的模板
        $widgetTemplate = $widget['template'] ?? '';
        if (!empty($widgetTemplate)) {
            return self::renderTemplate($widgetTemplate, $params);
        }

        // 尝试查找默认模板
        $widgetPath = $widget['path'] ?? '';
        if (!empty($widgetPath)) {
            $defaultTemplate = $widgetPath . DIRECTORY_SEPARATOR . 'template.phtml';
            if (file_exists($defaultTemplate)) {
                // 构建模板路径
                $module = $widget['module'] ?? '';
                $type = $widget['type'] ?? '';
                $name = $widget['widget_name'] ?? '';
                $templatePath = $module . '::widgets/' . $type . '/' . $name . '.phtml';
                return self::renderTemplate($templatePath, $params);
            }
        }

        return '<!-- Widget 错误: 未找到模板或 Block 类 -->';
    }

    /**
     * 渲染 Block 类
     *
     * @param string $blockClass Block 类名
     * @param array $params 参数
     * @return string
     */
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
        } catch (\Exception $e) {
            error_log("Widget Block 渲染错误: " . $e->getMessage());
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
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            // 传递参数到模板
            foreach ($params as $key => $value) {
                $template->assign($key, $value);
            }

            // 渲染模板
            $result = $template->fetch($templatePath);
            return is_string($result) ? $result : '';
        } catch (\Exception $e) {
            error_log("Widget 模板渲染错误: " . $e->getMessage());
            return '<!-- Widget 错误: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
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

