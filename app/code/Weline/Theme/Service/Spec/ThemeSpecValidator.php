<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Spec;

use Weline\Theme\Service\Theme\LayoutCriticalCssMaterializer;
use Weline\Theme\Service\Theme\ThemeCssLayerContract;

/**
 * 主题规范校验器（S1–S13）。
 *
 * 目标：把「head 预算 / 首屏图 / 字体 / SEO 单一出口 / 层序单一出处 / critical 与皮肤几何一致」
 * 从文档口号变成可执行、可测试、开发环境直接报错的门禁。规则正文见 Theme/doc/theme-spec-gates.md。
 *
 * 用法：
 * - `php bin/w theme:compile spec`：全量扫描默认主题 + 设计主题，硬违规即非 0 退出；
 * - 开发环境模板编译：{@see self::assertFileForDev()} 命中硬违规直接抛
 *   {@see ThemeSpecViolationException}。
 */
final class ThemeSpecValidator
{
    /** S4：layout-critical 内联预算（单文件） */
    public const MAX_LAYOUT_CRITICAL_BYTES = 14336;

    /**
     * S4（composed）：head 实际内联总量上限 = override（或基线）+ addendum。
     * 为何单独放宽到 16KB：基线现在自带移动端自适应契约（dvh / 触控下限 / 输入字号地板）；
     * 主题又合法持有自己的首屏 pin（CLS 实测证据驱动），“基线 + 增量”合起来 14KB 已装不下。
     * 语义仍然是“首屏只放必要规则”：超了就得分流到异步包，不是把阈值当可调参数反复抬。
     */
    public const MAX_COMPOSED_LAYOUT_CRITICAL_BYTES = 16384;

    /** S2：head 内阻塞样式表上限 */
    public const MAX_HEAD_STYLESHEETS = 6;

    private const LAYOUT_CRITICAL_FILES = [
        'layout-critical.css',
        'layout-critical-frontend.css',
        'layout-critical-backend.css',
    ];

    /**
     * 增量通道：主题在基线上追加自己的首屏规则（不整体替换基线）。
     * 免 S11：层序已由基线（文档中更早的样式表）钉死，追加文件再声明只是重复。
     * 不免 S4/S12：它们照样进 head 内联，照样会强隐。
     */
    private const LAYOUT_CRITICAL_ADDENDUM_FILES = [
        'layout-critical-addendum.css',
        'layout-critical-frontend-addendum.css',
        'layout-critical-backend-addendum.css',
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

        // S4（composed）：head 内联的是「生效集合」——override 或基线，再加 addendum。
        // 逐文件判会假通过（基线 ≤14KB、增量也 ≤14KB，合起来内联就超）。
        $violations = array_merge($violations, $this->assertComposedCriticalBudget($directory));

        // S13：主题自身 layout-critical 与皮肤叶的几何值不得矛盾（跨文件，必须整根校验）
        $violations = array_merge($violations, $this->validateCriticalSkinConflicts($directory));

        return $violations;
    }

