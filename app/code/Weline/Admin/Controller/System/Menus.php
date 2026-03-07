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
use Weline\Acl\Model\Acl;
use Weline\Acl\Service\ResourceTreeServiceInterface;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Menus extends BaseController
{
    private ?ResourceTreeServiceInterface $resourceTreeService = null;

    /**
     * 获取资源树服务
     */
    private function getResourceTreeService(): ResourceTreeServiceInterface
    {
        if ($this->resourceTreeService === null) {
            $this->resourceTreeService = ObjectManager::getInstance(ResourceTreeServiceInterface::class);
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
                $name = $menu[Acl::schema_fields_SOURCE_NAME] ?? '';
                $sourceId = $menu[Acl::schema_fields_SOURCE_ID] ?? '';
                $module = $menu[Acl::schema_fields_MODULE] ?? '';
                return stripos($name, $search) !== false 
                    || stripos($sourceId, $search) !== false 
                    || stripos($module, $search) !== false;
            });
            $allMenus = array_values($allMenus);
        }
        
        $menuTree = $this->getResourceTreeService()->buildMenuManagementTree($allMenus);
        
        $this->assign('menuTree', $menuTree);
        $this->assign('allMenus', $allMenus);
        $this->assign('search', $search);
        
        return $this->fetch();
    }

    public function postDelete()
    {
        try {
            $id = $this->request->getPost('id', 0);
            if (!$id) {
                return $this->fetchJson(['code' => 403, 'msg' => __('关键参数ID不存在！'), 'data' => []]);
            }
            
            // 加载 ACL 菜单资源
            $menu = $this->getResourceTreeService()->loadMenuResource($id);
            if (!$menu || !$menu->getSourceId()) {
                return $this->fetchJson(['code' => 403, 'msg' => __('菜单不存在！'), 'data' => []]);
            }
            
            // 检查是否有子菜单
            if ($this->getResourceTreeService()->hasMenuChildren($menu->getSourceId())) {
                return $this->fetchJson(['code' => 403, 'msg' => __('该菜单下有子菜单，请先删除子菜单！'), 'data' => []]);
            }
            
            // 删除
            $menu->delete();
            MenuUrlValidator::clearCache();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('删除成功！'), 'data' => []]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => []]);
        }
    }

    public function postSave()
    {
        try {
            $data = json_decode($this->request->getBodyParams(), true);
            if ($data) {
                // 映射字段名以兼容前端提交的数据
                $mappedData = $this->mapMenuDataToAcl($data);
                
                // 获取或创建 ACL 记录
                $acl = ObjectManager::getInstance(Acl::class);
                $sourceId = $mappedData[Acl::schema_fields_SOURCE_ID] ?? '';
                
                if ($sourceId) {
                    $acl->load($sourceId, Acl::schema_fields_SOURCE_ID);
                }
                
                $acl->addData($mappedData);
                
                // 确保类型为 menus
                if (!$acl->getType()) {
                    $acl->setType(Acl::type_MENUS);
                }
                
                $acl->save();
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
            $mapped[Acl::schema_fields_SOURCE_ID] = $data['source_id'];
        } elseif (isset($data['source'])) {
            $mapped[Acl::schema_fields_SOURCE_ID] = $data['source'];
        }
        
        // source_name (title -> source_name)
        if (isset($data['title'])) {
            $mapped[Acl::schema_fields_SOURCE_NAME] = $data['title'];
        } elseif (isset($data['source_name'])) {
            $mapped[Acl::schema_fields_SOURCE_NAME] = $data['source_name'];
        }
        
        // route (action -> route)
        if (isset($data['action'])) {
            $mapped[Acl::schema_fields_ROUTE] = trim($data['action'], '/');
        } elseif (isset($data['route'])) {
            $mapped[Acl::schema_fields_ROUTE] = trim($data['route'], '/');
        }
        
        // parent_source
        if (isset($data['parent_source'])) {
            $mapped[Acl::schema_fields_PARENT_SOURCE] = $data['parent_source'];
        }
        
        // icon
        if (isset($data['icon'])) {
            $mapped[Acl::schema_fields_ICON] = $data['icon'];
        }
        
        // order
        if (isset($data['order'])) {
            $mapped[Acl::schema_fields_ORDER] = (int)$data['order'];
        }
        
        // module
        if (isset($data['module'])) {
            $mapped[Acl::schema_fields_MODULE] = $data['module'];
        }
        
        // is_enable
        if (isset($data['is_enable'])) {
            $mapped[Acl::schema_fields_IS_ENABLE] = (int)$data['is_enable'];
        }
        
        // is_backend
        if (isset($data['is_backend'])) {
            $mapped[Acl::schema_fields_IS_BACKEND] = (int)$data['is_backend'];
        }
        
        // type (确保为 menus)
        $mapped[Acl::schema_fields_TYPE] = Acl::type_MENUS;
        
        // acl_id (如果存在)
        if (isset($data['acl_id'])) {
            $mapped[Acl::schema_fields_ACL_ID] = $data['acl_id'];
        }
        
        return $mapped;
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

            $acl = ObjectManager::getInstance(Acl::class);
            foreach ($data['orders'] as $item) {
                if (isset($item['id']) && isset($item['order'])) {
                    // id 可能是 acl_id 或 source_id
                    $menu = $this->getResourceTreeService()->loadMenuResource($item['id']);
                    if ($menu && $menu->getSourceId()) {
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

            $menu = $this->getResourceTreeService()->loadMenuResource($id);
            if (!$menu || !$menu->getSourceId()) {
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
}
