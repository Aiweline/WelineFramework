<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ApiUserRole extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'id';
    public const fields_user_id = 'user_id';
    public const fields_role_id = 'role_id';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['user_id', 'role_id'];

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
        // 数据库升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 表结构已在 Setup/Install.php 中创建
    }

    /**
     * 获取ID
     */
    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    /**
     * 获取用户ID
     */
    public function getUserId(): int
    {
        return (int)($this->getData(self::fields_user_id) ?? 0);
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): self
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    /**
     * 获取角色ID
     */
    public function getRoleId(): int
    {
        return (int)($this->getData(self::fields_role_id) ?? 0);
    }

    /**
     * 设置角色ID
     */
    public function setRoleId(int $roleId): self
    {
        return $this->setData(self::fields_role_id, $roleId);
    }

    /**
     * 保存前设置创建时间
     */
    public function beforeSave(): self
    {
        if (!$this->getId()) {
            $this->setData('created_at', date('Y-m-d H:i:s'));
        }
        return $this;
    }
}

