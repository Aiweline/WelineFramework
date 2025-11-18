<?php

namespace Weline\Index\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Setting extends BackendController
{
    private \Aiweline\Index\Model\Backend\Setting $setting;

    function __construct(\Aiweline\Index\Model\Backend\Setting $setting)
    {
        $this->setting = $setting;
    }

    function index()
    {
        $search = $this->request->getParam('search', '');
        $position = $this->request->getParam('position', 'global');
        $page = $this->request->getParam('page', 0);
        $pageSize = $this->request->getParam('pageSize', 10);
        if ($search) {
            $this->setting->where('CONCAT(`name`,`key`,`value`)', '%' . $search . '%','like');
        }
        $settings = $this->setting
            ->where('position', $position)
            ->pagination($page, $pageSize)
            ->select();
        $this->assign('settings', $settings->fetchArray());
        $this->assign('pagination', $settings->getPagination());
        return $this->fetch();
    }

    function add()
    {
        if ($this->request->isGet()) return $this->fetch('form');
        try {
            $this->setting->setModelData($this->request->getParams())
                ->save();
            $this->getMessageManager()->addSuccess(__('保存成功'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            $this->redirect('/component/offcanvas/error', ['msg' => __('保存失败!')]);
        }
        $this->redirect('/component/offcanvas/success', ['msg' => __('保存成功!')]);
    }

    function edit()
    {
        $id = $this->request->getParam('id');
        if (empty($id)) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('/backend/setting');
        }
        $setting = $this->setting
            ->where('settings_id', $id)
            ->find()
            ->fetch();
        if (!$setting->getId()) {
            $this->getMessageManager()->addError(__('设置不存在'));
            $this->redirect('/backend/setting');
        }
        if ($this->request->isGet()) {
            $this->assign('setting', $setting);
            return $this->fetch('form');
        }
        try {
            $this->setting->setModelData($this->request->getParams())
                ->save();
            $this->getMessageManager()->addSuccess(__('保存成功'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            $this->redirect('/component/offcanvas/error', ['msg' => __('保存失败!')]);
        }
        $this->redirect('/component/offcanvas/success', ['msg' => __('保存成功!')]);
    }
}