    /**
     * S4（composed）：按「一个 area 目录实际会内联多少字节」判，而不是按单个文件判。
     * 生效集合与 LayoutCriticalCssService 同口径：目录里有 override 就用 override，否则用基线；
     * 再加上同目录的 addendum。
     *
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    private function assertComposedCriticalBudget(string $themeRoot): array
    {
        $groups = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $basename = strtolower($file->getBasename());
            if (!in_array($basename, self::LAYOUT_CRITICAL_FILES, true)
                && !in_array($basename, self::LAYOUT_CRITICAL_ADDENDUM_FILES, true)) {
                continue;
            }
            $effective = in_array($basename, self::LAYOUT_CRITICAL_ADDENDUM_FILES, true)
                ? 'addendum'
                : 'override';
            if ($effective === 'override') {
                // 带 GENERATED 标记 = 物化器托管快照：实际内联的是基线，不是这个文件。
                $head = (string)@file_get_contents($file->getPathname(), false, null, 0, 2048);
                if (str_contains($head, LayoutCriticalCssMaterializer::GENERATED_MARKER)) {
                    $effective = 'snapshot';
                }
            }
            $dir = str_replace('\\', '/', dirname($file->getPathname()));
            $groups[$dir][$effective][] = str_replace('\\', '/', $file->getPathname());
        }

        $violations = [];
        foreach ($groups as $dir => $byKind) {
            // 仅托管快照 = 实际读基线，内联的不是这个文件，跳过。
            if (empty($byKind['override']) && empty($byKind['addendum'])) {
                continue;
            }

            $area = str_contains($dir, '/backend/') ? 'backend' : 'frontend';
            $baselinePath = BP . sprintf('app/code/Weline/Theme/view/ui/css/layout-critical-%s.css', $area);

            $total = 0;
            $sources = [];
            if (!empty($byKind['override'])) {
                $override = (string)$byKind['override'][0];
                $total += (int)@filesize($override);
                $sources[] = basename($override);
            } else {
                $total += (int)@filesize($baselinePath);
                $sources[] = 'view/ui/css/layout-critical-' . $area . '.css (baseline)';
            }
            foreach ($byKind['addendum'] ?? [] as $addendum) {
                $total += (int)@filesize($addendum);
                $sources[] = basename($addendum);
            }

            if ($total <= self::MAX_COMPOSED_LAYOUT_CRITICAL_BYTES) {
                continue;
            }

            $reported = $byKind['addendum'][0] ?? ($byKind['override'][0] ?? $dir);
            $violations[] = $this->violation(
                'S4',
                $reported,
                1,
                sprintf(
                    'Composed layout-critical CSS is %d bytes (%s), over the %d byte inline budget.',
                    $total,
                    implode(' + ', $sources),
                    self::MAX_COMPOSED_LAYOUT_CRITICAL_BYTES
                ),
                'The head inlines the whole effective set. Move non-first-paint rules into the async pack.'
            );
        }

        return $violations;
    }

    /**
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    private function validateCriticalSkinConflicts(string $themeRoot): array
    {
        $themeRoot = rtrim(str_replace('\\', '/', $themeRoot), '/');
        try {
            // 设计主题根已是 <theme>/<area>；模块默认主题根是 view/theme，需要下沉到 area 目录。
            $mayBeContainer = !is_file($themeRoot . '/' . ThemeCriticalSkinConflict::MANIFEST_RELATIVE);
            if ($mayBeContainer) {
                foreach (['frontend', 'backend'] as $area) {
                    $areaRoot = $themeRoot . '/' . $area;
                    if (is_file($areaRoot . '/' . ThemeCriticalSkinConflict::MANIFEST_RELATIVE)) {
                        return (new ThemeCriticalSkinConflict())->collect($areaRoot, $area);
                    }
                }

                return [];
            }

            return (new ThemeCriticalSkinConflict())->collect($themeRoot);
        } catch (\Throwable) {
            return [];
        }
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
            $stylesheets = count($this->blockingStylesheetTags($head));
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
        $isCritical = in_array($basename, self::LAYOUT_CRITICAL_FILES, true);
        $isAddendum = in_array($basename, self::LAYOUT_CRITICAL_ADDENDUM_FILES, true);
        if (!$isCritical && !$isAddendum) {
            return [];
        }

        $violations = [];
        $source = (string)@file_get_contents($path);

        // S11：首个样式表必须自钉层序，否则 weline-theme 的最低位可能被抢先固定而失效。
        if ($isCritical && !ThemeCssLayerContract::isDeclared($source)) {
            $violations[] = $this->violation(
                'S11',
                $path,
                1,
                'layout-critical CSS does not declare the canonical cascade layer order.',
                'Add "' . ThemeCssLayerContract::DECLARATION . '" so the earliest stylesheet in the document fixes the layer order.'
            );
        }

        // S12：未分层 pin 的强制显隐必须带条件，否则会压死响应式/打开态（hanfu 移动抽屉事故）。
        $violations = array_merge($violations, $this->assertCriticalImportantIsConditioned($path, $source));

        // S4：内联字节预算
        $size = (int)@filesize($path);
        if ($size > self::MAX_LAYOUT_CRITICAL_BYTES) {
            $violations[] = $this->violation(
                'S4',
                $path,
                1,
                sprintf('layout-critical CSS is %d bytes, over the %d byte inline budget.', $size, self::MAX_LAYOUT_CRITICAL_BYTES),
                'Move non-first-paint rules into the async storefront pack; layout-critical may only carry first-paint shells.'
            );
        }

        return $violations;
    }

    /**
     * S12：layout-critical 正文未分层 ⇒ 未分层恒胜所有 @layer，其 `!important` 不可被普通规则
     * 覆盖。因此 display/visibility 的 `!important` 必须带条件，二选一：
     *  a) 位于 @media 内（断点镜像，如「桌面才隐藏」）；
     *  b) 选择器带状态标记（[hidden] / :not([open]) / [data-* / :hover / :focus / .is-*）。
     * 无条件 `display:none!important` 会让已打开的面板也被压回 none（点不开）。
     *
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    private function assertCriticalImportantIsConditioned(string $path, string $source): array
    {
        $violations = [];
        $css = (string)preg_replace('!/\*.*?\*/!s', '', $source);
        $length = strlen($css);
        $stack = [];
        $buffer = '';
        $line = 1;
        $stateSelector = '/[\[\:](hidden|not\(\[open\]\)|data-|aria-|hover|focus|is\(|where\()|^\.is-/i';

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === "\n") {
                $line++;
            }
            if ($char === '{') {
                $stack[] = trim($buffer);
                $buffer = '';
                continue;
            }
            if ($char === '}') {
                array_pop($stack);
                $buffer = '';
                continue;
            }
            if ($char === ';') {
                $declaration = trim($buffer);
                $buffer = '';
                if ($declaration === ''
                    || !preg_match('/^(display|visibility)\s*:/i', $declaration)
                    || !str_contains($declaration, '!important')) {
                    continue;
                }

                $conditioned = false;
                foreach ($stack as $prelude) {
                    if (str_starts_with($prelude, '@media')) {
                        $conditioned = true;
                        break;
                    }
                }
                if (!$conditioned) {
                    $selector = (string)(end($stack) ?: '');
                    if ($selector !== '' && preg_match($stateSelector, $selector) === 1) {
                        $conditioned = true;
                    }
                }
                if (!$conditioned) {
                    $violations[] = $this->violation(
                        'S12',
                        $path,
                        $line,
                        'Unconditional "' . $declaration . '" in layout-critical CSS.',
                        'Condition it: place it inside the mirrored @media breakpoint, or scope it to the closed state ([hidden] / :not([open]) / [data-*). Otherwise it beats the design theme forever.'
                    );
                }
                continue;
            }
            $buffer .= $char;
        }

        return $violations;
    }

    /**
     * 真实阻塞样式表：`<link>` 的 rel **属性词元** 含 stylesheet、不含 preload。
     *
     * 不能用子串判 rel：合规写法 `rel="preload" ... onload="this.rel='stylesheet'"` 自身就含
     * `rel='stylesheet'`，子串判会把非阻塞包数成阻塞（实测把 2 个 preload 计成阻塞，S2 据此只会误报）。
     * `<noscript>` 里的回退副本也不算阻塞请求：JS 浏览器把 noscript 内容当惰性文本，从不加载。
     *
     * @return list<string>
     */
    private function blockingStylesheetTags(string $html): array
    {
        $stripped = (string)preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);
        $tags = [];
        if (preg_match_all('/<link\b[^>]*>/i', $stripped, $matches) === false) {
            return $tags;
        }
        foreach ($matches[0] as $tag) {
            if (preg_match('/\brel\s*=\s*(["\'])(.*?)\1/i', $tag, $rel) !== 1) {
                continue;
            }
            $raw = strtolower(trim($rel[2]));
            $tokens = $raw === '' ? [] : preg_split('/\s+/', $raw);
            if (!is_array($tokens) || $tokens === [] || !in_array('stylesheet', $tokens, true)) {
                continue;
            }
            if (in_array('preload', $tokens, true)) {
                continue;
            }
            $tags[] = $tag;
        }

        return $tags;
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
            $stylesheets = count($this->blockingStylesheetTags($source));
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
