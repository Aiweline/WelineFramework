<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Code3d;

use Weline\Framework\Module\Manifest\ModuleManifestReader;

/**
 * 模块依赖声明解析。
 *
 * 权威来源是 etc/module.php 的 requires/optional：Register::resolveAuthoritativeModuleMetadata
 * 在 manifest 存在时会忽略 register.php 的依赖数组。只有缺少 manifest 的模块才回退
 * register.php 第 6 个参数。requires 与 optional 分开收集，避免把“可选集成”误当硬依赖。
 */
final class ModuleDependencyMap
{
    /** @return array<string, list<string>> 模块名（如 Weline_Theme）=> requires + optional（兼容旧调用） */
    public function load(string $repoRoot): array
    {
        $out = [];
        foreach ($this->loadDetailed($repoRoot) as $name => $deps) {
            $out[$name] = array_values(array_unique(array_merge($deps['requires'], $deps['optional'])));
        }
        return $out;
    }

    /**
     * @return array<string, array{requires: list<string>, optional: list<string>}>
     */
    public function loadDetailed(string $repoRoot): array
    {
        $out = [];
        $pattern = rtrim($repoRoot, '/') . '/app/code/*/*/register.php';
        foreach (glob($pattern) ?: [] as $file) {
            $modulePath = \dirname($file);
            $manifestPath = $modulePath . '/etc/module.php';
            if (\is_file($manifestPath)) {
                try {
                    $manifest = (new ModuleManifestReader())->read($modulePath);
                    $out[$manifest->name] = [
                        'requires' => \array_keys($manifest->requires),
                        'optional' => \array_keys($manifest->optional),
                    ];
                    continue;
                } catch (\Throwable) {
                    /* manifest 损坏时回退 register.php，保持工具可用 */
                }
            }

            $name = null;
            $deps = [];
            $this->parse($file, $name, $deps);
            if ($name !== null) {
                $out[$name] = ['requires' => $deps, 'optional' => []];
            }
        }
        return $out;
    }

    private function parse(string $file, ?string &$name, array &$deps): void
    {
        $code = @file_get_contents($file);
        if ($code === false || !str_contains($code, 'Register::register')) {
            return;
        }
        $tokens = @token_get_all($code);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || strtolower($t[1]) !== 'register') {
                continue;
            }
            /* 形如 Register::register( */
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }
            $args = $this->readArgs($tokens, $j);
            if (count($args) < 6) {
                continue;
            }
            $kind = $this->firstString($tokens, $args[0]);
            if ($kind !== 'MODULE') {
                continue;
            }
            $module = $this->firstString($tokens, $args[1]);
            if ($module === null) {
                continue;
            }
            $name = $module;
            $deps = $this->arrayStrings($tokens, $args[5]);
            return;
        }
    }

    /** 返回括号内每个顶层参数 [start, end] token 下标区间 */
    private function readArgs(array $tokens, int $open): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
                continue;
            }
            if ($t === ')' || $t === ']' || $t === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i - 1;
                    if ($end >= $start) {
                        $args[] = [$start, $end];
                    }
                    break;
                }
                continue;
            }
            if ($t === ',' && $depth === 1) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        return $args;
    }

    /** 参数区间内的第一个常量子串（用于 Register::MODULE / 模块名） */
    private function firstString(array $tokens, array $range): ?string
    {
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                return trim($t[1], "'\"");
            }
            if (is_array($t) && $t[0] === T_STRING && $t[1] === 'MODULE') {
                return 'MODULE';
            }
        }
        return null;
    }

    /** 参数区间若是数组，取其中全部字符串字面量 */
    private function arrayStrings(array $tokens, array $range): array
    {
        $out = [];
        $hasArray = false;
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $t = $tokens[$i];
            if ($t === '[' || (is_array($t) && $t[0] === T_ARRAY)) {
                $hasArray = true;
                continue;
            }
            if ($hasArray && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = trim($t[1], "'\"");
            }
        }
        return array_values(array_unique($out));
    }
}
