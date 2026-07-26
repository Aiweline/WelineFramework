<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * F05b：渠道详情漏斗模式（营销简漏斗 / 电商四步）。
 *
 * 纯值对象：只做模式白名单与展示文案，不查库、不产 SQL。
 * 默认保持 `marketing`，避免既有渠道详情链接语义变化。
 */
final class PixelChannelFunnelMode
{
    public const MARKETING = 'marketing';
    public const ECOMMERCE = 'ecommerce';

    public const DEFAULT_MODE = self::MARKETING;

    /** @var list<string> */
    public const MODES = [self::MARKETING, self::ECOMMERCE];

    public static function normalize(mixed $mode): string
    {
        $value = strtolower(trim((string)$mode));

        return \in_array($value, self::MODES, true) ? $value : self::DEFAULT_MODE;
    }

    public static function isEcommerce(mixed $mode): bool
    {
        return self::normalize($mode) === self::ECOMMERCE;
    }

    public static function label(string $mode): string
    {
        return self::normalize($mode) === self::ECOMMERCE
            ? (string)__('电商四步')
            : (string)__('营销简漏斗');
    }

    public static function description(string $mode): string
    {
        return self::normalize($mode) === self::ECOMMERCE
            ? (string)__('步骤：浏览商品 → 加购 → 开始结账 → 购买成功；须顺序命中。与全站电商漏斗（F01）同口径。')
            : (string)__('步骤：落地 → 互动 → 加购 → 转化；须顺序命中。本漏斗为营销简漏斗，非电商字典四步。');
    }

    /**
     * 步 1 为空时的提示。
     */
    public static function emptyHint(string $mode): string
    {
        return self::normalize($mode) === self::ECOMMERCE
            ? (string)__('该窗口暂无浏览商品会话，无法计算步进占比。')
            : (string)__('该窗口暂无落地会话，无法计算步进占比。');
    }

    /**
     * 步 1 相对列表头。
     */
    public static function baselineLabel(string $mode): string
    {
        return self::normalize($mode) === self::ECOMMERCE
            ? (string)__('相对浏览')
            : (string)__('相对落地');
    }
}
