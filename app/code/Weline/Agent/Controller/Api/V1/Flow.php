<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Api\V1;

use Weline\Agent\Model\AgentFlow;
use Weline\Agent\Model\AgentFlowRun;
use Weline\Agent\Model\AgentRole;
use Weline\Framework\App\Controller\FrontendController;

/**
 * 可视化角色流程 API（前台编辑器/运行页）
 *
 * 端点：
 *  GET  /agent/api/v1/flow/flows            流程列表
 *  GET  /agent/api/v1/flow/flow?id=         流程详情（含 graph_json）
 *  GET  /agent/api/v1/flow/roles            角色目录（节点选择器 + 提示词导入）
 *  POST /agent/api/v1/flow/save             新建/更新流程
 *  GET  /agent/api/v1/flow/runs?flow_id=    运行记录列表
 *  GET  /agent/api/v1/flow/run?id=          单条运行详情（含节点运行）
 * @Extra type=fpc enabled=false
 */
class Flow extends FrontendController
{
    public function __construct(
        private readonly AgentFlow $flowModel,
        private readonly AgentFlowRun $runModel,
        private readonly AgentRole $roleModel,
    ) {}

    public function getFlows()
    {
        $rows = $this->flowModel->reset()
            ->order(AgentFlow::schema_fields_UPDATED_TIME, 'DESC')
            ->limit(100)
            ->select()
            ->fetchArray();

        $list = [];
        foreach ($rows as $row) {
            $graph = $this->decodeGraph((string)($row[AgentFlow::schema_fields_GRAPH_JSON] ?? ''));
            $list[] = [
                'flow_id' => (int)($row[AgentFlow::schema_fields_FLOW_ID] ?? 0),
                'name' => (string)($row[AgentFlow::schema_fields_NAME] ?? ''),
                'description' => (string)($row[AgentFlow::schema_fields_DESCRIPTION] ?? ''),
                'status' => (string)($row[AgentFlow::schema_fields_STATUS] ?? ''),
                'version' => (int)($row[AgentFlow::schema_fields_VERSION] ?? 0),
                'node_count' => count($graph['nodes'] ?? []),
                'updated_time' => (int)($row[AgentFlow::schema_fields_UPDATED_TIME] ?? 0),
            ];
        }

        return $this->fetchJson(['success' => true, 'data' => ['flows' => $list]]);
    }

