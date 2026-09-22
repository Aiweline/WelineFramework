<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/12 21:14:16
 */

namespace Weline\Acl\Model;

use Weline\Acl\Api\RoleIdentityInterface;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '角色表')]
#[\Weline\Framework\Database\Schema\Attribute\Index(name: 'uk_website_role_name', columns: ['website_id', 'role_name'], type: 'UNIQUE', comment: '站内角色名唯一')]
class Role extends Model implements RoleIdentityInterface
{
    /** 平台固定超管角色；不可删除；缺失时由 setup:upgrade 自愈补回 */
    public const ID_SUPER_ADMIN = 1;

#[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '角色ID')]
    public const schema_fields_ID = 'role_id';
#[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '角色名')]
    public const schema_fields_ROLE_NAME = 'role_name';
    #[Col(type: 'text', nullable: true, comment: '角色描述')]
    public const schema_fields_ROLE_DESCRIPTION = 'role_description';
    /** 0=平台/默认站角色；>0=该站站内角色 */
    #[Col(type: 'int', nullable: false, default: 0, comment: '所属网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    function getId(mixed $default = 0)
    {
        return (int)parent::getId($default);
    }

    function setRoleName(string $name): Role
    {
        return $this->setData(self::schema_fields_ROLE_NAME, $name);
    }

    function setRoleDescription(string $description): Role
    {
        return $this->setData(self::schema_fields_ROLE_DESCRIPTION, $description);
    }

    function getRoleName(): string
    {
        return $this->getData(self::schema_fields_ROLE_NAME);
    }

    function getRoleDescription(): string
    {
        return $this->getData(self::schema_fields_ROLE_DESCRIPTION);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): Role
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, max(0, $websiteId));
    }

    function delete_before()
    {
        if ($this->getId() === self::ID_SUPER_ADMIN) {
            throw new Exception(__('不能删除超级管理员！'));
        }
        parent::delete_before();
    }

    /**
     * 确保固定超管角色行存在（Install / setup:upgrade 自愈）。
     * @return bool true=本轮新建，false=已存在
     */
    public static function ensureSuperAdminRoleExists(): bool
    {
        /** @var self $role */
        $role = ObjectManager::getInstance(self::class, [], false);
        $role->load(self::ID_SUPER_ADMIN);
        if ((int)$role->getId() === self::ID_SUPER_ADMIN) {
            return false;
        }
        $role->clearData()
            ->setId(self::ID_SUPER_ADMIN)
            ->setRoleName('超级管理员')
            ->setRoleDescription('拥有所有权限的超管角色')
            ->setWebsiteId(0)
            ->save(true);

        return true;
    }

    /**
     * @DESC          # 获取角色权限
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/27 23:16
     * 参数区：
     * @return array
     * @throws null
     */
    function getAccess(): array
    {
        /**@var \Weline\Acl\Model\RoleAccess $roleAccess */
        $roleAccess = ObjectManager::getInstance(RoleAccess::class);
        return $roleAccess->getRoleAccessList($this);
    }
}
