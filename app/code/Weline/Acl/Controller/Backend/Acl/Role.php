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

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Menu;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
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

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_listing', '角色列表', '', '')]
    function getIndex()
    {
        // 创建新实例避免 WLS 环境下状态残留
        /** @var \Weline\Acl\Model\Role $roleModel */
        $roleModel = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false);
        if ($search = $this->request->getGet('search')) {
            $aclModelFields = implode(',', $roleModel->getModelFields());
            $roleModel->where('CONCAT(' . $aclModelFields . ')', '%' . $search . '%', 'like');
        }
        $roleModel->pagination()->select()->fetch();
        $this->assign('roles', $roleModel->getItems());
        $this->assign('pagination', $roleModel->getPagination());
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
            // 对于 AJAX 请求，优先从 body 中获取 JSON 数据，否则使用 POST 数据
            if ($this->request->isAjax()) {
                // 尝试从 body 中获取 JSON 数据
                $bodyParams = $this->request->getBodyParams(true);
                // 如果 body 参数存在且是数组，使用 body 参数，否则使用 POST 数据
                if (is_array($bodyParams) && !empty($bodyParams)) {
                    $postData = $bodyParams;
                } else {
                    // 如果 body 参数为空，尝试使用 getParams()，它会合并 body 和 POST 数据
                    $postData = $this->request->getParams();
                }
            } else {
                $postData = $this->request->getPost();
            }
            
            $role_name = $postData['role_name'] ?? '';
            if (empty($role_name)) {
                if ($this->request->isAjax()) {
                    return $this->jsonResponse(false, __('角色名不能为空！'));
                }
                $this->getMessageManager()->addError(__('角色名不能为空！'));
                $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/acl/role/add'));
                return $this->fetch('form');
            }
            
            // 检查角色是否已存在（创建新实例避免状态污染，$shared=false）
            /** @var \Weline\Acl\Model\Role $checkRole */
            $checkRole = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false);
            $existingRole = $checkRole->where(\Weline\Acl\Model\Role::fields_ROLE_NAME, $role_name)->find()->fetch();
            if ($existingRole->getId()) {
                if ($this->request->isAjax()) {
                    return $this->jsonResponse(false, __('角色已存在！'));
                }
                $this->getMessageManager()->addWarning(__('角色已存在！'));
                $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/acl/role/add'));
                return $this->fetch('form');
            }
            
            try {
                // 创建新实例保存，避免状态污染
                /** @var \Weline\Acl\Model\Role $newRole */
                $newRole = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false);
                $save_result = $newRole->setData($postData)
                    ->save(true, \Weline\Acl\Model\Role::fields_ROLE_NAME);
                
                if ($this->request->isAjax()) {
                    if ($save_result) {
                        return $this->jsonResponse(true, __('角色创建成功！'));
                    } else {
                        return $this->jsonResponse(false, __('角色创建失败！'));
                    }
                }
                
                if ($save_result) {
                    $this->getMessageManager()->addSuccess(__('角色创建成功！'));
                } else {
                    $this->getMessageManager()->addError(__('角色创建失败！'));
                }
            } catch (\Exception $exception) {
                if ($this->request->isAjax()) {
                    $error_msg = $exception->getMessage();
                    // 检查是否是唯一约束错误
                    if (str_contains($error_msg, 'duplicate key') || str_contains($error_msg, 'Unique violation')) {
                        $error_msg = __('角色已存在！');
                    }
                    return $this->jsonResponse(false, $error_msg);
                }
                $this->getMessageManager()->addException($exception);
            }
            
            if (!$this->request->isAjax()) {
                $this->redirect('*/backend/acl/role');
            }
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
            // 使用新实例加载
            /** @var \Weline\Acl\Model\Role $role */
            $role = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false);
            $role->load($id);
            if (!$role->getId()) {
                $this->getMessageManager()->addWarning(__('角色已不存在！'));
            } else {
                $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/acl/role/edit'));
                $this->assign('edit_role', $role);
            }
            return $this->fetch('form');
        }
        if ($this->request->isPost()) {
            // 使用新实例保存
            /** @var \Weline\Acl\Model\Role $role */
            $role = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false);
            $role->setData($this->request->getPost())->save();
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
        $this->assign('trees', $trees);
        $this->assign('current_accesses', $current_accesses);
        
        // 获取统计信息（用于前端显示已选/总数）
        $statistics = $roleAccessModel->clear()->getTreeStatistics($role);
        $this->assign('tree_statistics', $statistics);
        
        // 获取模块和类型列表（用于筛选器）
        $moduleList = $roleAccessModel->getModuleList();
        $typeList = $roleAccessModel->getTypeList();
        $this->assign('module_list', $moduleList);
        $this->assign('type_list', $typeList);
        
        // 当前用户角色
        /**@var AuthenticatedSessionInterface $session */
        $session = SessionFactory::getInstance()->createBackendSession();
        $user = $session->getUser();
        $userRole = ($user instanceof \Weline\Backend\Model\BackendUser) ? $user->getRole() : null;
        $this->assign('user_role', $userRole);
        return $this->fetch('assign');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_assign_post', '角色权限分配', '', '')]
    public function postAssign()
    {
//        if(!$this->session->getLoginUsername() !== 'admin' ){
//            $this->getMessageManager()->addError(__('仅允许超管分配权限！'));
//            $this->redirect('*/backend/acl/role');
//        }
        $role_id = $this->request->getPost('role_id');
        $role = $this->role->load($role_id);
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
            $roleAccessModel->reset()->where(\Weline\Acl\Model\Role::fields_ROLE_ID, $role_id)->delete()->fetch();
            if ($acls) {
                $roleAccessModel->reset()->insert($acls,[\Weline\Acl\Model\Role::fields_ROLE_ID, \Weline\Acl\Model\RoleAccess::fields_SOURCE_ID])->fetch();
            }
            $roleAccessModel->commit();
            $this->getMessageManager()->addSuccess(__('权限分配成功！'));
            // 清理权限缓存
            w_cache('acl')->clear();
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

    /**
     * JSON响应辅助方法
     * 
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return string
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    #[\Weline\Framework\Acl\Acl('Weline_Acl::acl_role_search', '角色搜索', '', '搜索角色列表')]
    public function getSearch(): string
    {
        $id = $this->request->getGet('id', '');
        $keyword = trim($this->request->getGet('keyword', '') ?: $this->request->getGet('q', ''));
        $limit = (int)$this->request->getGet('limit', 50);
        
        /** @var \Weline\Acl\Model\Role $roleModel */
        $roleModel = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false);
        
        if ($id !== '') {
            $roleModel->where(\Weline\Acl\Model\Role::fields_ROLE_ID, (int)$id);
        } elseif ($keyword !== '') {
            $roleModel->where(\Weline\Acl\Model\Role::fields_ROLE_NAME, '%' . $keyword . '%', 'like');
        }
        
        $roleModel->limit($limit)->order(\Weline\Acl\Model\Role::fields_ROLE_NAME, 'ASC')->select()->fetch();
        
        $data = [];
        foreach ($roleModel->getItems() as $role) {
            $data[] = [
                'value' => (string)$role->getId(),
                'label' => $role->getRoleName(),
            ];
        }
        
        return $this->jsonResponse(true, __('搜索成功'), $data);
    }

}