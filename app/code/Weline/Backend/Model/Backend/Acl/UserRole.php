<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/19 22:26:47
 */

namespace Weline\Backend\Model\Backend\Acl;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '管理员角色表')]
#[Index(name: 'idx_backend_acl_user_role_user_role', columns: ['user_id', 'role_id'], type: 'UNIQUE', comment: '用户角色唯一')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_role_id', columns: ['role_id'], comment: '角色ID索引')]
class UserRole extends Model
{
    public const schema_table = 'backend_acl_user_role';
    public const schema_primary_key = 'id';

    public const fields_ID      = 'user_id';
    public const fields_USER_ID = 'user_id';
    public const fields_ROLE_ID = 'role_id';

    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'int', nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    /** 业务上按 (user_id, role_id) 唯一定位一条关联 */
    public array $_unit_primary_keys = [self::schema_fields_USER_ID, self::schema_fields_ROLE_ID];

    public function getRoleId()
    {
        return $this->getData(self::schema_fields_ROLE_ID);
    }

    public function setRoleId(int $role_id): static
    {
        $this->setData(self::schema_fields_ROLE_ID, $role_id);
        return $this;
    }

    public function getUserId()
    {
        return $this->getData(self::schema_fields_USER_ID);
    }

    public function setUserId(int $user_id): static
    {
        $this->setData(self::schema_fields_USER_ID, $user_id);
        return $this;
    }
}