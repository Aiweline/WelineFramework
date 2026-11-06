<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Spec;

/**
 * S13：主题 layout-critical 与主题皮肤（overlay 叶）的几何值冲突检测。
 *
 * # 为什么需要这条规则
 *
 * 默认主题的架构契约是「首帧几何由内联 layout-critical 钉住，皮肤到齐后不得改动它」。
 * 一旦同一个 选择器+属性 在两侧写成不同值，渲染就必然发生位移：critical 先画一帧，
 * 皮肤（preload+onload，非阻塞）随后覆盖 → 已通过的 CLS 又被打回来，而且没人看得见
 * 是「谁和谁不一致」。hanfu 就踩过：`.ca-brand` 在 critical 里是 168px、在 changan.css
 * 里是 218px；`.ca-nav` 的 gap 在 critical 里是 28px、皮肤里是 clamp(22px,3.25vw,54px)。
 *
 * 皮肤叶的权威清单取自主题自己的 `partials/head/theme-head-css-overlay.php`
 * （与 ThemeHeadChromeCssPack 同源，不再另立第二份名单）。
 *
 * # 豁免
 *
 * 有些断点阶梯本来就要由皮肤接管（首帧允许一次水平位移换更少的 critical 字节）。
 * 这类意图必须写进 critical 文件顶部的显式豁免，不允许「沉默漂移」：
 *
 *     /* @critical-skin-waivers: .ca-brand:width, .ca-nav:gap *\/
 *
 * 语法：`selector:property`，多个用逗号分隔；property 为空表示豁免该选择器全部属性。
 *
 * work_mode=theme_module_runtime
 */
final class ThemeCriticalSkinConflict
{
    public const RULE = 'S13';

    /** 皮肤叶清单（与 ThemeHeadChromeCssPack::OVERLAY_LIST_RELATIVE 同源） */
    public const MANIFEST_RELATIVE = 'partials/head/theme-head-css-overlay.php';

    private const CRITICAL_BASENAMES = [
        'layout-critical.css',
        'layout-critical-frontend.css',
        'layout-critical-backend.css',
    ];

    private const WAIVER_PATTERN = '/@critical-skin-waivers\s*:\s*([^\r\n*]+)/i';

    /** 扫描主题根时的最大递归深度（主题 frontend 目录不会更深） */
    private const MAX_DEPTH = 6;

    /**
     * 只比较会移动盒子的属性。颜色/背景/边框不进 S13：它们不改布局，报出来只是噪声。
     */
    private const GEOMETRY_PROPERTIES = [
        'display', 'position', 'width', 'height', 'min-width', 'max-width',
        'min-height', 'max-height', 'inline-size', 'block-size', 'aspect-ratio',
        'gap', 'row-gap', 'column-gap', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink',
        'flex-direction', 'flex-wrap', 'order', 'grid-template', 'grid-template-columns',
        'grid-template-rows', 'grid-auto-rows', 'grid-auto-columns', 'grid-auto-flow',
        'align-items', 'align-content', 'align-self', 'justify-content', 'justify-items',
        'justify-self', 'inset', 'top', 'right', 'bottom', 'left', 'line-height',
        'font-size', 'letter-spacing', 'word-spacing', 'columns', 'column-width',
    ];

    private const GEOMETRY_PREFIXES = ['margin', 'padding'];

