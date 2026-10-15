<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

use Weline\Framework\View\Template;

/**
 * renderRuntimeTag 下静态声明式属性（value="fooVar"）需从 Template::getData 解析。
 */
final class TaglibRuntimeScope
{
    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function resolve(Template $template, array $attributes, array $keys): array
    {
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $attributes) || !\is_string($attributes[$key])) {
                continue;
            }
            $raw = \trim($attributes[$key]);
            if ($raw === '' || \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $raw) !== 1) {
                continue;
            }
            $data = $template->getData($raw);
            if ($data === null && \function_exists('w_env')) {
                $wenv = \w_env('template.' . $raw);
                if ($wenv !== null) {
                    $data = $wenv;
                }
            }
            if ($data === null) {
                continue;
            }
            if (\is_array($data)) {
                $attributes[$key] = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            } elseif (\is_scalar($data)) {
                $attributes[$key] = (string)$data;
            }
        }

        return $attributes;
    }

    /**
     * @param mixed $raw
     */
    public static function isTruthy(mixed $raw, bool $default = false): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return $default;
        }

        return \in_array(\strtolower(\trim((string)$raw)), ['true', '1', 'yes', 'on'], true);
    }
}
