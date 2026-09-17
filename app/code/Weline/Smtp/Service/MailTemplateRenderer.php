<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * 白名单 {{var.path}} 替换；默认 HTML escape；{{var.x|raw}} 显式不转义。
 * 支持非嵌套 {{#if var.path}}...{{/if}}（空字符串/null/false 视为假）。
 * 另：把 CKEditor 剥掉的邮件 CTA 内联样式补回（预览/发信一致）。
 */
class MailTemplateRenderer
{
    /**
     * @param array<string, mixed> $vars
     * @param list<string> $allowedKeys Provider 声明的变量 code（点路径第一段或完整 code）
     */
    public function render(string $template, array $vars, array $allowedKeys = []): string
    {
        $html = $this->normalizeEmailHtml($template);
        $html = $this->renderIfBlocks($html, $vars, $allowedKeys);

        return (string)preg_replace_callback(
            '/\{\{\s*var\.([A-Za-z][A-Za-z0-9_.]*?)(?:\|(raw))?\s*\}\}/',
            function (array $m) use ($vars, $allowedKeys): string {
                $path = $m[1];
                $raw = ($m[2] ?? '') === 'raw';
                if ($allowedKeys !== [] && !$this->isAllowed($path, $allowedKeys)) {
                    return '';
                }
                $value = $this->lookup($vars, $path);
                if ($value === null) {
                    return '';
                }
                $string = is_scalar($value) || $value instanceof \Stringable
                    ? (string)$value
                    : '';

                return $raw ? $string : htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $html
        );
    }

    /**
     * 非嵌套条件块：真值保留内文，假值整段去掉（避免 CTA 条件标签漏到收件正文）。
     *
     * @param array<string, mixed> $vars
     * @param list<string> $allowedKeys
     */
    private function renderIfBlocks(string $html, array $vars, array $allowedKeys): string
    {
        $out = (string)preg_replace_callback(
            '/\{\{\s*#if\s+var\.([A-Za-z][A-Za-z0-9_.]*?)\s*\}\}(.*?)\{\{\s*\/if\s*\}\}/s',
            function (array $m) use ($vars, $allowedKeys): string {
                $path = $m[1];
                $inner = $m[2];
                if ($allowedKeys !== [] && !$this->isAllowed($path, $allowedKeys)) {
                    return '';
                }
                $value = $this->lookup($vars, $path);

                return $this->isTruthy($value) ? $inner : '';
            },
            $html
        );

        // 未闭合/未知写法：剥掉残留标签，避免泄漏到邮件客户端
        $out = (string)preg_replace('/\{\{\s*#if\s+[^}]+\}\}/', '', $out);
        $out = (string)preg_replace('/\{\{\s*\/if\s*\}\}/', '', $out);

        return $out;
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }
        if (\is_string($value)) {
            return \trim($value) !== '';
        }
        if (\is_int($value) || \is_float($value)) {
            return (float)$value != 0.0;
        }
        if (\is_array($value)) {
            return $value !== [];
        }

        return (bool)$value;
    }

    /**
     * 邮件安全净化 + CTA 样式补偿（CKEditor 常把按钮收成裸 &lt;a&gt;）。
     */
    public function normalizeEmailHtml(string $html): string
    {
        $html = $this->stripScripts($html);
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // DOMDocument 会编码 href 中的 {{var.*}}；先占位再还原。
        $tokens = [];
        $protected = (string)preg_replace_callback(
            '/\{\{\s*[^}]+\}\}/',
            static function (array $m) use (&$tokens): string {
                $key = 'WELINEMAILTOK' . count($tokens) . 'X';
                $tokens[$key] = $m[0];

                return $key;
            },
            $html
        );

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $wrapped = '<?xml encoding="utf-8"><!DOCTYPE html><html><body><div id="weline-mail-normalize-root">'
                . $protected
                . '</div></body></html>';
            $dom->loadHTML($wrapped, LIBXML_NONET);
            $xpath = new \DOMXPath($dom);

            foreach ($xpath->query('//figure[contains(concat(" ", normalize-space(@class), " "), " table ")]') ?: [] as $figure) {
                if (!$figure instanceof \DOMElement || !$figure->parentNode) {
                    continue;
                }
                while ($figure->firstChild) {
                    $figure->parentNode->insertBefore($figure->firstChild, $figure);
                }
                $figure->parentNode->removeChild($figure);
            }

            foreach ($xpath->query('//td') ?: [] as $td) {
                if (!$td instanceof \DOMElement) {
                    continue;
                }
                $bg = $this->extractBackgroundColor($td);
                if ($bg === '') {
                    continue;
                }
                if ($td->getAttribute('bgcolor') === '') {
                    $td->setAttribute('bgcolor', $bg);
                }
                $tdStyle = trim($td->getAttribute('style'));
                if ($tdStyle === '' || !preg_match('/background(-color)?\s*:/i', $tdStyle)) {
                    $td->setAttribute(
                        'style',
                        trim($tdStyle . ($tdStyle !== '' ? ';' : '') . 'background:' . $bg . ';', ';') . ';'
                    );
                }
                foreach ($td->getElementsByTagName('a') as $anchor) {
                    if (!$anchor instanceof \DOMElement) {
                        continue;
                    }
                    $this->ensureCtaAnchorStyle($anchor, $bg);
                }
            }

            $root = $dom->getElementById('weline-mail-normalize-root');
            if (!$root instanceof \DOMElement) {
                return $html;
            }

            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            if ($out === '') {
                return $html;
            }
            foreach ($tokens as $key => $token) {
                $out = str_replace($key, $token, $out);
            }

            return $out;
        } catch (\Throwable) {
            return $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function stripScripts(string $html): string
    {
        $out = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $out = is_string($out) ? $out : $html;
        // 邮件协议：去掉内联事件与 javascript: URL
        $out = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is', '', $out) ?? $out;
        $out = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/is', '', $out) ?? $out;
        $out = preg_replace('/javascript\s*:/i', '', $out) ?? $out;

        return $out;
    }

    public function htmlToText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function extractBackgroundColor(\DOMElement $td): string
    {
        $bg = trim($td->getAttribute('bgcolor'));
        if ($bg !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bg)) {
            return strtolower(strlen($bg) === 4
                ? sprintf('#%s%s%s%s%s%s', $bg[1], $bg[1], $bg[2], $bg[2], $bg[3], $bg[3])
                : $bg);
        }
        $style = $td->getAttribute('style');
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,8})\b/i', $style, $m)) {
            $hex = strtolower($m[1]);
            if (strlen($hex) === 4) {
                $hex = sprintf('#%s%s%s%s%s%s', $hex[1], $hex[1], $hex[2], $hex[2], $hex[3], $hex[3]);
            }

            return $hex;
        }

        return '';
    }

    private function ensureCtaAnchorStyle(\DOMElement $anchor, string $bgHex): void
    {
        $map = $this->parseStyleMap($anchor->getAttribute('style'));
        $textColor = $this->contrastTextColor($bgHex);
        $map['display'] = $map['display'] ?? 'inline-block';
        $map['padding'] = $map['padding'] ?? '14px 26px';
        $map['font-family'] = $map['font-family'] ?? 'Arial,Helvetica,sans-serif';
        $map['font-size'] = $map['font-size'] ?? '15px';
        $map['font-weight'] = $map['font-weight'] ?? '700';
        $map['color'] = $textColor . ' !important';
        $map['text-decoration'] = 'none !important';
        $map['border'] = $map['border'] ?? '0';
        // 部分客户端对 a 的默认下划线更顽固；再显式关掉 text-decoration-line
        $map['text-decoration-line'] = 'none';
        $anchor->setAttribute('style', $this->styleMapToString($map));
    }

    /** @return array<string, string> */
    private function parseStyleMap(string $style): array
    {
        $map = [];
        foreach (explode(';', $style) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $part, 2));
            if ($k !== '' && $v !== '') {
                $map[strtolower($k)] = $v;
            }
        }

        return $map;
    }

    /** @param array<string, string> $map */
    private function styleMapToString(array $map): string
    {
        $parts = [];
        foreach ($map as $k => $v) {
            $parts[] = $k . ':' . $v;
        }

        return implode(';', $parts);
    }

    private function contrastTextColor(string $bgHex): string
    {
        $hex = ltrim($bgHex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '#16333f';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // 相对亮度：浅底用墨色字，深底用浅色字（对齐邮件按钮可读性）
        $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luma > 0.55 ? '#16333f' : '#f7f4ef';
    }

    /** @param list<string> $allowedKeys */
    private function isAllowed(string $path, array $allowedKeys): bool
    {
        if (in_array($path, $allowedKeys, true)) {
            return true;
        }
        $root = explode('.', $path, 2)[0];

        return in_array($root, $allowedKeys, true);
    }

    /** @param array<string, mixed> $vars */
    private function lookup(array $vars, string $path): mixed
    {
        if (array_key_exists($path, $vars)) {
            return $vars[$path];
        }
        $cursor = $vars;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
