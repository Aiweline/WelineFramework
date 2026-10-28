<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Spec;

/**
 * 主题规范校验器（S1–S10）。
 *
 * 目标：把「head 预算 / 首屏图 / 字体 / SEO 单一出口」从文档口号变成可执行、可测试、
 * 开发环境直接报错的门禁。规则正文见 Theme/doc/theme-spec-gates.md。
 *
 * 用法：
 * - `php bin/w theme:compile spec`：全量扫描默认主题 + 设计主题，硬违规即非 0 退出；
 * - 开发环境模板编译：{@see self::assertFileForDev()} 命中硬违规直接抛
 *   {@see ThemeSpecViolationException}。
 */
final class ThemeSpecValidator
{
    /** S4：layout-critical 内联预算 */
    public const MAX_LAYOUT_CRITICAL_BYTES = 14336;

    /** S2：head 内阻塞样式表上限 */
    public const MAX_HEAD_STYLESHEETS = 6;

    private const LAYOUT_CRITICAL_FILES = [
        'layout-critical.css',
        'layout-critical-frontend.css',
        'layout-critical-backend.css',
    ];

    /** @var array<string, array<int, array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>> */
    private static array $devFileCache = [];

    /**
     * dev 编译期入口：只校验主题模板，命中硬违规即抛异常。
     */
    public static function assertFileForDev(string $path, string $area = 'frontend'): void
    {
        $path = str_replace('\\', '/', $path);
        if (!str_ends_with($path, '.phtml')) {
            return;
        }
        if (!str_contains($path, '/view/theme/')
            && !str_contains($path, '/app/design/')
            && !str_contains($path, '/view/templates/')) {
            return;
        }

        $stamp = $path . ':' . (int)@filemtime($path);
        if (!isset(self::$devFileCache[$stamp])) {
            self::$devFileCache[$stamp] = (new self())->validateFile($path, $area);
        }

        foreach (self::$devFileCache[$stamp] as $violation) {
            if ($violation['severity'] !== 'hard') {
                continue;
            }
            throw new ThemeSpecViolationException(
                $violation['rule'],
                $violation['file'],
                $violation['line'],
                $violation['hint']
            );
        }
    }

