<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * 脱域消费契约（Detached-surface token scope contracts）。
 *
 * 锁住本轮审计定型的三类缺陷：
 * 1) 死引用：var(--T) 在全库无同名声明且无兜底（computed-time 失效 → 透明/无边框）。
 * 2) 脱域裸消费：裸 var(--T)（无兜底）只允许消费「同区域根作用域可解」的 token；
 *    仅子树作用域声明的 token 必须带兜底或登记豁免（运行时 JS/widget 注入类）。
 *    —— #cs-bind-modal 透明事故即此类：--cs-* 声明在 .customer-service-widget 子树，
 *       JS 把弹窗 append 到 body，裸 var(--cs-surface) 失效。
 * 3) 提取物结构完整：店面/后台 CSS 花括号配平，且 `/* from X.phtml *\/` 标记
 *    不得落在未闭合规则体内（extends/event 后台样式拼接事故：末规则缺 `}` 吞掉整段）。
 */
final class DetachedSurfaceTokenScopeContractsTest extends TestCase
{
    private const ROOTS = ['app/code/Weline', 'app/design/Weline', 'app/design/WeShop', 'app/design/Amayum'];

    /** 运行时注入 token（JS setProperty / widget 参数 / 工具类 API）：CSS 本就不声明。 */
    private const EXEMPT_EXACT = [
        'i' => 1, 'token' => 1, 'product-aspect' => 1, 'variant-swatch' => 1, 'w-bar' => 1,
        'w-language-select-anchor-width' => 1, 'w-currency-select-anchor-width' => 1,
        'w-api-sidebar-size' => 1, 'w-api-test-size' => 1, 'w-spectrum-r' => 1,
        'w-store-music-level' => 1, 'weline-frame-ratio' => 1, 'weline-frame-min-block' => 1,
        'w-param-preset-color' => 1, 'w-datatable-column-min' => 1, 'spacing-' => 1,
    ];
    private const EXEMPT_PREFIXES = [
        'w-floating-', 'w-builder-', 'w-store-music-', 'hanfu-floating-', 'wc-',
        'w-param-', 'w-datatable-', 'compare-', 'dc-compare-', 'dc-floor-',
        'w-api-', 'w-spectrum-', 'weline-frame-', 'token-bp-', 'pic-detail-hero-',
        'ck-sample-', 'bs-',
    ];

    /** @var array<string,list<array{file:string,root:bool,area:string}>>|null */
    private static ?array $declIndex = null;
    /** @var array<string,true>|null */
    private static ?array $jsSetIndex = null;
    /** @var array<string,string>|null rel => abs */
    private static ?array $consumerFiles = null;

    private static function isExcludedPath(string $rel): bool
    {
        foreach (['/view/tpl/', '/generated/', '/libs/', '/lib/', '/node_modules/', '/prototype', '/evidence/', '/dev/backup/'] as $x) {
            if (str_contains($rel, $x)) {
                return true;
            }
        }
        return false;
    }

    private static function areaOf(string $rel): string
    {
        if (str_contains($rel, '/view/ui/') || str_contains($rel, '/statics/ui/')) {
            return 'shared';
        }
        if (str_contains($rel, '/backend/')) {
            return 'backend';
        }
        if (preg_match('/backend|admin|editor/i', basename($rel)) === 1) {
            return 'backend';
        }
        return 'frontend';
    }

