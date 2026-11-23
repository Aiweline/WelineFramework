<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

/**
 * 从响应 HTML 提取健康判定需要的信号：标题、正文摘要、meta robots、canonical。
 *
 * 只做防御式解析，不引入 DOM 依赖，避免坏 HTML 抛异常。
 */
final class HtmlSignals
{
    /**
     * @return array{title:string, text:string, noindex:bool, canonical:string, canonical_mismatch:bool}
     */
    public static function extract(string $html, string $finalUrl = ''): array
    {
        $html = (string)$html;
        $title = '';
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)) {
            $title = self::plainText($match[1]);
        }

        $noindex = false;
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                if (!preg_match('/\bname\s*=\s*["\']?robots["\']?/i', $tag)) {
                    continue;
                }
                if (preg_match('/\bcontent\s*=\s*["\']?([^"\'>]+)/i', $tag, $contentMatch)) {
                    if (preg_match('/\bnoindex\b/i', $contentMatch[1])) {
                        $noindex = true;
                    }
                }
            }
        }

        $canonical = '';
        if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']?canonical["\']?[^>]*>/i', $html, $linkMatch)) {
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $linkMatch[0], $hrefMatch)) {
                $canonical = trim($hrefMatch[1]);
            }
        }

        $text = self::plainText($html);
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 4000);
        }

        $canonicalMismatch = false;
        if ($canonical !== '' && $finalUrl !== '') {
            $canonicalMismatch = UrlHealthKey::normalize($canonical) !== UrlHealthKey::normalize($finalUrl);
        }

        return [
            'title' => $title,
            'text' => $text,
            'noindex' => $noindex,
            'canonical' => $canonical,
            'canonical_mismatch' => $canonicalMismatch,
        ];
    }

    public static function plainText(string $html): string
    {
        $text = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
