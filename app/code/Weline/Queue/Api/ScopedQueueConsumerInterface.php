<?php

declare(strict_types=1);

namespace Weline\Queue\Api;

use Weline\Framework\Runtime\ScopeEnvelope;

/**
 * Scope 感知的队列消费者契约（P1b，v1）。
 *
 * 实现方声明可接受的 scope kind 与必需维度；运行时在执行前校验任务信封：
 * - 信封 kind 不在 acceptedScopeKinds() 内 → 拒绝执行（fail-closed）；
 * - requiredScopeDimensions() 中声明的字段在信封上必须非空（website_id 允许 0）；
 * - pre-P1b 遗留行（无信封）按迁移回填后的 global 处理。
 *
 * 未实现本接口的旧消费者不做 kind/维度校验（兼容期行为）。
 */
interface ScopedQueueConsumerInterface extends QueueConsumerInterface
{
    /**
     * 可接受的 scope kind 白名单。
     *
     * @return list<string> 取值 global|website|store|channel（ScopeIdentity::KINDS 子集）
     */
    public function acceptedScopeKinds(): array;

    /**
     * 除 kind 外必须出现的 Scope 维度。
     *
     * @return list<string> 取值 website_id|website_code|store_code|channel_code|store_mode
     */
    public function requiredScopeDimensions(): array;

    /**
     * 任务信封被拒绝时的处理提示（用于运维信息，可返回空串）。
     */
    public function rejectedScopeMessage(ScopeEnvelope $envelope): string;
}
