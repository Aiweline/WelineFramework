<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\StorefrontCacheKeyContext;

/**
 * 店面缓存键栅栏的行为契约。
 *
 * 生产现象：FPC payload 文件持续增长（写入确实发生），但同一 URL 连续多次请求
 * 始终 MISS（读永远落空），于是每个请求都付一次整页 SSR（实测中位 8s）。
 *
 * 机制：当真正的 Storefront 缓存上下文未解析时，currentOrRequestFence() 会安装
 * 一个「请求栅栏」上下文，其 cache_key_fingerprint 掺入了每次重新生成的随机
 * nonce。该指纹进入 FPC 缓存键，于是每次请求都写到一个全新键上，后续请求自然
 * 永远读不到——表现为 100% MISS，且不产生任何错误或告警。
 */
final class StorefrontCacheKeyFenceTest extends TestCase
{
    /**
     * 请求内必须稳定：同一个 Context 下重复取用不得产生不同指纹，否则单次请求
     * 内部就会自相冲突。逐请求的随机性来自 nonce 在请求之间重新生成，由
     * 跨进程校验（同一脚本两次运行）证明，而不是同一进程内两次调用。
     */
    public function testRequestFenceFingerprintIsStableWithinOneRequest(): void
    {
        $first = StorefrontCacheKeyContext::currentOrRequestFence('unit_first');
        $second = StorefrontCacheKeyContext::currentOrRequestFence('unit_second');

        self::assertFalse($first->cacheable, '未解析上下文必须不可缓存');
        self::assertSame(
            $first->cacheKeyFingerprint,
            $second->cacheKeyFingerprint,
            '同一请求内栅栏指纹必须稳定，避免请求内部自相冲突',
        );
    }

    public function testRequestFenceIsNotACompleteFrozenScope(): void
    {
        $context = StorefrontCacheKeyContext::currentOrRequestFence('unit_scope');

        self::assertFalse(
            $context->hasCompleteFrozenScope(),
            '请求栅栏上下文不得被当作完整冻结范围，否则会写入不可复用的缓存',
        );
        self::assertNotNull($context->failureCode !== '' ? $context->failureCode : null);
        self::assertSame('unit_scope', $context->failureCode, '失败原因码必须被保留以便诊断');
    }

    public function testCacheKeyDimensionsExposeFrozenVersusFenceState(): void
    {
        $context = StorefrontCacheKeyContext::currentOrRequestFence('unit_dims');
        $dimensions = $context->keyDimensions();

        self::assertSame('request-fence', $dimensions['scope_state'], '未冻结上下文必须显式标注为 request-fence');
        self::assertSame(
            $context->cacheKeyFingerprint,
            $dimensions['cache_key_fingerprint'],
            '键维度必须携带实际使用的指纹，便于线上比对是否逐请求变化',
        );
    }
}
