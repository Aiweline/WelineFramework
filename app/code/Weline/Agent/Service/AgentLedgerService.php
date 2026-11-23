<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentTrajectory;
use Weline\Agent\Model\AgentWorklog;
use Weline\Framework\Manager\ObjectManager;

/**
 * 工时台账 + 项目轨迹（P3）
 *
 * - beginWork/finishWork：记录每次执行（turn / flow node）的预期与实际工时，
 *   超时自动计算 overtime_minutes 并落 overtime_reason。
 * - track：向项目轨迹追加事件（任务开始/完成/失败、派单、巡检、反思、SOP）。
 *
 * project_id=0 的个人会话同样记录（project_id 列允许 0），便于全局复盘；
 * 项目级汇总查询只取 project_id>0。
 */
class AgentLedgerService
{
    public const REF_TURN = 'turn';
    public const REF_FLOW_RUN = 'flow_run';
    public const REF_NODE_RUN = 'node_run';
    public const REF_TASK = 'task';
    public const REF_PATROL = 'patrol';

    /** 开始一次工时记录，返回 worklog_id；失败返回 0（不阻断主流程） */
    public function beginWork(array $opts): int
    {
        try {
            $worklog = ObjectManager::getInstance(AgentWorklog::class);
            $worklog->setData(AgentWorklog::schema_fields_PROJECT_ID, (int)($opts['project_id'] ?? 0));
            $worklog->setData(AgentWorklog::schema_fields_REF_TYPE, (string)($opts['ref_type'] ?? self::REF_TURN));
            $worklog->setData(AgentWorklog::schema_fields_REF_ID, (int)($opts['ref_id'] ?? 0));
            $worklog->setData(AgentWorklog::schema_fields_ROLE_ID, (int)($opts['role_id'] ?? 0) ?: null);
            $worklog->setData(AgentWorklog::schema_fields_AGENT_CODE, (string)($opts['agent_code'] ?? '') ?: null);
            $worklog->setData(AgentWorklog::schema_fields_EXPECTED_MINUTES, isset($opts['expected_minutes']) ? max(0, (int)$opts['expected_minutes']) : null);
            $worklog->setData(AgentWorklog::schema_fields_STATUS, 'running');
            $worklog->setData(AgentWorklog::schema_fields_STARTED_TIME, time());
            $worklog->save();
            return (int)$worklog->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 结束工时记录。
     * $actualMinutes=null 时按 started_time 自动折算；overtime = actual - expected（expected>0 时）。
     */
    public function finishWork(int $worklogId, ?int $actualMinutes = null, string $status = 'done', ?string $overtimeReason = null): void
    {
        if ($worklogId <= 0) {
            return;
        }
        try {
            $worklog = ObjectManager::getInstance(AgentWorklog::class);
            $worklog->load($worklogId);
            if (!$worklog->getId()) {
                return;
            }
            $started = (int)$worklog->getData(AgentWorklog::schema_fields_STARTED_TIME);
            if ($actualMinutes === null) {
                $actualMinutes = $started > 0 ? max(1, (int)ceil((time() - $started) / 60)) : 0;
            }
            $expected = (int)($worklog->getData(AgentWorklog::schema_fields_EXPECTED_MINUTES) ?? 0);
            $overtime = $expected > 0 ? max(0, $actualMinutes - $expected) : 0;
            if ($overtime > 0 && ($overtimeReason === null || $overtimeReason === '')) {
                $overtimeReason = '实际工时超过预期';
            }
            $worklog->setData(AgentWorklog::schema_fields_ACTUAL_MINUTES, $actualMinutes);
            $worklog->setData(AgentWorklog::schema_fields_OVERTIME_MINUTES, $overtime);
            $worklog->setData(AgentWorklog::schema_fields_OVERTIME_REASON, $overtimeReason);
            $worklog->setData(AgentWorklog::schema_fields_STATUS, $status);
            $worklog->setData(AgentWorklog::schema_fields_FINISHED_TIME, time());
            $worklog->save();
        } catch (\Throwable) {
        }
    }

    /** 追加项目轨迹事件 */
    public function track(string $kind, array $opts = []): void
    {
        try {
            $row = ObjectManager::getInstance(AgentTrajectory::class);
            $row->setData(AgentTrajectory::schema_fields_PROJECT_ID, (int)($opts['project_id'] ?? 0));
            $row->setData(AgentTrajectory::schema_fields_KIND, mb_substr($kind, 0, 32));
            $row->setData(AgentTrajectory::schema_fields_REF_TYPE, isset($opts['ref_type']) ? mb_substr((string)$opts['ref_type'], 0, 32) : null);
            $row->setData(AgentTrajectory::schema_fields_REF_ID, isset($opts['ref_id']) ? (int)$opts['ref_id'] : null);
            $row->setData(AgentTrajectory::schema_fields_ACTOR_ROLE_ID, isset($opts['actor_role_id']) ? (int)$opts['actor_role_id'] : null);
            $row->setData(AgentTrajectory::schema_fields_ACTOR_AGENT_CODE, isset($opts['actor_agent_code']) ? mb_substr((string)$opts['actor_agent_code'], 0, 64) : null);
            $row->setData(AgentTrajectory::schema_fields_SUMMARY, mb_substr((string)($opts['summary'] ?? ''), 0, 255));
            $payload = $opts['payload'] ?? null;
            if ($payload !== null) {
                $row->setData(AgentTrajectory::schema_fields_PAYLOAD_JSON, is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $row->save();
        } catch (\Throwable) {
        }
    }
}
