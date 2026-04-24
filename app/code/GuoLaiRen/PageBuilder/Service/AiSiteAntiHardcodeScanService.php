<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 反硬编码校验器（T31 MVP · 只读扫描）
 *
 * 独立、无框架依赖、纯 PHP + 正则 + 文件 IO。
 * 负责发现 .phtml / .php / .js 中的硬编码陷阱（中文字面量、裸 URL、裸色号、
 * 原生 alert/confirm/prompt、未国际化的异常抛出、中文 console 调试日志、
 * phtml 顶部 declare(strict_types=1) 等），供后续 CLI / CI 接入。
 */

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAntiHardcodeScanService
{
    /**
     * 跳过超大文件的阈值（字节）。超过此值时短路并标记 skipped_reason。
     */
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /**
     * 默认扫描的扩展名（.phtml / .php / .js）。
     *
     * @var list<string>
     */
    private const DEFAULT_EXTENSIONS = ['phtml', 'php', 'js'];

    /**
     * 当单行过长（> 此值）时，snippet 会按此长度截断。
     */
    private const SNIPPET_MAX_LEN = 240;

    /**
     * 返回当前实现的规则元数据列表。
     *
     * @return list<array{rule_id:string,severity:string,description:string,pattern_kind:string}>
     */
    public function rules(): array
    {
        return [
            [
                'rule_id'      => 'AHC001',
                'severity'     => 'error',
                'description'  => '.phtml HTML 文本节点包含中文且该行未出现 __( / @lang / <lang> / htmlspecialchars((string)__ 保护调用。',
                'pattern_kind' => 'phtml-text-chinese',
            ],
            [
                'rule_id'      => 'AHC002',
                'severity'     => 'error',
                'description'  => '<script>...</script> 段内字面量含中文且该行非 json_encode((string)__( 产物。',
                'pattern_kind' => 'phtml-script-chinese',
            ],
            [
                'rule_id'      => 'AHC003',
                'severity'     => 'error',
                'description'  => '直接调用 window.alert / window.confirm / window.prompt（应使用 BackendToast / BackendConfirm）。',
                'pattern_kind' => 'js-native-dialog',
            ],
            [
                'rule_id'      => 'AHC004',
                'severity'     => 'warning',
                'description'  => '.phtml 出现裸 URL（https?://...）且该行非 getUrl / getBackendUrl / @url / 注释。',
                'pattern_kind' => 'phtml-bare-url',
            ],
            [
                'rule_id'      => 'AHC005',
                'severity'     => 'warning',
                'description'  => '裸色号（#xxx）出现在 style="..." 或 .style.(background|color|borderColor) = "#..." 中，且不是 var(--token,#fallback) 的 fallback。',
                'pattern_kind' => 'inline-hex-color',
            ],
            [
                'rule_id'      => 'AHC006',
                'severity'     => 'error',
                'description'  => 'PHP 直接 throw new RuntimeException("字面量") 未经 __() 包裹，且不是错误码常量。',
                'pattern_kind' => 'php-throw-literal',
            ],
            [
                'rule_id'      => 'AHC007',
                'severity'     => 'warning',
                'description'  => 'console.log / console.warn / console.error 中包含中文（调试残留）。',
                'pattern_kind' => 'js-console-chinese',
            ],
            [
                'rule_id'      => 'AHC008',
                'severity'     => 'info',
                'description'  => '.phtml 顶部出现 declare(strict_types=1)（框架约定不允许）。',
                'pattern_kind' => 'phtml-strict-types',
            ],
        ];
    }

    /**
     * 扫描单个文件。
     *
     * @return list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }>
     */
    public function scanFile(string $absolutePath): array
    {
        if ($absolutePath === '' || !\is_file($absolutePath) || !\is_readable($absolutePath)) {
            return [];
        }

        $size = @\filesize($absolutePath);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            return [];
        }

        $raw = @\file_get_contents($absolutePath);
        if ($raw === false || $raw === '') {
            return [];
        }

        $ext = \strtolower(\pathinfo($absolutePath, \PATHINFO_EXTENSION));
        if (!\in_array($ext, self::DEFAULT_EXTENSIONS, true)) {
            return [];
        }

        $lines = \preg_split('/\r\n|\n|\r/', $raw);
        if (!\is_array($lines)) {
            return [];
        }

        $violations = [];

        $isPhtml = ($ext === 'phtml');
        $isPhp   = ($ext === 'php' || $ext === 'phtml');
        $isJs    = ($ext === 'js');

        // 先预处理：对于 .phtml，标记每行是否在 <script>...</script> 段内。
        $inScriptMap = $isPhtml ? $this->computeScriptSpan($lines) : [];

        foreach ($lines as $idx => $line) {
            $lineNo   = $idx + 1;
            $prevLine = $idx > 0 ? (string)$lines[$idx - 1] : '';
            $nextLine = isset($lines[$idx + 1]) ? (string)$lines[$idx + 1] : '';
            $ctxJoin  = $prevLine . "\n" . $line . "\n" . $nextLine;

            // -------- AHC001: .phtml 中文文本节点 --------
            if ($isPhtml) {
                $this->applyRuleAhc001($absolutePath, $lineNo, $line, $ctxJoin, $inScriptMap[$idx] ?? false, $violations);
            }

            // -------- AHC002: <script> 内中文字面量 --------
            if ($isPhtml && ($inScriptMap[$idx] ?? false)) {
                $this->applyRuleAhc002($absolutePath, $lineNo, $line, $ctxJoin, $prevLine, $violations);
            }

            // -------- AHC003: window.alert/confirm/prompt --------
            if ($isPhtml || $isJs) {
                $this->applyRuleAhc003($absolutePath, $lineNo, $line, $violations);
            }

            // -------- AHC004: .phtml 裸 URL --------
            if ($isPhtml) {
                $this->applyRuleAhc004($absolutePath, $lineNo, $line, $violations);
            }

            // -------- AHC005: 裸 hex 颜色 --------
            if ($isPhtml || $isJs) {
                $this->applyRuleAhc005($absolutePath, $lineNo, $line, $violations);
            }

            // -------- AHC006: PHP throw RuntimeException 字面量 --------
            if ($isPhp) {
                $this->applyRuleAhc006($absolutePath, $lineNo, $line, $violations);
            }

            // -------- AHC007: console.* 中文调试 --------
            if ($isPhtml || $isJs) {
                $this->applyRuleAhc007($absolutePath, $lineNo, $line, $violations);
            }
        }

        // -------- AHC008: phtml 顶部 declare(strict_types=1) --------
        if ($isPhtml) {
            $this->applyRuleAhc008($absolutePath, $lines, $violations);
        }

        return $violations;
    }

    /**
     * 批量扫描。支持：
     * - 单个文件绝对路径
     * - 目录绝对路径（递归）
     * - glob 通配（带 *）
     *
     * @param list<string> $absolutePaths
     * @return array{
     *     files: array<string, array{skipped_reason: ?string, violations_count: int}>,
     *     totals: array<string, int>,
     *     violations: list<array{
     *         rule_id:string,
     *         severity:string,
     *         path:string,
     *         line:int,
     *         col:int,
     *         snippet:string,
     *         message:string
     *     }>
     * }
     */
    public function scanPaths(array $absolutePaths): array
    {
        $files      = [];
        $totals     = [];
        $violations = [];

        $resolved = $this->resolvePathList($absolutePaths);

        foreach ($resolved as $file) {
            if (!\is_file($file) || !\is_readable($file)) {
                $files[$file] = ['skipped_reason' => 'not-readable', 'violations_count' => 0];
                continue;
            }

            $size = @\filesize($file);
            if ($size !== false && $size > self::MAX_FILE_BYTES) {
                $files[$file] = ['skipped_reason' => 'too-large', 'violations_count' => 0];
                continue;
            }

            $ext = \strtolower(\pathinfo($file, \PATHINFO_EXTENSION));
            if (!\in_array($ext, self::DEFAULT_EXTENSIONS, true)) {
                $files[$file] = ['skipped_reason' => 'unsupported-extension', 'violations_count' => 0];
                continue;
            }

            $fileViolations = $this->scanFile($file);
            $files[$file]   = ['skipped_reason' => null, 'violations_count' => \count($fileViolations)];

            foreach ($fileViolations as $v) {
                $violations[] = $v;
                $rid          = $v['rule_id'];
                $totals[$rid] = ($totals[$rid] ?? 0) + 1;
            }
        }

        \ksort($totals);

        return [
            'files'      => $files,
            'totals'     => $totals,
            'violations' => $violations,
        ];
    }

    // ------------------------------------------------------------------
    // Rule implementations
    // ------------------------------------------------------------------

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc001(string $path, int $lineNo, string $line, string $ctxJoin, bool $inScript, array &$violations): void
    {
        if ($inScript) {
            return;
        }
        if (!\preg_match('/\p{Han}/u', $line)) {
            return;
        }
        if ($this->isProtectedChinese($ctxJoin)) {
            return;
        }
        // 豁免：纯 PHP 注释行（// 或 /* ... */ 或 * ...）
        $trim = \ltrim($line);
        if ($trim !== '' && (
            \str_starts_with($trim, '//')
            || \str_starts_with($trim, '/*')
            || \str_starts_with($trim, '*')
            || \str_starts_with($trim, '#')
        )) {
            return;
        }

        $col = $this->firstHanColumn($line);
        $violations[] = [
            'rule_id'  => 'AHC001',
            'severity' => 'error',
            'path'     => $path,
            'line'     => $lineNo,
            'col'      => $col,
            'snippet'  => $this->snippet($line),
            'message'  => 'HTML 文本出现中文硬编码，请用 __() / @lang / <lang> 包裹。',
        ];
    }

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc002(string $path, int $lineNo, string $line, string $ctxJoin, string $prevLine, array &$violations): void
    {
        $trim = \ltrim($line);
        // 豁免：注释行（docblock / 单行注释）
        if ($trim !== '' && (
            \str_starts_with($trim, '//')
            || \str_starts_with($trim, '/*')
            || \str_starts_with($trim, '*')
            || \str_starts_with($trim, '#')
        )) {
            return;
        }
        // 豁免：显式标注行（matcher 源词库 / 与后端文案匹配）
        if ($this->hasAhc002ExemptMarker($line, $prevLine)) {
            return;
        }
        if (!\preg_match('/[\'"`][^\'"`]*\p{Han}[^\'"`]*[\'"`]/u', $line)) {
            return;
        }
        if (\preg_match('/json_encode\s*\(\s*\(string\)\s*__\s*\(/u', $ctxJoin) === 1) {
            return;
        }
        if (\preg_match('/__\s*\(/u', $line) === 1) {
            return;
        }
        $col = $this->firstHanColumn($line);
        $violations[] = [
            'rule_id'  => 'AHC002',
            'severity' => 'error',
            'path'     => $path,
            'line'     => $lineNo,
            'col'      => $col,
            'snippet'  => $this->snippet($line),
            'message'  => '<script> 段内出现中文硬编码字面量，请使用 json_encode((string)__()) 输出。',
        ];
    }

    private function hasAhc002ExemptMarker(string $line, string $prevLine): bool
    {
        return \str_contains($line, 'i18n: matcher-source')
            || \str_contains($line, 'i18n: match-with-backend')
            || \str_contains($prevLine, 'i18n: matcher-source')
            || \str_contains($prevLine, 'i18n: match-with-backend');
    }

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc003(string $path, int $lineNo, string $line, array &$violations): void
    {
        if (!\preg_match('/\bwindow\.(alert|confirm|prompt)\s*\(/u', $line, $m, \PREG_OFFSET_CAPTURE)) {
            return;
        }
        if ($this->lineHasExemptMarker($line)) {
            return;
        }
        $col = (int)$m[0][1] + 1;
        $violations[] = [
            'rule_id'  => 'AHC003',
            'severity' => 'error',
            'path'     => $path,
            'line'     => $lineNo,
            'col'      => $col,
            'snippet'  => $this->snippet($line),
            'message'  => '禁止使用 window.' . (string)$m[1][0] . '()，请改用 BackendToast / BackendConfirm。',
        ];
    }

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc004(string $path, int $lineNo, string $line, array &$violations): void
    {
        $trim = \ltrim($line);
        if ($trim === '' || \str_starts_with($trim, '//') || \str_starts_with($trim, '*') || \str_starts_with($trim, '#')) {
            return;
        }
        // 豁免：getUrl / getBackendUrl / @url 已经包裹
        if (\preg_match('/\bgetUrl\s*\(|\bgetBackendUrl\s*\(|@url\b/u', $line) === 1) {
            return;
        }
        // 豁免：HTML 注释 / PHP 注释内
        if (\preg_match('/<!--.*https?:\/\/.*-->/u', $line) === 1) {
            return;
        }
        if (!\preg_match_all('#https?://[^\s\'"<>()]+#u', $line, $matches, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $match) {
            $matchText   = (string)$match[0];
            $byteOffset  = (int)$match[1];
            // 豁免：白名单保留域（w3.org / schemas.*）
            if ($this->isWhitelistedUrl($matchText)) {
                continue;
            }
            $col = $byteOffset + 1;
            $violations[] = [
                'rule_id'  => 'AHC004',
                'severity' => 'warning',
                'path'     => $path,
                'line'     => $lineNo,
                'col'      => $col,
                'snippet'  => $this->snippet($line),
                'message'  => '裸 URL 未经 getUrl / getBackendUrl / @url 包装：' . $matchText,
            ];
        }
    }

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc005(string $path, int $lineNo, string $line, array &$violations): void
    {
        $candidates = [];

        // 1) style="... #xxx ..."
        if (\preg_match_all('/style\s*=\s*"([^"]*)"/u', $line, $m, \PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $full) {
                $segText   = (string)$m[1][$i][0];
                $segOffset = (int)$m[1][$i][1];
                if (\preg_match_all('/#[0-9a-fA-F]{3,8}\b/u', $segText, $colors, \PREG_OFFSET_CAPTURE)) {
                    foreach ($colors[0] as $c) {
                        $color = (string)$c[0];
                        $byteOffset = $segOffset + (int)$c[1];
                        if ($this->isVarFallbackAt($line, $byteOffset)) {
                            continue;
                        }
                        $candidates[] = ['color' => $color, 'col' => $byteOffset + 1];
                    }
                }
            }
        }

        // 2) .style.(background|color|borderColor) = '#xxx'
        if (\preg_match_all(
            '/\.style\.(background|backgroundColor|color|borderColor)\s*=\s*[\'"](#[0-9a-fA-F]{3,8})\b/u',
            $line,
            $m2,
            \PREG_OFFSET_CAPTURE
        )) {
            foreach ($m2[2] as $c) {
                $color       = (string)$c[0];
                $byteOffset  = (int)$c[1];
                if ($this->isVarFallbackAt($line, $byteOffset)) {
                    continue;
                }
                $candidates[] = ['color' => $color, 'col' => $byteOffset + 1];
            }
        }

        foreach ($candidates as $cand) {
            $violations[] = [
                'rule_id'  => 'AHC005',
                'severity' => 'warning',
                'path'     => $path,
                'line'     => $lineNo,
                'col'      => (int)$cand['col'],
                'snippet'  => $this->snippet($line),
                'message'  => '裸十六进制色号 ' . $cand['color'] . ' 应改用主题变量或 var(--token,#fallback) 回退。',
            ];
        }
    }

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc006(string $path, int $lineNo, string $line, array &$violations): void
    {
        if (!\preg_match('/throw\s+new\s+\\\\?(?:RuntimeException|InvalidArgumentException|LogicException)\s*\(\s*([\'"])/u', $line, $m, \PREG_OFFSET_CAPTURE)) {
            return;
        }
        // 豁免：__() 包裹
        if (\preg_match('/throw\s+new\s+\\\\?(?:RuntimeException|InvalidArgumentException|LogicException)\s*\(\s*__\s*\(/u', $line) === 1) {
            return;
        }
        // 豁免：全部大写常量（错误码）
        if (\preg_match('/throw\s+new\s+\\\\?(?:RuntimeException|InvalidArgumentException|LogicException)\s*\(\s*[A-Z_][A-Z0-9_]{2,}\s*[,)]/u', $line) === 1) {
            return;
        }
        $col = (int)$m[0][1] + 1;
        $violations[] = [
            'rule_id'  => 'AHC006',
            'severity' => 'error',
            'path'     => $path,
            'line'     => $lineNo,
            'col'      => $col,
            'snippet'  => $this->snippet($line),
            'message'  => 'throw new *Exception("字面量") 未经 __() 或错误码常量包裹。',
        ];
    }

    /**
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc007(string $path, int $lineNo, string $line, array &$violations): void
    {
        if (!\preg_match('/\bconsole\.(log|warn|error)\s*\(([^)]*)/u', $line, $m, \PREG_OFFSET_CAPTURE)) {
            return;
        }
        $argSection = (string)$m[2][0];
        if (!\preg_match('/\p{Han}/u', $argSection)) {
            return;
        }
        $col = (int)$m[0][1] + 1;
        $violations[] = [
            'rule_id'  => 'AHC007',
            'severity' => 'warning',
            'path'     => $path,
            'line'     => $lineNo,
            'col'      => $col,
            'snippet'  => $this->snippet($line),
            'message'  => 'console.' . (string)$m[1][0] . ' 中包含中文调试日志，发布前请清理。',
        ];
    }

    /**
     * @param list<string> $lines
     * @param list<array{
     *     rule_id:string,
     *     severity:string,
     *     path:string,
     *     line:int,
     *     col:int,
     *     snippet:string,
     *     message:string
     * }> $violations
     */
    private function applyRuleAhc008(string $path, array $lines, array &$violations): void
    {
        // 仅检查前 20 行（框架约定：.phtml 顶部不得 declare(strict_types=1)）
        $limit = \min(20, \count($lines));
        for ($i = 0; $i < $limit; $i++) {
            $line = (string)$lines[$i];
            if (\preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/u', $line, $m, \PREG_OFFSET_CAPTURE) === 1) {
                $col = (int)$m[0][1] + 1;
                $violations[] = [
                    'rule_id'  => 'AHC008',
                    'severity' => 'info',
                    'path'     => $path,
                    'line'     => $i + 1,
                    'col'      => $col,
                    'snippet'  => $this->snippet($line),
                    'message'  => '.phtml 模板顶部出现 declare(strict_types=1)，违反框架约定。',
                ];
                return;
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * 计算每行是否落在 <script> ... </script> 范围内（非自闭合）。
     *
     * @param list<string> $lines
     * @return array<int,bool>
     */
    private function computeScriptSpan(array $lines): array
    {
        $map      = [];
        $inScript = false;
        foreach ($lines as $idx => $line) {
            $segments = [];
            $cursor   = 0;
            $len      = \strlen($line);
            $curState = $inScript;
            while ($cursor < $len) {
                if (!$curState) {
                    $open = \stripos($line, '<script', $cursor);
                    if ($open === false) {
                        break;
                    }
                    $gt = \strpos($line, '>', $open);
                    if ($gt === false) {
                        // 打开标签跨行：简化视为开启
                        $curState = true;
                        break;
                    }
                    $cursor   = $gt + 1;
                    $curState = true;
                } else {
                    $close = \stripos($line, '</script', $cursor);
                    if ($close === false) {
                        break;
                    }
                    $gt = \strpos($line, '>', $close);
                    if ($gt === false) {
                        break;
                    }
                    $cursor   = $gt + 1;
                    $curState = false;
                }
            }
            // 该行是否“曾经”处于 script 里 —— 只要开头为真 或 中途开启并未关闭就算。
            $map[$idx] = $inScript || $this->lineContainsScriptBody($line, $inScript);
            $inScript  = $curState;
        }

        return $map;
    }

    private function lineContainsScriptBody(string $line, bool $startInScript): bool
    {
        if ($startInScript) {
            return true;
        }
        $open = \stripos($line, '<script');
        if ($open === false) {
            return false;
        }
        $gt = \strpos($line, '>', $open);
        if ($gt === false) {
            return true;
        }
        $after = \substr($line, $gt + 1);
        if ($after === '' || $after === false) {
            return false;
        }
        // 同行就关闭了：仍然计入（AHC002 对同行脚本内容生效）。
        return true;
    }

    private function isProtectedChinese(string $ctxJoin): bool
    {
        return \preg_match('/__\s*\(|@lang\b|<lang>|htmlspecialchars\s*\(\s*\(string\)\s*__\s*\(/u', $ctxJoin) === 1;
    }

    private function lineHasExemptMarker(string $line): bool
    {
        // 允许在同一行显式写注释 "// anti-hardcode: allow" 豁免。
        return \str_contains($line, 'anti-hardcode: allow');
    }

    private function isWhitelistedUrl(string $url): bool
    {
        $lower = \strtolower($url);
        $prefixes = [
            'http://www.w3.org/',
            'https://www.w3.org/',
            'http://schemas.',
            'https://schemas.',
        ];
        foreach ($prefixes as $p) {
            if (\str_starts_with($lower, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判定 offset 位置的 hex 颜色是否是 var(--token, #xxx) 的 fallback 形态。
     */
    private function isVarFallbackAt(string $line, int $byteOffset): bool
    {
        $prefix = \substr($line, 0, $byteOffset);
        $prefixLen = \strlen($prefix);
        if ($prefixLen === 0) {
            return false;
        }
        $varPos = \strrpos($prefix, 'var(');
        if ($varPos === false) {
            return false;
        }
        $between = \substr($prefix, $varPos, $prefixLen - $varPos);
        // var(... 后尚未关闭 ')' 且中间出现过逗号 → 认为是 fallback 片段。
        if (\str_contains($between, ')')) {
            return false;
        }
        return \str_contains($between, ',');
    }

    private function firstHanColumn(string $line): int
    {
        if (\preg_match('/\p{Han}/u', $line, $m, \PREG_OFFSET_CAPTURE) === 1) {
            return (int)$m[0][1] + 1;
        }
        return 1;
    }

    private function snippet(string $line): string
    {
        $trim = \rtrim($line);
        if (\strlen($trim) <= self::SNIPPET_MAX_LEN) {
            return $trim;
        }
        return \substr($trim, 0, self::SNIPPET_MAX_LEN) . '…';
    }

    /**
     * 将用户传入的路径列表展开为具体文件清单（去重、排序）。
     *
     * @param list<string> $absolutePaths
     * @return list<string>
     */
    private function resolvePathList(array $absolutePaths): array
    {
        $out = [];
        foreach ($absolutePaths as $p) {
            $p = (string)$p;
            if ($p === '') {
                continue;
            }
            if (\str_contains($p, '*')) {
                $globbed = \glob($p, \GLOB_BRACE) ?: [];
                foreach ($globbed as $g) {
                    $this->collectInto($g, $out);
                }
                continue;
            }
            $this->collectInto($p, $out);
        }

        $out = \array_values(\array_unique($out));
        \sort($out);

        return $out;
    }

    /**
     * @param list<string> $out
     */
    private function collectInto(string $path, array &$out): void
    {
        if (\is_file($path)) {
            $out[] = $path;
            return;
        }
        if (!\is_dir($path)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $fileInfo */
        foreach ($iter as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $ext = \strtolower($fileInfo->getExtension());
            if (!\in_array($ext, self::DEFAULT_EXTENSIONS, true)) {
                continue;
            }
            $out[] = $fileInfo->getPathname();
        }
    }
}
