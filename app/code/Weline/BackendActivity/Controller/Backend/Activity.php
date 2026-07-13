<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：7/4/2024 14:41:09
 */

namespace Weline\BackendActivity\Controller\Backend;

use Weline\Backend\Api\User\BackendUserAdministrationInterface;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_BackendActivity::activity_controller', '后台活动管理', 'fas fa-history', '查看管理员在后台的操作', 'Weline_BackendActivity::main')]
class Activity extends BackendController
{
    private const SEARCH_FIELDS = 'name,main_table.user_id,request_id,request_method,request_params,request_data,host,path,module,url,ip,user_agent,response,response_code,business_module,business_entity_type,business_entity_id,business_action,business_title,business_payload';

    private BackendActivityLog $activityLog;
    private BackendUserAdministrationInterface $backendUsers;

    function __construct(
        BackendActivityLog $activityLog,
        BackendUserAdministrationInterface $backendUsers,
    )
    {
        $this->activityLog = $activityLog;
        $this->backendUsers = $backendUsers;
    }

    #[Acl('Weline_BackendActivity::listing', '后台活动历史', 'fas fa-history', '查看后台活动历史')]
    function getListing()
    {
        $search = $this->request->getGet('search');
        if ($search) {
            // Keep the request-id exclusion on both sides of the OR branch:
            // C AND A OR B AND C is equivalent to C AND (A OR B).
            $this->activityLog->where('request_id', $this->request->getId(), '!=');
            $this->applySearch((string)$search);
        }
        $logs = $this->activityLog
            ->where('request_id', $this->request->getId(), '!=')
            ->order('backend_activity_log_id')
            ->pagination()
            ->select()
            ->fetchArray();
        $logs = $this->hydrateUsernames($logs);
        $this->assign('logs', $logs);
        $this->assign('request_id', $this->request->getId());
        $this->assign('pagination', $this->activityLog->getPagination());
        return $this->fetch('listing');
    }

    private function applySearch(string $search): void
    {
        $userIds = $this->backendUsers->idsMatchingUsername($search);
        $this->activityLog->where(
            'CONCAT(' . self::SEARCH_FIELDS . ')',
            '%' . $search . '%',
            'like',
            $userIds === [] ? 'and' : 'or',
        );
        if ($userIds !== []) {
            $this->activityLog->where('main_table.user_id', $userIds, 'in');
        }
    }

    /** @param array<array-key, mixed> $logs @return array<array-key, mixed> */
    private function hydrateUsernames(array $logs): array
    {
        $userIds = [];
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $userId = (int)($log[BackendActivityLog::schema_fields_user_id] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }
        $usernames = $this->backendUsers->usernamesByIds(array_values($userIds));

        foreach ($logs as &$log) {
            if (!is_array($log)) {
                continue;
            }
            $userId = (int)($log[BackendActivityLog::schema_fields_user_id] ?? 0);
            $log['username'] = $usernames[$userId] ?? null;
        }
        unset($log);

        return $logs;
    }

    #[Acl('Weline_BackendActivity::delete', '删除日志', 'fas fa-trash', '删除日志')]
    function getDelete()
    {
        $id = $this->request->getGet('id');
        if (!$id) {
            $this->getMessageManager()->addError('日志不存在！');
            return $this->redirect('*/backend/activity/listing');
        }
        $res = $this->activityLog->clearData()->reset()
            ->where('backend_activity_log_id', $this->request->getGet('id'))
            ->delete();
        $this->getMessageManager()->addSuccess('日志已删除！');
        return $this->redirect('*/backend/activity/listing');
    }

    #[Acl('Weline_BackendActivity::show', '后台活动详情', 'fas fa-eye', '查看后台活动详情')]
    function getShow()
    {
        $type   = $this->request->getGet('type');
        $log    = $this->activityLog
            ->where('backend_activity_log_id', $this->request->getGet('id'))
            ->find()
            ->fetchArray();
        $result = $log[$type] ?? '日志已不存在！';
        switch ($type) {
            case 'request_data':
            case 'request_params':
            case 'business_payload':
                $result = w_var_export(json_decode($result), true);
            default:
        }
        $this->assign('data', $result);
        return $this->fetch('show');
    }
}
