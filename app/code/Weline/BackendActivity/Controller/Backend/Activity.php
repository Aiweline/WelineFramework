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

use Weline\Backend\Model\BackendUser;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_BackendActivity::activity_controller', '后台活动管理', 'fas fa-history', '查看管理员在后台的操作', 'Aiweline_BackendActivity:main')]
class Activity extends BackendController
{
    private BackendActivityLog $activityLog;

    function __construct(BackendActivityLog $activityLog)
    {
        $this->activityLog = $activityLog;
    }

    #[Acl('Weline_BackendActivity::listing', '后台活动历史', 'fas fa-history', '查看后台活动历史')]
    function getListing()
    {
        $search = $this->request->getGet('search');
        if ($search) {
            $this->activityLog->concat_like('name,u.username,main_table.user_id,request_id,request_method,request_params,request_data,host,path,module,url,ip,user_agent,response,response_code', "%$search%");
        }
        $logs = $this->activityLog
            ->joinModel(BackendUser::class, 'u', 'main_table.user_id=u.user_id')
            ->where('request_id', $this->request->getId(), '!=')
            ->order('backend_activity_log_id')
            ->pagination()
            ->select()
            ->fetchArray();
        $this->assign('logs', $logs);
        $this->assign('request_id', $this->request->getId());
        $this->assign('pagination', $this->activityLog->getPagination());
        return $this->fetch('listing');
    }

    #[Acl('Aiweline_BackendActivity::delete', '删除日志', 'fas fa-trash', '删除日志')]
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
                $result = w_var_export(json_decode($result), true);
            default:
        }
        $this->assign('data', $result);
        return $this->fetch('show');
    }
}