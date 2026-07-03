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
        private readonly WidgetRegistry $widgetRegistry,
        private readonly ?WidgetRuntimeTemplateRenderer $runtimeTemplateRenderer = null,
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
        $widget = $this->findWidgetByModuleAndCode($widgetModule, $widgetCode, $area);
        if ($widget === null) {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars($widgetCode) . '</div>';
        }
        $finalConfig = $this->mergeWithParamDefaults($widget, $config);
        $finalConfig['preview_mode'] = true;
        $templateContent = (string)($widget['template_content'] ?? '');
        if ($templateContent !== '') {
            try {
                $renderer = $this->runtimeTemplateRenderer ?? ObjectManager::getInstance(WidgetRuntimeTemplateRenderer::class);
                return $this->sanitizePreviewHtml($renderer->renderContent($templateContent, $finalConfig));
            } catch (\Throwable $e) {
                return '<div class="widget-preview-error">' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }

        $template = $widget['template'] ?? '';
        if ($template === '') {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars((string)($widget['name'] ?? $widgetCode)) . '</div>';
        }
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

    private function findWidgetByModuleAndCode(string $widgetModule, string $widgetCode, string $area): ?array
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
                    $widgetArea = (string)($widget['area'] ?? 'frontend');
                    if ($widgetArea !== '' && $widgetArea !== $area) {
                        continue;
                    }
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
     * 预览用 HTML 清理：deny-by-default，仅保留安全展示所需标签与属性。
     */
    public function sanitizePreviewHtml(string $html): string
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

            $allowedTags = [
                'a', 'abbr', 'article', 'aside', 'audio', 'b', 'blockquote', 'br', 'button', 'caption',
                'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt',
                'em', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'header', 'hr', 'i', 'img', 'input', 'ins', 'label', 'legend', 'li', 'main', 'mark',
                'nav', 'ol', 'option', 'p', 'picture', 'pre', 'progress', 's', 'section', 'select',
                'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td',
                'textarea', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul', 'video',
            ];
            $allowedAttr = [
                'abbr', 'accept', 'action', 'alt', 'aria-label', 'aria-labelledby', 'aria-describedby',
                'aria-hidden', 'aria-expanded', 'aria-controls', 'autocomplete', 'checked', 'class',
                'cols', 'colspan', 'controls', 'datetime', 'dir', 'disabled', 'for', 'height', 'hidden',
                'href', 'id', 'label', 'loading', 'max', 'maxlength', 'method', 'min', 'multiple', 'name',
                'pattern', 'placeholder', 'poster', 'readonly', 'rel', 'required', 'role', 'rows',
                'rowspan', 'selected', 'sizes', 'src', 'style', 'target', 'title', 'type', 'value', 'width',
            ];
            $uriAttr = ['action', 'href', 'poster', 'src'];
            $allowedInputTypes = [
                'button', 'checkbox', 'color', 'date', 'datetime-local', 'email', 'hidden', 'month',
                'number', 'password', 'radio', 'range', 'reset', 'search', 'submit', 'tel', 'text',
                'time', 'url', 'week',
            ];

            foreach ($xpath->query('//body//*') as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($node->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }
            }

            foreach ($xpath->query('//body//*[@*]') as $node) {
                if (!$node instanceof \DOMElement || !$node->hasAttributes()) {
                    continue;
                }
                $toRemove = [];
                foreach ($node->attributes as $attr) {
                    $name = strtolower($attr->name);
                    if (
                        str_starts_with($name, 'on')
                        || $name === 'srcdoc'
                        || (!in_array($name, $allowedAttr, true) && !str_starts_with($name, 'data-'))
                    ) {
                        $toRemove[] = $attr->name;
                        continue;
                    }
                    if (in_array($name, $uriAttr, true) && !$this->isSafePreviewUrl((string)$attr->value)) {
                        $toRemove[] = $attr->name;
                        continue;
                    }
                    if ($name === 'target' && !in_array($attr->value, ['_blank', '_self', '_parent', '_top'], true)) {
                        $toRemove[] = $attr->name;
                        continue;
                    }
                    if ($name === 'type' && strtolower($node->tagName) === 'input' && !in_array(strtolower($attr->value), $allowedInputTypes, true)) {
                        $node->setAttribute('type', 'text');
                    }
                    if ($name === 'rel' && strtolower($node->tagName) === 'a') {
                        $node->setAttribute('rel', 'noopener noreferrer');
                    }
                    if ($name === 'style' && !$this->isSafePreviewCss((string)$attr->value)) {
                        $toRemove[] = $attr->name;
                        continue;
                    }
                }
                foreach ($toRemove as $name) {
                    $node->removeAttribute($name);
                }
                if (strtolower($node->tagName) === 'a' && $node->getAttribute('target') === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
            foreach ($xpath->query('//body//style') as $node) {
                if (!$this->isSafePreviewCss((string)$node->textContent)) {
                    $node->parentNode?->removeChild($node);
                }
            }
            $body = $xpath->query('//body')->item(0);
            if (!$body) {
                return '';
            }
            $result = $doc->saveHTML($body);
            $result = preg_replace('#^<body>|</body>$#', '', (string)$result);
            return $result !== null ? $result : '';
        } catch (\Throwable $e) {
            return '';
        } finally {
            libxml_use_internal_errors($prev);
        }
    }

    private function isSafePreviewUrl(string $url): bool
    {
        $value = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }
        if (str_starts_with($value, '#') || str_starts_with($value, '/') || str_starts_with($value, './') || str_starts_with($value, '../')) {
            return true;
        }
        if (str_starts_with($value, '//')) {
            return true;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false || $scheme === '') {
            return true;
        }
        return in_array(strtolower((string)$scheme), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function isSafePreviewCss(string $css): bool
    {
        $css = trim($css);
        if ($css === '') {
            return true;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css) === 1) {
            return false;
        }

        return preg_match('/@import\b|expression\s*\(|behavior\s*:|-moz-binding\s*:|url\s*\(\s*[\'"]?\s*(?:javascript|vbscript):|url\s*\(\s*[\'"]?\s*data:(?!image\/(?:png|gif|jpe?g|webp|bmp|svg\+xml);base64,)/i', $css) !== 1;
    }
}
