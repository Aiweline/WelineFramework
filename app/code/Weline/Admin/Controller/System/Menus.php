<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller\System;

use Weline\Admin\Controller\BaseController;
use Weline\Backend\Model\Menu;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Menus extends BaseController
{
    public function index()
    {
        $menu = $this->getMenu();
        $menu->pagination(
            intval($this->request->getParam('page', 1)),
            intval($this->request->getParam('pageSize', 10)),
            $this->request->getParams()
        )->select();
        $this->assign('menus', $menu->fetch()->getItems());
        $this->assign('pagination', $menu->getPagination());
        return $this->fetch();
    }

    public function postDelete()
    {
        try {
            if ($id = $this->request->getPost('id', 0)) {
                /**@var Menu $menu */
                $menu = ObjectManager::getInstance(Menu::class)->load($id);
                if ($menu->isSystem()) {
                    throw new Exception(__('系统菜单无法删除！'));
                }
                $menu->delete();
                return $this->fetchJson(['code' => 200, 'msg' => __('删除成功！'), 'data' => []]);
            } else {
                return $this->fetchJson(['code' => 403, 'msg' => __('关键参数ID不存在！'), 'data' => []]);
            }
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => []]);
        }
    }

    public function postSave()
    {
        try {
            $data = json_decode($this->request->getBodyParams(), true);
            if ($data) {
                $this->getMenu()->save($data);
            }
            return json_encode($this->success());
        } catch (\Exception $exception) {
            return json_encode($this->exception($exception));
        }
    }

    private function getMenu(): Menu
    {
        return ObjectManager::getInstance(Menu::class);
    }
}
