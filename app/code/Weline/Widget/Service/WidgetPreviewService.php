<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * 部件预览服务：按 template + config 渲染部件 HTML
 */
class WidgetPreviewService
{
    public function __construct(
        private readonly WidgetRegistry $widgetRegistry
    ) {
    }

    /**
     * 渲染部件预览 HTML
     *
     * @param string $widgetModule 部件模块
     * @param string $widgetCode 部件代码
     * @param array $config 配置键值
     * @param string $area 区域（可选，用于未来扩展）
     * @return string HTML
     */
    public function render(string $widgetModule, string $widgetCode, array $config = [], string $area = 'frontend'): string
    {
        $widget = $this->findWidgetByModuleAndCode($widgetModule, $widgetCode);
        if ($widget === null) {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars($widgetCode) . '</div>';
        }
        $template = $widget['template'] ?? '';
        if ($template === '') {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widget['name'] ?? $widgetCode)) . '</div>';
        }
        $finalConfig = $this->mergeWithParamDefaults($widget, $config);
        $finalConfig['preview_mode'] = true;
        try {
            /** @var Template $templateObj */
            $templateObj = ObjectManager::getInstance(Template::class);
            // WLS 下 Template 单例 _data 会跨请求残留，渲染前清空，避免上一请求（如社媒）的数据污染当前部件预览
            $templateObj->unsetData();
            $html = $templateObj->fetchHtml($template, $finalConfig);
            return $this->sanitizePreviewHtml(is_string($html) ? $html : '');
        } catch (\Throwable $e) {
            return '<div class="widget-preview-error">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    private function findWidgetByModuleAndCode(string $widgetModule, string $widgetCode): ?array
    {
        $registry = $this->widgetRegistry->getRegistry();
        foreach ($registry as $type => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $code => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (($widget['module'] ?? '') === $widgetModule && ($widget['code'] ?? '') === $widgetCode) {
                    return $widget;
                }
            }
        }
        return null;
    }

    private function mergeWithParamDefaults(array $widget, array $config): array
    {
        $final = $config;
        foreach ($widget['params'] ?? [] as $key => $param) {
            if (!is_array($param)) {
                continue;
            }
            if (isset($final[$key]) && $final[$key] !== '' && $final[$key] !== null) {
                continue;
            }
            $default = $param['default'] ?? '';
            if ($key === 'end_date' || $key === 'countdown_end') {
                if ($default === '' || $default === null) {
                    $default = date('Y-m-d H:i:s', time() + 86400);
                }
            }
            if ($default !== '' && $default !== null) {
                $final[$key] = $default;
            }
        }
        return $final;
    }

    /**
     * 预览用 HTML 清理：移除 script/iframe 与内联事件
     */
    private function sanitizePreviewHtml(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
            if (function_exists('mb_encode_numericentity')) {
                $wrapped = mb_encode_numericentity($wrapped, [0x80, 0x10FFFF, 0, 0xFFFF], 'UTF-8');
            }
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($doc);
            foreach ($xpath->query('//script') as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//iframe') as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//*[@*]') as $node) {
                if (!$node instanceof \DOMElement || !$node->hasAttributes()) {
                    continue;
                }
                $toRemove = [];
                foreach ($node->attributes as $attr) {
                    if (stripos($attr->name, 'on') === 0) {
                        $toRemove[] = $attr->name;
                    }
                }
                foreach ($toRemove as $name) {
                    $node->removeAttribute($name);
                }
            }
            $body = $xpath->query('//body')->item(0);
            $result = $body ? $doc->saveHTML($body) : $html;
            $result = preg_replace('#^<body>|</body>$#', '', (string)$result);
            return $result !== null ? $result : $html;
        } catch (\Throwable $e) {
            return $html;
        } finally {
            libxml_use_internal_errors($prev);
        }
    }
}
