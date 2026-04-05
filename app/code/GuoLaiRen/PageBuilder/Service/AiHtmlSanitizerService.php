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
        $html = \strip_tags($html, self::STRICT_ALLOWED_TAGS);
        $html = \preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = \preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = \preg_replace('/\sjavascript\s*:/i', '', $html) ?? $html;

        return \trim($html);
    }

    /**
     * @param array<string, mixed> $layout 须含 blocks:list
     * @return array<string, mixed>
     */
    public function sanitizeAiLayout(array $layout): array
    {
        $blocks = $layout['blocks'] ?? [];
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

        return ['blocks' => $out];
    }
}