    /**
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    public function validateFile(string $path, string $area = 'frontend'): array
    {
        $path = str_replace('\\', '/', $path);
        if (!is_file($path)) {
            return [];
        }
        $basename = strtolower(basename($path));

        if (str_ends_with($basename, '.css')) {
            return $this->validateCssFile($path, $basename);
        }
        if (str_ends_with($basename, '.phtml')) {
            return $this->validateTemplateFile($path, $area);
        }

        return [];
    }

    /**
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    public function assertDirectory(string $directory): array
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (!is_dir($directory)) {
            return [];
        }

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '.phtml') || str_ends_with($path, '.css')) {
                $violations = array_merge($violations, $this->validateFile($path));
            }
        }

        return $violations;
    }

    public function assertHtml(string $html, string $origin = 'html'): void
    {
        if (trim($html) === '') {
            return;
        }

        // S10：文档完整性
        if (stripos($html, '<html') !== false) {
            foreach (['</body>' => 'body', '</html>' => 'html'] as $needle => $label) {
                if (stripos($html, $needle) === false) {
                    throw new ThemeSpecViolationException(
                        'S10',
                        $origin,
                        0,
                        sprintf('Rendered document is missing %s closing tag.', $label)
                    );
                }
            }
        }

        // S2：head 样式表预算
        $headEnd = stripos($html, '</head>');
        $head = $headEnd === false ? '' : substr($html, 0, $headEnd);
        if ($head !== '') {
            $stylesheets = preg_match_all('/<link\b[^>]*rel\s*=\s*["\']stylesheet["\'][^>]*>/i', $head);
            if ($stylesheets > self::MAX_HEAD_STYLESHEETS) {
                throw new ThemeSpecViolationException(
                    'S2',
                    $origin,
                    0,
                    sprintf('Head carries %d blocking stylesheets (max %d); switch storefront/overlay packs to preload.', $stylesheets, self::MAX_HEAD_STYLESHEETS)
                );
            }
        }

        // S8：SEO 单一出口
        if (preg_match('/<meta\b[^>]*property\s*=\s*["\']og:/i', $html) === false
            && preg_match('/<script\b[^>]*application\/ld\+json/i', $html) === false) {
            return;
        }
        // 渲染后的页面允许存在 og/JSON-LD（由 <w:seo> 输出）；S8 只约束模板源码。
    }

    /**
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    private function validateCssFile(string $path, string $basename): array
    {
        if (!in_array($basename, self::LAYOUT_CRITICAL_FILES, true)) {
            return [];
        }
        $size = (int)@filesize($path);
        if ($size <= self::MAX_LAYOUT_CRITICAL_BYTES) {
            return [];
        }

        return [$this->violation(
            'S4',
            $path,
            1,
            sprintf('layout-critical CSS is %d bytes, over the %d byte inline budget.', $size, self::MAX_LAYOUT_CRITICAL_BYTES),
            'Move non-first-paint rules into the async storefront pack; layout-critical may only carry first-paint shells.'
        )];
    }

    /**
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    private function validateTemplateFile(string $path, string $area): array
    {
        $source = (string)@file_get_contents($path);
        if ($source === '') {
            return [];
        }
        $isHeadPartial = str_contains($path, '/partials/head/');
        $isFrontend = !str_contains($path, '/backend/') && (str_contains($path, '/frontend/') || $area === 'frontend');
        $violations = [];

        if ($isHeadPartial) {
            // S1：禁止用 media="print" 伪装非阻塞
            $line = $this->lineOf($source, 'media="print"');
            if ($line > 0) {
                $violations[] = $this->violation('S1', $path, $line, 'Head partial uses media="print" to fake non-blocking CSS.', 'Use tryPreloadLinkHtml() (rel=preload as=style + onload switch) instead of media="print".');
            }

            // S2：head 阻塞样式表 / 业务 JS 外链
            $stylesheets = preg_match_all('/rel\s*=\s*["\']stylesheet["\']/i', $source);
            if ($stylesheets > self::MAX_HEAD_STYLESHEETS) {
                $violations[] = $this->violation('S2', $path, 1, sprintf('Head partial emits %d stylesheet links (max %d).', $stylesheets, self::MAX_HEAD_STYLESHEETS), 'Only tokens/backend packs stay blocking; everything else must preload.');
            }
            if ($isFrontend && preg_match_all('/<link\b[^>]*rel\s*=\s*["\']stylesheet["\'][^>]*>/i', $source, $literalLinks)) {
                foreach ($literalLinks[0] as $tag) {
                    if (preg_match('/\brel\s*=\s*["\']preload["\']/i', $tag)) {
                        continue; // rel=preload ... this.rel='stylesheet' is the compliant form
                    }
                    if (str_contains($tag, 'data-weline-theme-tokens="1"')
                        || str_contains($tag, 'data-weline-preload-fallback="1"')) {
                        continue; // tokens pack and <noscript> fallbacks are the only allowed blocking links
                    }
                    $violations[] = $this->violation(
                        'S2',
                        $path,
                        $this->lineOf($source, $tag),
                        'Head partial emits a blocking <link rel="stylesheet">: ' . trim($tag),
                        'Use rel="preload" as="style" + onload switch; keep only a <noscript> fallback marked data-weline-preload-fallback="1".'
                    );
                }
            }
            if ($isFrontend && preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\'][^"\']+["\'][^>]*>/i', $source, $scriptMatches)) {
                foreach ($scriptMatches[0] as $tag) {
                    if (preg_match('/weline(-ui|-theme|-css)/i', $tag)) {
                        continue;
                    }
                    // defer/async/module do not block parsing; only parser-blocking
                    // business JS on the head critical path is a violation.
                    if (preg_match('/\b(defer|async)\b/i', $tag) || str_contains($tag, 'type="module"')) {
                        continue;
                    }
                    $violations[] = $this->violation('S2', $path, $this->lineOf($source, $tag), 'Frontend head loads parser-blocking business JS: ' . trim($tag), 'Business JS belongs in the footer (or must be defer/async); only framework module scripts may live in head.');
                }
            }

            // S3：默认主题 head 必须内联 layout-critical
            if (in_array(basename($path), ['default.phtml', 'assets-prefix.phtml', 'minimal.phtml'], true)
                && !str_contains($source, 'theme:layout-critical')) {
                $violations[] = $this->violation('S3', $path, 1, 'Default theme head is missing <theme:layout-critical>.', 'Add <theme:layout-critical area="frontend"/> so first paint never waits for remote CSS.');
            }
        }

        // S5 / S6：首屏图
        if (preg_match_all('/<img\b[^>]*>/i', $source, $imgMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($imgMatches[0] as [$tag, $offset]) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $firstScreen = (bool)preg_match('/data-weline-first-screen|class\s*=\s*["\'][^"\']*\b(hero|lcp|banner|cover)\b/i', $tag);
                if (!$firstScreen) {
                    continue;
                }
                if (!preg_match('/\bwidth\s*=/i', $tag) || !preg_match('/\bheight\s*=/i', $tag)) {
                    $violations[] = $this->violation('S5', $path, $line, 'First-screen <img> is missing width/height: ' . trim($tag), 'Add intrinsic width/height (CLS stays 0 when the box is reserved before CSS arrives).');
                }
                if (preg_match('/loading\s*=\s*["\']lazy["\']/i', $tag)) {
                    $violations[] = $this->violation('S6', $path, $line, 'First-screen <img> must not be loading="lazy".', 'Remove loading="lazy"; add fetchpriority="high" to the LCP image.');
                }
                if (!preg_match('/fetchpriority\s*=/i', $tag)) {
                    $violations[] = $this->violation('S6', $path, $line, 'First-screen <img> is missing fetchpriority.', 'Add fetchpriority="high" so the LCP image is not queued behind other requests.', 'soft');
                }
            }
        }

        // S7：字体不得显式使用阻塞式 font-display
        if (preg_match_all('/<w:theme:font\b[^>]*>/i', $source, $fontMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($fontMatches[0] as [$tag, $offset]) {
                if (preg_match('/\bdisplay\s*=\s*["\']([^"\']+)["\']/i', $tag, $display)
                    && !in_array(strtolower(trim($display[1])), ['swap', 'optional'], true)) {
                    $violations[] = $this->violation('S7', $path, substr_count(substr($source, 0, $offset), "\n") + 1, 'Font declares display="' . $display[1] . '".', 'Use display="swap" (default) so text paints before the webfont loads.');
                }
            }
        }

        // S8：SEO 单一出口 —— 只约束 head 区域；正文内的局部 JSON-LD
        // （如 article 内 FAQ）属于内容本身，由 Seo structure 规则校验。
        // 纯内容片段（没有 <head> 也不是 head partial）不适用。
        if (!$isHeadPartial && preg_match('/<head\b/i', $source) !== 1) {
            return $violations;
        }
        $headEndOffset = stripos($source, '</head>');
        if ($headEndOffset === false) {
            $headEndOffset = stripos($source, '<body');
        }
        $headSource = $headEndOffset === false ? $source : substr($source, 0, $headEndOffset);
        $ogLine = $this->lineOf($headSource, 'property="og:');
        if ($ogLine > 0) {
            $violations[] = $this->violation('S8', $path, $ogLine, 'Template hand-writes Open Graph meta.', 'Remove it; <w:seo slot="head"/> is the single SEO outlet.');
        }
        $ldLine = $this->lineOf($headSource, 'application/ld+json');
        if ($ldLine > 0) {
            $violations[] = $this->violation('S8', $path, $ldLine, 'Template hand-writes JSON-LD in head.', 'Remove it; structured data must come from <w:seo slot="head"/> / Seo structures.');
        }

        return $violations;
    }

    private function lineOf(string $source, string $needle): int
    {
        $offset = strpos($source, $needle);
        if ($offset === false) {
            return 0;
        }

        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * @return array{rule:string,file:string,line:int,severity:string,message:string,hint:string}
     */
    private function violation(string $rule, string $file, int $line, string $message, string $hint, string $severity = 'hard'): array
    {
        return [
            'rule' => $rule,
            'file' => str_replace('\\', '/', $file),
            'line' => max(1, $line),
            'severity' => $severity,
            'message' => $message,
            'hint' => $hint,
        ];
    }
}
