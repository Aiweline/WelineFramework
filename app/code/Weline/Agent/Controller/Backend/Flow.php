<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Agent\Model\AgentFlow;
use Weline\Agent\Model\AgentFlowRun;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * Backend flow management（列表/删除；编排编辑在前台 /agent/frontend/flow）
 */
#[Acl('Weline_Agent::flow', '流程编排', '管理可视化角色流程', '')]
class Flow extends BackendController
{
    use AgentHtmlSafeMutationTrait;

    public function __construct(
        private readonly AgentFlow $flowModel,
    ) {}

    #[Acl('Weline_Agent::flow_list', '流程列表', '', '查看流程列表')]
    public function getList()
    {
        $flows = $this->flowModel->reset()
            ->order(AgentFlow::schema_fields_UPDATED_TIME, 'DESC')
            ->pagination()
            ->select()
            ->fetchArray();

        // 每个流程最近一次运行状态
        $lastRuns = [];
        try {
            $runModel = ObjectManager::getInstance(AgentFlowRun::class);
            foreach ($flows as $flow) {
                $fid = (int)($flow[AgentFlow::schema_fields_FLOW_ID] ?? 0);
                if ($fid <= 0) {
                    continue;
                }
                $row = $runModel->reset()->clearQuery()
                    ->where(AgentFlowRun::schema_fields_FLOW_ID, $fid)
                    ->order(AgentFlowRun::schema_fields_CREATED_TIME, 'DESC')
                    ->limit(1)
                    ->select()
                    ->fetch();
                if ($row && $row->getId()) {
                    $lastRuns[$fid] = [
                        'status' => (string)$row->getData(AgentFlowRun::schema_fields_STATUS),
                        'time' => (int)$row->getData(AgentFlowRun::schema_fields_CREATED_TIME),
                    ];
                }
            }
        } catch (\Throwable) {
        }

        $this->assign('flows', $flows);
        $this->assign('pagination', $this->flowModel->getPagination());
        $this->assign('last_runs', $lastRuns);
        $this->assign('editor_url', '/agent/frontend/flow');
        return $this->fetch();
    }

    #[Acl('Weline_Agent::flow_list', '流程列表', '', '查看流程列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Agent::flow_delete', '删除流程', '', '删除流程定义')]
    public function postDelete()
    {
        $id = (int)$this->request->getParam('id', 0);
        try {
            $flow = $this->flowModel->clearData()->load($id)->fetch();
            if (!$flow->getId()) {
                return $this->respondMutation(['success' => false, 'msg' => __('流程不存在')], '*/backend/flow/listing');
            }
            $flow->delete();
            return $this->respondMutation(['success' => true, 'msg' => __('流程已删除')], '*/backend/flow/listing');
        } catch (\Throwable $e) {
            return $this->respondMutation(['success' => false, 'msg' => $e->getMessage()], '*/backend/flow/listing');
        }
    }
}
