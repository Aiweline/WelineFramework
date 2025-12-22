<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Backend;

use Weline\Backend\Model\BackendUser;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\StatisticsService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

/**
 * 客服人员管理控制器
 */
#[Acl('Weline_CustomerService::agent', '客服人员', 'mdi-account', '客服人员管理', 'Weline_CustomerService::customer_service')]
class Agent extends BackendController
{
    private StatisticsService $statisticsService;

    public function __construct(
        StatisticsService $statisticsService
    ) {
        $this->statisticsService = $statisticsService;
    }
    /**
     * 客服人员列表
     */
    #[Acl('Weline_CustomerService::agent_index', '查看客服人员', 'mdi-account', '查看客服人员')]
    public function index(): string
    {
        try {
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            
            $agents = $agent->reset()
                ->select()
                ->fetch()
                ->getItems();

            // 获取关联的用户信息和统计数据
            foreach ($agents as &$agentData) {
                if ($agentData['user_id']) {
                    /** @var BackendUser $user */
                    $user = ObjectManager::getInstance(BackendUser::class);
                    $user->load($agentData['user_id']);
                    if ($user->getId()) {
                        $agentData['user_name'] = $user->getUsername();
                    }
                }
                
                // 获取统计数据（全部时间）
                $agentId = (int)$agentData['agent_id'];
                if ($agentId > 0) {
                    $statistics = $this->statisticsService->getAgentStatistics($agentId, 'all');
                    $agentData['statistics'] = [
                        'total_sessions' => $statistics['sessions']['total'],
                        'total_messages' => $statistics['messages']['total'],
                        'avg_response_time' => $statistics['response_time']['average'],
                    ];
                }
            }

            $this->assign('agents', $agents);
            $this->assign('page_title', __('客服人员管理'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载客服人员失败：%{1}', $e->getMessage()));
            $this->assign('agents', []);
            return $this->fetch();
        }
    }

    /**
     * 保存客服人员
     */
    #[Acl('Weline_CustomerService::agent_save', '保存客服人员', 'mdi-content-save', '保存客服人员')]
    public function save(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $agentId = (int)$this->request->getPost('agent_id', 0);
            $userId = (int)$this->request->getPost('user_id', 0);
            $name = trim($this->request->getPost('name', ''));
            $email = trim($this->request->getPost('email', ''));
            $locale = trim($this->request->getPost('locale', 'zh_Hans_CN'));
            $isActive = (int)$this->request->getPost('is_active', 1);
            $maxSessions = (int)$this->request->getPost('max_sessions', 10);

            if (empty($name)) {
                return $this->jsonResponse(false, __('客服名称不能为空'));
            }

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->jsonResponse(false, __('邮箱格式不正确'));
            }

            if ($userId <= 0) {
                return $this->jsonResponse(false, __('请选择后台用户'));
            }

            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            
            if ($agentId > 0) {
                $agent->load($agentId);
                if (!$agent->getId()) {
                    return $this->jsonResponse(false, __('客服人员不存在'));
                }
            }

            $agent->setUserId($userId)
                ->setName($name)
                ->setEmail($email)
                ->setLocale($locale)
                ->setIsActive((bool)$isActive)
                ->setMaxSessions($maxSessions)
                ->setData(ServiceAgent::fields_updated_at, date('Y-m-d H:i:s'));

            if ($agentId <= 0) {
                $agent->setData(ServiceAgent::fields_created_at, date('Y-m-d H:i:s'));
            }

            $agent->save();

            return $this->jsonResponse(true, __('保存成功'), ['agent_id' => $agent->getId()]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 删除客服人员
     */
    #[Acl('Weline_CustomerService::agent_delete', '删除客服人员', 'mdi-delete', '删除客服人员')]
    public function delete(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $agentId = (int)$this->request->getPost('agent_id', 0);

            if ($agentId <= 0) {
                return $this->jsonResponse(false, __('无效的客服ID'));
            }

            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->load($agentId);

            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('客服人员不存在'));
            }

            $agent->delete();

            return $this->jsonResponse(true, __('删除成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('删除失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 获取客服详细统计（AJAX）
     */
    #[Acl('Weline_CustomerService::agent_statistics', '查看客服统计', 'mdi-chart-line', '查看客服统计')]
    public function getAgentStatistics(): string
    {
        try {
            $agentId = (int)$this->request->getParam('agent_id', 0);
            $period = $this->request->getParam('period', 'all');

            if ($agentId <= 0) {
                return $this->jsonResponse(false, __('无效的客服ID'));
            }

            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->load($agentId);
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('客服人员不存在'));
            }

            $statistics = $this->statisticsService->getAgentStatistics($agentId, $period);

            return $this->jsonResponse(true, __('获取成功'), $statistics);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取统计数据失败：%{1}', $e->getMessage()));
        }
    }

    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}

