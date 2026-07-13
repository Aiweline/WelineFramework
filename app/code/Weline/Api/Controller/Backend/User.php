<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Controller\Backend;

use Weline\Api\Model\ApiUser;
use Weline\Api\Model\ApiUserRole;
use Weline\Acl\Api\Role;
use Weline\Acl\Api\RoleAccess;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl(
    'Weline_Api::api_user_management',
    'API用户管理',
    'fa fa-users',
    '管理API用户',
    'Weline_Api::integration'
)]
class User extends \Weline\Framework\App\Controller\BackendController
{
    private ApiUser $apiUser;

    public function __construct(
        ApiUser $apiUser
    ) {
        $this->apiUser = $apiUser;
    }

    #[Acl('Weline_Api::api_user_list', 'API用户列表', 'fa fa-list')]
    public function index()
    {
        // 搜索功能
        if ($search = $this->request->getGet('search')) {
            $this->apiUser->concat_like('username,email', "%{$search}%");
        }
        
        // 过滤已删除的用户
        $this->apiUser->where('is_deleted', 0);
        
        // 分页查询
        $users = $this->apiUser->order('user_id', 'desc')
            ->pagination()
            ->select()
            ->fetch();
        
        $this->assign('users', $users->getItems());
        $this->assign('pagination', $users->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Api::api_user_add', '添加API用户', 'fa fa-plus')]
    public function getAdd()
    {
        // 获取所有角色
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class);
        $roles = $role->select()->fetch()->getItems();
        $this->assign('roles', $roles);
        
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/api/backend/user/add'));
        return $this->fetch('form');
    }

    #[Acl('Weline_Api::api_user_edit', '编辑API用户', 'fa fa-edit')]
    public function getEdit()
    {
        $userId = (int)$this->request->getGet('id', 0);
        if (!$userId) {
            $this->getMessageManager()->addWarning(__('用户ID不能为空'));
            $this->redirect('*/api/backend/user');
            return;
        }
        
        $user = clone $this->apiUser->clear()->load($userId);
        if (!$user->getId()) {
            $this->getMessageManager()->addWarning(__('用户不存在'));
            $this->redirect('*/api/backend/user');
            return;
        }
        
        // 获取用户角色
        $userRole = $user->getRoleModel();
        $this->assign('user_role_id', $userRole ? $userRole->getId() : 0);
        
        // 获取所有角色
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class);
        $roles = $role->select()->fetch()->getItems();
        $this->assign('roles', $roles);
        
        $this->assign('user', $user);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/api/backend/user/edit', ['id' => $userId]));
        return $this->fetch('form');
    }

