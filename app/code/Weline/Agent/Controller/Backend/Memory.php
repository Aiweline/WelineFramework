<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Agent\Model\AgentMemoryEdge;
use Weline\Agent\Model\AgentMemoryNode;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

/**
 * Backend memory management.
 */
#[Acl('Weline_Agent::memory', '记忆管理', '管理智能体记忆图谱节点与边', '')]
class Memory extends BackendController
{
    public function __construct(
        private readonly AgentMemoryNode $memoryNodeModel,
        private readonly AgentMemoryEdge $memoryEdgeModel,
    ) {}

    #[Acl('Weline_Agent::memory_list', '记忆列表', '', '查看记忆列表')]
    public function getList()
    {
        $type = trim((string) $this->request->getParam('type', ''));
        $status = trim((string) $this->request->getParam('status', ''));

        $nodes = $this->memoryNodeModel->reset();
        if ($type !== '') {
            $nodes->where(AgentMemoryNode::schema_fields_NODE_TYPE, $type);
        }
        if ($status !== '') {
            $nodes->where(AgentMemoryNode::schema_fields_STATUS, $status);
        }

        // 模板 helper 兼容数组/对象，取数组行；分页仍在 $nodes 上。
        $nodeRows = $nodes->order(AgentMemoryNode::schema_fields_UPDATED_AT, 'DESC')
            ->pagination()
            ->select()
            ->fetchArray();

        $recentEdges = $this->memoryEdgeModel->reset()
            ->order(AgentMemoryEdge::schema_fields_EDGE_ID, 'DESC')
            ->limit(30)
            ->select()
            ->fetchArray();

        $this->assign('memory_nodes', $nodeRows);
        $this->assign('memory_edges', $recentEdges);
        $this->assign('pagination', $nodes->getPagination());
        $this->assign('current_type', $type);
        $this->assign('current_status', $status);
        $this->assign('node_types', [
            AgentMemoryNode::TYPE_FACT,
            AgentMemoryNode::TYPE_PREFERENCE,
            AgentMemoryNode::TYPE_ENTITY,
            AgentMemoryNode::TYPE_EVENT,
        ]);
        $this->assign('node_statuses', [
            AgentMemoryNode::STATUS_ACTIVE,
            AgentMemoryNode::STATUS_ARCHIVED,
            AgentMemoryNode::STATUS_FORGETTING,
        ]);

        return $this->fetch();
    }

    #[Acl('Weline_Agent::memory_listing', '记忆列表', '', '查看记忆列表')]
    public function listing()
    {
        return $this->getList();
    }
}
