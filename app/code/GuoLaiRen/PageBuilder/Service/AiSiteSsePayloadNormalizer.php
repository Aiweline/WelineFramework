<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * AiSiteSsePayloadNormalizer
 *
 * 统一 PageBuilder AI 建站工作台的 SSE payload 字段契约，消除"同一份数据用 task_summary /
 * task_progress / build_task_summary 三个键复发"、"queue 状态在 status / queue_status /
 * job_status / state 多个字段名漂移"等历史契约不稳定问题。
 *
 * 契约原则：
 *  - 权威字段（前端永远从此读取）：
 *      operation        — 当前操作名（plan / build / regenerate_page / block_partial_patch / publish / image_asset ...）
 *      message          — 面向用户的可读消息
 *      queue_status     — 队列权威状态（pending / queued / running / processing / done / error / stop / cancelled）
 *      task_summary     — 任务汇总 { total, todo, doing, done, failed, cancelled, groups[] }
 *      progress_kind    — payload 子类（task_progress / queue_info / page_progress / asset_progress ...）
 *      progress_percent — 0-100 整数
 *  - 过渡期别名（normalizer 自动同步写入；前端可在 1-2 个 release 内逐步删除 fallback）：
 *      queue_status     ←─ status / job_status / state / semantic_status
 *      task_summary     ←─ task_progress / build_task_summary
 *
 * 用法：
 *   $payload = $this->ssePayloadNormalizer->normalize([
 *       'operation' => 'build',
 *       'task_summary' => $summary,
 *       'queue_status' => 'running',
 *       'message' => '...',
 *   ]);
 *   $sse->sendEvent('progress', $payload);
 *
 * normalizer 不修改输入键集合（除了在缺失权威字段时回填别名→权威，并把权威字段镜像到别名给老前端兼容）。
 * 前端单字段化完成、稳定一段时间后，可在 `EMITTED_DEPRECATED_ALIASES = false` 关掉别名输出，
 * 同时只保留 contract test 锁住单一字段读取。
 */
class AiSiteSsePayloadNormalizer
{
    /** 过渡期是否继续向 payload 同步写入旧别名字段（保证未升级的前端读取点不破）。 */
    public const EMITTED_DEPRECATED_ALIASES = true;

    /**
     * 别名 → 权威字段 的归一表。当 payload 中只存在别名时，把别名值复制到权威字段。
     *
     * @var array<string, string>
     */
    private const ALIAS_TO_AUTHORITATIVE = [
        // queue status 家族
        'status'           => 'queue_status',
        'job_status'       => 'queue_status',
        'state'            => 'queue_status',
        'semantic_status'  => 'queue_status',
        // task summary 家族
        'task_progress'    => 'task_summary',
        'build_task_summary' => 'task_summary',
    ];

    /**
     * 权威字段 → 需要镜像的别名字段集合。过渡期把权威字段同步写入这些别名，
     * 让历史前端读取点（fallback 链）仍能拿到正确数据。
     *
     * @var array<string, list<string>>
     */
    private const AUTHORITATIVE_TO_ALIASES = [
        'queue_status' => ['status', 'job_status'],
        'task_summary' => ['task_progress', 'build_task_summary'],
    ];

    /**
     * SSE 事件名权威清单。Contract Test 用这份白名单与后端 sendEvent / 前端 addEventListener
     * 实际出现的事件名对比，发现"前端死监听 / 后端死发送"立即失败。
     *
     * 命名约定：
     *  - 通用：start / progress / chunk / info / warning / done / error
     *  - 任务粒度：task_progress / task_completed / task_failed
     *  - 页面/区域粒度：page_generated / shared_component_generated
     *  - 资产：asset_generation_started / asset_manifest_updated / asset_generation_progress
     *           / asset_generation_done / asset_generation_failed / asset_generation_skipped
     *  - 区块部分补丁：block_partial_patch_applied / block_partial_patch_failed
     *  - 环境与基础设施：environment_ready / snapshot / log / total
     *
     * @return list<string>
     */
    public static function authoritativeEventNames(): array
    {
        return [
            // 通用生命周期
            'start',
            'progress',
            'chunk',
            'info',
            'warning',
            'done',
            'error',
            // 任务
            'task_progress',
            'task_completed',
            'task_failed',
            // 页面 / 共享区域
            'page_generated',
            'shared_component_generated',
            // 资产生成
            'asset_generation_started',
            'asset_manifest_updated',
            'asset_generation_progress',
            'asset_generation_done',
            'asset_generation_failed',
            'asset_generation_skipped',
            // 区块部分补丁
            'block_partial_patch_applied',
            'block_partial_patch_failed',
            // 基础设施
            'environment_ready',
            'snapshot',
            'log',
            // 块级 AI 流（仅在 visual edit 流出现，前端目前未直接监听，contract test 会标记为后端单边）
            'ai_chunk',
        ];
    }

    /**
     * 把 payload 归一为权威字段契约：
     *  - 缺失权威字段时，从别名回填（兼容老调用方）
     *  - 过渡期同步把权威字段镜像到别名（兼容老前端读取）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        foreach (self::ALIAS_TO_AUTHORITATIVE as $alias => $authoritative) {
            if (!\array_key_exists($authoritative, $payload) && \array_key_exists($alias, $payload)) {
                $payload[$authoritative] = $payload[$alias];
            }
        }

        if (self::EMITTED_DEPRECATED_ALIASES) {
            foreach (self::AUTHORITATIVE_TO_ALIASES as $authoritative => $aliases) {
                if (!\array_key_exists($authoritative, $payload)) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (!\array_key_exists($alias, $payload)) {
                        $payload[$alias] = $payload[$authoritative];
                    }
                }
            }
        }

        // queue_status 必须是小写字符串，避免 'Running' / 'RUNNING' 这种大小写漂移让前端误判。
        if (isset($payload['queue_status']) && \is_string($payload['queue_status'])) {
            $payload['queue_status'] = \strtolower(\trim($payload['queue_status']));
            // 同步写回别名以保证 fallback 读取一致。
            foreach (self::AUTHORITATIVE_TO_ALIASES['queue_status'] ?? [] as $alias) {
                if (\array_key_exists($alias, $payload)) {
                    $payload[$alias] = $payload['queue_status'];
                }
            }
        }

        return $payload;
    }

    /**
     * 给单元测试 / 文档使用：返回权威字段集合。
     *
     * @return list<string>
     */
    public static function authoritativePayloadFields(): array
    {
        return [
            'operation',
            'message',
            'queue_status',
            'task_summary',
            'progress_kind',
            'progress_percent',
        ];
    }

    /**
     * 给单元测试 / 文档使用：返回 alias → authoritative 映射表。
     *
     * @return array<string, string>
     */
    public static function aliasToAuthoritativeMap(): array
    {
        return self::ALIAS_TO_AUTHORITATIVE;
    }

    /**
     * 给单元测试 / 文档使用：返回 authoritative → alias 镜像表。
     *
     * @return array<string, list<string>>
     */
    public static function authoritativeToAliasesMap(): array
    {
        return self::AUTHORITATIVE_TO_ALIASES;
    }
}
