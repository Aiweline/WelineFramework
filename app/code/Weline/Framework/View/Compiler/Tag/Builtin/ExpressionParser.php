<?php

declare(strict_types=1);

/**
 * 表达式解析（变量路径 → PHP 表达式）。
 *
 * 逐条复刻 `CodeGenerator::parseVarExpression()` / `parseVariablePath()` /
 * `parseSingleVariablePath()`：
 * - `content` → `($content ?? $this->getData('content'))`
 * - `meta.showHeader` → `(($meta ?? $this->getData('meta') ?? [])['showHeader'] ?? null)`
 * - `$user.name` → `($user ?? [])['name']`
 * - `attribute.type_id|0` → `((...['type_id'] ?? null) ?? 0)`（单竖线是默认值回退）
 *
 * 为什么照抄而不是「写个更好的」：这些字符串会直接进入编译产物，语义必须与旧编译器
 * 逐字节一致，否则 `if` 的条件在边界取值上会有细微差异（Undefined variable、
 * null 数组访问等），而这种差异只会在运行时炸。等 P2 收口、旧生成器删除后再统一优化。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

final class ExpressionParser
{
    private const RESERVED = [
        'true', 'false', 'null', 'and', 'or', 'xor', 'not',
        'empty', 'isset', 'array', 'new', 'instanceof',
    ];

    /**
     * `{{ ... }}` 的路径语义（旧 `CodeGenerator::parseVariablePath()`）。
     *
     * 与 `parse()` 的区别：这里**只有一个变量路径 + 可选的默认值**，不做复合表达式替换。
     * 旧编译器对 `{{}}` 走的就是这条路径，所以 `{{x||y}}` 在旧产物里是
     * `($x||y ?? $this->getData('x||y'))`；为了逐字节一致，这里必须照抄（含它的怪癖）。
     *
     * 单竖线 `|` 是「回退值」，`||` 是逻辑或，不能被拆开。
     * 回退值三种写法：字面量（`0`/`"str"`/`true`）、`$var`、以及 `default:<value>` 过滤器；
     * 其余按变量路径解析（`{{group.local|group.name}}`）。
     */
    public function path(string $expr): string
    {
        $expr = \trim($expr);
        if (\preg_match('/(?<!\|)\|(?!\|)/', $expr) !== 1) {
            return $this->singlePath($expr);
        }

        $parts = \array_map('trim', \preg_split('/(?<!\|)\|(?!\|)/', $expr, 2));
        $primary = $this->singlePath($parts[0] ?? '');
        $fallback = $parts[1] ?? '';

        if (\str_starts_with($fallback, 'default:')) {
            $defaultValue = \trim(\substr($fallback, 8));
            if ($defaultValue === '') {
                $defaultValue = "''";
            }
            if (\preg_match('/^(["\']).*\1$/', $defaultValue) === 1
                || \is_numeric($defaultValue)
                || \in_array(\strtolower($defaultValue), ['true', 'false', 'null'], true)
                || \str_starts_with($defaultValue, '$')) {
                return '(' . $primary . ' ?? ' . $defaultValue . ')';
            }

            return '(' . $primary . ' ?? ' . $this->singlePath($defaultValue) . ')';
        }

        return '(' . $primary . ' ?? ' . $this->singlePath($fallback) . ')';
    }

    public function parse(string $expr): string
    {
        if (\str_contains($expr, '<?php') || \str_contains($expr, '__PHP_')) {
            return $expr;
        }

        $trimmed = \trim($expr);
        if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $trimmed) === 1) {
            return $this->singlePath($trimmed);
        }

        // 复合表达式里的点分路径（如 user.role === 'admin'）
        $expr = (string)\preg_replace_callback(
            '/(?<![\$\'"\[])\b([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)+)\b/',
            fn (array $matches): string => $this->singlePath($matches[1]),
            $expr,
        );

        // 复合表达式里的裸标识（排除已写 $、引号/数组键、函数名）
        $expr = (string)\preg_replace_callback(
            '/(?<![\$\'"\[])\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?![\'"\]\.(])/',
            function (array $matches): string {
                $var = $matches[1];
                if (\in_array(\strtolower($var), self::RESERVED, true)) {
                    return $var;
                }
                if (\is_numeric($var)) {
                    return $var;
                }

                return $this->singlePath($var);
            },
            $expr,
        );

        return $expr;
    }

    public function singlePath(string $varPath): string
    {
        $varPath = \trim($varPath);

        if (\str_starts_with($varPath, "'") || \str_starts_with($varPath, '"')) {
            return $varPath;
        }
        if (\is_numeric($varPath)) {
            return $varPath;
        }
        if (\in_array(\strtolower($varPath), ['true', 'false', 'null'], true)) {
            return $varPath;
        }

        if (\str_starts_with($varPath, '$')) {
            if (\str_contains($varPath, '.')) {
                $parts = \explode('.', $varPath);
                $result = \array_shift($parts);
                foreach ($parts as $part) {
                    $result = '(' . $result . ' ?? [])[\'' . $part . '\']';
                }

                return (string)$result;
            }

            return $varPath;
        }

        // 超全局变量：_SERVER.REQUEST_URI
        if (\preg_match('/^_([A-Z]+)\.(.+)$/', $varPath, $matches) === 1) {
            return '$_' . $matches[1] . '[\'' . $matches[2] . '\']';
        }

        if (\str_contains($varPath, '.')) {
            $parts = \explode('.', $varPath);
            $first = \array_shift($parts);
            $result = '($' . $first . ' ?? $this->getData(\'' . $first . '\') ?? [])';
            foreach ($parts as $part) {
                $result = '(' . $result . '[\'' . $part . '\'] ?? null)';
            }

            return $result;
        }

        return '($' . $varPath . ' ?? $this->getData(\'' . $varPath . '\'))';
    }
}
