<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentFlow;
use Weline\Agent\Model\AgentFlowNodeRun;
use Weline\Agent\Model\AgentFlowRun;
use Weline\Agent\Model\AgentRole;
use Weline\Framework\Manager\ObjectManager;

/**
 * 可视化角色流程执行器（v1：拓扑序串行）
 *
 * graph_json.nodes[] = {node_key, role_id, agent_code?, prompt_override?, position}
 * graph_json.edges[] = {from_node_key, to_node_key, label?}
 * 节点输入 = 原始需求 + 上游节点输出（截断）；节点级 prompt_override
 * 作为系统提示词覆盖注入 AgentEngine。
 */
final class FlowRunner
{
    private const UPSTREAM_SUMMARY_LIMIT = 4000;

    public function __construct(
        private readonly ?AgentEngine $engine = null,
        private readonly ?ChatSessionManager $sessionManager = null,
        private readonly ?AgentRole $roleModel = null,
    ) {}

    /**
     * 执行一个流程运行
     *
     * @param callable(string,array):void|null $onEvent 事件出口（node_start/node_chunk/node_done/node_error）
     */
    public function run(AgentFlowRun $run, AgentFlow $flow, ?callable $onEvent = null): AgentFlowRun
    {
        $emit = static function (string $type, array $data = []) use ($onEvent): void {
            if (is_callable($onEvent)) {
                try {
                    $onEvent($type, $data);
                } catch (\Throwable) {
                }
            }
        };

        $graph = $flow->getGraph();
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        $inputText = (string)$run->getData(AgentFlowRun::schema_fields_INPUT_TEXT);

        $order = $this->topoSort($nodes, $edges);
        $outputs = []; // node_key => output text
        $nodeRunIds = [];

        $run->setData(AgentFlowRun::schema_fields_STATUS, AgentFlowRun::STATUS_RUNNING);
        $run->setData(AgentFlowRun::schema_fields_STARTED_TIME, time());
        $run->save();

        // 流程运行绑定一个项目会话（project 隔离由 session.project_id 承载）
        $session = $this->resolveRunSession($run, $nodes);

        foreach ($order as $nodeKey) {
            $node = $this->findNode($nodes, $nodeKey);
            if ($node === null) {
                continue;
            }

            $nodeRun = $this->createNodeRun($run, $flow, $node, $nodeKey);
            $nodeRunIds[$nodeKey] = (int)$nodeRun->getId();

            $role = $this->resolveNodeRole($node);
            if ($role === null) {
                $nodeRun->setData(AgentFlowNodeRun::schema_fields_STATUS, AgentFlowNodeRun::STATUS_FAILED);
                $nodeRun->save();
                $emit('node_error', ['node_key' => $nodeKey, 'error' => 'role not found or disabled']);
                $run->setData(AgentFlowRun::schema_fields_STATUS, AgentFlowRun::STATUS_FAILED);
                $run->setData(AgentFlowRun::schema_fields_FINISHED_TIME, time());
                $run->save();
                return $run;
            }

            $nodeRun->setData(AgentFlowNodeRun::schema_fields_ROLE_ID, (int)$role->getId());
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_AGENT_CODE, (string)($node['agent_code'] ?? ''));
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_STATUS, AgentFlowNodeRun::STATUS_RUNNING);
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_STARTED_TIME, time());
            $nodeRun->save();

            $emit('node_start', [
                'node_key' => $nodeKey,
                'node_run_id' => (int)$nodeRun->getId(),
                'role_id' => (int)$role->getId(),
                'role_name' => (string)$role->getData(AgentRole::schema_fields_NAME),
            ]);

            // P3 工时+轨迹：节点级 beginWork（ref=node_run），完成/失败时 finishWork+track
            $flowProjectId = (int)$run->getData(AgentFlowRun::schema_fields_PROJECT_ID);
            $ledger = ObjectManager::getInstance(AgentLedgerService::class);
            $nodeWorklogId = $ledger->beginWork([
                'project_id' => $flowProjectId,
                'ref_type' => AgentLedgerService::REF_NODE_RUN,
                'ref_id' => (int)$nodeRun->getId(),
                'role_id' => (int)$role->getId(),
                'agent_code' => (string)($node['agent_code'] ?? ''),
            ]);
            $ledger->track('flow_node_start', [
                'project_id' => $flowProjectId,
                'ref_type' => AgentLedgerService::REF_NODE_RUN,
                'ref_id' => (int)$nodeRun->getId(),
                'actor_role_id' => (int)$role->getId(),
                'actor_agent_code' => (string)($node['agent_code'] ?? ''),
                'summary' => '流程节点开始：' . $nodeKey,
                'payload' => ['flow_run_id' => (int)$run->getId(), 'node_key' => $nodeKey],
            ]);

            $upstream = $this->collectUpstream($edges, $nodeKey, $outputs);
            $nodeInput = $this->buildNodeInput($inputText, $upstream, $outputs, $node);
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_INPUT_SUMMARY, mb_substr($nodeInput, 0, 2000));
            $nodeRun->save();

            $turnOpts = [
                'message_meta' => [
                    'role_id' => (int)$role->getId(),
                    'agent_code' => (string)($node['agent_code'] ?? ''),
                    'project_id' => (int)$run->getData(AgentFlowRun::schema_fields_PROJECT_ID),
                    'meta_json' => ['flow_run_id' => (int)$run->getId(), 'node_key' => $nodeKey],
                ],
                'on_event' => static function (string $type, array $data = []) use ($emit, $nodeKey): void {
                    $emit('node_' . $type, ['node_key' => $nodeKey] + $data);
                },
            ];
            $override = trim((string)($node['prompt_override'] ?? ''));
            if ($override !== '') {
                $turnOpts['system_prompt_override'] = $override;
            }
            $projectId = (int)$run->getData(AgentFlowRun::schema_fields_PROJECT_ID);
            if ($projectId > 0) {
                $turnOpts['project_id'] = $projectId;
            }

            $engine = $this->engine ?? ObjectManager::getInstance(AgentEngine::class);
            $startedAt = microtime(true);
            $result = $engine->execute($nodeInput, $role, $session, null, $turnOpts);
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

            if (!$result->success) {
                $nodeRun->setData(AgentFlowNodeRun::schema_fields_STATUS, AgentFlowNodeRun::STATUS_FAILED);
                $nodeRun->setData(AgentFlowNodeRun::schema_fields_DURATION_MS, $durationMs);
                $nodeRun->setData(AgentFlowNodeRun::schema_fields_FINISHED_TIME, time());
                $nodeRun->save();
                $ledger->finishWork($nodeWorklogId, (int)ceil($durationMs / 60000), 'failed', (string)$result->error);
                $ledger->track('flow_node_failed', [
                    'project_id' => $flowProjectId,
                    'ref_type' => AgentLedgerService::REF_NODE_RUN,
                    'ref_id' => (int)$nodeRun->getId(),
                    'actor_role_id' => (int)$role->getId(),
                    'actor_agent_code' => (string)($node['agent_code'] ?? ''),
                    'summary' => '流程节点失败：' . $nodeKey . ' — ' . mb_substr((string)$result->error, 0, 120),
                    'payload' => ['flow_run_id' => (int)$run->getId(), 'node_key' => $nodeKey],
                ]);
                $emit('node_error', ['node_key' => $nodeKey, 'error' => (string)$result->error]);
                $run->setData(AgentFlowRun::schema_fields_STATUS, AgentFlowRun::STATUS_FAILED);
                $run->setData(AgentFlowRun::schema_fields_FINISHED_TIME, time());
                $run->save();
                return $run;
            }

            $output = (string)$result->content;
            $outputs[$nodeKey] = $output;

            $nodeRun->setData(AgentFlowNodeRun::schema_fields_OUTPUT, $output);
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_MODEL_CODE, (string)($result->modelCode ?? ''));
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_STATUS, AgentFlowNodeRun::STATUS_DONE);
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_DURATION_MS, $durationMs);
            $nodeRun->setData(AgentFlowNodeRun::schema_fields_FINISHED_TIME, time());
            $nodeRun->save();

            $ledger->finishWork($nodeWorklogId, max(1, (int)ceil($durationMs / 60000)), 'done');
            $ledger->track('flow_node_done', [
                'project_id' => $flowProjectId,
                'ref_type' => AgentLedgerService::REF_NODE_RUN,
                'ref_id' => (int)$nodeRun->getId(),
                'actor_role_id' => (int)$role->getId(),
                'actor_agent_code' => (string)($node['agent_code'] ?? ''),
                'summary' => '流程节点完成：' . $nodeKey,
                'payload' => ['flow_run_id' => (int)$run->getId(), 'node_key' => $nodeKey, 'duration_ms' => $durationMs],
            ]);

            $emit('node_done', [
                'node_key' => $nodeKey,
                'node_run_id' => (int)$nodeRun->getId(),
                'output' => mb_substr($output, 0, 2000),
            ]);
        }

        // 汇总结果：最后一个有输出的节点 + 全部节点输出映射
        $finalOutput = '';
        foreach (array_reverse($order) as $nodeKey) {
            if (isset($outputs[$nodeKey]) && $outputs[$nodeKey] !== '') {
                $finalOutput = $outputs[$nodeKey];
                break;
            }
        }

        $run->setData(AgentFlowRun::schema_fields_STATUS, AgentFlowRun::STATUS_DONE);
        $run->setData(AgentFlowRun::schema_fields_RESULT_JSON, json_encode([
            'output' => $finalOutput,
            'node_outputs' => $outputs,
            'node_run_ids' => $nodeRunIds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $run->setData(AgentFlowRun::schema_fields_FINISHED_TIME, time());
        $run->save();

        $emit('flow_done', ['run_id' => (int)$run->getId()]);
        return $run;
    }

    /** Kahn 拓扑排序；环/孤立节点按出现顺序兜底 */
    private function topoSort(array $nodes, array $edges): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            $key = (string)($node['node_key'] ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));

        $inDegree = array_fill_keys($keys, 0);
        $adj = [];
        foreach ($edges as $edge) {
            $from = (string)($edge['from_node_key'] ?? '');
            $to = (string)($edge['to_node_key'] ?? '');
            if ($from === '' || $to === '' || !isset($inDegree[$from]) || !isset($inDegree[$to])) {
                continue;
            }
            $adj[$from][] = $to;
            $inDegree[$to]++;
        }

        $queue = array_values(array_filter($keys, static fn(string $k): bool => ($inDegree[$k] ?? 0) === 0));
        $order = [];
        while ($queue !== []) {
            $key = array_shift($queue);
            $order[] = $key;
            foreach ($adj[$key] ?? [] as $next) {
                $inDegree[$next]--;
                if ($inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }
        // 环兜底：把未排序节点按原顺序追加
        foreach ($keys as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        return $order;
    }

    /** @return array<string,mixed>|null */
    private function findNode(array $nodes, string $nodeKey): ?array
    {
        foreach ($nodes as $node) {
            if ((string)($node['node_key'] ?? '') === $nodeKey) {
                return is_array($node) ? $node : null;
            }
        }
        return null;
    }

    private function resolveNodeRole(array $node): ?AgentRole
    {
        $roleId = (int)($node['role_id'] ?? 0);
        if ($roleId <= 0) {
            return null;
        }
        try {
            $prototype = $this->roleModel ?? ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->load($roleId);
            if (!$role->getId() || $role->getData(AgentRole::schema_fields_STATUS) === AgentRole::STATUS_DISABLED) {
                return null;
            }
            return $role;
        } catch (\Throwable) {
            return null;
        }
    }

    private function createNodeRun(AgentFlowRun $run, AgentFlow $flow, array $node, string $nodeKey): AgentFlowNodeRun
    {
        // ObjectManager 返回共享实例：必须 clone + clearData 出独立对象，
        // 否则多节点会携带上一个节点的主键走 checkUpdateOrInsert 互相覆盖
        $nodeRun = clone ObjectManager::getInstance(AgentFlowNodeRun::class);
        $nodeRun->clearData();
        $nodeRun->setData(AgentFlowNodeRun::schema_fields_RUN_ID, (int)$run->getId());
        $nodeRun->setData(AgentFlowNodeRun::schema_fields_FLOW_ID, (int)$flow->getId());
        $nodeRun->setData(AgentFlowNodeRun::schema_fields_NODE_KEY, $nodeKey);
        $nodeRun->setData(AgentFlowNodeRun::schema_fields_PROMPT_OVERRIDE, (string)($node['prompt_override'] ?? ''));
        $nodeRun->setData(AgentFlowNodeRun::schema_fields_STATUS, AgentFlowNodeRun::STATUS_PENDING);
        $nodeRun->save();
        return $nodeRun;
    }

    /** 流程运行绑定一个会话（复用项目/默认角色语义） */
    private function resolveRunSession(AgentFlowRun $run, array $nodes): ?AgentChatSession
    {
        try {
            $sm = $this->sessionManager ?? ObjectManager::getInstance(ChatSessionManager::class);
            $projectId = (int)$run->getData(AgentFlowRun::schema_fields_PROJECT_ID);
            $firstRoleId = 0;
            foreach ($nodes as $node) {
                $firstRoleId = (int)($node['role_id'] ?? 0);
                if ($firstRoleId > 0) {
                    break;
                }
            }
            if ($firstRoleId <= 0) {
                return null;
            }
            $role = $this->resolveNodeRole(['role_id' => $firstRoleId]);
            if ($role === null) {
                return null;
            }
            $options = ['default_role_id' => (int)$role->getId()];
            if ($projectId > 0) {
                $options['project_id'] = $projectId;
            }
            return $sm->createSession(
                $role,
                AgentChatSession::CHANNEL_WEB,
                'flow_run_' . (int)$run->getId(),
                ['flow_run_id' => (int)$run->getId()],
                $options
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> 上游节点 key（直接前驱，按拓扑序） */
    private function collectUpstream(array $edges, string $nodeKey, array $outputs): array
    {
        $upstream = [];
        foreach ($edges as $edge) {
            if ((string)($edge['to_node_key'] ?? '') === $nodeKey) {
                $from = (string)($edge['from_node_key'] ?? '');
                if ($from !== '' && isset($outputs[$from])) {
                    $upstream[] = $from;
                }
            }
        }
        return $upstream;
    }

    /**
     * @param list<string> $upstreamKeys
     * @param array<string,string> $outputs
     */
    private function buildNodeInput(string $original, array $upstreamKeys, array $outputs, array $node): string
    {
        $parts = ["【原始需求】\n{$original}"];
        if ($upstreamKeys !== []) {
            $lines = ["【上游节点输出】"];
            foreach ($upstreamKeys as $upKey) {
                $text = (string)($outputs[$upKey] ?? '');
                $lines[] = "--- {$upKey} ---\n" . mb_substr($text, 0, self::UPSTREAM_SUMMARY_LIMIT);
            }
            $parts[] = implode("\n\n", $lines);
        }
        $parts[] = "【本节点任务】\n请基于以上输入完成你职责范围内的工作，输出供下游节点使用的结果。";
        return implode("\n\n", $parts);
    }
}
