<?php

declare(strict_types=1);

namespace Weline\Visitor\Interface;

/**
 * 模块事件链贡献扩展点（对齐 PixelEventVendor）。
 *
 * 也可通过 Framework 事件 `Weline_Visitor::event_chain_collect` 追加 chains；
 * 两种方式都会在 runtime 合并进前台 eventChains。
 *
 * @return list<array{
 *   id: string,
 *   name?: string,
 *   complete_event: string,
 *   steps: list<array<string, mixed>>,
 *   owner?: string
 * }>
 */
interface EventChainProviderInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getEventChains(int $websiteId = 0): array;
}