    /**
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    public function collect(string $themeRoot, string $area = 'frontend'): array
    {
        $themeRoot = rtrim(str_replace('\\', '/', $themeRoot), '/');
        if ($themeRoot === '' || !is_dir($themeRoot)) {
            return [];
        }

        $criticalFiles = $this->criticalFiles($themeRoot);
        if ($criticalFiles === []) {
            return [];
        }

        $skinFiles = $this->skinFiles($themeRoot, $area);
        if ($skinFiles === []) {
            return [];
        }

        $waivers = $this->waivers($criticalFiles);

        $critical = $this->declarationsOf($criticalFiles);
        $skin = $this->declarationsOf($skinFiles);

        $violations = [];
        foreach ($critical as $key => $decl) {
            if (!isset($skin[$key])) {
                continue;
            }
            if (!self::isGeometryProperty($decl['property'])) {
                continue;
            }
            if (self::valuesEqual($decl['value'], $skin[$key]['value'])) {
                continue;
            }
            if ($this->waived($waivers, $decl['selector'], $decl['property'])) {
                continue;
            }
            $where = $decl['media'] === '' ? '' : ' ' . $decl['media'];
            $violations[] = [
                'rule' => self::RULE,
                'file' => $decl['file'],
                'line' => $decl['line'],
                'severity' => 'hard',
                'message' => sprintf(
                    '%s%s { %s: %s } conflicts with skin %s:%d { %s } — first paint pins one value and the skin replaces it, so the shift is guaranteed.',
                    $decl['selector'],
                    $where,
                    $decl['property'],
                    $decl['value'],
                    $skin[$key]['file_short'] ?? basename($skin[$key]['file']),
                    $skin[$key]['line'],
                    $skin[$key]['value']
                ),
                'hint' => 'Make layout-critical pin the skin\'s steady-state value (same expression, e.g. the same clamp()), or delete the pin and let the skin own it. If a breakpoint ladder is intentionally skin-owned, declare it: /* @critical-skin-waivers: ' . $decl['selector'] . ':' . $decl['property'] . ' */',
            ];
        }

        return $violations;
    }

    /**
     * @param list<string> $files
     * @return array<string, array{selector:string,property:string,media:string,value:string,line:int,file:string,file_short:string}>
     */
    private function declarationsOf(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $source = (string)@file_get_contents($file);
            if ($source === '') {
                continue;
            }
            foreach (self::declarations($source) as $key => $decl) {
                // 同一 选择器+属性 有多条声明时取最后一条：CSS 层叠就是后者胜，
                // 皮肤叶按 manifest 顺序拼接，后面的文件同样覆盖前面的。
                $decl['file'] = $file;
                $decl['file_short'] = basename($file);
                $out[$key] = $decl;
            }
        }

        return $out;
    }

    /**
     * 解析 CSS 的 选择器+属性 → 值 表。键 = media|selector|property。
     *
     * @return array<string, array{selector:string,property:string,media:string,value:string,line:int}>
     */
    public static function declarations(string $css): array
    {
        $masked = self::maskComments($css);
        $length = strlen($masked);
        $stack = [];
        $buffer = '';
        $line = 1;
        $out = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $masked[$i];
            if ($char === "\n") {
                $line++;
                $buffer .= $char;
                continue;
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
                if ($declaration === '' || str_starts_with($declaration, '@')) {
                    continue;
                }
                $colon = strpos($declaration, ':');
                if ($colon === false) {
                    continue;
                }
                $property = strtolower(trim(substr($declaration, 0, $colon)));
                if ($property === '') {
                    continue;
                }
                $selector = '';
                $media = [];
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    $prelude = $stack[$k];
                    if ($prelude === '') {
                        continue;
                    }
                    if ($prelude[0] === '@') {
                        if (stripos($prelude, '@media') === 0) {
                            $media[] = self::normalize($prelude);
                        }
                        continue;
                    }
                    if ($selector === '') {
                        $selector = self::normalize($prelude);
                    }
                }
                if ($selector === '') {
                    continue;
                }
                $key = implode(' && ', array_reverse($media)) . '|' . $selector . '|' . $property;
                $out[$key] = [
                    'selector' => $selector,
                    'property' => $property,
                    'media' => implode(' and ', array_reverse($media)),
                    'value' => self::normalize(substr($declaration, $colon + 1)),
                    'line' => $line,
                ];
                continue;
            }
            $buffer .= $char;
        }

        return $out;
    }

    /**
     * 块注释替换为等长空格（保留换行）→ 偏移与行号仍与原文对齐。
     */
    private static function maskComments(string $css): string
    {
        return (string)preg_replace_callback(
            '!/\*.*?\*/!s',
            static function (array $m): string {
                return (string)preg_replace('/[^\r\n]/', ' ', $m[0]);
            },
            $css
        );
    }

    private static function normalize(string $value): string
    {
        $value = (string)preg_replace('/\s+/', ' ', trim($value));
        $value = str_replace([' ,', ', ', ' : '], [' ,', ',', ':'], $value);

        return self::stripVarFallbacks($value);
    }

    /**
     * `var(--x, 22px)` → `var(--x)`。
     *
     * critical 里的回退值是设计意图：tokens 包尚未到齐时用默认值顶住首帧，
     * 所以「critical 带回退 vs 皮肤只写 var()」不是漂移，不应报冲突。
     * 真正的漂移是回退值与 token 值不一致，那属于 token 层，不在 S13 范围。
     */
    private static function stripVarFallbacks(string $value): string
    {
        $result = $value;
        $offset = 0;
        $length = strlen($result);
        while (($start = strpos($result, 'var(', $offset)) !== false) {
            $depth = 0;
            $close = $length;
            for ($i = $start + 4; $i < $length; $i++) {
                if ($result[$i] === '(') {
                    $depth++;
                    continue;
                }
                if ($result[$i] === ')') {
                    if ($depth === 0) {
                        $close = $i;
                        break;
                    }
                    $depth--;
                }
            }
            if ($close >= $length) {
                break;
            }
            $inner = substr($result, $start + 4, $close - $start - 4);
            $comma = strpos($inner, ',');
            $name = trim($comma === false ? $inner : substr($inner, 0, $comma));
            $trimmed = 'var(' . $name . ')';
            if ($trimmed === substr($result, $start, $close - $start + 1)) {
                $offset = $start + 4;
                $length = strlen($result);
                continue;
            }
            $result = substr($result, 0, $start) . $trimmed . substr($result, $close + 1);
            $offset = $start + strlen($trimmed);
            $length = strlen($result);
        }

        return $result;
    }

    private static function isGeometryProperty(string $property): bool
    {
        if (in_array($property, self::GEOMETRY_PROPERTIES, true)) {
            return true;
        }
        foreach (self::GEOMETRY_PREFIXES as $prefix) {
            if (str_starts_with($property, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function valuesEqual(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $na = self::numericValue($a);
        $nb = self::numericValue($b);
        if ($na !== null && $nb !== null) {
            $tolerance = max(abs($na), abs($nb)) * 0.01;

            return abs($na - $nb) <= $tolerance;
        }

        return false;
    }

    private static function numericValue(string $value): ?float
    {
        if (preg_match('/^(-?[0-9.]+)\s*\/\s*([0-9.]+)$/', $value, $m) === 1) {
            $divisor = (float)$m[2];

            return $divisor === 0.0 ? null : (float)$m[1] / $divisor;
        }
        if (preg_match('/^-?[0-9.]+$/', $value) === 1) {
            return (float)$value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function criticalFiles(string $themeRoot): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeRoot, \FilesystemIterator::SKIP_DOTS)
        );
        $rootDepth = substr_count($themeRoot, '/');
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (substr_count($path, '/') - $rootDepth > self::MAX_DEPTH) {
                continue;
            }
            if (!in_array(strtolower($file->getBasename()), self::CRITICAL_BASENAMES, true)) {
                continue;
            }
            $found[] = $path;
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function skinFiles(string $themeRoot, string $area): array
    {
        $manifest = $themeRoot . '/' . self::MANIFEST_RELATIVE;
        if (!is_file($manifest)) {
            return [];
        }
        try {
            /** @var mixed $list */
            $list = include $manifest;
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($list)) {
            return [];
        }

        $leaves = [];
        if (isset($list['common']) || isset($list['layouts'])) {
            foreach ((array)($list['common'] ?? []) as $item) {
                if (is_string($item)) {
                    $leaves[] = $item;
                }
            }
            foreach ((array)($list['layouts'] ?? []) as $items) {
                foreach ((array)$items as $item) {
                    if (is_string($item)) {
                        $leaves[] = $item;
                    }
                }
            }
        } else {
            foreach ($list as $item) {
                if (is_string($item)) {
                    $leaves[] = $item;
                }
            }
        }

        $prefix = 'theme/' . $area . '/';
        $files = [];
        foreach (array_unique($leaves) as $leaf) {
            $leaf = ltrim(str_replace('\\', '/', trim($leaf)), '/');
            if ($leaf === '' || str_contains($leaf, '..')) {
                continue;
            }
            if (!str_starts_with($leaf, $prefix)) {
                continue;
            }
            $absolute = $themeRoot . '/' . substr($leaf, strlen($prefix));
            if (!is_file($absolute)) {
                continue;
            }
            if (substr($absolute, -4) !== '.css') {
                continue;
            }
            $files[] = $absolute;
        }

        return $files;
    }

    /**
     * @param list<string> $criticalFiles
     * @return array<string, true>  selector|property 与 selector|* 的组合
     */
    private function waivers(array $criticalFiles): array
    {
        $waivers = [];
        foreach ($criticalFiles as $file) {
            $source = (string)@file_get_contents($file);
            if ($source === '' || preg_match(self::WAIVER_PATTERN, $source, $m) !== 1) {
                continue;
            }
            foreach (explode(',', $m[1]) as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                $parts = explode(':', $entry, 2);
                $selector = self::normalize((string)$parts[0]);
                if ($selector === '') {
                    continue;
                }
                $property = isset($parts[1]) ? strtolower(trim($parts[1])) : '';
                $waivers[$selector . '|' . $property] = true;
            }
        }

        return $waivers;
    }

    /**
     * @param array<string, true> $waivers
     */
    private function waived(array $waivers, string $selector, string $property): bool
    {
        return isset($waivers[$selector . '|' . $property]) || isset($waivers[$selector . '|']);
    }
}
