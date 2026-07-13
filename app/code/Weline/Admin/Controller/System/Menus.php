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
use Weline\Acl\Api\Resource\MenuResourceServiceInterface;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Menus extends BaseController
{
    private ?MenuResourceServiceInterface $resourceTreeService = null;

    /**
     * 获取资源树服务
     */
    private function getResourceTreeService(): MenuResourceServiceInterface
    {
        if ($this->resourceTreeService === null) {
            $this->resourceTreeService = ObjectManager::getInstance(MenuResourceServiceInterface::class);
        }
        return $this->resourceTreeService;
    }

    public function index()
    {
        $search = $this->request->getParam('search', '');
        
        // 从 ACL 表获取所有菜单资源
        $allMenus = $this->getResourceTreeService()->getAllMenuResources();
        
        // 搜索过滤
        if ($search) {
            $allMenus = array_filter($allMenus, function($menu) use ($search) {
                $name = $menu['source_name'] ?? '';
                $sourceId = $menu['source_id'] ?? '';
                $module = $menu['module'] ?? '';
                return stripos($name, $search) !== false 
                    || stripos($sourceId, $search) !== false 
                    || stripos($module, $search) !== false;
            });
            $allMenus = array_values($allMenus);
        }
        
        $menuTree = $this->getResourceTreeService()->buildManagementTree($allMenus);
        
        $this->assign('menuTree', $menuTree);
        $this->assign('allMenus', $allMenus);
        $this->assign('search', $search);
        
        return $this->fetch();
    }

    #[AclAttribute('Weline_Admin::system_menu_delete', '删除菜单', 'mdi mdi-delete-outline', '删除后台菜单资源', 'Weline_Admin::system_menu_manager', accessMode: AclAttribute::ACCESS_MODE_EDIT)]
    public function postDelete()
    {
        try {
            $id = $this->request->getPost('id', 0);
            if (!$id) {
                return $this->fetchJson(['code' => 403, 'msg' => __('关键参数ID不存在！'), 'data' => []]);
            }
            
            // 加载 ACL 菜单资源
            $menu = $this->getResourceTreeService()->loadMenuResource($id);
            if (!$menu || empty($menu['source_id'])) {
                return $this->fetchJson(['code' => 403, 'msg' => __('菜单不存在！'), 'data' => []]);
            }
            
            // 检查是否有子菜单
            if ($this->getResourceTreeService()->hasMenuChildren((string)$menu['source_id'])) {
                return $this->fetchJson(['code' => 403, 'msg' => __('该菜单下有子菜单，请先删除子菜单！'), 'data' => []]);
            }
            
            // 删除
            $this->getResourceTreeService()->deleteMenuResource($id);
            MenuUrlValidator::clearCache();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('删除成功！'), 'data' => []]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => []]);
        }
    }

    #[AclAttribute('Weline_Admin::system_menu_save', '保存菜单', 'mdi mdi-content-save-outline', '新增或更新后台菜单资源', 'Weline_Admin::system_menu_manager', accessMode: AclAttribute::ACCESS_MODE_EDIT)]
    public function postSave()
    {
        try {
            $data = json_decode($this->request->getBodyParams(), true);
            if ($data) {
                // 映射字段名以兼容前端提交的数据
                $mappedData = $this->mapMenuDataToAcl($data);
                
                $this->getResourceTreeService()->saveMenuResource($mappedData);
                MenuUrlValidator::clearCache();
            }
            return json_encode($this->success());
        } catch (\Exception $exception) {
            return json_encode($this->exception($exception));
        }
    }

    /**
     * 映射前端数据到 ACL 字段
     */
    private function mapMenuDataToAcl(array $data): array
    {
        $mapped = [];
        
        // source_id
        if (isset($data['source_id'])) {
            $mapped['source_id'] = $data['source_id'];
        } elseif (isset($data['source'])) {
            $mapped['source_id'] = $data['source'];
        }
        
        // source_name (title -> source_name)
        if (isset($data['title'])) {
            $mapped['source_name'] = $data['title'];
        } elseif (isset($data['source_name'])) {
            $mapped['source_name'] = $data['source_name'];
        }
        
        // route (action -> route)
        if (isset($data['action'])) {
            $mapped['route'] = trim($data['action'], '/');
        } elseif (isset($data['route'])) {
            $mapped['route'] = trim($data['route'], '/');
        }
        
        // parent_source
        if (isset($data['parent_source'])) {
            $mapped['parent_source'] = $data['parent_source'];
        }
        
        // icon
        if (isset($data['icon'])) {
            $mapped['icon'] = $data['icon'];
        }
        
        // order
        if (isset($data['order'])) {
            $mapped['order'] = (int)$data['order'];
        }
        
        // module
        if (isset($data['module'])) {
            $mapped['module'] = $data['module'];
        }
        
        // is_enable
        if (isset($data['is_enable'])) {
            $mapped['is_enable'] = (int)$data['is_enable'];
        }
        
        // is_backend
        if (isset($data['is_backend'])) {
            $mapped['is_backend'] = (int)$data['is_backend'];
        }
        
        // acl_id (如果存在)
        if (isset($data['acl_id'])) {
            $mapped['acl_id'] = $data['acl_id'];
        }
        
        return $mapped;
    }

    /**
     * 更新菜单排序
     */
    #[AclAttribute('Weline_Admin::system_menu_update_order', '更新菜单排序', 'mdi mdi-sort', '更新后台菜单排序', 'Weline_Admin::system_menu_manager', accessMode: AclAttribute::ACCESS_MODE_EDIT)]
    public function postUpdateOrder()
    {
        try {
            $data = json_decode($this->request->getBodyParams(), true);
            if (!$data || !isset($data['orders']) || !is_array($data['orders'])) {
                throw new Exception(__('参数错误：缺少排序数据'));
            }

            foreach ($data['orders'] as $item) {
                if (isset($item['id']) && isset($item['order'])) {
                    $this->getResourceTreeService()->updateMenuOrder($item['id'], (int)$item['order']);
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
    #[AclAttribute('Weline_Admin::system_menu_detail', '查看菜单详情', 'mdi mdi-eye-outline', '查看后台菜单资源详情', 'Weline_Admin::system_menu_manager', accessMode: AclAttribute::ACCESS_MODE_READ)]
    public function getDetail()
    {
        try {
            $id = $this->request->getParam('id', 0);
            if (!$id) {
                throw new Exception(__('参数错误：缺少菜单ID'));
            }

            $menu = $this->getResourceTreeService()->loadMenuResource($id);
            if (!$menu || empty($menu['source_id'])) {
                throw new Exception(__('菜单不存在'));
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => 'success',
                'data' => $menu
            ]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => []]);
        }
    }
}
