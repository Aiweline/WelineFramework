<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentFlow;
use Weline\Agent\Model\AgentFlowNodeRun;
use Weline\Agent\Model\AgentFlowRun;
use Weline\Agent\Model\AgentPatrol;
use Weline\Agent\Model\AgentTask;
use Weline\Agent\Model\AgentTrajectory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 状态巡检引擎（P6）
 *
 * - syncNodePatrols()：扫描启用流程图中 inspect_interval>0 的节点，
 *   upsert scope=node 的巡检行（ref_id = "flow_id:node_key"）。
 * - runDue()：拉取 next_check_at 到期的 enabled 巡检，计算状态 hash：
 *   * hash 变化 → unchanged_count=0，更新 last_state_hash
 *   * hash 不变 → unchanged_count++；达到 3 次且过 expect_by（或无截止）
 *     → 深度巡检：以巡检角色 leader 身份跑一次引擎回合，让其检查项目
 *     智能体工作现场并决定是否重新拉起，结果写轨迹 patrol_deep。
 *   深度巡检后 unchanged_count 清零、next_check_at 顺延一个间隔，防止刷屏。
 */
class PatrolService
{
    private const UNCHANGED_THRESHOLD = 3;
    private const MAX_PATROLS_PER_RUN = 10;

    public function __construct(
        private readonly ?AgentRuntimeService $runtime = null,
        private readonly ?AgentLedgerService $ledger = null,
    ) {}

    /** 节点巡检同步：流程图 inspect_interval>0 → AgentPatrol(scope=node) */
    public function syncNodePatrols(): int
    {
        $synced = 0;
        try {
            $flowProto = ObjectManager::getInstance(AgentFlow::class);
            $flowRows = clone $flowProto;
            $flowRows = $flowRows->reset()
                ->where(AgentFlow::schema_fields_STATUS, AgentFlow::STATUS_ACTIVE)
                ->limit(200)
                ->select()->fetchArray();
            if (!is_array($flowRows)) {
                $flowRows = [];
            }

            $patrolProto = ObjectManager::getInstance(AgentPatrol::class);
            foreach ($flowRows as $flow) {
                if (!is_array($flow)) {
                    continue;
                }
                $flowId = (int)($flow[AgentFlow::schema_fields_FLOW_ID] ?? 0);
                $graph = json_decode((string)($flow[AgentFlow::schema_fields_GRAPH_JSON] ?? ''), true);
                $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
                foreach ($nodes as $node) {
                    $interval = (int)($node['inspect_interval'] ?? 0);
                    if ($interval <= 0) {
                        continue;
                    }
                    $nodeKey = (string)($node['node_key'] ?? '');
                    $roleId = (int)($node['role_id'] ?? 0);
                    if ($flowId <= 0 || $nodeKey === '' || $roleId <= 0) {
                        continue;
                    }
                    $this->upsertPatrol($patrolProto, 'node', $flowId . ':' . $nodeKey, $roleId, $interval);
                    $synced++;
                }
            }
        } catch (\Throwable) {
        }
        return $synced;
    }

    /** 项目巡检 upsert（供项目级调度/后台使用） */
    public function upsertProjectPatrol(int $projectId, int $roleId, int $intervalSeconds, ?int $expectBy = null): void
    {
        if ($projectId <= 0 || $roleId <= 0 || $intervalSeconds <= 0) {
            return;
        }
        $proto = ObjectManager::getInstance(AgentPatrol::class);
        $this->upsertPatrol($proto, 'project', (string)$projectId, $roleId, $intervalSeconds, $expectBy);
    }

    private function upsertPatrol(object $proto, string $scope, string $refId, int $roleId, int $intervalSeconds, ?int $expectBy = null): void
    {
        try {
            $existing = $this->findPatrol($proto, $scope, $refId);
            if ($existing && $existing->getId()) {
                // 间隔/角色变化时更新，保留巡检状态
                $existing->setData(AgentPatrol::schema_fields_ROLE_ID, $roleId);
                $existing->setData(AgentPatrol::schema_fields_INTERVAL_SECONDS, $intervalSeconds);
                $existing->setData(AgentPatrol::schema_fields_EXPECT_BY, $expectBy);
                $existing->setData(AgentPatrol::schema_fields_ENABLED, 'enabled');
                $existing->save();
                return;
            }
            $patrol = clone $proto;
            $patrol->setData(AgentPatrol::schema_fields_SCOPE, $scope);
            $patrol->setData(AgentPatrol::schema_fields_REF_ID, $refId);
            $patrol->setData(AgentPatrol::schema_fields_ROLE_ID, $roleId);
            $patrol->setData(AgentPatrol::schema_fields_INTERVAL_SECONDS, $intervalSeconds);
            $patrol->setData(AgentPatrol::schema_fields_ENABLED, 'enabled');
            $patrol->setData(AgentPatrol::schema_fields_EXPECT_BY, $expectBy);
            $patrol->setData(AgentPatrol::schema_fields_UNCHANGED_COUNT, 0);
            $patrol->setData(AgentPatrol::schema_fields_NEXT_CHECK_AT, time());
            $patrol->save();
        } catch (\Throwable) {
        }
    }

