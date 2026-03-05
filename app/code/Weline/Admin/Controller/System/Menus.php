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
use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Backend\Model\Menu;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Menus extends BaseController
{
    public function index()
    {
        $menu = $this->getMenu();
        
        $search = $this->request->getParam('search', '');
        
        if ($search) {
            $menu->where('name', '%' . $search . '%', 'like')
                 ->whereOr('title', '%' . $search . '%', 'like')
                 ->whereOr('source', '%' . $search . '%', 'like')
                 ->whereOr('module', '%' . $search . '%', 'like');
        }
        
        $allMenus = $menu->order('order', 'asc')->select()->fetchArray();
        
        $menuTree = $this->buildMenuTree($allMenus);
        
        $this->assign('menuTree', $menuTree);
        $this->assign('allMenus', $allMenus);
        $this->assign('search', $search);
        
        return $this->fetch();
    }

    /**
     * 构建菜单树形结构
     */
    private function buildMenuTree(array $menus, string $parentSource = ''): array
    {
        $tree = [];
        foreach ($menus as $menu) {
            if ($menu['parent_source'] === $parentSource) {
                $children = $this->buildMenuTree($menus, $menu['source']);
                $menu['children'] = $children;
                $menu['has_children'] = !empty($children);
                $tree[] = $menu;
            }
        }
        usort($tree, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        return $tree;
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
                
                $childCount = $this->getMenu()
                    ->where(Menu::schema_fields_PARENT_SOURCE, $menu->getSource())
                    ->select()
                    ->fetchArray();
                
                if (!empty($childCount)) {
                    throw new Exception(__('该菜单下有 %{1} 个子菜单，请先删除子菜单！', count($childCount)));
                }
                
                $menu->delete();
                MenuUrlValidator::clearCache();
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
                MenuUrlValidator::clearCache();
            }
            return json_encode($this->success());
        } catch (\Exception $exception) {
            return json_encode($this->exception($exception));
        }
    }

    /**
     * 更新菜单排序
     */
    public function postUpdateOrder()
    {
        try {
            $data = json_decode($this->request->getBodyParams(), true);
            if (!$data || !isset($data['orders']) || !is_array($data['orders'])) {
                throw new Exception(__('参数错误：缺少排序数据'));
            }

            foreach ($data['orders'] as $item) {
                if (isset($item['id']) && isset($item['order'])) {
                    $menu = $this->getMenu()->load($item['id']);
                    if ($menu->getId()) {
                        $menu->setOrder((int)$item['order'])->save();
                    }
                }
            }

            MenuUrlValidator::clearCache();
            return $this->fetchJson(['code' => 200, 'msg' => __('排序更新成功'), 'data' => []]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => []]);
        }
    }

    /**
     * 获取菜单详情（用于编辑）
     */
    public function getDetail()
    {
        try {
            $id = $this->request->getParam('id', 0);
            if (!$id) {
                throw new Exception(__('参数错误：缺少菜单ID'));
            }

            $menu = $this->getMenu()->load($id);
            if (!$menu->getId()) {
                throw new Exception(__('菜单不存在'));
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => 'success',
                'data' => $menu->getData()
            ]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => []]);
        }
    }

    private function getMenu(): Menu
    {
        return ObjectManager::getInstance(Menu::class);
    }
}
