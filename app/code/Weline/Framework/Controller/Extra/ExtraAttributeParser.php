<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

/**
 * `@Extra` 注解值的**唯一**类型转换口径。
 *
 * 注解值来自 docblock 文本，永远是字符串。直接用 PHP 强转会静默改变语义：
 * `(bool)'false' === true`（非空字符串恒真），于是 `enabled=false` 被当成「允许缓存」。
 * 因此布尔/整数/列表的解析只能有一处实现，`ExtraCollector::parseExtraDoc()`（注解文本解析）
 * 与 `FpcExtraType::normalize()`（类型提供者归一）都必须调用本类，禁止各自再写一份。
 *
 * 布尔口径：
 * - 真：`true` / `1` / `yes` / `on` / `y` / 非零数字
 * - 假：`false` / `0` / `no` / `off` / `n` / 空串 / `null` / 非标量
 * - 其余非空字符串按历史 `(bool)` 语义判真（例如 `enabled=ture` 仍是真，不改变既有行为）
 */
final class ExtraAttributeParser
{
    /** @var list<string> */
    private const TRUTHY_TOKENS = ['1', 'true', 'yes', 'on', 'y'];

    /** @var list<string> */
    private const FALSEY_TOKENS = ['0', 'false', 'no', 'off', 'n', ''];

    public static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (\is_int($value) || \is_float($value)) {
            return $value != 0;
        }
        if (!\is_string($value)) {
            // 数组/对象不是合法注解值；构件侧车时一个坏注解不该阻断 upgrade，故 fail-closed。
            return false;
        }

        $token = \strtolower(\trim($value));
        if (\in_array($token, self::FALSEY_TOKENS, true)) {
            return false;
        }
        if (\in_array($token, self::TRUTHY_TOKENS, true)) {
            return true;
        }
        if (\is_numeric($token)) {
            return (float)$token != 0.0;
        }

        return $token !== '';
    }

    public static function toInt(mixed $value, int $default = 0): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (\is_float($value)) {
            return (int)$value;
        }
        if (!\is_string($value)) {
            return $default;
        }

        $raw = \trim($value);
        if ($raw === '' || !\is_numeric($raw)) {
            return $default;
        }

        return (int)$raw;
    }

    /**
     * 逗号分隔字符串或数组 → 去空、去首尾空白的字符串列表。
     *
     * 只对**字符串**入参做逗号切分；数组入参逐项处理，
     * 这样 `public_path_patterns` 里的正则量词 `{1,3}` 不会被逗号误拆成两条 pattern。
     *
     * @return list<string>
     */
    public static function toStringList(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \explode(',', $value);
        } elseif (!\is_array($value)) {
            if (!\is_scalar($value)) {
                return [];
            }
            $value = [$value];
        }

        $out = [];
        foreach ($value as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $entry = \trim((string)$item);
            if ($entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