    private function findPatrol(object $proto, string $scope, string $refId): ?AgentPatrol
    {
        try {
            $query = clone $proto;
            $found = $query->reset()->clearQuery()
                ->where(AgentPatrol::schema_fields_SCOPE, $scope)
                ->where(AgentPatrol::schema_fields_REF_ID, $refId)
                ->limit(1)
                ->find()->fetch();
            return $found instanceof AgentPatrol && $found->getId() ? $found : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cron 入口：先同步节点巡检，再执行到期巡检 */
    public function runDue(): array
    {
        $this->syncNodePatrols();
        $now = time();
        $stats = ['checked' => 0, 'changed' => 0, 'deep' => 0, 'errors' => 0];

        try {
            $proto = ObjectManager::getInstance(AgentPatrol::class);
            $rows = clone $proto;
            $rows = $rows->reset()
                ->where(AgentPatrol::schema_fields_ENABLED, 'enabled')
                ->where(AgentPatrol::schema_fields_NEXT_CHECK_AT, $now, '<=')
                ->limit(self::MAX_PATROLS_PER_RUN)
                ->select()->fetchArray();
            if (!is_array($rows)) {
                $rows = [];
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $patrolId = (int)($row[AgentPatrol::schema_fields_PATROL_ID] ?? 0);
                if ($patrolId <= 0) {
                    continue;
                }
                $stats['checked']++;
                try {
                    $this->checkOne($patrolId, $now, $stats);
                } catch (\Throwable) {
                    $stats['errors']++;
                }
            }
        } catch (\Throwable) {
            $stats['errors']++;
        }
        return $stats;
    }

    private function checkOne(int $patrolId, int $now, array &$stats): void
    {
        $proto = ObjectManager::getInstance(AgentPatrol::class);
        $patrol = clone $proto;
        $patrol->load($patrolId);
        if (!$patrol->getId() || $patrol->getData(AgentPatrol::schema_fields_ENABLED) !== 'enabled') {
            return;
        }

        $scope = (string)$patrol->getData(AgentPatrol::schema_fields_SCOPE);
        $refId = (string)$patrol->getData(AgentPatrol::schema_fields_REF_ID);
        $interval = max(60, (int)$patrol->getData(AgentPatrol::schema_fields_INTERVAL_SECONDS));
        $expectBy = (int)($patrol->getData(AgentPatrol::schema_fields_EXPECT_BY) ?? 0);
        $unchanged = (int)($patrol->getData(AgentPatrol::schema_fields_UNCHANGED_COUNT) ?? 0);

        $hash = $scope === 'node' ? $this->nodeStateHash($refId) : $this->projectStateHash($refId);
        $lastHash = (string)($patrol->getData(AgentPatrol::schema_fields_LAST_STATE_HASH) ?? '');

        if ($lastHash !== '' && $hash !== '' && $hash === $lastHash) {
            $unchanged++;
        } else {
            $unchanged = 0;
            $stats['changed']++;
        }

        $patrol->setData(AgentPatrol::schema_fields_LAST_STATE_HASH, $hash);
        $patrol->setData(AgentPatrol::schema_fields_UNCHANGED_COUNT, $unchanged);
        $patrol->setData(AgentPatrol::schema_fields_LAST_CHECK_AT, $now);
        $patrol->setData(AgentPatrol::schema_fields_NEXT_CHECK_AT, $now + $interval);

        // 连续无变化达到阈值且（无截止或已过截止）→ 深度巡检
        $dueForDeep = $unchanged >= self::UNCHANGED_THRESHOLD && ($expectBy <= 0 || $now > $expectBy);
        if ($dueForDeep) {
            $patrol->setData(AgentPatrol::schema_fields_UNCHANGED_COUNT, 0);
            $patrol->save();
            $this->deepPatrol($patrol, $scope, $refId, $now);
            $stats['deep']++;
            return;
        }

        $patrol->save();
    }

    /** 节点状态 hash：最近一次该节点 node_run 的 状态+完成时间+输出指纹 */
    private function nodeStateHash(string $refId): string
    {
        // ref_id = "flow_id:node_key"
        $pos = strpos($refId, ':');
        if ($pos === false) {
            return '';
        }
        $flowId = (int)substr($refId, 0, $pos);
        $nodeKey = substr($refId, $pos + 1);
        if ($flowId <= 0 || $nodeKey === '') {
            return '';
        }
        try {
            // 该流程最近一次 run 的该节点 node_run
            $runProto = ObjectManager::getInstance(AgentFlowRun::class);
            $runs = clone $runProto;
            $runs = $runs->reset()
                ->where(AgentFlowRun::schema_fields_FLOW_ID, $flowId)
                ->order(AgentFlowRun::schema_fields_RUN_ID, 'DESC')
                ->limit(1)
                ->select()->fetchArray();
            if (!is_array($runs)) {
                $runs = [];
            }
            $runId = 0;
            foreach ($runs as $r) {
                if (is_array($r)) {
                    $runId = (int)($r[AgentFlowRun::schema_fields_RUN_ID] ?? 0);
                    break;
                }
            }
            if ($runId <= 0) {
                // 流程从未运行：以 flow updated_time 作为弱状态
                $flowProto = ObjectManager::getInstance(AgentFlow::class);
                $flow = clone $flowProto;
                $flow->load($flowId);
                return $flow->getId() ? 'flow:' . $flowId . ':' . (int)$flow->getData(AgentFlow::schema_fields_UPDATED_TIME) : '';
            }
            $nrProto = ObjectManager::getInstance(AgentFlowNodeRun::class);
            $nrs = clone $nrProto;
            $nrs = $nrs->reset()
                ->where(AgentFlowNodeRun::schema_fields_RUN_ID, $runId)
                ->where(AgentFlowNodeRun::schema_fields_NODE_KEY, $nodeKey)
                ->limit(1)
                ->select()->fetchArray();
            if (!is_array($nrs)) {
                $nrs = [];
            }
            foreach ($nrs as $nr) {
                if (!is_array($nr)) {
                    continue;
                }
                return 'nr:' . (int)($nr[AgentFlowNodeRun::schema_fields_STATUS] ?? 0)
                    . ':' . (int)($nr[AgentFlowNodeRun::schema_fields_FINISHED_TIME] ?? 0)
                    . ':' . md5((string)($nr[AgentFlowNodeRun::schema_fields_OUTPUT] ?? ''));
            }
            return 'run:' . $runId . ':pending';
        } catch (\Throwable) {
            return '';
        }
    }

    /** 项目状态 hash：未完成任务状态集合 + 最近轨迹指纹 */
    private function projectStateHash(string $projectId): string
    {
        $pid = (int)$projectId;
        if ($pid <= 0) {
            return '';
        }
        try {
            $taskProto = ObjectManager::getInstance(AgentTask::class);
            $tasks = clone $taskProto;
            $tasks = $tasks->reset()
                ->where(AgentTask::schema_fields_PROJECT_ID, $pid)
                ->where(AgentTask::schema_fields_STATUS, AgentTask::STATUS_DONE, '!=')
                ->limit(500)
                ->select()->fetchArray();
            if (!is_array($tasks)) {
                $tasks = [];
            }
            $parts = [];
            foreach ($tasks as $t) {
                if (is_array($t)) {
                    $parts[] = (int)($t[AgentTask::schema_fields_TASK_ID] ?? 0) . ':' . (string)($t[AgentTask::schema_fields_STATUS] ?? '');
                }
            }
            sort($parts);
            $trProto = ObjectManager::getInstance(AgentTrajectory::class);
            $trs = clone $trProto;
            $trs = $trs->reset()
                ->where(AgentTrajectory::schema_fields_PROJECT_ID, $pid)
                ->order(AgentTrajectory::schema_fields_TRAJECTORY_ID, 'DESC')
                ->limit(1)
                ->select()->fetchArray();
            if (!is_array($trs)) {
                $trs = [];
            }
            $lastTr = 0;
            foreach ($trs as $tr) {
                if (is_array($tr)) {
                    $lastTr = (int)($tr[AgentTrajectory::schema_fields_TRAJECTORY_ID] ?? 0);
                    break;
                }
            }
            return md5(implode('|', $parts) . '#tr' . $lastTr);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 深度巡检：让巡检角色检查工作现场。节点巡检 → 检查该节点所属流程的
     * 项目智能体状态；项目巡检 → 检查项目任务/轨迹。结果写轨迹。
     */
    private function deepPatrol(AgentPatrol $patrol, string $scope, string $refId, int $now): void
    {
        $ledger = $this->ledger ?? ObjectManager::getInstance(AgentLedgerService::class);
        $projectId = $scope === 'project' ? (int)$refId : $this->flowProjectId($refId);
        $worklogId = $ledger->beginWork([
            'project_id' => $projectId,
            'ref_type' => AgentLedgerService::REF_PATROL,
            'ref_id' => (int)$patrol->getId(),
            'role_id' => (int)$patrol->getData(AgentPatrol::schema_fields_ROLE_ID),
        ]);

        $prompt = $scope === 'node'
            ? "【定期状态巡检】你负责的流程节点 {$refId} 已连续多次巡检状态无变化。"
              . "请检查该节点对应项目智能体的工作现场（任务/轨迹/运行记录），判断是否需要重新拉起智能体或上报异常。"
              . "给出结论与建议动作。若需重新拉起某角色智能体，输出 <!--RELAUNCH:角色code-->任务描述<!--/RELAUNCH-->；"
              . "若沉淀了可复用巡检做法，输出 <!--SOP:巡检-场景-->做法<!--/SOP-->"
            : "【项目巡检】项目 {$refId} 状态已连续多次无变化。请检查项目下智能体任务与轨迹，"
              . "判断是否有任务卡死、智能体掉线需要重新拉起，或需要向上汇报。给出结论与建议动作。"
              . "若需重新拉起某角色智能体，输出 <!--RELAUNCH:角色code-->任务描述<!--/RELAUNCH-->；"
              . "若沉淀了可复用巡检做法，输出 <!--SOP:巡检-场景-->做法<!--/SOP-->";

        try {
            $runtime = $this->runtime ?? ObjectManager::getInstance(AgentRuntimeService::class);
            $result = $runtime->runTurn([
                'prompt' => $prompt,
                'role_id' => (int)$patrol->getData(AgentPatrol::schema_fields_ROLE_ID),
                'project_id' => $projectId,
                'skip_mentions' => true,
            ]);
            $ok = (bool)($result->success ?? false);
            $content = (string)($result->content ?? '');
            $ledger->finishWork($worklogId, null, $ok ? 'done' : 'failed');

            // RELAUNCH 标记 → 项目级派单（require_approval：重拉智能体需人工确认）
            $relaunches = [];
            if ($ok && $projectId > 0 && preg_match_all('/<!--RELAUNCH:([A-Za-z0-9_-]+)-->(.*?)<!--\/RELAUNCH-->/su', $content, $mRl, PREG_SET_ORDER)) {
                $dispatch = ObjectManager::getInstance(TeamDispatchService::class);
                foreach ($mRl as $m) {
                    $roleCode = trim($m[1]);
                    $taskPrompt = trim($m[2]);
                    if ($roleCode === '' || $taskPrompt === '') {
                        continue;
                    }
                    $dr = $dispatch->dispatchForProject($projectId, $roleCode, $taskPrompt, true, 'patrol', (string)$patrol->getId());
                    $relaunches[] = [
                        'role_code' => $roleCode,
                        'task_id' => (int)($dr['task_id'] ?? 0),
                        'status' => (string)($dr['status'] ?? ($dr['success'] ?? false ? 'done' : 'failed')),
                        'error' => $dr['error'] ?? null,
                    ];
                }
            }

            $ledger->track('patrol_deep', [
                'project_id' => $projectId,
                'ref_type' => AgentLedgerService::REF_PATROL,
                'ref_id' => (int)$patrol->getId(),
                'actor_role_id' => (int)$patrol->getData(AgentPatrol::schema_fields_ROLE_ID),
                'summary' => '深度巡检（' . $scope . ' ' . $refId . '）：' . ($ok ? '已检查' : '检查失败'),
                'payload' => [
                    'scope' => $scope,
                    'ref_id' => $refId,
                    'output' => mb_substr($content, 0, 2000),
                    'relaunches' => $relaunches,
                ],
            ]);
        } catch (\Throwable $e) {
            $ledger->finishWork($worklogId, null, 'failed', $e->getMessage());
            $ledger->track('patrol_deep_failed', [
                'project_id' => $projectId,
                'ref_type' => AgentLedgerService::REF_PATROL,
                'ref_id' => (int)$patrol->getId(),
                'actor_role_id' => (int)$patrol->getData(AgentPatrol::schema_fields_ROLE_ID),
                'summary' => '深度巡检异常：' . mb_substr($e->getMessage(), 0, 160),
            ]);
        }
    }

    private function flowProjectId(string $refId): int
    {
        $pos = strpos($refId, ':');
        $flowId = $pos === false ? 0 : (int)substr($refId, 0, $pos);
        if ($flowId <= 0) {
            return 0;
        }
        try {
            $proto = ObjectManager::getInstance(AgentFlow::class);
            $flow = clone $proto;
            $flow->load($flowId);
            return $flow->getId() ? (int)$flow->getData(AgentFlow::schema_fields_PROJECT_ID) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
