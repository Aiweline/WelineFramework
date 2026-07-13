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

use Weline\Acl\Api\Role\RoleCatalogInterface;
use Weline\Backend\Api\User\BackendUserAdministrationInterface;
use Weline\Backend\Api\User\BackendUserRecord;
use Weline\Backend\Api\UserData\BackendCurrentUserDataInterface;
use Weline\Framework\Manager\MessageManager;

#[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_listing', '用户管理', '管理后台用户', '')]
class User extends \Weline\Framework\App\Controller\BackendController
{
    function __construct(
        private BackendUserAdministrationInterface $backendUser,
        private BackendCurrentUserDataInterface $backendUserData,
        private RoleCatalogInterface $roleCatalog,
    )
    {
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_list', '管理员列表', '', '查看管理后台用户列表')]
    function listing()
    {
        $users = $this->backendUser->search((string)($this->request->getGet('search') ?? ''));
        $this->assign('users', array_map(
            static fn(BackendUserRecord $user): array => $user->toArray(),
            $users->getUsers(),
        ));
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
        $user = $this->backendUser->find((int)$id);
        if ($user === null) {
            MessageManager::warning(__('用户不存在'));
            $this->redirect('*/backend/user/listing');
            return;
        }
        $this->assign('w_edit_user', $user->toArray());
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/backend/user/edit', $this->request->getGet()));
        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_user_edit_post', '管理员修改请求', '', '修改管理员请求')]
    function postEdit()
    {
        try {
            $password = trim($this->request->getPost('password') ?? '');
            $user = $this->backendUser->save(
                (int)$this->request->getPost('user_id'),
                (string)$this->request->getPost('username'),
                (string)$this->request->getPost('email'),
                $password !== '' ? $password : null,
            );
            MessageManager::success(__('修改成功！'));
            $this->backendUserData->clearScope('w_user');
            $this->redirect('*/backend/user/edit', ['id' => $user->getId()]);
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
            $user = $this->backendUser->save(
                null,
                (string)$this->request->getPost('username'),
                (string)$this->request->getPost('email'),
                trim((string)$this->request->getPost('password')),
            );
            MessageManager::success(__('添加成功！'));
            $this->backendUserData->clearScope('w_user');
            $this->redirect('*/backend/user/edit', ['id' => $user->getId()]);
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

            if ((int)$id === 1) {
                throw new \Exception(__('超级管理员不能被删除'));
            }
            
            if ($this->backendUser->find((int)$id) === null) {
                throw new \Exception(__('用户不存在'));
            }

            $this->backendUser->setState((int)$id, false, true);
            
            // 如果是AJAX请求，返回JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('用户已删除，可在列表中重新激活'),
                    'msg' => __('用户已删除，可在列表中重新激活')
                ]);
            }
            
            MessageManager::success(__('用户已删除，可在列表中重新激活'));
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
            $this->backendUser->setState((int)$this->request->getPost('id'), true, false);
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
            $this->backendUser->setState((int)$this->request->getPost('id'), false);
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
            $isEdit = $userId !== null && $userId !== '';
            $userIdInt = $isEdit ? (int)$userId : null;
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
            
            if ($isEdit) {
                if ($this->backendUser->find($userIdInt) === null) {
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

            $existingUsername = $this->findBackendUserBy(BackendUserAdministrationInterface::FIELD_USERNAME, $username, $userIdInt);
            if ($existingUsername->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => $existingUsername->getIsDeleted()
                        ? __('该用户名对应的管理员已删除，请先激活该用户')
                        : __('用户名已存在')
                ]);
            }

            $existingEmail = $this->findBackendUserBy(BackendUserAdministrationInterface::FIELD_EMAIL, $email, $userIdInt);
            if ($existingEmail->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => $existingEmail->getIsDeleted()
                        ? __('该邮箱对应的管理员已删除，请先激活该用户')
                        : __('邮箱已存在')
                ]);
            }
            
            $savedUser = $this->backendUser->save(
                $userIdInt,
                $username,
                $email,
                $password !== '' ? $password : null,
            );
            
            $actionText = $isEdit ? __('修改') : __('新增');
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('%{1}管理员成功', [$actionText]),
                'data' => [
                    'user_id' => $savedUser->getId()
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

    private function findBackendUserBy(string $field, string $value, ?int $excludeUserId = null): BackendUserRecord
    {
        $backendUser = match ($field) {
            BackendUserAdministrationInterface::FIELD_EMAIL => $this->backendUser->findByEmail($value, $excludeUserId),
            default => $this->backendUser->findByUsername($value, $excludeUserId),
        };
        return $backendUser ?? BackendUserRecord::empty();
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
            
            if ($this->backendUser->find((int)$id) === null) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户不存在')
                ]);
            }
            
            $this->backendUser->setState((int)$id, false, true);
            
            return $this->fetchJson([
                'success' => true,
                'msg' => __('用户已删除，可在列表中重新激活')
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
            
            if ($this->backendUser->find((int)$id) === null) {
                return $this->fetchJson([
                    'success' => false,
                    'msg' => __('用户不存在')
                ]);
            }
            
            if ($action === 'enable') {
                $this->backendUser->setState((int)$id, true, false);
                $msg = __('用户已激活');
            } else {
                $this->backendUser->setState((int)$id, false);
                $msg = __('用户已禁用');
            }
            
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
        $users = $this->backendUser->listWithRoles();
        $currentUser = $this->backendUser->find((int)$this->session->getLoginUserID());
        $this->assign('current_user', ($currentUser ?? BackendUserRecord::empty())->toArray());
        $this->assign('users', array_map(
            static fn(BackendUserRecord $user): array => $user->toArray(),
            $users->getUsers(),
        ));
        $this->assign('pagination', $users->getPagination());
        $this->assign('roles', array_map(
            static fn(\Weline\Acl\Api\Role\RoleRecord $role): array => [
                'role_id' => $role->getId(),
                'role_name' => $role->getName(),
                'role_description' => $role->getDescription(),
            ],
            $this->roleCatalog->list(),
        ));
        return $this->fetch('assign_role');
    }

    #[\Weline\Framework\Acl\Acl('Weline_Admin::system_assign_role_post', '管理员角色归配请求', '', '请求归配')]
    function postAssignRole()
    {
        if ($this->session->getLoginUserID() === $this->request->getGet('user_id')) {
            MessageManager::warning(__('不能给自己分配权限！'));
            $this->redirect('*/backend/user/listing');
        }
        $userId = (int) ($this->request->getPost('user_id') ?? 0);
        try {
            $roleId = $this->request->getPost('role_id');
            $this->backendUser->assignRole(
                $userId,
                $roleId !== '' && $roleId !== null ? (int)$roleId : null,
            );
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
            
            $userIdInt = (int) $userId;

            if (empty($roleId)) {
                $this->backendUser->assignRole($userIdInt, null);
                return $this->fetchJson([
                    'success' => true,
                    'msg' => __('已取消角色分配')
                ]);
            }

            $this->backendUser->assignRole($userIdInt, (int)$roleId);
            
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
