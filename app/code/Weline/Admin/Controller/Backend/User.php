<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/29 22:12:42
 */

namespace Weline\Admin\Controller\Backend;

use Weline\Acl\Model\Role;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserData;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_listing', '用户管理', '管理后台用户', '')]
class User extends \Weline\Framework\App\Controller\BackendController
{
    function __construct(
        private \Weline\Backend\Model\BackendUser $backendUser,
        private BackendUserData                   $backendUserData
    )
    {
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_list', '管理员列表', '', '查看管理后台用户列表')]
    function listing()
    {
        if ($search = $this->request->getGet('search')) {
            $this->backendUser->concat_like('username,email',"%$search%");
        }
        $users = $this->backendUser->order()
            ->pagination()
            ->select()
            ->fetch();
        $this->assign('users', $users->getItems());
        $this->assign('pagination', $users->getPagination());
        return $this->fetch();
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_add', '管理员添加界面', '', '添加管理员界面访问')]
    function getAdd()
    {
        $this->assign('w_edit_user', $this->backendUserData->getScope('w_user'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/user/add', $this->request->getGet()));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_edit', '管理员修改界面', '', '修改管理员界面访问')]
    function getEdit()
    {
        $id = $this->request->getGet('id');
        if (empty($id) || !is_numeric($id)) {
            MessageManager::warning(__('用户ID无效'));
            $this->redirect('*/backend/user/listing');
            return;
        }
        $user = clone $this->backendUser->clear()->load((int)$id);
        if (!$user->getId()) {
            MessageManager::warning(__('用户不存在'));
            $this->redirect('*/backend/user/listing');
            return;
        }
        $this->assign('w_edit_user', $user);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/user/edit', $this->request->getGet()));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_edit_post', '管理员修改请求', '', '修改管理员请求')]
    function postEdit()
    {
        try {
            $password = trim($this->request->getPost('password') ?? '');
            $this->backendUser->clearData()
                ->setId($this->request->getPost('user_id'))
                ->setUsername($this->request->getPost('username'))
                ->setEmail($this->request->getPost('email'));
            // 只有在提供了密码时才更新密码
            if (!empty($password)) {
                $this->backendUser->setPassword($password);
            }
            $this->backendUser->save(true);
            MessageManager::success(__('修改成功！'));
            $this->backendUserData->deleteScope('w_user');
            $this->redirect('*/backend/user/edit', ['id' => $this->backendUser->getId()]);
        } catch (\Exception $exception) {
            MessageManager::warning(__('修改失败！'));
            if (DEV) MessageManager::exception($exception);
            $this->redirect('*/backend/user/add');
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_add_post', '管理员添加请求', '', '请求添加管理员')]
    function postAdd()
    {
        try {
            $this->backendUser->clearData()->setUsername($this->request->getPost('username'))
                ->setEmail($this->request->getPost('email'))
                ->setPassword(trim($this->request->getPost('password')))
                ->save(true);
            MessageManager::success(__('添加成功！'));
            $this->backendUserData->deleteScope('w_user');
            $this->redirect('*/backend/user/edit', ['id' => $this->backendUser->getId()]);
        } catch (\Exception $exception) {
            MessageManager::warning(__('添加失败！'));
            if (DEV) MessageManager::exception($exception);
            $this->redirect('*/backend/user/add');
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_delete_post', '管理员删除请求', '', '请求删除管理员')]
    function postDelete()
    {
        try {
            // 支持JSON和表单数据
            $params = $this->request->getParams();
            $id = $params['id'] ?? $this->request->getPost('id');
            
            if (empty($id)) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $this->backendUser->clearData()->load($id)
                ->setIsDeleted()
                ->save();
            
            // 如果是AJAX请求，返回JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('删除成功！'),
                    'msg' => __('删除成功！')
                ]);
            }
            
            MessageManager::success(__('删除成功！'));
            $this->redirect('*/backend/user/listing');
        } catch (\Exception $exception) {
            // 如果是AJAX请求，返回JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('删除失败！') . ($exception->getMessage() ? ': ' . $exception->getMessage() : ''),
                    'msg' => __('删除失败！') . ($exception->getMessage() ? ': ' . $exception->getMessage() : '')
                ]);
            }
            
            MessageManager::warning(__('删除失败！'));
            if (DEV) MessageManager::exception($exception);
            $this->redirect('*/backend/user/listing');
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_active_post', '激活管理员', '', '请求激活管理员')]
    function postActive()
    {
        try {
            $this->backendUser->clearData()->load($this->request->getPost('id'))
                ->setIsDeleted(false)
                ->setIsEnabled(true)
                ->save();
            MessageManager::success(__('激活成功！'));
            $this->redirect('*/backend/user/listing');
        } catch (\Exception $exception) {
            MessageManager::warning(__('激活失败！'));
            if (DEV) MessageManager::exception($exception);
            $this->redirect('*/backend/user/listing');
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_inactive_post', '禁用管理员', '', '请求禁用管理员')]
    function postInActive()
    {
        try {
            $this->backendUser->clearData()->load($this->request->getPost('id'))
                ->setIsEnabled(false)
                ->save();
            MessageManager::success(__('禁用成功！'));
            $this->redirect('*/backend/user/listing');
        } catch (\Exception $exception) {
            MessageManager::warning(__('禁用失败！'));
            if (DEV) MessageManager::exception($exception);
            $this->redirect('*/backend/user/listing');
        }
    }

    /**
     * API: 保存用户（新增/编辑）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_edit_post', '管理员保存API', '', 'AJAX保存管理员')]
    function postApiSave()
    {
        try {
            $params = $this->request->getParams();
            $userId = $params['user_id'] ?? null;
            $username = trim($params['username'] ?? '');
            $email = trim($params['email'] ?? '');
            $password = trim($params['password'] ?? '');
            
            if (empty($username)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户名不能为空')
                ]);
            }
            
            if (empty($email)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('邮箱不能为空')
                ]);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('邮箱格式不正确')
                ]);
            }
            
            $this->backendUser->clearData();
            
            if (!empty($userId)) {
                $this->backendUser->load((int)$userId);
                if (!$this->backendUser->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'msg' => __('用户不存在')
                    ]);
                }
            } else {
                if (empty($password)) {
                    return $this->fetchJson([
                        'success' => false,
                        'msg' => __('新增用户必须设置密码')
                    ]);
                }
            }
            
            $this->backendUser->setUsername($username)->setEmail($email);
            
            if (!empty($password)) {
                $this->backendUser->setPassword($password);
            }
            
            $this->backendUser->save(true);
            
            $actionText = empty($userId) ? __('新增') : __('修改');
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('%{1}管理员成功', [$actionText]),
                'data' => [
                    'user_id' => $this->backendUser->getId()
                ]
            ]);
        } catch (\Exception $e) {
            $errorMsg = __('保存失败');
            if (DEV) {
                $errorMsg .= '：' . $e->getMessage();
            }
            return $this->fetchJson([
                'success' => false,
                'msg' => $errorMsg
            ]);
        }
    }

    /**
     * API: 删除用户
     */
    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_delete_post', '管理员删除API', '', 'AJAX删除管理员')]
    function postApiDelete()
    {
        try {
            $params = $this->request->getParams();
            $id = $params['id'] ?? null;
            
            if (empty($id)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户ID不能为空')
                ]);
            }
            
            if ((int)$id === 1) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('超级管理员不能被删除')
                ]);
            }
            
            $this->backendUser->clearData()->load((int)$id);
            
            if (!$this->backendUser->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户不存在')
                ]);
            }
            
            $this->backendUser->setIsDeleted()->save();
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('删除成功')
            ]);
        } catch (\Exception $e) {
            $errorMsg = __('删除失败');
            if (DEV) {
                $errorMsg .= '：' . $e->getMessage();
            }
            return $this->fetchJson([
                'success' => false,
                'msg' => $errorMsg
            ]);
        }
    }

    /**
     * API: 切换用户状态（激活/禁用）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_active_post', '管理员状态切换API', '', 'AJAX切换管理员状态')]
    function postApiToggle()
    {
        try {
            $params = $this->request->getParams();
            $id = $params['id'] ?? null;
            $action = $params['action'] ?? '';
            
            if (empty($id)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户ID不能为空')
                ]);
            }
            
            if ((int)$id === 1) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('超级管理员状态不能被修改')
                ]);
            }
            
            if (!in_array($action, ['enable', 'disable'])) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('无效的操作类型')
                ]);
            }
            
            $this->backendUser->clearData()->load((int)$id);
            
            if (!$this->backendUser->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户不存在')
                ]);
            }
            
            if ($action === 'enable') {
                $this->backendUser->setIsDeleted(false)->setIsEnabled(true);
                $msg = __('用户已激活');
            } else {
                $this->backendUser->setIsEnabled(false);
                $msg = __('用户已禁用');
            }
            
            $this->backendUser->save();
            
            return $this->fetchJson([
                'success' => true,
                'msg' => $msg
            ]);
        } catch (\Exception $e) {
            $errorMsg = __('操作失败');
            if (DEV) {
                $errorMsg .= '：' . $e->getMessage();
            }
            return $this->fetchJson([
                'success' => false,
                'msg' => $errorMsg
            ]);
        }
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_assign_role', '管理员角色归配', '', '将管理员分配到角色')]
    function getAssignRole()
    {
        // 使用非共享实例，避免 WLS 环境下状态污染
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class, [], false);
        /** @var BackendUser $backendUser */
        $backendUser = ObjectManager::getInstance(BackendUser::class, [], false);
        $users = $backendUser
            ->joinModel(UserRole::class, 'ur', 'main_table.user_id=ur.user_id', 'left')
            ->joinModel(Role::class, 'r', 'ur.role_id=r.role_id', 'left')
            ->order('main_table.create_time')
            ->pagination()
            ->select()
            ->fetch();
        $this->assign('current_user', $this->session->getLoginUser($backendUser::class));
        $this->assign('users', $users->getItems());
        $this->assign('pagination', $users->getPagination());
        $this->assign('roles', $role->select()->fetch()->getItems());
        return $this->fetch('assign_role');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_assign_role_post', '管理员角色归配请求', '', '请求归配')]
    function postAssignRole()
    {
        if ($this->session->getLoginUserID() === $this->request->getGet('user_id')) {
            MessageManager::warning(__('不能给自己分配权限！'));
            $this->redirect('*/backend/user/listing');
        }
        /**@var UserRole $userRole */
        $userRole = ObjectManager::getInstance(UserRole::class);
        try {
            $userRole->clearData()->setData($this->request->getPost())->save(true);
            MessageManager::success(__('角色分配成功！'));
        } catch (\Exception $exception) {
            MessageManager::warning(__('角色分配失败！'));
            if (DEV) MessageManager::exception($exception);
        }
        $this->redirect('*/backend/user/assign-role');
    }

    /**
     * API: 角色归配（AJAX 保存）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_assign_role_post', '管理员角色归配API', '', 'AJAX分配角色')]
    function postApiAssignRole()
    {
        try {
            $params = $this->request->getParams();
            $userId = $params['user_id'] ?? null;
            $roleId = $params['role_id'] ?? null;
            
            if (empty($userId)) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户ID不能为空')
                ]);
            }
            
            $currentUserId = $this->session->getLoginUserID();
            if ((string)$currentUserId === (string)$userId) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('不能修改自己的角色')
                ]);
            }
            
            /** @var UserRole $userRole */
            $userRole = ObjectManager::getInstance(UserRole::class, [], false);
            
            $userRole->clearData()
                ->where($userRole::schema_fields_USER_ID, (int)$userId)
                ->find()
                ->fetch();
            
            if (empty($roleId)) {
                if ($userRole->getId()) {
                    $userRole->delete();
                }
                return $this->fetchJson([
                    'success' => true,
                    'msg' => __('已取消角色分配')
                ]);
            }
            
            $userRole->clearData();
            $userRole->setData([
                'user_id' => (int)$userId,
                'role_id' => (int)$roleId
            ]);
            $userRole->save(true);
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('角色分配成功')
            ]);
        } catch (\Exception $e) {
            $errorMsg = __('角色分配失败');
            if (DEV) {
                $errorMsg .= '：' . $e->getMessage();
            }
            return $this->fetchJson([
                'success' => false,
                'msg' => $errorMsg
            ]);
        }
    }
}