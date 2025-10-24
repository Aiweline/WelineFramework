<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/5 01:23:06
 */

namespace Weline\Smtp\Controller\Backend;

use Weline\Smtp\Model\SmtpSendLog;

class Log extends \Weline\Admin\Controller\BaseController
{
    /**
     * @var \Weline\Smtp\Model\SmtpSendLog
     */
    private SmtpSendLog $smtpSendLog;

    function __construct(SmtpSendLog $smtpSendLog)
    {
        $this->smtpSendLog = $smtpSendLog;
    }

    function listing()
    {
        $listings = $this->smtpSendLog->order('create_time')->pagination()->select()->fetch();
        $this->assign('logs', $listings->getOriginData());
        $this->assign('pagination', $listings->getPagination());
        $this->assign('total', $listings->getPaginationData()['totalSize']);
        return $this->fetch();
    }

    function get()
    {
        # TODO 预览邮件
        $log = $this->smtpSendLog->load($this->request->getGet('log_id', 0));
        $this->assign('log', $log);
        return $this->fetch();
    }

    function postDelete()
    {
        $log = $this->smtpSendLog->load($this->request->getPost('log_id', 0));
        if ($log->getId()) {
            $log->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } else {
            $this->getMessageManager()->addSuccess(__('你要删除的记录已不存在！'));
        }
        $this->redirect($this->_url->getBackendUrl('*/backend/log/listing'));
    }
}