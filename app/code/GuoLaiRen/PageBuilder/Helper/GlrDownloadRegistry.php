<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Helper;

use Weline\Framework\Runtime\StateManager;

/**
 * 页面级下载链接注册表：code 由「已解析的下载 URL」SHA-256 派生（glr_ + 64 位 hex），
 * 同一 URL 多次 register 得到同一 code；map 内存真实 href，footer-common 按 code 跳转。
 */
final class GlrDownloadRegistry
{
    /**
     * @var array<string, array{href: string, slot: string, target: string}>
     */
    private static array $entries = [];

    private static bool $stateHooked = false;

    private static function ensureStateHook(): void
    {
        if (self::$stateHooked) {
            return;
        }
        self::$stateHooked = true;
        if (class_exists(StateManager::class)) {
            StateManager::registerStaticReset(self::class, 'entries', []);
            StateManager::registerStaticReset(self::class, 'stateHooked', false);
        }
    }

    /** 参与哈希的 URL 规范化，避免首尾空白导致同址不同 hash */
    private static function normalizeHrefForHash(string $href): string
    {
        return trim($href);
    }

    /**
     * 由下载 URL 派生稳定 code：glr_ + sha256(hex)
     */
    public static function codeFromHref(string $href): string
    {
        return 'glr_' . hash('sha256', self::normalizeHrefForHash($href));
    }

    /**
     * 登记已解析的最终 href，返回由 URL 哈希得到的 code（模板写在 data-glr-ref；href 用 {@see codeHref}）。
     * 同一 href 重复 register 返回同一 code，保留首次登记的 slot/target。
     *
     * @param string $href        已解析 URL（如 resolveAppDownloadUrl 结果）
     * @param string $slot        primary|secondary|android|ios|url（与像素事件映射一致）
     * @param string $openTarget  _self 或 _blank
     */
    public static function register(string $href, string $slot, string $openTarget = '_self'): string
    {
        self::ensureStateHook();
        $target = strcasecmp($openTarget, '_blank') === 0 ? '_blank' : '_self';
        $norm = self::normalizeHrefForHash($href);

        $primary = self::codeFromHref($norm);
        if (isset(self::$entries[$primary]) && self::$entries[$primary]['href'] === $href) {
            return $primary;
        }

        $code = $primary;
        if (isset(self::$entries[$primary]) && self::$entries[$primary]['href'] !== $href) {
            $salt = 1;
            while (true) {
                $code = 'glr_' . hash('sha256', $norm . "\0glr_dl_collision\0" . (string)$salt);
                if (!isset(self::$entries[$code])) {
                    break;
                }
                if (self::$entries[$code]['href'] === $href) {
                    return $code;
                }
                $salt++;
            }
        }

        self::$entries[$code] = [
            'href' => $href,
            'slot' => $slot,
            'target' => $target,
        ];

        return $code;
    }

    /**
     * 模板里 &lt;a href&gt;：# + code（与 data-glr-ref 相同字符串前加 #），点击由 footer-common 按 code 查表下载。
     */
    public static function codeHref(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || !str_starts_with($ref, 'glr_')) {
            return '#';
        }

        return '#' . $ref;
    }

    /**
     * 对配置中的原始 URL 先 resolveAppDownloadUrl 再登记。
     */
    public static function registerFromConfig(string $rawUrl, string $slot, string $openTarget = '_self'): string
    {
        $href = PageHelper::resolveAppDownloadUrl($rawUrl);

        return self::register($href, $slot, $openTarget);
    }

    /**
     * @return array<string, array{href: string, slot: string, target: string}>
     */
    public static function all(): array
    {
        return self::$entries;
    }

    /**
     * 供 footer-common 内联输出（JSON，不含外层 script 标签）。
     */
    public static function toJsonForFooter(): string
    {
        $payload = ['map' => self::$entries];

        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        ) ?: '{"map":{}}';
    }
}
