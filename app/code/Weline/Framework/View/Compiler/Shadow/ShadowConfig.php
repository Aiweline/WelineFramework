<?php

declare(strict_types=1);

/**
 * 影子编译配置（只读 `env.php` 的 `template.engine.*`）。
 *
 * 设计成「纯配置对象」而不是直接读 Env，是为了能在单测里构造任意组合，
 * 不依赖真实配置；生产由 `fromEnv()` 读取。
 *
 * 开关语义（与计划书 §4 一致）：
 * - `v3`：总闸，只有它为真才允许新管线接管编译入口；
 * - `shadow`：只编译、只比对、不生效；
 * - `tags`：已转正、允许走 v3 的标签组；
 * - `shadow.tags`：只影子比对的标签组。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Shadow;

final class ShadowConfig
{
    /**
     * @param list<string> $tags
     * @param list<string> $shadowTags
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly bool $v3 = false,
        public readonly array $tags = [],
        public readonly array $shadowTags = [],
        public readonly array $templates = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config `env.php` 里的 `template` 段
     */
    public static function fromArray(array $config): self
    {
        $engine = \is_array($config['engine'] ?? null) ? $config['engine'] : [];

        return new self(
            enabled: \is_array($engine['shadow'] ?? null)
                ? self::flag($engine['shadow']['enabled'] ?? false)
                : self::flag($engine['shadow'] ?? false),
            v3: self::flag($engine['v3'] ?? false),
            tags: self::names($engine['tags'] ?? []),
            // `shadow` 允许两种写法：布尔开关，或 `['enabled'=>bool,'tags'=>[...]]`。
            shadowTags: self::names(
                \is_array($engine['shadow'] ?? null)
                    ? ($engine['shadow']['tags'] ?? [])
                    : ($engine['shadow_tags'] ?? [])
            ),
            templates: self::paths($engine['templates'] ?? []),
        );
    }

    public static function fromEnv(): self
    {
        if (!\class_exists(\Weline\Framework\App\Env::class)) {
            return new self();
        }

        $template = \Weline\Framework\App\Env::get('template', []);

        return self::fromArray(\is_array($template) ? $template : []);
    }

    public function shouldShadow(): bool
    {
        return $this->enabled && !$this->v3;
    }

    /**
     * S2-A：模板级白名单。`v3` 总闸为真时全量；否则只命中显式模板。
     * 未配置 templates 时恒为 false，旧编译入口行为不变。
     */
    public function shouldCompileTemplate(string $fileName): bool
    {
        if ($this->v3) {
            return true;
        }
        if ($this->templates === []) {
            return false;
        }
        $normalized = \str_replace('\\', '/', $fileName);
        foreach ($this->templates as $template) {
            $template = \str_replace('\\', '/', $template);
            if (!\str_starts_with($template, '/')) {
                $template = (\defined('BP') ? BP : '') . $template;
            }
            if ($normalized === $template) {
                return true;
            }
        }

        return false;
    }

    public function isTagShadowed(string $name): bool
    {
        return \in_array(\strtolower($name), $this->shadowTags, true);
    }

    private static function flag(mixed $value): bool
    {
        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    private static function paths(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \array_map('trim', \explode(',', $value));
        }
        if (!\is_array($value)) {
            return [];
        }
        $paths = [];
        foreach ($value as $path) {
            $path = \trim((string)$path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private static function names(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \array_map('trim', \explode(',', $value));
        }
        if (!\is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $name) {
            $normalized = \strtolower(\trim((string)$name));
            if ($normalized !== '') {
                $names[] = $normalized;
            }
        }

        return $names;
    }
}