    private static function stripComments(string $css): string
    {
        return (string)preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * 极简 CSS 解析：返回 [{selectors, at, body}]，容忍 EOF 未闭合（与浏览器一致）。
     * @return list<array{selectors:list<string>,at:list<string>,body:string}>
     */
    private static function parseRules(string $css): array
    {
        $css = self::stripComments($css);
        $rules = [];
        self::parseInto($css, 0, strlen($css), [], $rules);
        return $rules;
    }

    private static function parseInto(string $css, int $i, int $end, array $atChain, array &$rules): void
    {
        $hdr = '';
        while ($i < $end) {
            $c = $css[$i];
            if ($c === '{') {
                $h = trim($hdr);
                $hdr = '';
                if (($x = strrpos($h, '}')) !== false) {
                    $h = trim(substr($h, $x + 1));
                }
                if ($h === '') {
                    $i = self::skipBlock($css, $i, $end);
                    continue;
                }
                $close = self::findBlockEnd($css, $i, $end);
                if ($close === false) {
                    $close = $end;
                }
                if ($h[0] === '@') {
                    $sub = [];
                    self::parseInto($css, $i + 1, $close, array_merge($atChain, [$h]), $sub);
                    $rules = array_merge($rules, $sub);
                } else {
                    $sels = array_values(array_filter(array_map('trim', explode(',', $h))));
                    if ($sels) {
                        $rules[] = ['selectors' => $sels, 'at' => $atChain, 'body' => substr($css, $i + 1, $close - $i - 1)];
                    }
                }
                $i = min($close + 1, $end);
                continue;
            }
            if ($c === ';' || $c === '}') {
                $hdr = '';
                $i++;
                continue;
            }
            $hdr .= $c;
            $i++;
        }
    }

    private static function findBlockEnd(string $css, int $i, int $end): int|false
    {
        $depth = 0;
        $q = false;
        for ($j = $i; $j < $end; $j++) {
            $c = $css[$j];
            if ($q !== false) {
                if ($c === $q) {
                    $q = false;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $q = $c;
                continue;
            }
            if ($c === '{') {
                $depth++;
            }
            if ($c === '}' && --$depth === 0) {
                return $j;
            }
        }
        return false;
    }

    private static function skipBlock(string $css, int $i, int $end): int
    {
        $close = self::findBlockEnd($css, $i, $end);
        return $close === false ? $end : $close + 1;
    }

    private static function isRootSafeSelector(string $sel): bool
    {
        return (bool)preg_match('/^(:root|html|body|html\s+body)\s*$/', trim($sel));
    }

    /** 提取 var() 参数：(name, hasFallback)；只识别括号配平内的顶层逗号。 */
    private static function consumeVars(string $body): array
    {
        $out = [];
        $pos = 0;
        $blen = strlen($body);
        while (($p = strpos($body, 'var(--', $pos)) !== false) {
            $depth = 0;
            $arg = '';
            $j = $p + 3;
            for (; $j < $blen; $j++) {
                $c = $body[$j];
                if ($c === '(') {
                    $depth++;
                    if ($depth > 1) {
                        $arg .= $c;
                    }
                    continue;
                }
                if ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    $arg .= $c;
                    continue;
                }
                $arg .= $c;
            }
            if ($j >= $blen) {
                break;
            }
            if (preg_match('/^--([\w-]+)/', trim($arg), $mm)) {
                $d2 = 0;
                $hasFb = false;
                for ($k = 0, $al = strlen($arg); $k < $al; $k++) {
                    $c = $arg[$k];
                    if ($c === '(') {
                        $d2++;
                    } elseif ($c === ')') {
                        $d2--;
                    } elseif ($c === ',' && $d2 === 0) {
                        $hasFb = true;
                        break;
                    }
                }
                $out[] = [$mm[1], $hasFb, $p];
            }
            $pos = $j + 1;
        }
        return $out;
    }

    private static function buildIndex(): void
    {
        if (self::$declIndex !== null) {
            return;
        }
        $decl = [];
        $jsSet = [];
        $consumers = [];
        foreach (self::ROOTS as $rel) {
            $abs = BP . $rel;
            if (!is_dir($abs)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = $f->getPathname();
                $r = substr($p, strlen(BP));
                if (self::isExcludedPath($r)) {
                    continue;
                }
                if (str_ends_with($p, '.css') && !str_ends_with($p, '.min.css')) {
                    $consumers[$r] = $p;
                    foreach (self::parseRules((string)file_get_contents($p)) as $rule) {
                        if (preg_match_all('/--([A-Za-z_][\w-]*)\s*:\s*([^;}]+)/', $rule['body'], $m, PREG_SET_ORDER)) {
                            $root = false;
                            foreach ($rule['selectors'] as $s) {
                                if (self::isRootSafeSelector($s)) {
                                    $root = true;
                                    break;
                                }
                            }
                            foreach ($m as $mm) {
                                $decl[$mm[1]][] = ['file' => $r, 'root' => $root, 'area' => self::areaOf($r)];
                            }
                        }
                    }
                } elseif (str_ends_with($p, '.phtml')) {
                    $consumers[$r] = $p;
                    $src = (string)file_get_contents($p);
                    // <style> 块：按 CSS 规则解析，:root 声明算根声明
                    if (preg_match_all('~<style[^>]*>(.*?)</style>~si', $src, $mm)) {
                        foreach ($mm[1] as $chunk) {
                            // PHP 段会污染选择器/声明解析，剥为空（声明值允许为空）
                            $chunk = (string)preg_replace('~<\?.*?\?>~s', '', $chunk);
                            foreach (self::parseRules($chunk) as $rule) {
                                if (preg_match_all('/--([A-Za-z_][\w-]*)\s*:\s*([^;}]*)/', $rule['body'], $m2, PREG_SET_ORDER)) {
                                    $root = false;
                                    foreach ($rule['selectors'] as $s) {
                                        if (self::isRootSafeSelector($s)) {
                                            $root = true;
                                            break;
                                        }
                                    }
                                    foreach ($m2 as $mm2) {
                                        $decl[$mm2[1]][] = ['file' => $r, 'root' => $root, 'area' => self::areaOf($r)];
                                    }
                                }
                            }
                        }
                    }
                    // style="--x: ..." 内联声明 / <script> setProperty：运行时注入 token
                    if (preg_match_all('~style=([\'"])(.*?)\1~s', $src, $mm)) {
                        foreach ($mm[2] as $attr) {
                            if (preg_match_all('/--([\w-]+)\s*:/', $attr, $m2)) {
                                foreach ($m2[1] as $n) {
                                    $jsSet[$n] = true;
                                }
                            }
                        }
                    }
                    if (preg_match_all("~setProperty\(\s*['\"]--([\w-]+)~", $src, $m2)) {
                        foreach ($m2[1] as $n) {
                            $jsSet[$n] = true;
                        }
                    }
                } elseif (str_ends_with($p, '.js') && !str_ends_with($p, '.min.js')) {
                    $s = (string)file_get_contents($p);
                    if (preg_match_all("/setProperty\\(\\s*['\"]--([\\w-]+)/", $s, $m)) {
                        foreach ($m[1] as $n) {
                            $jsSet[$n] = true;
                        }
                    }
                    if (str_contains($s, 'cssText') || str_contains($s, '<style') || str_contains($s, 'innerHTML')) {
                        if (preg_match_all('/--([\w-]+)\s*:/', $s, $m)) {
                            foreach ($m[1] as $n) {
                                $jsSet[$n] = true;
                            }
                        }
                    }
                }
            }
        }
        self::$declIndex = $decl;
        self::$jsSetIndex = $jsSet;
        self::$consumerFiles = $consumers;
    }

    private static function isExempt(string $tok): bool
    {
        self::buildIndex();
        if (isset(self::EXEMPT_EXACT[$tok]) || isset(self::$jsSetIndex[$tok])) {
            return true;
        }
        foreach (self::EXEMPT_PREFIXES as $p) {
            if (str_starts_with($tok, $p)) {
                return true;
            }
        }
        return false;
    }

    private static function hasVisibleRootDecl(string $tok, string $area): bool
    {
        self::buildIndex();
        $visible = match ($area) {
            'frontend' => ['frontend', 'shared'],
            'backend' => ['backend', 'shared'],
            default => ['frontend', 'backend', 'shared'],
        };
        foreach (self::$declIndex[$tok] ?? [] as $d) {
            if ($d['root'] && in_array($d['area'], $visible, true)) {
                return true;
            }
        }
        return false;
    }

    /** 消费文件中的全部裸 var()（无兜底）：token => [file:line, ...] */
    private static function bareConsumers(): array
    {
        self::buildIndex();
        $out = [];
        foreach (self::$consumerFiles as $rel => $abs) {
            $src = (string)file_get_contents($abs);
            if (!str_contains($src, 'var(--')) {
                continue;
            }
            if (str_ends_with($rel, '.phtml')) {
                // phtml：整块算消费面（含 <style> 与内联 style=""）；剥 /* */ 注释避免注释内示例 var()
                foreach (self::consumeVars(self::stripComments($src)) as [$name, $hasFb, $pos]) {
                    if ($hasFb) {
                        continue;
                    }
                    $out[$name][] = $rel . ':' . (substr_count(substr($src, 0, $pos), "\n") + 1);
                }
                continue;
            }
            // css：按规则消费——[style*="--x:"] 工具类守卫选择器内的 var(--x) 由 inline style 供值，属 API 语义
            $rules = self::parseRules($src);
            // 同文件作用域声明索引：声明与消费同处一个作用域块（同 at 链、选择器集合覆盖）
            // 时，声明随元素自身走、不可能脱域（如刻意留裸的 pin/poison rebind 晶格）。
            $fileDecls = [];
            foreach ($rules as $dr) {
                if (preg_match_all('/--([A-Za-z_][\w-]*)\s*:\s*([^;}]+)/', $dr['body'], $dm)) {
                    foreach ($dm[1] as $dn) {
                        $fileDecls[$dn][] = $dr;
                    }
                }
            }
            foreach ($rules as $rule) {
                $styleApi = [];
                foreach ($rule['selectors'] as $s) {
                    if (preg_match_all('/style\*="--([\w-]+):/', $s, $mm)) {
                        foreach ($mm[1] as $n) {
                            $styleApi[$n] = true;
                        }
                    }
                }
                foreach (self::consumeVars($rule['body']) as [$name, $hasFb, $pos]) {
                    if ($hasFb || isset($styleApi[$name])) {
                        continue;
                    }
                    $covered = false;
                    foreach ($fileDecls[$name] ?? [] as $dr) {
                        if ($dr['at'] == $rule['at'] && !array_diff($rule['selectors'], $dr['selectors'])) {
                            $covered = true;
                            break;
                        }
                    }
                    if ($covered) {
                        continue;
                    }
                    $out[$name][] = $rel . ' ' . $rule['selectors'][0];
                }
            }
        }
        return $out;
    }

    public function testNoDeadTokenReferences(): void
    {
        self::buildIndex();
        $dead = [];
        foreach (self::bareConsumers() as $tok => $sites) {
            if (isset(self::$declIndex[$tok]) || self::isExempt($tok)) {
                continue;
            }
            $dead[$tok] = $sites;
        }
        self::assertSame(
            [],
            $dead,
            "var() 引用了全库无声明的 token 且无兜底（computed-time 失效）：\n"
            . implode("\n", array_map(static fn($t, $s) => "$t <- " . implode(', ', array_slice($s, 0, 5)), array_keys($dead), $dead))
        );
    }

    public function testBareVarMustHaveVisibleRootDeclOrFallback(): void
    {
        self::buildIndex();
        $violations = [];
        foreach (self::bareConsumers() as $tok => $sites) {
            if (self::isExempt($tok)) {
                continue;
            }
            $offenders = [];
            foreach ($sites as $site) {
                $rel = strstr($site, ' ', true);
                if ($rel === false) {
                    $rel = strtok($site, ':');
                }
                if (!self::hasVisibleRootDecl($tok, self::areaOf($rel))) {
                    $offenders[] = $site;
                }
            }
            if ($offenders) {
                $violations[$tok] = $offenders;
            }
        }
        self::assertSame(
            [],
            $violations,
            "裸 var(--T) 消费的 token 在同区域根作用域不可解且无兜底（脱域即透明/失效类缺陷）：\n"
            . implode("\n", array_map(static fn($t, $s) => "$t <- " . implode(', ', array_slice($s, 0, 8)), array_keys($violations), $violations))
        );
    }

    public function testCssFilesAreBraceBalanced(): void
    {
        self::buildIndex();
        $bad = [];
        foreach (self::$consumerFiles as $rel => $abs) {
            if (!str_ends_with($rel, '.css')) {
                continue;
            }
            $src = (string)file_get_contents($abs);
            $depth = 0;
            $underflow = 0;
            $len = strlen($src);
            $i = 0;
            while ($i < $len) {
                $c = $src[$i];
                if ($c === '/' && ($src[$i + 1] ?? '') === '*') {
                    $j = $i + 2;
                    while ($j < $len - 1 && !($src[$j] === '*' && $src[$j + 1] === '/')) {
                        $j++;
                    }
                    $i = $j + 2;
                    continue;
                }
                if ($c === '"' || $c === "'") {
                    $q = $c;
                    $i++;
                    while ($i < $len && $src[$i] !== $q) {
                        if ($src[$i] === '\\') {
                            $i++;
                        }
                        $i++;
                    }
                    $i++;
                    continue;
                }
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth < 0) {
                        $underflow++;
                        $depth = 0;
                    }
                }
                $i++;
            }
            if ($depth !== 0 || $underflow !== 0) {
                $bad[] = "$rel (depth=$depth underflow=$underflow)";
            }
        }
        self::assertSame([], $bad, "CSS 花括号不配平（提取拼接缺 }/多 } 类缺陷）：\n" . implode("\n", $bad));
    }

    public function testFromMarkersNeverInsideUnclosedRule(): void
    {
        self::buildIndex();
        $bad = [];
        foreach (self::$consumerFiles as $rel => $abs) {
            if (!str_ends_with($rel, '.css')) {
                continue;
            }
            $lines = explode("\n", (string)file_get_contents($abs));
            foreach ($lines as $n => $line) {
                if (!str_contains($line, '/* from')) {
                    continue;
                }
                // 上一非空行若以 ; 结尾 => 标记落在未闭合规则体内（末规则缺 }）
                for ($k = $n - 1; $k >= 0; $k--) {
                    $t = trim($lines[$k]);
                    if ($t === '') {
                        continue;
                    }
                    if (str_ends_with($t, ';')) {
                        $bad[] = $rel . ':' . ($n + 1);
                    }
                    break;
                }
            }
        }
        self::assertSame([], $bad, "`/* from */` 标记落在未闭合规则体内（提取拼接未闭合末规则）：\n" . implode("\n", $bad));
    }
}
