<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiTenant;
use Weline\Ai\Model\AiTenantUser;
use Weline\Framework\App\Exception;

/**
 * 多租户管理服务
 * 
 * 功能：
 * - 租户创建和管理
 * - 租户用户管理
 * - 租户权限控制
 * - 资源配额管理
 * - 租户数据隔离
 */
class MultiTenantManager
{
    /**
     * @var AiTenant
     */
    private AiTenant $tenantModel;

    /**
     * @var AiTenantUser
     */
    private AiTenantUser $tenantUserModel;

    /**
     * 当前租户上下文
     * 
     * @var AiTenant|null
     */
    private ?AiTenant $currentTenant = null;

    /**
     * 构造函数
     * 
     * @param AiTenant $tenantModel
     * @param AiTenantUser $tenantUserModel
     */
    public function __construct(
        AiTenant $tenantModel,
        AiTenantUser $tenantUserModel
    ) {
        $this->tenantModel = $tenantModel;
        $this->tenantUserModel = $tenantUserModel;
    }

    /**
     * 创建租户
     * 
     * @param string $tenantName 租户名称
     * @param string $tenantCode 租户代码
     * @param string $tenantType 租户类型
     * @param string $planType 计划类型
     * @param array $resourceQuota 资源配额
     * @param array $billingInfo 计费信息
     * @return AiTenant
     * @throws Exception
     */
    public function createTenant(
        string $tenantName,
        string $tenantCode,
        string $tenantType = AiTenant::TYPE_INDIVIDUAL,
        string $planType = AiTenant::PLAN_FREE,
        array $resourceQuota = [],
        array $billingInfo = []
    ): AiTenant {
        // 验证租户代码唯一性
        if ($this->tenantCodeExists($tenantCode)) {
            throw new Exception("租户代码已存在: {$tenantCode}");
        }

        // 创建租户
        $tenant = new AiTenant();
        $tenant->setData(AiTenant::fields_TENANT_NAME, $tenantName)
               ->setData(AiTenant::fields_TENANT_CODE, $tenantCode)
               ->setData(AiTenant::fields_TENANT_TYPE, $tenantType)
               ->setData(AiTenant::fields_STATUS, AiTenant::STATUS_ACTIVE)
               ->setData(AiTenant::fields_PLAN_TYPE, $planType)
               ->setResourceQuota($resourceQuota)
               ->setBillingInfo($billingInfo)
               ->save();

        return $tenant;
    }

    /**
     * 获取租户
     * 
     * @param string $tenantCode 租户代码
     * @return AiTenant|null
     */
    public function getTenant(string $tenantCode): ?AiTenant
    {
        $tenant = $this->tenantModel->reset()
            ->where(AiTenant::fields_TENANT_CODE, $tenantCode)
            ->find()
            ->fetch();

        return $tenant->getId() ? $tenant : null;
    }

    /**
     * 设置当前租户上下文
     * 
     * @param string $tenantCode 租户代码
     * @return bool
     * @throws Exception
     */
    public function setCurrentTenant(string $tenantCode): bool
    {
        $tenant = $this->getTenant($tenantCode);
        
        if (!$tenant) {
            throw new Exception("租户不存在: {$tenantCode}");
        }

        if (!$tenant->isActive()) {
            throw new Exception("租户未激活: {$tenantCode}");
        }

        $this->currentTenant = $tenant;
        return true;
    }

    /**
     * 获取当前租户
     * 
     * @return AiTenant|null
     */
    public function getCurrentTenant(): ?AiTenant
    {
        return $this->currentTenant;
    }

    /**
     * 添加用户到租户
     * 
     * @param int $userId 用户ID
     * @param string $role 用户角色
     * @param array $permissions 权限列表
     * @return AiTenantUser
     * @throws Exception
     */
    public function addUserToTenant(int $userId, string $role = AiTenantUser::ROLE_MEMBER, array $permissions = []): AiTenantUser
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        // 检查用户是否已在租户中
        if ($this->isUserInTenant($userId)) {
            throw new Exception("用户已在租户中");
        }

        // 创建租户用户关联
        $tenantUser = new AiTenantUser();
        $tenantUser->setData(AiTenantUser::fields_TENANT_ID, $this->currentTenant->getId())
                  ->setData(AiTenantUser::fields_USER_ID, $userId)
                  ->setData(AiTenantUser::fields_ROLE, $role)
                  ->setData(AiTenantUser::fields_IS_ACTIVE, 1)
                  ->setPermissions($permissions)
                  ->save();

