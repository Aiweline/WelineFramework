<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * DEV / 预览态部件 HTML 健康检测。
 *
 * 对部件原始 HTML 字符串做标签栈平衡、常见空壳、以及 PHP 运行时错误捞取。
 * 浏览器会“修好”坏 HTML，因此必须在服务端对原始片段检查。
 * 仅由 SlotRendererService::shouldInspectWidgetHtml()（DEV / 预览）调用。
 */
final class WidgetHtmlHealthInspector
{
    /**
     * Purchase CTAs may intentionally render nothing (quote_only / unsellable).
     * Empty HTML is not a health fault for these widget codes.
     *
     * @var array<string, true>
     */
    private const OPTIONAL_EMPTY_WIDGET_CODES = [
        'product-add-to-cart' => true,
        'product-buy-now' => true,
        'product-card-buy-now' => true,
    ];

    /** @var list<array{pattern:string,severity:string,code:string}> */
    private const PHP_ERROR_MATCHERS = [
        [
            'pattern' => '/(?:<br\s*\/?>\s*)?(?:<b>)?(Fatal error|Parse error)(?:<\/b>)?:\s*(.+?)(?:\s+in\s+(?:<b>)?(.+?)(?:<\/b>)?\s+on\s+line\s+(?:<b>)?(\d+)(?:<\/b>)?)?(?:<br\s*\/?>)?/is',
            'severity' => 'error',
            'code' => 'php_fatal',
        ],
        [
            'pattern' => '/Uncaught\s+([A-Za-z0-9_\\\\]+(?:Error|Exception))\s*:\s*(.+?)(?:\s+in\s+(\S+)\s+on\s+line\s+(\d+))?/i',
            'severity' => 'error',
            'code' => 'php_uncaught',
        ],
        [
            'pattern' => '/WLS\s+Runtime\s+Error[:\s]+(.+)/i',
            'severity' => 'error',
            'code' => 'php_wls_runtime',
        ],
        [
            'pattern' => '/(?:<br\s*\/?>\s*)?(?:<b>)?(Warning|Notice|Deprecated|Strict Standards)(?:<\/b>)?:\s*(.+?)(?:\s+in\s+(?:<b>)?(.+?)(?:<\/b>)?\s+on\s+line\s+(?:<b>)?(\d+)(?:<\/b>)?)?(?:<br\s*\/?>)?/is',
            'severity' => 'warning',
            'code' => 'php_warning',
        ],
    ];

    private const VOID_TAGS = [
        'area' => true,
        'base' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
        'command' => true,
        'keygen' => true,
        'menuitem' => true,
    ];

    private const RAW_TEXT_TAGS = [
        'script' => true,
        'style' => true,
        'textarea' => true,
        'title' => true,
        'xmp' => true,
        'iframe' => true,
        'noembed' => true,
        'noframes' => true,
        'noscript' => true,
    ];