    public function getFlow()
    {
        $flowId = (int)$this->request->getParam('id', 0);
        if ($flowId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('流程 ID 无效')]);
        }
        $flow = $this->flowModel->clearData()->load($flowId)->fetch();
        if (!$flow->getId()) {
            return $this->fetchJson(['success' => false, 'msg' => __('流程不存在')]);
        }

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'flow' => [
                    'flow_id' => (int)$flow->getId(),
                    'name' => (string)$flow->getData(AgentFlow::schema_fields_NAME),
                    'description' => (string)$flow->getData(AgentFlow::schema_fields_DESCRIPTION),
                    'status' => (string)$flow->getData(AgentFlow::schema_fields_STATUS),
                    'version' => (int)$flow->getData(AgentFlow::schema_fields_VERSION),
                    'graph' => $this->decodeGraph((string)$flow->getData(AgentFlow::schema_fields_GRAPH_JSON)),
                ],
            ],
        ]);
    }

    public function getRoles()
    {
        $rows = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->order(AgentRole::schema_fields_IS_DEFAULT, 'DESC')
            ->order(AgentRole::schema_fields_ROLE_ID, 'ASC')
            ->limit(200)
            ->select()
            ->fetchArray();

        $roles = [];
        foreach ($rows as $row) {
            $roles[] = [
                'role_id' => (int)($row[AgentRole::schema_fields_ROLE_ID] ?? 0),
                'code' => (string)($row[AgentRole::schema_fields_CODE] ?? ''),
                'name' => (string)($row[AgentRole::schema_fields_NAME] ?? ''),
                'system_prompt' => (string)($row[AgentRole::schema_fields_SYSTEM_PROMPT] ?? ''),
                'is_default' => (int)($row[AgentRole::schema_fields_IS_DEFAULT] ?? 0),
            ];
        }

        return $this->fetchJson(['success' => true, 'data' => ['roles' => $roles]]);
    }

    public function postSave()
    {
        $body = $this->request->getBodyParams();
        $data = is_string($body) ? json_decode($body, true) : $body;
        $data = is_array($data) ? $data : [];

        $flowId = (int)($data['flow_id'] ?? 0);
        $projectId = (int)($data['project_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $status = (string)($data['status'] ?? AgentFlow::STATUS_DRAFT);
        $graph = $data['graph'] ?? null;

        if ($name === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('流程名称不能为空')]);
        }
        if (!is_array($graph) || !isset($graph['nodes']) || !is_array($graph['nodes'])) {
            return $this->fetchJson(['success' => false, 'msg' => __('graph.nodes 必须为数组')]);
        }
        if (!in_array($status, [AgentFlow::STATUS_DRAFT, AgentFlow::STATUS_ACTIVE, AgentFlow::STATUS_DISABLED], true)) {
            $status = AgentFlow::STATUS_DRAFT;
        }

        // 规范化节点：node_key 唯一、role_id 必填、prompt_override 可空
        $nodes = [];
        $seen = [];
        foreach ($graph['nodes'] as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $key = trim((string)($node['node_key'] ?? ''));
            if ($key === '') {
                $key = 'node_' . ($i + 1);
            }
            if (isset($seen[$key])) {
                $key .= '_' . ($i + 1);
            }
            $seen[$key] = true;
            $roleId = (int)($node['role_id'] ?? 0);
            if ($roleId <= 0) {
                return $this->fetchJson(['success' => false, 'msg' => sprintf(__('节点 %s 未选择角色'), $key)]);
            }
            $nodes[] = [
                'node_key' => $key,
                'role_id' => $roleId,
                'agent_code' => trim((string)($node['agent_code'] ?? '')),
                'prompt_override' => trim((string)($node['prompt_override'] ?? '')),
                'label' => trim((string)($node['label'] ?? '')),
                // 节点巡检间隔（秒，0=不巡检）；上限 30 天
                'inspect_interval' => isset($node['inspect_interval']) && is_numeric($node['inspect_interval'])
                    ? max(0, min(2592000, (int)$node['inspect_interval'])) : 0,
                // 画布坐标（可选）：收起/展开布局由前端持久化
                'x' => isset($node['x']) && is_numeric($node['x']) ? (float)$node['x'] : null,
                'y' => isset($node['y']) && is_numeric($node['y']) ? (float)$node['y'] : null,
            ];
        }
        if ($nodes === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('流程至少需要一个节点')]);
        }

        // 边：仅保留指向已存在节点的边；缺省按节点顺序串成链
        $validKeys = array_column($nodes, 'node_key');
        $edges = [];
        if (isset($graph['edges']) && is_array($graph['edges'])) {
            foreach ($graph['edges'] as $edge) {
                if (!is_array($edge)) {
                    continue;
                }
                $from = (string)($edge['from_node_key'] ?? '');
                $to = (string)($edge['to_node_key'] ?? '');
                if (in_array($from, $validKeys, true) && in_array($to, $validKeys, true) && $from !== $to) {
                    $edges[] = ['from_node_key' => $from, 'to_node_key' => $to];
                }
            }
        }
        if ($edges === [] && count($nodes) > 1) {
            for ($i = 0; $i < count($nodes) - 1; $i++) {
                $edges[] = ['from_node_key' => $nodes[$i]['node_key'], 'to_node_key' => $nodes[$i + 1]['node_key']];
            }
        }

        $graphJson = json_encode(['nodes' => $nodes, 'edges' => $edges], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($flowId > 0) {
            $flow = $this->flowModel->clearData()->load($flowId)->fetch();
            if (!$flow->getId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('流程不存在')]);
            }
        } else {
            // clearData 清掉共享实例上可能残留的上一个流程主键，保证走 INSERT
            $flow = $this->flowModel->clearData();
        }

        $flow->setData(AgentFlow::schema_fields_NAME, $name);
        $flow->setData(AgentFlow::schema_fields_DESCRIPTION, $description);
        $flow->setData(AgentFlow::schema_fields_GRAPH_JSON, $graphJson);
        $flow->setData(AgentFlow::schema_fields_STATUS, $status);
        if (!$flowId) {
            $flow->setData(AgentFlow::schema_fields_VERSION, 1);
            $flow->setData(AgentFlow::schema_fields_PROJECT_ID, max(0, $projectId));
        } else {
            $flow->setData(AgentFlow::schema_fields_VERSION, (int)$flow->getData(AgentFlow::schema_fields_VERSION) + 1);
        }
        $flow->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('已保存'),
            'data' => ['flow_id' => (int)$flow->getId(), 'version' => (int)$flow->getData(AgentFlow::schema_fields_VERSION)],
        ]);
    }

    public function getRuns()
    {
        $flowId = (int)$this->request->getParam('flow_id', 0);
        $query = $this->runModel->reset()
            ->order(AgentFlowRun::schema_fields_CREATED_TIME, 'DESC')
            ->limit(50);
        if ($flowId > 0) {
            $query->where(AgentFlowRun::schema_fields_FLOW_ID, $flowId);
        }
        $rows = $query->select()->fetchArray();

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = [
                'run_id' => (int)($row[AgentFlowRun::schema_fields_RUN_ID] ?? 0),
                'flow_id' => (int)($row[AgentFlowRun::schema_fields_FLOW_ID] ?? 0),
                'status' => (string)($row[AgentFlowRun::schema_fields_STATUS] ?? ''),
                'input_text' => mb_substr((string)($row[AgentFlowRun::schema_fields_INPUT_TEXT] ?? ''), 0, 200),
                'created_time' => (int)($row[AgentFlowRun::schema_fields_CREATED_TIME] ?? 0),
                'finished_time' => (int)($row[AgentFlowRun::schema_fields_FINISHED_TIME] ?? 0),
            ];
        }

        return $this->fetchJson(['success' => true, 'data' => ['runs' => $runs]]);
    }

    public function getRun()
    {
        $runId = (int)$this->request->getParam('id', 0);
        if ($runId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('运行 ID 无效')]);
        }
        $run = $this->runModel->clearData()->load($runId)->fetch();
        if (!$run->getId()) {
            return $this->fetchJson(['success' => false, 'msg' => __('运行不存在')]);
        }

        $nodeRuns = [];
        try {
            $nodeRunModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Agent\Model\AgentFlowNodeRun::class);
            $rows = $nodeRunModel->reset()
                ->where(\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_RUN_ID, $runId)
                ->order(\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_NODE_RUN_ID, 'ASC')
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                $nodeRuns[] = [
                    'node_run_id' => (int)($row[\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_NODE_RUN_ID] ?? 0),
                    'node_key' => (string)($row[\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_NODE_KEY] ?? ''),
                    'status' => (string)($row[\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_STATUS] ?? ''),
                    'output' => (string)($row[\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_OUTPUT] ?? ''),
                    'duration_ms' => (int)($row[\Weline\Agent\Model\AgentFlowNodeRun::schema_fields_DURATION_MS] ?? 0),
                ];
            }
        } catch (\Throwable) {
        }

        $result = json_decode((string)$run->getData(AgentFlowRun::schema_fields_RESULT_JSON), true);

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'run' => [
                    'run_id' => $runId,
                    'flow_id' => (int)$run->getData(AgentFlowRun::schema_fields_FLOW_ID),
                    'status' => (string)$run->getData(AgentFlowRun::schema_fields_STATUS),
                    'input_text' => (string)$run->getData(AgentFlowRun::schema_fields_INPUT_TEXT),
                    'result' => is_array($result) ? $result : null,
                    'node_runs' => $nodeRuns,
                    'created_time' => (int)$run->getData(AgentFlowRun::schema_fields_CREATED_TIME),
                    'finished_time' => (int)$run->getData(AgentFlowRun::schema_fields_FINISHED_TIME),
                ],
            ],
        ]);
    }

    /** @return array{nodes?:array,edges?:array} */
    private function decodeGraph(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['nodes' => [], 'edges' => []];
    }
}
