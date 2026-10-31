<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;

/**
 * 主题源变更监视 cron（theme_layout_origin_watch）的启用闸门。
 *
 * 设计口径：
 * - **默认关闭**（dev 与 prod 都默认关）：不配置即不启用，两套环境行为一致，避免「装好就每分钟跑」。
 * - **开发环境需显式开启**：只有开发者确实需要 hash 监视 + 级联固化 / chrome heal 时，
 *   才在 app/etc/env.php 显式写 theme.origin_watch_cron.enabled = true。
 * - **生产不配即不启用**：生产环境没有该配置时本任务不产生子进程，也不做磁盘扫描与 bake。
 *
 * 配置位置：app/etc/env.php → 'theme' => ['origin_watch_cron' => ['enabled' => true]]，
 * 等价扁平写法 'theme.origin_watch_cron.enabled' => true（Env::getConfig 支持点号取数组）。
 */
final class ThemeOriginWatchCronGate
{
    /**
     * 显式启用开关的配置键（点号路径；Env::getConfig 会按 . 逐层取数组）。
     */
    public const CONFIG_KEY = 'theme.origin_watch_cron.enabled';

    /**
     * 「配置键不存在」的哨兵默认值。
     *
     * 必须与任何合法配置值都不相等，才能把「未配置」与「显式写了 false」区分开：
     * 前者是 disabled_by_default（运维没配，属预期状态），后者是 disabled_by_config（有人显式关了）。
     * 依赖前提：normalize() 对未知字符串返回 false，故哨兵永远不会被归一成 true。
     */
    private const UNSET_SENTINEL = '__weline_theme_origin_watch_cron_unset__';

    /**
     * 是否启用主题源变更监视 cron。
     *
     * 缺省 false；true/1/'1'/'true'/'on'/'yes' 归一到 true，false/0/'0'/'false'/'off'/'no' 归一到 false；
     * 其它非法值一律按 false（宁可不开）；读取异常的按 false（宁可不开，绝不让异常冒泡到调度器）。
     * 未配置时 configuredValue() 返回 UNSET_SENTINEL，经 normalize()（未知字符串 → false）得到 false，
     * 故未配置必然不影响启用，也不会被误判成 true。
     */
    public function isEnabled(): bool
    {
        try {
            return self::normalize($this->configuredValue());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 读取 env.php 里该项的原始配置值。
     *
     * 键不存在时返回 self::UNSET_SENTINEL（**不是** false），以便 reason() 区分
     * 「未配置」与「显式 false」；读取异常时同样返回 UNSET_SENTINEL（按未配置处理）。
     *
     * 只做纯内存数组查找（Env::getConfig 取 Env::$config，无 IO）。
     * 单测可子类覆盖本方法或用 Reflection 直接喂值，**禁止**用 Env::setConfig()/Env::set()
     * 造测试开关——那会真写磁盘上的 app/etc/env.php。
     */
    public function configuredValue(): mixed
    {
        try {
            return Env::getInstance()->getConfig(self::CONFIG_KEY, self::UNSET_SENTINEL);
        } catch (\Throwable) {
            return self::UNSET_SENTINEL;
        }
    }

    /**
     * 可读的判定原因，供 tip / 日志使用。
     *
     * @return 'disabled_by_default'|'enabled'|'disabled_by_config'
     */
    public function reason(): string
    {
        try {
            $raw = $this->configuredValue();
        } catch (\Throwable) {
            return 'disabled_by_config';
        }

        // 未配置（哨兵）/ null / 空串 → 默认关
        if ($raw === self::UNSET_SENTINEL || $raw === null || $raw === '') {
            return 'disabled_by_default';
        }

        return self::normalize($raw) ? 'enabled' : 'disabled_by_config';
    }

    /**
     * 把 env.php 里手写的各种布尔写法归一到 bool；无法识别的值按 false。
     *
     * 纯函数（不读 Env、无 IO、无副作用），供单测直接断言。
     * 接受：bool；int/float（仅 1 为 true）；string（忽略大小写与首尾空白）：
     * '1'/'true'/'on'/'yes' → true，'0'/'false'/'off'/'no' → false，其它 → false。
     */
    public static function normalize(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        if (\is_int($raw) || \is_float($raw)) {
            return (int)$raw === 1;
        }
        if (\is_string($raw)) {
            $value = \strtolower(\trim($raw));

            return match ($value) {
                '1', 'true', 'on', 'yes' => true,
                '0', 'false', 'off', 'no' => false,
                default => false,
            };
        }

        return false;
    }
}
