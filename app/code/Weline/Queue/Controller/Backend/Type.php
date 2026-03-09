<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：18/7/2023 10:36:12
 */

namespace Weline\Queue\Controller\Backend;

class Type extends \Weline\Framework\App\Controller\BackendController
{
    private \Weline\Queue\Model\Queue\Type $type;

    public function __construct(\Weline\Queue\Model\Queue\Type $type)
    {
        $this->type = $type;
    }

    public function index()
    {
        $this->assign('title', __('队列类型'));
        if ($search = $this->request->getGet('q')) {
            $this->type->where('concat(name,type_id)', "%$search%", 'LIKE');
        }
        $this->type->pagination()->select()->fetch();
        $this->assign('types', $this->type->getItems());
        $this->assign('pagination', $this->type->getPagination());
        return $this->fetch();
    }

    public function enable()
    {
        $id = $this->request->getGet('id');
        $isAjax = $this->request->isXmlHttpRequest();
        
        if (empty($id)) {
            $msg = __('请选择要启用的队列类型');
            if ($isAjax) {
                return $this->fetchJson(['code' => 400, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect('/component/offcanvas/error', ['msg' => $msg, 'reload' => 1]);
        }
        
        $this->type->load($id);
        if (!$this->type->getId()) {
            $msg = __('队列类型不存在');
            if ($isAjax) {
                return $this->fetchJson(['code' => 404, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect('/component/offcanvas/error', ['msg' => $msg, 'reload' => 0]);
        }

        $this->type->setEnable(true);
        $this->type->save();
        
        $msg = __('队列类型 "%1" 已成功启用', $this->type->getName());
        if ($isAjax) {
            return $this->fetchJson(['code' => 200, 'msg' => $msg]);
        }
        $this->getMessageManager()->addSuccess($msg);
        $this->redirect('/component/offcanvas/success', ['msg' => $msg, 'reload' => 1]);
    }

    public function disable()
    {
        $id = $this->request->getGet('id');
        $isAjax = $this->request->isXmlHttpRequest();
        
        if (empty($id)) {
            $msg = __('请选择要禁用的队列类型');
            if ($isAjax) {
                return $this->fetchJson(['code' => 400, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect('/component/offcanvas/error', ['msg' => $msg, 'reload' => 1]);
        }
        
        $this->type->load($id);
        if (!$this->type->getId()) {
            $msg = __('队列类型不存在');
            if ($isAjax) {
                return $this->fetchJson(['code' => 404, 'msg' => $msg]);
            }
            $this->getMessageManager()->addWarning($msg);
            $this->redirect('/component/offcanvas/error', ['msg' => $msg, 'reload' => 0]);
        }
        
        $this->type->setEnable(false);
        $this->type->save();
        
        $msg = __('队列类型 "%1" 已成功禁用', $this->type->getName());
        if ($isAjax) {
            return $this->fetchJson(['code' => 200, 'msg' => $msg]);
        }
        $this->getMessageManager()->addSuccess($msg);
        $this->redirect('/component/offcanvas/success', ['msg' => $msg, 'reload' => 1]);
    }

    function show()
    {
        $this->layoutType = 'default.blank';
        $id = $this->request->getGet('id');
        if (empty($id)) {
            $this->getMessageManager()->addWarning(__('请选择要查看的队列类型'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('请选择要查看的队列类型'), 'reload' => 1]);
        }
        $this->type->load($id);
        if (!$this->type->getId()) {
            $this->getMessageManager()->addWarning(__('队列类型不存在'));
            $this->redirect('/component/offcanvas/error', ['msg' => __('队列类型不存在'), 'reload' => 0]);
        }
        $this->assign('type', $this->type);
        return $this->fetch();
    }
}