        return $tenantUser;
    }

    /**
     * 从租户移除用户
     * 
     * @param int $userId 用户ID
     * @return bool
     * @throws Exception
     */
    public function removeUserFromTenant(int $userId): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        $tenantUser = $this->getTenantUser($userId);
        if (!$tenantUser) {
            return false;
        }

        $tenantUser->delete();
        return true;
    }

    /**
     * 获取租户用户
     * 
     * @param int $userId 用户ID
     * @return AiTenantUser|null
     */
    public function getTenantUser(int $userId): ?AiTenantUser
    {
        if (!$this->currentTenant) {
            return null;
        }

        $tenantUser = $this->tenantUserModel->reset()
            ->where(AiTenantUser::fields_TENANT_ID, $this->currentTenant->getId())
            ->where(AiTenantUser::fields_USER_ID, $userId)
            ->find()
            ->fetch();

        return $tenantUser->getId() ? $tenantUser : null;
    }

    /**
     * 检查用户是否在租户中
     * 
     * @param int $userId 用户ID
     * @return bool
     */
    public function isUserInTenant(int $userId): bool
    {
        return $this->getTenantUser($userId) !== null;
    }

    /**
     * 检查用户权限
     * 
     * @param int $userId 用户ID
     * @param string $permission 权限名称
     * @return bool
     */
    public function checkUserPermission(int $userId, string $permission): bool
    {
        $tenantUser = $this->getTenantUser($userId);
        
        if (!$tenantUser || !$tenantUser->isActive()) {
            return false;
        }

        return $tenantUser->hasPermission($permission);
    }

    /**
     * 获取租户用户列表
     * 
     * @param string $role 角色过滤
     * @param bool $activeOnly 仅激活用户
     * @return array
     */
    public function getTenantUsers(string $role = '', bool $activeOnly = true): array
    {
        if (!$this->currentTenant) {
            return [];
        }

        $query = $this->tenantUserModel->reset()
            ->where(AiTenantUser::fields_TENANT_ID, $this->currentTenant->getId());

        if ($role) {
            $query->where(AiTenantUser::fields_ROLE, $role);
        }

        if ($activeOnly) {
            $query->where(AiTenantUser::fields_IS_ACTIVE, 1);
        }

        return $query->select()->fetch();
    }

    /**
     * 更新租户信息
     * 
     * @param array $data 更新数据
     * @return bool
     * @throws Exception
     */
    public function updateTenant(array $data): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        foreach ($data as $field => $value) {
            if (in_array($field, [
                AiTenant::fields_TENANT_NAME,
                AiTenant::fields_TENANT_TYPE,
                AiTenant::fields_STATUS,
                AiTenant::fields_PLAN_TYPE
            ])) {
                $this->currentTenant->setData($field, $value);
            }
        }

        return $this->currentTenant->save();
    }

    /**
     * 更新租户资源配额
     * 
     * @param array $quota 资源配额
     * @return bool
     * @throws Exception
     */
    public function updateResourceQuota(array $quota): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        $this->currentTenant->setResourceQuota($quota);
        return $this->currentTenant->save();
    }

    /**
     * 检查资源配额
     * 
     * @param string $resourceType 资源类型
     * @param int $requestedAmount 请求数量
     * @return bool
     * @throws Exception
     */
    public function checkResourceQuota(string $resourceType, int $requestedAmount = 1): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        return $this->currentTenant->checkResourceQuota($resourceType, $requestedAmount);
    }

    /**
     * 使用资源配额
     * 
     * @param string $resourceType 资源类型
     * @param int $amount 使用数量
     * @return bool
     * @throws Exception
     */
    public function useResourceQuota(string $resourceType, int $amount = 1): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        return $this->currentTenant->useResourceQuota($resourceType, $amount);
    }

    /**
     * 获取租户统计信息
     * 
     * @return array
     */
    public function getTenantStats(): array
    {
        if (!$this->currentTenant) {
            return [];
        }

        $userCount = $this->tenantUserModel->reset()
            ->where(AiTenantUser::fields_TENANT_ID, $this->currentTenant->getId())
            ->where(AiTenantUser::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->count();

        return [
            'tenant' => [
                'id' => $this->currentTenant->getId(),
                'name' => $this->currentTenant->getTenantName(),
                'code' => $this->currentTenant->getTenantCode(),
                'type' => $this->currentTenant->getTenantType(),
                'status' => $this->currentTenant->getStatus(),
                'plan' => $this->currentTenant->getPlanType()
            ],
            'users' => [
                'total' => $userCount,
                'active' => $userCount
            ],
            'quota' => $this->currentTenant->getResourceQuota()
        ];
    }

    /**
     * 检查租户代码是否存在
     * 
     * @param string $tenantCode 租户代码
     * @return bool
     */
    private function tenantCodeExists(string $tenantCode): bool
    {
        $tenant = $this->tenantModel->reset()
            ->where(AiTenant::fields_TENANT_CODE, $tenantCode)
            ->find()
            ->fetch();

        return $tenant->getId() > 0;
    }

    /**
     * 获取所有租户
     * 
     * @param string $status 状态过滤
     * @param string $type 类型过滤
     * @return array
     */
    public function getAllTenants(string $status = '', string $type = ''): array
    {
        $query = $this->tenantModel->reset();

        if ($status) {
            $query->where(AiTenant::fields_STATUS, $status);
        }

        if ($type) {
            $query->where(AiTenant::fields_TENANT_TYPE, $type);
        }

        return $query->select()->fetch();
    }

    /**
     * 暂停租户
     * 
     * @return bool
     * @throws Exception
     */
    public function suspendTenant(): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        $this->currentTenant->setData(AiTenant::fields_STATUS, AiTenant::STATUS_SUSPENDED);
        return $this->currentTenant->save();
    }

    /**
     * 激活租户
     * 
     * @return bool
     * @throws Exception
     */
    public function activateTenant(): bool
    {
        if (!$this->currentTenant) {
            throw new Exception("未设置当前租户上下文");
        }

        $this->currentTenant->setData(AiTenant::fields_STATUS, AiTenant::STATUS_ACTIVE);
        return $this->currentTenant->save();
    }
}
