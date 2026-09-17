<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

/**
 * 固定邮件页头/页尾壳：业务模板只提供正文片段，发信时组装。
 * 协议：table + 内联样式；禁止 JS。
 * 壳源：view/email/shell.phtml（&lt;lang&gt; 进 I18n collect/AI）；按邮件 locale 实时译。
 * CLI/队列发信：wrap/loadShell 会临时进入 Context 并强制邮件 locale（无 Web 请求时）。
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
        return $this->withMailLocaleEnvironment($locale, function () use ($bodyHtml, $locale, $options): string {
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

            $storageScope = trim((string)($options['storage_scope'] ?? ''));
            $shell = $this->loadShellRaw($locale, $storageScope);
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
        });
    }

    public function loadShell(string $locale, string $storageScope = ''): string
    {
        return $this->withMailLocaleEnvironment($locale, function () use ($locale, $storageScope): string {
            return $this->loadShellRaw($locale, $storageScope);
        });
    }

    /**
     * CLI/队列无 Web 请求时：临时进入 Context，并强制本邮件 locale。
     * 须已由 bin/w.php / App 引导 ObjectManager+Env；本方法不替代完整 bootstrap。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withMailLocaleEnvironment(string $locale, callable $fn): mixed
    {
        $locale = trim($locale) !== '' ? trim($locale) : 'zh_Hans_CN';
        $hadContext = Context::hasCurrent();
        $previousOverride = State::getRequestLanguageOverride();
        try {
            if (!$hadContext) {
                Context::enter(new Context([
                    'meta' => [
                        'type' => 'system',
                        'mode' => 'mail_shell',
                    ],
                ]));
            }
            State::setRequestLanguageOverride($locale);

            return $fn();
        } finally {
            State::setRequestLanguageOverride($previousOverride);
            if (!$hadContext && Context::hasCurrent()) {
                Context::leave();
            }
        }
    }

    private function loadShellRaw(string $locale, string $storageScope = ''): string
    {
        $locale = trim($locale) !== '' ? trim($locale) : 'zh_Hans_CN';
        $phtml = dirname(__DIR__) . '/view/email/shell.phtml';
        if (is_file($phtml)) {
            $raw = @file_get_contents($phtml);
            if (is_string($raw) && $raw !== '') {
                $shell = $this->renderShellPhtml($raw, $locale);

                return $this->applyShellRegionOverrides($shell, $storageScope);
            }
        }

        // 兼容旧版按 locale 静态 html（迁移期）
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
                    return $this->applyShellRegionOverrides($html, $storageScope);
                }
            }
        }

        return $this->applyShellRegionOverrides(
            '<!DOCTYPE html><html><body data-weline-mail-shell="1">{{MAIL_BODY}}</body></html>',
            $storageScope
        );
    }

    private function applyShellRegionOverrides(string $shell, string $storageScope): string
    {
        $storageScope = trim($storageScope);
        if ($storageScope === '') {
            return $shell;
        }
        try {
            /** @var MailShellRegionStore $store */
            $store = ObjectManager::getInstance(MailShellRegionStore::class);

            return $store->applyToShell($shell, $storageScope);
        } catch (\Throwable) {
            return $shell;
        }
    }

    /**
     * 轻量渲染：解析 &lt;lang&gt; 为词典译文（按邮件 locale），避免 Taglib 编译期烤死单语。
     * collect 仍扫描 phtml 中的 &lt;lang&gt;，走默认 I18n AI。
     * 文本方向对齐前端 Template::resolveTextDirection（ar/ur 等 RTL）。
     */
    private function renderShellPhtml(string $source, string $locale): string
    {
        $dir = $this->resolveTextDirection($locale);
        $isRtl = $dir === 'rtl';
        $alignStart = $isRtl ? 'right' : 'left';
        $alignEnd = $isRtl ? 'left' : 'right';
        $padInlineStart = $isRtl ? 'padding-right:16px;' : 'padding-left:16px;';
        $htmlLang = htmlspecialchars($this->htmlLangAttribute($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = (string)preg_replace(
            '/<html\s+lang="[^"]*"/i',
            '<html lang="' . $htmlLang . '"',
            $source,
            1
        );
        $html = str_replace(
            [
                '{{MAIL_HTML_DIR}}',
                '{{MAIL_ALIGN_START}}',
                '{{MAIL_ALIGN_END}}',
                '{{MAIL_PAD_INLINE_START}}',
            ],
            [
                $dir,
                $alignStart,
                $alignEnd,
                $padInlineStart,
            ],
            $html
        );

        return (string)preg_replace_callback(
            '/<lang(?:\s[^>]*)?>(.*?)<\/lang>/s',
            function (array $m) use ($locale): string {
                $word = trim(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($word === '') {
                    return '';
                }

                return htmlspecialchars(
                    $this->translateShellWord($word, $locale),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
            },
            $html
        );
    }

    private function translateShellWord(string $word, string $locale): string
    {
        try {
            /** @var TranslationResolverInterface $resolver */
            $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
            $translated = trim($resolver->translate($word, $locale, ['Weline_Smtp', 'Weline_Framework', 'Weline_Theme']));
            if ($translated !== '') {
                return $translated;
            }
        } catch (\Throwable) {
            // CLI/单测无容器时回落源文
        }

        return $word;
    }

    /**
     * 与 Framework\View\Template::resolveTextDirection 对齐（邮件无 View 上下文时本地复刻）。
     */
    public function resolveTextDirection(string $locale): string
    {
        $parts = preg_split('/[-_]+/', trim($locale)) ?: [];
        $language = strtolower((string)($parts[0] ?? ''));
        $script = '';
        foreach ($parts as $part) {
            if (strlen($part) === 4 && ctype_alpha($part)) {
                $script = strtolower($part);
                break;
            }
        }
        $rtlLanguages = [
            'ar' => true,
            'arc' => true,
            'ckb' => true,
            'dv' => true,
            'fa' => true,
            'he' => true,
            'iw' => true,
            'ks' => true,
            'ps' => true,
            'sd' => true,
            'ug' => true,
            'ur' => true,
            'yi' => true,
        ];
        $rtlScripts = [
            'adlm' => true,
            'arab' => true,
            'hebr' => true,
            'nkoo' => true,
            'rohg' => true,
            'syrc' => true,
            'thaa' => true,
        ];

        return isset($rtlScripts[$script]) || isset($rtlLanguages[$language]) ? 'rtl' : 'ltr';
    }

    private function htmlLangAttribute(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale === '') {
            return 'zh';
        }
        // BCP 47：语言小写，脚本首字母大写，地区大写（对齐前端 htmlLang）
        $parts = preg_split('/-+/', $locale) ?: [];
        $out = [];
        foreach ($parts as $i => $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            if ($i === 0) {
                $out[] = strtolower($part);
            } elseif (strlen($part) === 4 && ctype_alpha($part)) {
                $out[] = ucfirst(strtolower($part));
            } elseif (strlen($part) === 2 && ctype_alpha($part)) {
                $out[] = strtoupper($part);
            } else {
                $out[] = $part;
            }
        }

        return $out !== [] ? implode('-', $out) : 'zh';
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

        return $this->translateShellWord('来自商城的通知', $locale);
    }

    private function normalizeFragment(string $html): string
    {
        $html = trim($html);
        // 去掉误带的壳标记
        $html = preg_replace('/\s*data-weline-mail-shell="1"/i', '', $html) ?? $html;

        return trim($html);
    }
}
