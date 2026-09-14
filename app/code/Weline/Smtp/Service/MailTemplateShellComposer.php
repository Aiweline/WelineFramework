<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * 固定邮件页头/页尾壳：业务模板只提供正文片段，发信时组装。
 * 协议：table + 内联样式；禁止 JS。
 */
class MailTemplateShellComposer
{
    public const MARKER = 'data-weline-mail-shell';

    public function isAlreadyWrapped(string $html): bool
    {
        return str_contains($html, self::MARKER . '=')
            || str_contains($html, self::MARKER . '="1"');
    }

    public function looksLikeFullDocument(string $html): bool
    {
        return (bool)preg_match('/<!DOCTYPE\s+html|<html[\s>]/i', $html);
    }

    /**
     * 从历史完整 HTML 中尽量抽出正文（去掉固定壳），便于 Seeder/迁移。
     */
    public function extractBodyFragment(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // 已是片段：不含完整壳特征
        if (!$this->looksLikeFullDocument($html) && !$this->looksLikeEmbeddedShell($html)) {
            return $this->normalizeFragment($html);
        }

        // 优先：琥珀条之后、信任脚之前
        if (preg_match(
            '/background:#e8a14a;[\s\S]*?<\/tr>\s*<tr>\s*<td[^>]*>([\s\S]*?)<\/td>\s*<\/tr>\s*<tr>\s*<td[^>]*border-top/i',
            $html,
            $m
        )) {
            return $this->normalizeFragment((string)$m[1]);
        }

        // 退路：取 body 内第一块大 padding 内容区
        if (preg_match('/<td[^>]*padding:2\dpx 28px[^>]*>([\s\S]*?)<\/td>/i', $html, $m)
            || preg_match('/<td[^>]*padding:3\dpx 28px[^>]*>([\s\S]*?)<\/td>/i', $html, $m)) {
            $candidate = $this->normalizeFragment((string)$m[1]);
            if ($candidate !== '' && !str_contains($candidate, 'site_logo_img')) {
                return $candidate;
            }
        }

        if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $html, $m)) {
            return $this->normalizeFragment(strip_tags((string)$m[1], '<p><h1><h2><h3><strong><em><a><br><table><tr><td><th><tbody><thead><ul><ol><li><div><span><img><!-->'));
        }

        return $this->normalizeFragment($html);
    }

    public function looksLikeEmbeddedShell(string $html): bool
    {
        return str_contains($html, 'site_logo_img')
            && str_contains($html, '#16333f')
            && (str_contains($html, 'contact_email') || str_contains($html, '需要帮助'));
    }

    /**
     * @param array{preheader?:string} $options
     */
    public function wrap(string $bodyHtml, string $locale = 'zh_Hans_CN', array $options = []): string
    {
        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '') {
            return '';
        }
        if ($this->isAlreadyWrapped($bodyHtml)) {
            return $bodyHtml;
        }
        // 完整自定义文档（无系统壳标记）保持原样，避免二次包裹破坏定制
        if ($this->looksLikeFullDocument($bodyHtml)) {
            return $bodyHtml;
        }

        $shell = $this->loadShell($locale);
        $preheader = trim((string)($options['preheader'] ?? ''));
        if ($preheader === '') {
            $preheader = $this->guessPreheader($bodyHtml, $locale);
        }
        $preheaderEsc = htmlspecialchars($preheader, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['{{MAIL_BODY}}', '{{PREHEADER}}'],
            [$bodyHtml, $preheaderEsc],
            $shell
        );
    }

    public function loadShell(string $locale): string
    {
        $locale = trim($locale) !== '' ? trim($locale) : 'zh_Hans_CN';
        $candidates = [
            $locale,
            str_starts_with($locale, 'zh') ? 'zh_Hans_CN' : 'en_US',
            'zh_Hans_CN',
        ];
        $base = dirname(__DIR__) . '/view/email/shell';
        foreach (array_unique($candidates) as $code) {
            $path = $base . '/' . $code . '.html';
            if (is_file($path)) {
                $html = file_get_contents($path);
                if (is_string($html) && $html !== '') {
                    return $html;
                }
            }
        }
        // 极简回退，保证发信不空
        return '<!DOCTYPE html><html><body data-weline-mail-shell="1">{{MAIL_BODY}}</body></html>';
    }

    private function guessPreheader(string $bodyHtml, string $locale): string
    {
        if (preg_match('/<!--\s*mail:preheader:\s*(.*?)\s*-->/i', $bodyHtml, $m)) {
            return trim(html_entity_decode(strip_tags((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/i', $bodyHtml, $m)) {
            return trim(html_entity_decode(strip_tags((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $text = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text !== '') {
            return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 80);
        }

        return str_starts_with($locale, 'zh') ? '来自商城的通知' : 'A message from the store';
    }

    private function normalizeFragment(string $html): string
    {
        $html = trim($html);
        // 去掉误带的壳标记
        $html = preg_replace('/\s*data-weline-mail-shell="1"/i', '', $html) ?? $html;

        return trim($html);
    }
}
