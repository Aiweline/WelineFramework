<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Bot\Model\BotMemoryEdge;
use Weline\Bot\Model\BotMemoryNode;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

/**
 * Backend memory management.
 */
#[Acl('Weline_Bot::memory', 'Memory Management', 'Manage bot memory graph nodes and edges', '')]
class Memory extends BackendController
{
    public function __construct(
        private readonly BotMemoryNode $memoryNodeModel,
        private readonly BotMemoryEdge $memoryEdgeModel,
    ) {}

    #[Acl('Weline_Bot::memory_list', 'Memory List', '', 'View memory list')]
    public function getList()
    {
        $type = trim((string) $this->request->getParam('type', ''));
        $status = trim((string) $this->request->getParam('status', ''));

        $nodes = $this->memoryNodeModel->reset();
        if ($type !== '') {
            $nodes->where(BotMemoryNode::schema_fields_NODE_TYPE, $type);
        }
        if ($status !== '') {
            $nodes->where(BotMemoryNode::schema_fields_STATUS, $status);
        }

        $nodes->order(BotMemoryNode::schema_fields_UPDATED_AT, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $recentEdges = $this->memoryEdgeModel->reset()
            ->order(BotMemoryEdge::schema_fields_EDGE_ID, 'DESC')
            ->limit(30)
            ->select()
            ->fetch();

        $this->assign('memory_nodes', $nodes->getItems());
        $this->assign('memory_edges', $recentEdges->getItems());
        $this->assign('pagination', $nodes->getPagination());
        $this->assign('current_type', $type);
        $this->assign('current_status', $status);
        $this->assign('node_types', [
            BotMemoryNode::TYPE_FACT,
            BotMemoryNode::TYPE_PREFERENCE,
            BotMemoryNode::TYPE_ENTITY,
            BotMemoryNode::TYPE_EVENT,
        ]);
        $this->assign('node_statuses', [
            BotMemoryNode::STATUS_ACTIVE,
            BotMemoryNode::STATUS_ARCHIVED,
            BotMemoryNode::STATUS_FORGETTING,
        ]);

        return $this->fetch();
    }

    #[Acl('Weline_Bot::memory_listing', 'Memory List', '', 'View memory list')]
    public function listing()
    {
        return $this->getList();
    }
}
