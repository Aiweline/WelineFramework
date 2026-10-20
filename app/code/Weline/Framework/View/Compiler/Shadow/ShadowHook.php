<?php

declare(strict_types=1);

/**
 * 影子编译挂钩：`Template::compileSourceBytes()` 与 `ShadowRunner` 之间的唯一薄层。
 *
 * 三条纪律（红线③的安全带）：
 * 1. **默认关闭**：只有 `env.php` 的 `template.engine.shadow` 为真才编译第二遍；
 * 2. **fail-open**：任何异常（缺标签、模块回调状态、diff 目录不可写）都只记日志，
 *    绝不影响旧产物、绝不阻断编译；
 * 3. **只读**：不写回 `$compiled`、不碰 `generated/`，差异只落
 *    `var/compile/shadow/<hash>.diff` 供 S3 白名单签核。
 *
 * 注册表用 `CompiledTagRegistry`（模块标签）+ `BuiltinRegistry`（已转正内置标签），
 * 与语料 S1 的脚本保持同一份名单，避免「影子跑通了、S1 却是另一套」。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Shadow;

use Weline\Framework\View\Compiler\Tag\Adapter\CompiledTagRegistry;
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\RegistryNameMatcher;
use Weline\Framework\View\Template;

final class ShadowHook
{
    private const DEFAULT_DIFF_DIR = 'var/compile/shadow';

    private static ?Registry $registry = null;

    private static ?RegistryNameMatcher $matcher = null;

    private static bool $unavailable = false;

    /** 只影子顶层编译：嵌套编译（js:part / template fetch）由外层覆盖。 */
    private static int $depth = 0;

    public static function isEnabled(): bool
    {
        try {
            return ShadowConfig::fromEnv()->shouldShadow();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param ShadowConfig|null $config 测试可显式注入；生产走 `env.php`
     * @param string|null $diffDir 测试可指向临时目录；默认为 `var/compile/shadow`
     */
    public static function compare(
        string $source,
        string $fileName,
        string $legacyCompiled,
        ?Template $template = null,
        ?ShadowConfig $config = null,
        ?string $diffDir = null,
    ): ?ShadowReport {
        try {
            $config ??= ShadowConfig::fromEnv();
            if (!$config->shouldShadow()) {
                return null;
            }

            if (self::$depth > 0) {
                return null;
            }

            $runner = self::runner($diffDir);
            if ($runner === null) {
                return null;
            }

            self::$depth++;
            try {
                return $runner->compare($source, $fileName, $legacyCompiled, $template);
            } finally {
                self::$depth--;
            }
        } catch (\Throwable $exception) {
            self::logOnce($fileName, $exception);

            return null;
        }
    }

    private static function runner(?string $diffDir): ?ShadowRunner
    {
        if (self::$unavailable) {
            return null;
        }

        $generated = \defined('BP') ? BP . 'generated/taglibs.php' : '';
        if ($generated === '' || !\is_file($generated)) {
            self::$unavailable = true;
            self::logOnce('(bootstrap)', new \RuntimeException('缺少 generated/taglibs.php，影子编译跳过'));

            return null;
        }

        if (self::$registry === null || self::$matcher === null) {
            $modules = (new CompiledTagRegistry($generated))->build();
            $names = [];
            foreach ($modules->all() as $descriptor) {
                foreach ($descriptor->allNames() as $name) {
                    $names[] = $name;
                }
            }
            $names = \array_values(\array_unique([...BuiltinRegistry::names(), ...$names]));
            self::$registry = new Registry([...$modules->all(), ...BuiltinRegistry::descriptors()]);
            // 必须用「模块 + 内置」合并后的 Registry：否则 form 等内置描述符的
            // wOnly 规则不生效，原生 <form> 会被误当成模板标签。
            self::$matcher = new RegistryNameMatcher(self::$registry, $names, $names);
        }

        $dir = $diffDir ?? (defined('BP') ? BP . self::DEFAULT_DIFF_DIR : null);

        return new ShadowRunner(self::$registry, self::$matcher, $dir);
    }

    private static function logOnce(string $fileName, \Throwable $exception): void
    {
        static $logged = [];
        $key = $fileName . '|' . $exception::class . '|' . $exception->getMessage();
        if (isset($logged[$key])) {
            return;
        }
        $logged[$key] = true;

        if (\function_exists('w_log_error')) {
            \w_log_error('[template-shadow] ' . $fileName . ': ' . $exception::class . ': ' . $exception->getMessage());
        }
    }
}