    #[Acl('Weline_Api::api_user_add_post', '添加API用户请求', 'fa fa-save')]
    public function postAdd()
    {
        try {
            $username = trim($this->request->getPost('username') ?? '');
            $email = trim($this->request->getPost('email') ?? '');
            $password = trim($this->request->getPost('password') ?? '');
            $tokenExpireTime = (int)($this->request->getPost('token_expire_time') ?? 604800);
            $refreshTokenExpireTime = (int)($this->request->getPost('refresh_token_expire_time') ?? 2592000);
            $roleId = (int)($this->request->getPost('role_id') ?? 0);
            $isEnabled = (int)($this->request->getPost('is_enabled') ?? 1);
            
            // 验证输入
            if (empty($username)) {
                throw new \Exception(__('用户名不能为空'));
            }
            if (strlen($username) < 3 || strlen($username) > 50) {
                throw new \Exception(__('用户名长度必须在3-50字符之间'));
            }
            if (empty($email)) {
                throw new \Exception(__('邮箱不能为空'));
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception(__('邮箱格式不正确'));
            }
            if (empty($password)) {
                throw new \Exception(__('密码不能为空'));
            }
            if (strlen($password) < 6) {
                throw new \Exception(__('密码长度不能少于6个字符'));
            }
            
            // 检查用户名是否已存在
            $existingUser = clone $this->apiUser;
            $existingUser->where('username', $username)
                ->where('is_deleted', 0)
                ->find()
                ->fetch();
            if ($existingUser->getId()) {
                throw new \Exception(__('用户名已存在'));
            }
            
            // 检查邮箱是否已存在
            $existingUser = clone $this->apiUser;
            $existingUser->where('email', $email)
                ->where('is_deleted', 0)
                ->find()
                ->fetch();
            if ($existingUser->getId()) {
                throw new \Exception(__('邮箱已存在'));
            }
            
            // 创建用户
            $user = clone $this->apiUser;
            $user->clearData()
                ->setUsername($username)
                ->setEmail($email)
                ->setPassword($password)
                ->setTokenExpireTime($tokenExpireTime)
                ->setRefreshTokenExpireTime($refreshTokenExpireTime)
                ->setIsEnabled((bool)$isEnabled)
                ->autoGenerateApiCredentials() // 自动生成API Key和Secret
                ->save();
            
            // 分配角色
            if ($roleId > 0) {
                $user->assignRole($roleId);
            }
            
            // 获取生成的API Key和Secret（仅创建时返回一次）
            $apiKey = $user->getApiKey();
            $apiSecret = $user->getData('raw_api_secret') ?? '';
            
            $this->getMessageManager()->addSuccess(__('添加成功！'));
            $this->getMessageManager()->addWarning(__('API Key: %{1}', [$apiKey]));
            $this->getMessageManager()->addWarning(__('API Secret: %{1}（请妥善保管，仅显示一次）', [$apiSecret]));
            
            $this->redirect('*/api/backend/user/edit', ['id' => $user->getId()]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('添加失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/api/backend/user/add');
        }
    }

    #[Acl('Weline_Api::api_user_edit_post', '编辑API用户请求', 'fa fa-save')]
    public function postEdit()
    {
        try {
            $userId = (int)$this->request->getPost('user_id', 0);
            if (!$userId) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $user = clone $this->apiUser;
            $user->load($userId);
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            $username = trim($this->request->getPost('username') ?? '');
            $email = trim($this->request->getPost('email') ?? '');
            $password = trim($this->request->getPost('password') ?? '');
            $tokenExpireTime = (int)($this->request->getPost('token_expire_time') ?? $user->getTokenExpireTime());
            $refreshTokenExpireTime = (int)($this->request->getPost('refresh_token_expire_time') ?? $user->getRefreshTokenExpireTime());
            $roleId = (int)($this->request->getPost('role_id') ?? 0);
            $isEnabled = (int)($this->request->getPost('is_enabled') ?? $user->getIsEnabled());
            
            // 验证输入
            if (empty($username)) {
                throw new \Exception(__('用户名不能为空'));
            }
            if (strlen($username) < 3 || strlen($username) > 50) {
                throw new \Exception(__('用户名长度必须在3-50字符之间'));
            }
            if (empty($email)) {
                throw new \Exception(__('邮箱不能为空'));
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception(__('邮箱格式不正确'));
            }
            if (!empty($password) && strlen($password) < 6) {
                throw new \Exception(__('密码长度不能少于6个字符'));
            }
            
            // 检查用户名是否已被其他用户使用
            if ($username !== $user->getUsername()) {
                $existingUser = clone $this->apiUser;
                $existingUser->where('username', $username)
                    ->where('is_deleted', 0)
                    ->where('user_id', $userId, '!=')
                    ->find()
                    ->fetch();
                if ($existingUser->getId()) {
                    throw new \Exception(__('用户名已存在'));
                }
            }
            
            // 检查邮箱是否已被其他用户使用
            if ($email !== $user->getEmail()) {
                $existingUser = clone $this->apiUser;
                $existingUser->where('email', $email)
                    ->where('is_deleted', 0)
                    ->where('user_id', $userId, '!=')
                    ->find()
                    ->fetch();
                if ($existingUser->getId()) {
                    throw new \Exception(__('邮箱已存在'));
                }
            }
            
            // 更新用户信息
            $user->setUsername($username)
                ->setEmail($email)
                ->setTokenExpireTime($tokenExpireTime)
                ->setRefreshTokenExpireTime($refreshTokenExpireTime)
                ->setIsEnabled((bool)$isEnabled);
            
            // 如果提供了新密码，更新密码
            if (!empty($password)) {
                $user->setPassword($password);
            }
            
            $user->save();
            
            // 更新角色
            if ($roleId > 0) {
                // 先移除所有角色
                /** @var ApiUserRole $userRole */
                $userRole = ObjectManager::getInstance(ApiUserRole::class);
                $userRole->where('user_id', $userId)->delete();
                
                // 分配新角色
                $user->assignRole($roleId);
            } else {
                // 移除所有角色
                /** @var ApiUserRole $userRole */
                $userRole = ObjectManager::getInstance(ApiUserRole::class);
                $userRole->where('user_id', $userId)->delete();
            }
            
            $this->getMessageManager()->addSuccess(__('修改成功！'));
            $this->redirect('*/api/backend/user/edit', ['id' => $userId]);
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('修改失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/api/backend/user/edit', ['id' => $userId ?? 0]);
        }
    }

    #[Acl('Weline_Api::api_user_delete_post', '删除API用户', 'fa fa-trash')]
    public function postDelete()
    {
        try {
            $userId = (int)$this->request->getPost('id', 0);
            if (!$userId) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $user = clone $this->apiUser;
            $user->load($userId);
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            // 软删除
            $user->setIsDeleted(true)->save();
            
            // 删除所有相关令牌
            /** @var \Weline\Api\Model\ApiUserToken $token */
            $token = ObjectManager::getInstance(\Weline\Api\Model\ApiUserToken::class);
            $token->where('user_id', $userId)->delete();
            
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('删除失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
        }
        $this->redirect('*/api/backend/user');
    }

    #[Acl('Weline_Api::api_user_enable_post', '启用API用户', 'fa fa-check')]
    public function postEnable()
    {
        try {
            $userId = (int)$this->request->getPost('id', 0);
            if (!$userId) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $user = clone $this->apiUser;
            $user->load($userId);
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            $user->setIsEnabled(true)->save();
            $this->getMessageManager()->addSuccess(__('启用成功！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('启用失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
        }
        $this->redirect('*/api/backend/user');
    }

    #[Acl('Weline_Api::api_user_disable_post', '禁用API用户', 'fa fa-ban')]
    public function postDisable()
    {
        try {
            $userId = (int)$this->request->getPost('id', 0);
            if (!$userId) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $user = clone $this->apiUser;
            $user->load($userId);
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            $user->setIsEnabled(false)->save();
            $this->getMessageManager()->addSuccess(__('禁用成功！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('禁用失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
        }
        $this->redirect('*/api/backend/user');
    }

    #[Acl('Weline_Api::api_user_regenerate_credentials_post', '重新生成API凭证', 'fa fa-refresh')]
    public function postRegenerateCredentials()
    {
        try {
            $userId = (int)$this->request->getPost('id', 0);
            if (!$userId) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $user = clone $this->apiUser;
            $user->load($userId);
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            // 重新生成API Key和Secret
            $user->autoGenerateApiCredentials()->save();
            
            // 删除所有旧令牌
            /** @var \Weline\Api\Model\ApiUserToken $token */
            $token = ObjectManager::getInstance(\Weline\Api\Model\ApiUserToken::class);
            $token->where('user_id', $userId)->delete();
            
            $apiKey = $user->getApiKey();
            $apiSecret = $user->getData('raw_api_secret') ?? '';
            
            $this->getMessageManager()->addSuccess(__('重新生成成功！'));
            $this->getMessageManager()->addWarning(__('API Key: %{1}', [$apiKey]));
            $this->getMessageManager()->addWarning(__('API Secret: %{1}（请妥善保管，仅显示一次）', [$apiSecret]));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('重新生成失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
        }
        $this->redirect('*/api/backend/user/edit', ['id' => $userId ?? 0]);
    }

    #[Acl('Weline_Api::api_user_assign_permissions', 'API用户权限分配', 'fa fa-key')]
    public function getAssignPermissions()
    {
        $userId = (int)$this->request->getGet('id', 0);
        if (!$userId) {
            $this->getMessageManager()->addWarning(__('用户ID不能为空'));
            $this->redirect('*/api/backend/user');
            return;
        }
        
        $user = clone $this->apiUser->clear()->load($userId);
        if (!$user->getId()) {
            $this->getMessageManager()->addWarning(__('用户不存在'));
            $this->redirect('*/api/backend/user');
            return;
        }
        
        // 获取用户角色
        $userRole = $user->getRoleModel();
        if (!$userRole || !$userRole->getId()) {
            $this->getMessageManager()->addWarning(__('该用户尚未分配角色，请先分配角色'));
            $this->redirect('*/api/backend/user/edit', ['id' => $userId]);
            return;
        }
        
        // 获取权限树（使用RoleAccess的getTreeWithRole方法）
        /** @var RoleAccess $roleAccessModel */
        $roleAccessModel = ObjectManager::getInstance(RoleAccess::class);
        $trees = $roleAccessModel->clear()->getTreeWithRole($userRole);
        
        // 获取当前角色已分配的权限
        $currentAccesses = $roleAccessModel->clearData()->getRoleAccessList($userRole);
        $currentAccessIds = [];
        foreach ($currentAccesses as $access) {
            $currentAccessIds[] = $access['source_id'] ?? '';
        }
        
        $this->assign('user', $user);
        $this->assign('user_role', $userRole);
        $this->assign('trees', $trees);
        $this->assign('current_accesses', $currentAccessIds);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('*/api/backend/user/assign-permissions', ['id' => $userId]));
        return $this->fetch('assign_permissions');
    }

    #[Acl('Weline_Api::api_user_assign_permissions_post', 'API用户权限分配请求', 'fa fa-save')]
    public function postAssignPermissions()
    {
        try {
            $userId = (int)$this->request->getPost('user_id', 0);
            if (!$userId) {
                throw new \Exception(__('用户ID不能为空'));
            }
            
            $user = clone $this->apiUser;
            $user->load($userId);
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            // 获取用户角色
            $userRole = $user->getRoleModel();
            if (!$userRole || !$userRole->getId()) {
                throw new \Exception(__('该用户尚未分配角色，请先分配角色'));
            }
            
            $roleId = $userRole->getId();
            $aclIds = $this->request->getPost('ids', []);
            
            // 构建权限数据
            $acls = [];
            foreach ($aclIds as $aclId) {
                if (empty($aclId)) {
                    continue;
                }
                $acls[] = [
                    RoleAccess::schema_fields_ROLE_ID => $roleId,
                    RoleAccess::schema_fields_SOURCE_ID => $aclId,
                ];
            }
            
            /** @var RoleAccess $roleAccessModel */
            $roleAccessModel = ObjectManager::getInstance(RoleAccess::class);
            $roleAccessModel->beginTransaction();
            
            try {
                // 清除角色原有权限
                $roleAccessModel->reset()
                    ->where(Role::schema_fields_ROLE_ID, $roleId)
                    ->delete()
                    ->fetch();
                
                // 保存新权限
                if (!empty($acls)) {
                    $roleAccessModel->reset()
                        ->insert($acls, [Role::schema_fields_ROLE_ID, RoleAccess::schema_fields_SOURCE_ID])
                        ->fetch();
                }
                
                $roleAccessModel->commit();
                
                // 清理权限缓存
                w_cache('acl')->clear();
                
                $this->getMessageManager()->addSuccess(__('权限分配成功！'));
            } catch (\Exception $exception) {
                $roleAccessModel->rollBack();
                throw $exception;
            }
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('权限分配失败：%{1}', [$exception->getMessage()]));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
        }
        
        $this->redirect('*/api/backend/user/assign-permissions', ['id' => $userId ?? 0]);
    }
}