    /**
     * @param array{module?:string,code?:string,type?:string,slot_id?:string,layout_id?:string} $meta
     * @return list<array{severity:string,code:string,message:string,detail?:string}>
     */
    public function inspect(string $html, array $meta = []): array
    {
        if (trim($html) === '') {
            if ($this->allowsEmptyHtml($meta)) {
                return [];
            }

            return [[
                'severity' => 'warning',
                'code' => 'empty_html',
                'message' => (string)__('部件 HTML 为空'),
            ]];
        }

        $issues = [];
        foreach ($this->inspectPhpErrors($html) as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->inspectTagBalance($html) as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->inspectEmptyShells($html) as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->inspectDuplicateIds($html) as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->inspectMissingClosedRoot($html, $meta) as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    public function worstSeverity(array $issues): string
    {
        $worst = 'ok';
        foreach ($issues as $issue) {
            $severity = (string)($issue['severity'] ?? 'info');
            if ($severity === 'error') {
                return 'error';
            }
            if ($severity === 'warning' && $worst !== 'error') {
                $worst = 'warning';
            } elseif ($severity === 'info' && $worst === 'ok') {
                $worst = 'info';
            }
        }

        return $worst;
    }

    /**
     * @param array{module?:string,code?:string,type?:string,slot_id?:string,layout_id?:string} $meta
     */
    private function allowsEmptyHtml(array $meta): bool
    {
        $code = strtolower(trim((string)($meta['code'] ?? '')));

        return $code !== '' && isset(self::OPTIONAL_EMPTY_WIDGET_CODES[$code]);
    }

    /**
     * @return list<array{severity:string,code:string,message:string,detail?:string}>
     */
    private function inspectTagBalance(string $html): array
    {
        $issues = [];
        $stack = [];
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $lt = strpos($html, '<', $offset);
            if ($lt === false) {
                break;
            }

            // skip comments / doctype / cdata
            if (substr($html, $lt, 4) === '<!--') {
                $end = strpos($html, '-->', $lt + 4);
                $offset = $end === false ? $length : $end + 3;
                continue;
            }
            if (preg_match('/<!(?:DOCTYPE|doctype)\b/A', $html, $m, 0, $lt) === 1) {
                $gt = strpos($html, '>', $lt + 2);
                $offset = $gt === false ? $length : $gt + 1;
                continue;
            }

            if ($html[$lt + 1] === '/') {
                if (preg_match('/<\/([a-zA-Z][\w:-]*)\s*>/A', $html, $m, 0, $lt) !== 1) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'malformed_close_tag',
                        'message' => (string)__('关闭标签格式错误'),
                        'detail' => $this->snippet($html, $lt),
                    ];
                    break;
                }
                $name = strtolower($m[1]);
                $offset = $lt + strlen($m[0]);
                // libxml/saveHTML may emit </source>/</img> for void elements; never
                // let those closes pop real containers (picture/section/div cascade).
                if (isset(self::VOID_TAGS[$name])) {
                    continue;
                }
                if ($stack === []) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'unexpected_close',
                        'message' => (string)__('多余的关闭标签: %{1}', $name),
                        'detail' => $this->snippet($html, $lt),
                    ];
                    continue;
                }
                $open = array_pop($stack);
                if ($open['name'] !== $name) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'tag_mismatch',
                        'message' => (string)__('标签不匹配: 期望 </%{1}>，实际 </%{2}>', [$open['name'], $name]),
                        'detail' => $this->snippet($html, $lt),
                    ];
                    // keep scanning with remaining stack
                }
                continue;
            }

            $openTagEnd = $this->findHtmlTagClose($html, $lt + 1);
            if ($openTagEnd === null) {
                break;
            }

            $openTag = substr($html, $lt, $openTagEnd - $lt);
            if (preg_match('/^<([a-zA-Z][\w:-]*)/', $openTag, $m) !== 1) {
                $offset = $lt + 1;
                continue;
            }

            $name = strtolower($m[1]);
            $selfClosing = str_ends_with(rtrim($openTag), '/>')
                || preg_match('/\/\s*>$/', $openTag) === 1
                || isset(self::VOID_TAGS[$name]);
            $offset = $openTagEnd;

            if ($selfClosing) {
                continue;
            }

            $stack[] = ['name' => $name, 'pos' => $lt];

            if (isset(self::RAW_TEXT_TAGS[$name])) {
                $close = stripos($html, '</' . $name . '>', $offset);
                if ($close === false) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'unclosed_raw_tag',
                        'message' => (string)__('未闭合的原始文本标签: <%{1}>', $name),
                        'detail' => $this->snippet($html, $lt),
                    ];
                    array_pop($stack);
                    break;
                }
                $offset = $close + strlen('</' . $name . '>');
                array_pop($stack);
            }
        }

        foreach ($stack as $open) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'unclosed_tag',
                'message' => (string)__('未闭合标签: <%{1}>', $open['name']),
                'detail' => $this->snippet($html, (int)$open['pos']),
            ];
        }

        return $issues;
    }

    /**
     * @return list<array{severity:string,code:string,message:string,detail?:string}>
     */
    private function inspectEmptyShells(string $html): array
    {
        $issues = [];

        if (preg_match('/<a\b[^>]*\bclass=(["\'])[^"\']*\bbrand-item\b[^"\']*\1[^>]*>\s*<\/a>/is', $html) === 1) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'empty_brand_item',
                'message' => (string)__('品牌项为空壳（无 logo / 文案）'),
            ];
        }

        if (preg_match('/<a\b[^>]*>\s*<\/a>/is', $html) === 1) {
            $issues[] = [
                'severity' => 'info',
                'code' => 'empty_anchor',
                'message' => (string)__('存在空的 <a> 标签'),
            ];
        }

        // hero slide title equals common page/theme title pollution
        if (preg_match('/class=(["\'])[^"\']*\bslide-title\b[^"\']*\1[^>]*>\s*Weline_Theme\s*</i', $html) === 1) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'page_title_leak',
                'message' => (string)__('轮播标题疑似泄漏页面 title（Weline_Theme）'),
            ];
        }

        return $issues;
    }

    /**
     * @return list<array{severity:string,code:string,message:string,detail?:string}>
     */
    private function inspectDuplicateIds(string $html): array
    {
        // Do not use \bid= — word-boundary still matches the trailing "id=" in
        // data-form-id / aria-labelledby-style compound attributes (false ×2).
        if (preg_match_all('/(?<![\w:-])id=(["\'])([^"\']+)\1/i', $html, $matches) < 1) {
            return [];
        }

        $counts = [];
        foreach ($matches[2] as $id) {
            $id = trim((string)$id);
            // numeric SVG path ids are noisy; skip empty / pure digits
            if ($id === '' || ctype_digit($id)) {
                continue;
            }
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        $dupes = [];
        foreach ($counts as $id => $count) {
            if ($count > 1) {
                $dupes[] = $id . '×' . $count;
            }
        }
        if ($dupes === []) {
            return [];
        }

        return [[
            'severity' => 'warning',
            'code' => 'duplicate_id',
            'message' => (string)__('部件内重复 id: %{1}', implode(', ', array_slice($dupes, 0, 5))),
        ]];
    }

    /**
     * 捞取部件输出中的 PHP / WLS 运行时错误文案（display_errors / 未捕获异常）。
     *
     * @return list<array{severity:string,code:string,message:string,detail?:string}>
     */
    private function inspectPhpErrors(string $html): array
    {
        if ($html === '' || (!str_contains($html, 'error')
            && !str_contains($html, 'Warning')
            && !str_contains($html, 'Notice')
            && !str_contains($html, 'Deprecated')
            && !str_contains($html, 'Uncaught')
            && !str_contains($html, 'WLS')
            && !str_contains($html, 'Parse'))
        ) {
            return [];
        }

        $issues = [];
        $seen = [];
        foreach (self::PHP_ERROR_MATCHERS as $matcher) {
            if (preg_match_all($matcher['pattern'], $html, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $match) {
                $label = match ($matcher['code']) {
                    'php_wls_runtime' => 'WLS Runtime Error',
                    'php_uncaught' => 'Uncaught ' . trim((string)($match[1] ?? 'Error')),
                    default => trim((string)($match[1] ?? 'PHP')),
                };
                $body = trim(html_entity_decode(strip_tags((string)($match[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($body === '' && $matcher['code'] === 'php_wls_runtime') {
                    $body = trim(html_entity_decode(strip_tags((string)($match[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($body === '' && isset($match[0])) {
                    $body = trim(html_entity_decode(strip_tags((string)$match[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($body === '') {
                    continue;
                }
                $file = trim((string)($match[3] ?? ''));
                $line = trim((string)($match[4] ?? ''));
                $detail = $body;
                if ($file !== '') {
                    $detail .= ' @ ' . basename(str_replace('\\', '/', $file));
                    if ($line !== '') {
                        $detail .= ':' . $line;
                    }
                }
                $fingerprint = strtolower($matcher['code'] . '|' . $detail);
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                $issues[] = [
                    'severity' => $matcher['severity'],
                    'code' => $matcher['code'],
                    'message' => (string)__('部件输出含 PHP 错误: %{1}', $label),
                    'detail' => $this->truncateDetail($detail),
                ];
                if (count($issues) >= 5) {
                    return $issues;
                }
            }
        }

        return $issues;
    }

    /**
     * @param array{code?:string} $meta
     * @return list<array{severity:string,code:string,message:string,detail?:string}>
     */
    private function inspectMissingClosedRoot(string $html, array $meta): array
    {
        $code = trim((string)($meta['code'] ?? ''));
        if ($code === '') {
            return [];
        }

        $expected = 'wc-theme_widget_' . str_replace(['-', '/'], '_', strtolower($code));
        if (stripos($html, $expected) !== false) {
            return [];
        }

        // some widgets intentionally use alternate roots (header-cart etc.)
        if (preg_match('/class=(["\'])[^"\']*\bwc-theme_widget_/i', $html) === 1) {
            return [];
        }

        // WidgetUiScope::forWidget slugifies '-' to '_' in weline-code.
        $slug = strtolower(str_replace(['-', '/'], '_', $code));
        $expectedWelineCode = 'theme.widget.' . $slug;
        $hasOwnWidgetCode = stripos($html, 'weline-code="' . $expectedWelineCode . '"') !== false
            || stripos($html, "weline-code='" . $expectedWelineCode . "'") !== false;

        if ($hasOwnWidgetCode) {
            return [[
                'severity' => 'info',
                'code' => 'missing_closed_root',
                'message' => (string)__('未检测到闭包根类 .%{1}', $expected),
            ]];
        }

        // DOM 拆散：仅剩 component 级 weline-code（如 product_label），无部件自身根。
        if (str_contains($html, 'weline-code=') && trim(strip_tags($html)) !== '') {
            return [[
                'severity' => 'warning',
                'code' => 'broken_widget_shell',
                'message' => (string)__('部件根结构疑似被拆散（缺少 .%{1}）', $expected),
            ]];
        }

        return [];
    }

    private function truncateDetail(string $detail, int $max = 180): string
    {
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;
        $detail = trim($detail);
        if (strlen($detail) <= $max) {
            return $detail;
        }

        return substr($detail, 0, $max - 1) . '…';
    }

    private function findHtmlTagClose(string $html, int $from): ?int
    {
        $length = strlen($html);
        $quote = null;
        for ($i = max(0, $from); $i < $length; $i++) {
            $ch = $html[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    private function snippet(string $html, int $pos, int $radius = 48): string
    {
        $start = max(0, $pos - $radius);
        $chunk = substr($html, $start, $radius * 2);
        $chunk = preg_replace('/\s+/', ' ', $chunk) ?? $chunk;

        return trim($chunk);
    }
}
