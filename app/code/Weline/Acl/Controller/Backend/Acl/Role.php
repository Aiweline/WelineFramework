<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/7 20:39:18
 */

namespace Weline\Acl\Controller\Backend\Acl;

use Weline\Acl\Cache\AclCache;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Menu;
use Weline\Backend\Session\BackendSession;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role', '管理权限', 'mdi mdi-security', '访问控制权限管理', 'Weline_Acl::acl')]
class Role extends \Weline\Admin\Controller\BaseController
{
    private \Weline\Acl\Model\Role $role;

    function __construct(
        \Weline\Acl\Model\Role $role,
    )
    {
        $this->role = $role;
    }

    function getIndex()
    {
        if ($search = $this->request->getGet('search')) {
            $aclModelFields = implode(',', $this->role->getModelFields());
            $this->role->where('CONCAT(' . $aclModelFields . ')', '%' . $search . '%', 'like');
        }
        $this->role->pagination()->select()->fetch();
        $this->assign('roles', $this->role->getItems());
        $this->assign('pagination', $this->role->getPagination());
        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_add', '角色添加', '', '')]
    function add()
    {
        if ($this->request->isGet()) {
            $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/acl/role/add'));
            return $this->fetch('form');
        }

        if ($this->request->isPost()) {
            $role = $this->role->clear()->where($this->role::fields_ROLE_NAME, $this->request->getPost('role_name'))->find()->fetch();
            if ($role->getId()) {
                $this->getMessageManager()->addWarning(__('角色已存在！'));
                $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/acl/role/add'));
                return $this->fetch('form');
            }
            try {
                $this->role->setData($this->request->getPost())
                    ->save(true, $this->role::fields_ROLE_NAME);
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/acl/role');
        } else {
            $this->redirect(404);
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_edit', '角色编辑', '', '')]
    function edit()
    {
        if ($this->request->isGet()) {
            $id = $this->request->getGet('id');
            if (!$id) {
                $this->redirect(404);
            }
            $role = clone $this->role->clear()->load($id);
            if (!$role->getId()) {
                $this->getMessageManager()->addWarning(__('角色已不存在！'));
            } else {
                $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/acl/role/edit'));
                $this->assign('edit_role', $role);
            }
            return $this->fetch('form');
        }
        if ($this->request->isPost()) {
            $this->role->save($this->request->getPost());
            $this->redirect('*/backend/acl/role');
        } else {
            $this->redirect(404);
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_delete', '角色删除', '', '')]
    public function postDelete()
    {
        $id = $this->request->getPost('id');
        if (!$id) {
            $this->redirect(404);
        }
        $role = $this->role->load($id);
        if (!$role->getId()) {
            $this->getMessageManager()->addWarning(__('角色已不存在！'));
        }
        try {
            $role->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (\ReflectionException|Exception|Core $e) {
            $this->getMessageManager()->addException($e);
        }
        $this->redirect('*/backend/acl/role');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_assign', '权限分配', '', '')]
    public function getAssign()
    {
        // 角色
        $id = $this->request->getGet('id');
        if (!$id) {
            $this->redirect(404);
        }

        $role = clone $this->role->load($id);
        if (!$role->getId()) {
            $this->getMessageManager()->addWarning(__('角色已不存在！'));
            $this->redirect('*/backend/acl/role');
        } else {
            $this->assign('assign_role', $role->getData());
        }
        // 可分配权限
        /**@var \Weline\Acl\Model\RoleAccess $roleAccessModel */
        $roleAccessModel = ObjectManager::getInstance(\Weline\Acl\Model\RoleAccess::class);
        $trees = $roleAccessModel->clear()->getTreeWithRole($role);
        // 当前角色权限
        $current_accesses = $roleAccessModel->clearData()->getRoleAccessList($role);
//        $this->checkAccess($trees, $current_accesses);
        $this->assign('trees', $trees);
        $this->assign('current_accesses', $current_accesses);
        // 当前用户角色
        /**@var BackendSession $session */
        $session = ObjectManager::getInstance(BackendSession::class);
        $this->assign('user_role', $session->getLoginUser()->getRole());
        return $this->fetch('assign');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_assign_post', '角色权限分配', '', '')]
    public function postAssign()
    {
        $role_id = $this->request->getPost('role_id');
        $role = $this->role->clear()->load($role_id);
        if (empty($role->getId())) {
            $this->getMessageManager()->addError(__('角色ID不存在！'));
            $this->redirect('*/backend/acl/role');
        }
        $acl_ids = $this->request->getPost('ids', []);
        $acls = [];
        foreach ($acl_ids as $acl_id) {
            $acls[] = [
                RoleAccess::fields_ROLE_ID => $role_id,
                RoleAccess::fields_SOURCE_ID => $acl_id,
            ];
        }
        /**@var RoleAccess $roleAccessModel */
        $roleAccessModel = ObjectManager::getInstance(RoleAccess::class);
        $roleAccessModel->beginTransaction();
        try {
            $roleAccessModel->where(\Weline\Acl\Model\Role::fields_ROLE_ID, $role_id)->delete()->fetch();
            if ($acls) {
                $roleAccessModel->reset()->insert($acls, [RoleAccess::fields_ROLE_ID, RoleAccess::fields_SOURCE_ID])->fetch();
            }
            $roleAccessModel->commit();
            $this->getMessageManager()->addSuccess(__('权限分配成功！'));
            // 清理权限缓存
            /**@var \Weline\Framework\Cache\CacheInterface $aclCache */
            $aclCache = ObjectManager::getInstance(AclCache::class . 'Factory');
            $aclCache->clear();
            $this->getMessageManager()->addSuccess(__('权限缓存清理成功！'));
        } catch (\Exception $exception) {
            $roleAccessModel->rollBack();
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->getMessageManager()->addError(__('权限分配失败！'));
        }
        $this->redirect('*/backend/acl/role/assign', ['id' => $role_id]);
    }

}