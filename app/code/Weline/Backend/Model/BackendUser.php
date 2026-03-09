<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Model;

use Weline\Acl\Model\Role;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticableInterface;
#[Table(comment: '管理员表')]
#[Index(name: 'uk_email', columns: ['email'], type: 'UNIQUE', comment: '邮箱唯一')]
#[Index(name: 'uk_username', columns: ['username'], type: 'UNIQUE', comment: '用户名唯一')]
class BackendUser extends Model implements AuthenticableInterface
{
    public const schema_table = 'backend_user';
    public const schema_primary_key = 'user_id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '用户ID')]
    public const schema_fields_ID = 'user_id';
    #[Col('varchar', 255, nullable: false, comment: '邮箱')]
    public const schema_fields_email = 'email';
    #[Col('varchar', 128, nullable: false, comment: '用户名')]
    public const schema_fields_username = 'username';
    #[Col('varchar', 255, nullable: false, comment: '密码')]
    public const schema_fields_password = 'password';
    #[Col('varchar', 255, comment: '头像')]
    public const schema_fields_avatar = 'avatar';
    #[Col('varchar', 255, comment: '登录IP')]
    public const schema_fields_login_ip = 'login_ip';
    #[Col('varchar', 255, comment: '尝试登录IP')]
    public const schema_fields_attempt_ip = 'attempt_ip';
    #[Col('int', 0, default: 0, comment: '尝试登录次数')]
    public const schema_fields_attempt_times = 'attempt_times';
    #[Col('varchar', 32, comment: '管理员Session ID')]
    public const schema_fields_sess_id = 'sess_id';
    #[Col('int', 1, default: 0, comment: '是否删除')]
    public const schema_fields_is_deleted = 'is_deleted';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    #[Col('int', 1, default: 0, comment: '是否沙盒账户')]
    public const schema_fields_is_sandbox = 'is_sandbox';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['user_id', 'email', 'username'];

    private bool $_is_new_user = false;

    public function getAttemptTimes(): int
    {
        return intval($this->getData(self::schema_fields_attempt_times));
    }

    public function addAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, intval($this->getData(self::schema_fields_attempt_times)) + 1)
            ->forceCheck();
        return $this;
    }

    public function getAttemptIp()
    {
        return $this->getData(self::schema_fields_attempt_ip);
    }

    public function setAttemptIp($ip): BackendUser
    {
        return $this->setData(self::schema_fields_attempt_ip, $ip)->forceCheck();
    }

    public function resetAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, 0);
        $this->save();
        return $this;
    }

    public function getUsername()
    {
        return $this->getData('username');
    }

    public function getIsDeleted(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_deleted);
    }


    public function setIsDeleted(bool $isDeleted = true): static
    {
        return $this->setData(self::schema_fields_is_deleted, (int)$isDeleted);
    }

    public function getIsEnabled(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_enabled);
    }


    public function setIsEnabled(bool $isEnabled = true): static
    {
        return $this->setData(self::schema_fields_is_enabled, (int)$isEnabled);
    }

    public function setUsername(string $username): BackendUser
    {
        return $this->setData('username', $username);
    }

    public function getEmail()
    {
        return $this->getData('email');
    }

    public function setEmail(string $email): BackendUser
    {
        return $this->setData('email', $email);
    }

    public function getAvatar()
    {
        return $this->getData('avatar');
    }

    public function setAvatar(string $avatar): BackendUser
    {
        return $this->setData('avatar', $avatar);
    }

    public function getPassword()
    {
        return $this->getData('password');
    }

    public function setPassword(string $password): BackendUser
    {
        return $this->setData('password', password_hash($password, PASSWORD_DEFAULT));
    }


    public function getSessionId()
    {
        return $this->getData(self::schema_fields_sess_id);
    }

    public function setSessionId(string $sess_id): BackendUser
    {
        return $this->setData(self::schema_fields_sess_id, $sess_id)->forceCheck();
    }

    public function getLoginIp()
    {
        return $this->getData(self::schema_fields_login_ip);
    }

    public function setLoginIp(string $ip): BackendUser
    {
        return $this->setData(self::schema_fields_login_ip, $ip);
    }

    public function isSandboxAccount(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_sandbox);
    }

    public function setSandboxAccount(bool $flag): static
    {
        return $this->setData(self::schema_fields_is_sandbox, $flag ? 1 : 0);
    }

    public function getRole(): Backend\Acl\UserRole
    {
        if ($role = $this->getData('user_role')) {
            return $role;
        }
        /**@var \Weline\Backend\Model\Backend\Acl\UserRole $userRole */
        $userRole = ObjectManager::getInstance(UserRole::class);
        try {
            $userRole->clear()->joinModel(Role::class, 'r', 'main_table.role_id=r.role_id')
                ->where('main_table.' . self::schema_fields_ID, $this->getId())
                ->find()->fetch();
        } catch (\Throwable $e) {
            throw $e;
        }
        // user_id=1 视为超管：若关联表无记录则虚拟为 role_id=1，保证菜单与权限逻辑一致
        if ((int) $this->getId() === 1 && !$userRole->getRoleId()) {
            $userRole->setUserId((int) $this->getId())->setRoleId(1);
        }
        $this->setData('user_role', $userRole);
        return $userRole;
    }

    public function getRoleModel(): Role
    {
        if ($role = $this->getData('role')) {
            return $role;
        }
        /**@var Role $role */
        $role = clone ObjectManager::getInstance(Role::class);
        $role = $role->load($this->getRole()->getRoleId() ?: 0);
        if ($role->getId()) $this->setData('role', $role);
        return $role;
    }

    public function assignRole(int $role_id)
    {
        /**@var UserRole $userRole */
        $userRole = ObjectManager::getInstance(UserRole::class);
        $userRole->setUserId($this->getId())
            ->setRoleId($role_id)
            ->save(true);
    }

    public function save_before(): void
    {
        $this->_is_new_user = !$this->getOriginData(self::schema_fields_ID);
    }

    public function save_after(): void
    {
        if ($this->_is_new_user && $this->getId()) {
            $this->dispatchUserRegisteredEvent();
        }
    }

    private function dispatchUserRegisteredEvent(): void
    {
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);

        $eventData = [
            'user_id'  => (int) $this->getId(),
            'username' => $this->getUsername(),
            'email'    => $this->getEmail(),
            'phone'    => $this->getData('phone') ?: null,
            'is_new'   => true,
        ];

        $eventsManager->dispatch('Weline_Backend::user::registered', $eventData);
    }

    // ==================== AuthenticableInterface 实现 ====================

    /**
     * @inheritDoc
     */
    public function getAuthIdentifier(): int|string
    {
        return (int)$this->getId();
    }

    /**
     * @inheritDoc
     */
    public function getAuthUsername(): string
    {
        return (string)$this->getUsername();
    }

    /**
     * @inheritDoc
     * 后台 sess_id 仅用于审计/追踪，不用于“切换用户 session”，故始终返回空，避免 login 时误 destroy 当前 session。
     */
    public function getAuthSessionId(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public static function getAuthModelClass(): string
    {
        return self::class;
    }
}

