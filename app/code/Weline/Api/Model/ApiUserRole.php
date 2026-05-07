<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'API用户角色关联表')]
#[Index(name: 'idx_w_api_user_role_user_role', columns: ['user_id', 'role_id'], type: 'UNIQUE', comment: '用户角色唯一')]
#[Index(name: 'idx_w_api_user_role_user_id', columns: ['user_id'], comment: '用户ID')]
#[Index(name: 'idx_w_api_user_role_role_id', columns: ['role_id'], comment: '角色ID')]
class ApiUserRole extends Model
{
    public const schema_table = 'm_api_user_role';
    public const schema_primary_key = 'id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: 'API用户ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col(type: 'int', nullable: false, comment: '角色ID')]
    public const schema_fields_role_id = 'role_id';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['user_id', 'role_id'];

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
        return (int)($this->getData(self::schema_fields_user_id) ?? 0);
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): self
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }

    /**
     * 获取角色ID
     */
    public function getRoleId(): int
    {
        return (int)($this->getData(self::schema_fields_role_id) ?? 0);
    }

    /**
     * 设置角色ID
     */
    public function setRoleId(int $roleId): self
    {
        return $this->setData(self::schema_fields_role_id, $roleId);
    }

    /**
     * 保存前设置创建时间
     */
    public function save_before()
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_created_at, date('Y-m-d H:i:s'));
        }
        parent::save_before();
    }
}


