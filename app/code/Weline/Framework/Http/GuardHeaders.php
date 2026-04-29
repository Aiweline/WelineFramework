<?php

declare(strict_types=1);

/**
 * Weline 标准 Guard / Cache 协调请求头
 *
 * 提供统一的请求头常量与读写工具，避免在多模块里重复硬编码字符串。
 * 这些请求头同时被 CDN 规则与服务器层共同识别：
 *
 * | Header                       | 来源     | 含义                                                      |
 * | ---------------------------- | -------- | --------------------------------------------------------- |
 * | X-Weline-Idempotent          | 客户端   | "1" 表示请求幂等，CDN/服务器可强制走缓存                   |
 * | X-Weline-Idempotency-Key     | 客户端   | 与 Idempotent=1 配合使用的请求级唯一键                     |
 * | X-Weline-Cache-Bypass        | 客户端   | "1" 表示要求绕过缓存（管理员/调试场景）                    |
 * | X-Weline-Url-Guard           | 服务器   | 路由 Guard 决策："accepted" / "rejected:{guard_name}"      |
 * | X-Weline-Cache-Status        | 服务器   | 缓存命中状态："hit" / "miss" / "bypass" / "locked"        |
 * | X-Weline-Hot-Key             | 服务器   | 当前 key 是否被识别为热点，"1" 表示是                      |
 *
 * 所有比较都不区分大小写（依赖底层 Request 实现），但常量保持规范化大小写。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

final class GuardHeaders
{
    public const IDEMPOTENT = 'X-Weline-Idempotent';
    public const IDEMPOTENCY_KEY = 'X-Weline-Idempotency-Key';
    public const CACHE_BYPASS = 'X-Weline-Cache-Bypass';
    public const URL_GUARD = 'X-Weline-Url-Guard';
    public const CACHE_STATUS = 'X-Weline-Cache-Status';
    public const HOT_KEY = 'X-Weline-Hot-Key';

    public const STATUS_HIT = 'hit';
    public const STATUS_MISS = 'miss';
    public const STATUS_BYPASS = 'bypass';
    public const STATUS_LOCKED = 'locked';

    public const URL_GUARD_ACCEPTED = 'accepted';
    public const URL_GUARD_REJECTED = 'rejected';

    private function __construct()
    {
    }

    /**
     * 当前请求是否标记为幂等可缓存。
     */
    public static function isIdempotentRetry(RequestInterface $request): bool
    {
        return self::isHeaderTruthy($request, self::IDEMPOTENT);
    }

    /**
     * 当前请求是否要求绕过缓存。
     */
    public static function isCacheBypass(RequestInterface $request): bool
    {
        return self::isHeaderTruthy($request, self::CACHE_BYPASS);
    }

    /**
     * 取出客户端提供的幂等键（不存在返回空字符串）。
     */
    public static function getIdempotencyKey(RequestInterface $request): string
    {
        $value = $request->getHeader(self::IDEMPOTENCY_KEY);
        if (\is_array($value)) {
            $value = \reset($value);
        }
        return \is_string($value) ? \trim($value) : '';
    }

    /**
     * 在响应头上写入缓存状态。容错：若 response 不支持 setHeader 则静默忽略。
     */
    public static function writeCacheStatus(object $response, string $status): void
    {
        self::trySetHeader($response, self::CACHE_STATUS, $status);
    }

    /**
     * 在响应头上写入 URL Guard 决策。
     */
    public static function writeUrlGuardDecision(object $response, string $decision, string $guardName = ''): void
    {
        $value = $guardName !== ''
            ? $decision . ':' . $guardName
            : $decision;
        self::trySetHeader($response, self::URL_GUARD, $value);
    }

    /**
     * 标注热点 key 命中，配合 CDN/网关做路由决策。
     */
    public static function writeHotKey(object $response, bool $isHot): void
    {
        self::trySetHeader($response, self::HOT_KEY, $isHot ? '1' : '0');
    }

    private static function isHeaderTruthy(RequestInterface $request, string $header): bool
    {
        $value = $request->getHeader($header);
        if (\is_array($value)) {
            $value = \reset($value);
        }

        if (!\is_string($value)) {
            return false;
        }

        $normalized = \strtolower(\trim($value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function trySetHeader(object $response, string $header, string $value): void
    {
        if (\method_exists($response, 'setHeader')) {
            try {
                $response->setHeader($header, $value);
            } catch (\Throwable) {
                // setHeader 不应抛错，但我们在响应头注入失败时不能影响主流程。
            }
        }
    }
}
