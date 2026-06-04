<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * 发布前对 AI 区块 HTML 做严格白名单消毒（编辑/预览可更宽，由调用方区分）
 */
final class AiHtmlSanitizerService
{
    private const STRICT_ALLOWED_TAGS = '<p><br><hr><strong><b><em><i><u><a><img><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><span><div><section><article><header><footer><nav><main><table><tr><td><th><tbody><thead><tfoot><figure><figcaption><svg><path><g><use>';

    public function sanitizeBlockHtml(string $html): string
    {
        $html = $this->removeDangerousElementBlocks($html);
        $styleBlocks = [];
        $html = (string)\preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            function (array $matches) use (&$styleBlocks): string {
                $css = $this->sanitizeCssBlock((string)($matches[1] ?? ''));
                if ($css === '') {
                    return '';
                }
                $key = '___PB_AI_STYLE_BLOCK_' . \count($styleBlocks) . '___';
                $styleBlocks[$key] = '<style>' . $css . '</style>';
                return $key;
            },
            $html
        );
        $html = \strip_tags($html, self::STRICT_ALLOWED_TAGS);
        $html = \preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = \preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = \preg_replace('/\sjavascript\s*:/i', '', $html) ?? $html;
        if ($styleBlocks !== []) {
            $html = \strtr($html, $styleBlocks);
        }

        return \trim($html);
    }

    private function removeDangerousElementBlocks(string $html): string
    {
        return \preg_replace(
            '/<(script|iframe|object|embed|link|meta|base)\b[^>]*>.*?<\/\1>/is',
            '',
            $html
        ) ?? $html;
    }

    private function sanitizeCssBlock(string $css): string
    {
        $css = \str_replace(["\0", '</style', '<', '>'], ['', '', '', ''], $css);
        $css = \preg_replace('/@import\b[^;]*(;|$)/i', '', $css) ?? $css;
        $css = \preg_replace('/expression\s*\([^)]*\)/i', '', $css) ?? $css;
        $css = \preg_replace('/javascript\s*:/i', '', $css) ?? $css;
        $css = \preg_replace('/\bbehavior\s*:\s*[^;]+;?/i', '', $css) ?? $css;
        $css = \preg_replace('/-moz-binding\s*:\s*[^;]+;?/i', '', $css) ?? $css;

        return \trim($css);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function sanitizeAiLayout(array $layout): array
    {
        $blocks = $layout['block_nodes'] ?? [];
        if (!\is_array($blocks)) {
            $blocks = [];
        }
        $out = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $bid = \trim((string)($block['block_id'] ?? ''));
            if ($bid === '') {
                $bid = 'blk_' . \bin2hex(\random_bytes(4));
            }
            $out[] = [
                'block_id' => $bid,
                'type' => \trim((string)($block['type'] ?? 'section')),
                'html' => $this->sanitizeBlockHtml((string)($block['html'] ?? '')),
            ];
        }

        return ['block_nodes' => $out];
    }
}
