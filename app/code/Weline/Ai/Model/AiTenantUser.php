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

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI租户用户关联数据模型
 * 
 * 功能：
 * - 管理租户与用户的关联关系
 * - 用户角色和权限管理
 * - 租户内用户状态管理
 */
class AiTenantUser extends Model
{
    public const table = 'ai_tenant_user';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_USER_ID = 'user_id';
    public const fields_ROLE = 'role';
    public const fields_PERMISSIONS = 'permissions';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 用户角色常量
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_TENANT_ID, TableInterface::column_type_INTEGER, 11, 'not null', '租户ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 11, 'not null', '用户ID')
                ->addColumn(self::fields_ROLE, TableInterface::column_type_VARCHAR, 50, 'not null default "member"', '用户角色')
                ->addColumn(self::fields_PERMISSIONS, TableInterface::column_type_TEXT, null, 'null', '权限配置JSON')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_id', self::fields_TENANT_ID, '租户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_role', self::fields_ROLE, '角色索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_tenant_user', [self::fields_TENANT_ID, self::fields_USER_ID], '租户用户唯一索引')
                ->create();
        }
    }

    /**
     * 获取租户ID
     * 
     * @return int
     */
    public function getTenantId(): int
    {
        return (int)$this->getData(self::fields_TENANT_ID);
    }

    /**
     * 获取用户ID
     * 
     * @return int
     */
    public function getUserId(): int
    {
        return (int)$this->getData(self::fields_USER_ID);
    }

    /**
     * 获取用户角色
     * 
     * @return string
     */
    public function getRole(): string
    {
        return $this->getData(self::fields_ROLE) ?? self::ROLE_MEMBER;
    }

    /**
     * 获取权限配置
     * 
     * @return array
     */
    public function getPermissions(): array
    {
        $permissions = $this->getData(self::fields_PERMISSIONS);
        return $permissions ? json_decode($permissions, true) : [];
    }

    /**
     * 设置权限配置
     * 
     * @param array $permissions
     * @return $this
     */
    public function setPermissions(array $permissions): self
    {
        $this->setData(self::fields_PERMISSIONS, json_encode($permissions));
        return $this;
    }

    /**
     * 检查是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 检查是否为管理员
     * 
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->getRole() === self::ROLE_ADMIN;
    }

    /**
     * 检查是否为成员
     * 
     * @return bool
     */
    public function isMember(): bool
    {
        return $this->getRole() === self::ROLE_MEMBER;
    }

    /**
     * 检查是否为查看者
     * 
     * @return bool
     */
    public function isViewer(): bool
    {
        return $this->getRole() === self::ROLE_VIEWER;
    }

    /**
     * 获取角色显示名称
     * 
     * @return string
     */
    public function getRoleDisplayName(): string
    {
        $roleNames = [
            self::ROLE_ADMIN => '管理员',
            self::ROLE_MEMBER => '成员',
            self::ROLE_VIEWER => '查看者'
        ];

        return $roleNames[$this->getRole()] ?? $this->getRole();
    }

    /**
     * 检查权限
     * 
     * @param string $permission 权限名称
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissions();
        
        // 管理员拥有所有权限
        if ($this->isAdmin()) {
            return true;
        }

        // 检查具体权限
        return in_array($permission, $permissions);
    }

    /**
     * 添加权限
     * 
     * @param string $permission
     * @return $this
     */
    public function addPermission(string $permission): self
    {
        $permissions = $this->getPermissions();
        
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->setPermissions($permissions);
        }
        
        return $this;
    }

    /**
     * 移除权限
     * 
     * @param string $permission
     * @return $this
     */
    public function removePermission(string $permission): self
    {
        $permissions = $this->getPermissions();
        
        $key = array_search($permission, $permissions);
        if ($key !== false) {
            unset($permissions[$key]);
            $this->setPermissions(array_values($permissions));
        }
        
        return $this;
    }

    /**
     * 设置角色
     * 
     * @param string $role
     * @return $this
     */
    public function setRole(string $role): self
    {
        $this->setData(self::fields_ROLE, $role);
        return $this;
    }

    /**
     * 激活用户
     * 
     * @return $this
     */
    public function activate(): self
    {
        $this->setData(self::fields_IS_ACTIVE, 1);
        return $this;
    }

    /**
     * 停用用户
     * 
     * @return $this
     */
    public function deactivate(): self
    {
        $this->setData(self::fields_IS_ACTIVE, 0);
        return $this;
    }

    /**
     * 保存前的数据处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
