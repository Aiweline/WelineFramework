<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * AiSiteQueueSnapshotService
 *
 * 从 AiSiteAgent.php 抽出的纯数据转换层，负责把一行 `weline_queue` 原始记录整形为
 * 前端 / 侧栏 / 事件载荷所需的公共快照（`buildQueueObserverPublicSnapshot`），
 * 并同时统一 token 用量的字段别名（`prompt_tokens/completion_tokens` → `input_tokens/output_tokens`）。
 *
 * 抽出动机：
 *  - 纯数据结构转换，无 Session / DB / Request 依赖，和 Controller 耦合度最低，迁移风险最小；
 *  - 作为 R4 SOLID 拆分的第一块"安全样本"，后续抽取 QueueObserver、DomainPurchase 等领域可以复用同样范式；
 *  - 让 AiSiteVirtualThemePlanService / QueueDbWriter / 前端 state 之间对 token 字段的去重规则有同一个 Source-Of-Truth。
 *
 * 重要：方法签名、输入输出 shape 必须与 AiSiteAgent.php 原有私有方法严格一致，
 * 以便保持 SSE 载荷和前端 debug 数据向后兼容。调整时请同步更新
 * `AiSiteQueueSnapshotServiceTest` 的锁定用例。
 */
class AiSiteQueueSnapshotService
{
    /**
     * 对外暴露的 queue 公共快照字段，供前端状态栏、SSE 下发、事件回放共用。
     *
     * @param array<string, mixed> $queueRow 来自 `weline_queue` 的一行原始数据
     *
     * @return array<string, mixed>
     */
    public function buildObserverPublicSnapshot(array $queueRow): array
    {
        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $name = \trim((string)($queueRow['name'] ?? ''));
        $module = \trim((string)($queueRow['module'] ?? ''));
        $bizKey = \trim((string)($queueRow['biz_key'] ?? ''));
        $status = \trim((string)($queueRow['status'] ?? ''));
        $pid = (int)($queueRow['pid'] ?? 0);
        $typeId = (int)($queueRow['type_id'] ?? 0);
        $finished = (int)($queueRow['finished'] ?? 0);
        $startAt = \trim((string)($queueRow['start_at'] ?? ''));
        $endAt = \trim((string)($queueRow['end_at'] ?? ''));

        $publicIdHint = '';
        $jobKey = '';
        $jobType = '';
        $jobStatus = '';
        $token = '';
        $tokenUsage = $this->normalizeTokenUsage($queueRow);
        $contentRaw = (string)($queueRow['content'] ?? '');
        if ($contentRaw !== '') {
            $decoded = \json_decode($contentRaw, true);
            if (\is_array($decoded)) {
                $pidStr = \trim((string)($decoded['public_id'] ?? ''));
                if ($pidStr !== '') {
                    if (\defined('DEV') && DEV) {
                        $publicIdHint = $pidStr;
                    } else {
                        $len = \strlen($pidStr);
                        $publicIdHint = $len > 12 ? \substr($pidStr, 0, 6) . '…' . \substr($pidStr, -4) : $pidStr;
                    }
                }
                $jobKey = \trim((string)($decoded['job_key'] ?? ''));
                $jobType = \trim((string)($decoded['job_type'] ?? ''));
                $jobStatus = \trim((string)($decoded['status'] ?? ''));
                $token = \trim((string)($decoded['token'] ?? ($decoded['execution_token'] ?? '')));
                $contentTokenUsage = $this->normalizeTokenUsage($decoded);
                foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
                    if ($tokenUsage[$tokenKey] === null && $contentTokenUsage[$tokenKey] !== null) {
                        $tokenUsage[$tokenKey] = $contentTokenUsage[$tokenKey];
                    }
                }
                if (!\is_array($tokenUsage['token_cost_meta'] ?? null) && \is_array($contentTokenUsage['token_cost_meta'] ?? null)) {
                    $tokenUsage['token_cost_meta'] = $contentTokenUsage['token_cost_meta'];
                }
            }
        }

        $effectiveJobStatus = $jobStatus !== '' ? $jobStatus : $status;
        if (\in_array($status, ['error', 'done', 'stop', 'cancelled'], true)) {
            $effectiveJobStatus = $status;
        }

        return [
            'queue_id' => $queueId,
            'name' => $name,
            'module' => $module,
            'biz_key' => $bizKey,
            'status' => $status,
            'pid' => $pid,
            'type_id' => $typeId,
            'finished' => $finished,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'public_id_hint' => $publicIdHint,
            'job_key' => $jobKey,
            'job_type' => $jobType,
            'job_status' => $effectiveJobStatus,
            'token' => $token,
            'token_usage' => $tokenUsage,
        ];
    }

    /**
     * 把 queue / content 中的 token 字段统一整形为 {input_tokens,output_tokens,total_tokens,token_cost_meta}，
     * 兼容 OpenAI 家族的 `prompt_tokens/completion_tokens` 别名。
     *
     * @param array<string, mixed> $source
     *
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null}
     */
    public function normalizeTokenUsage(array $source): array
    {
        $nested = \is_array($source['token_usage'] ?? null) ? $source['token_usage'] : [];
        $input = $this->normalizeTokenCount(
            $nested['input_tokens']
            ?? $source['input_tokens']
            ?? $nested['prompt_tokens']
            ?? $source['prompt_tokens']
            ?? null
        );
        $output = $this->normalizeTokenCount(
            $nested['output_tokens']
            ?? $source['output_tokens']
            ?? $nested['completion_tokens']
            ?? $source['completion_tokens']
            ?? null
        );
        $total = $this->normalizeTokenCount(
            $nested['total_tokens']
            ?? $source['total_tokens']
            ?? null
        );
        if ($total === null && $input !== null && $output !== null) {
            $total = $input + $output;
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'token_cost_meta' => \is_array($nested['token_cost_meta'] ?? null)
                ? $nested['token_cost_meta']
                : (\is_array($source['token_cost_meta'] ?? null) ? $source['token_cost_meta'] : null),
        ];
    }

    /**
     * 负数、空白、非数字字符串都视为"未知"返回 null，方便调用方做 `??` 合并。
     */
    public function normalizeTokenCount(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (\is_float($value)) {
            return $value >= 0 ? (int)\round($value) : null;
        }
        if (\is_string($value)) {
            $trimmed = \trim($value);
            if ($trimmed !== '' && \preg_match('/^\d+$/', $trimmed) === 1) {
                return (int)$trimmed;
            }
        }

        return null;
    }
}
