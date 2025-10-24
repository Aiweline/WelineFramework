<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Model;

use Weline\Backend\Model\Config;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Db\Ddl\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\View\Template;

class FrontendUser extends \Weline\Framework\Database\Model
{
    public const fields_ID            = 'user_id';
    public const fields_username      = 'username';
    public const fields_password      = 'password';
    public const fields_avatar        = 'avatar';
    public const fields_login_ip      = 'login_ip';
    public const fields_attempt_ip    = 'attempt_ip';
    public const fields_attempt_times = 'attempt_times';
    public const fields_sess_id       = 'sess_id';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
//        $setup->createTable('管理员表')
//            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '用户ID')
//            ->addColumn(self::fields_username, TableInterface::column_type_VARCHAR, 60, '', '用户名')
//            ->addColumn(self::fields_password, TableInterface::column_type_VARCHAR, 255, '', '密码')
//            ->addColumn(self::fields_avatar, TableInterface::column_type_VARCHAR, 255, '', '头像')
//            ->addColumn(self::fields_login_ip, TableInterface::column_type_VARCHAR, 16, '', '登录IP')
//            ->addColumn(self::fields_sess_id, TableInterface::column_type_VARCHAR, 32, '', '管理员Session ID')
//            ->addColumn(self::fields_attempt_times, TableInterface::column_type_INTEGER, 0, '', '尝试登录次数')
//            ->addColumn(self::fields_attempt_ip, TableInterface::column_type_VARCHAR, 16, '', '尝试登录IP')
//            ->create();
//
//        # 初始化一个账户
//        /**@var AdminUser $adminUser */
//        $adminUser = ObjectManager::getInstance(AdminUser::class);
//        $adminUser->setUsername('Admin')->setPassword('admin')->save();
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('用户表')
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '用户ID')
                  ->addColumn(self::fields_username, TableInterface::column_type_VARCHAR, 60, '', '用户名')
                  ->addColumn(self::fields_password, TableInterface::column_type_VARCHAR, 255, '', '密码')
                  ->addColumn(self::fields_avatar, TableInterface::column_type_VARCHAR, 255, '', '头像')
                  ->addColumn(self::fields_login_ip, TableInterface::column_type_VARCHAR, 16, '', '登录IP')
                  ->addColumn(self::fields_sess_id, TableInterface::column_type_VARCHAR, 32, '', '管理员Session ID')
                  ->addColumn(self::fields_attempt_times, TableInterface::column_type_INTEGER, 0, '', '尝试登录次数')
                  ->addColumn(self::fields_attempt_ip, TableInterface::column_type_VARCHAR, 16, '', '尝试登录IP')
                  ->create();

            # 初始化一个账户
            /**@var FrontendUser $user */
            $user = ObjectManager::getInstance(FrontendUser::class);
            $user->setUsername('秋枫雁飞')->setPassword('admin')->save();
        }
    }

    public function getAttemptTimes()
    {
        return intval($this->getData(self::fields_attempt_times));
    }

    public function addAttemptTimes(): static
    {
        $this->setData(self::fields_attempt_times, intval($this->getData(self::fields_attempt_times)) + 1);
        return $this;
    }

    public function getAttemptIp()
    {
        return $this->getData(self::fields_attempt_ip);
    }

    public function setAttemptIp($ip)
    {
        return $this->setData(self::fields_attempt_ip, $ip);
    }

    public function resetAttemptTimes(): static
    {
        $this->setData(self::fields_attempt_times, 0);
        $this->save();
        return $this;
    }

    public function getUsername()
    {
        return $this->getData('username');
    }

    public function setUsername(string $username)
    {
        return $this->setData('username', $username);
    }

    public function getAvatar()
    {
        return $this->getData('avatar');
    }

    public function setAvatar(string $avatar)
    {
        return $this->setData('avatar', $avatar);
    }

    public function getPassword()
    {
        return $this->getData('password');
    }

    public function setPassword(string $password)
    {
        return $this->setData('password', password_hash($password, PASSWORD_DEFAULT));
    }


    public function getSessionId()
    {
        return $this->getData(self::fields_sess_id);
    }

    public function setSessionId(string $sess_id): AdminUser
    {
        return $this->setData(self::fields_sess_id, $sess_id);
    }

    public function getLoginIp()
    {
        return $this->getData(self::fields_login_ip);
    }

    public function setLoginIp(string $ip): AdminUser
    {
        return $this->setData(self::fields_login_ip, $ip);
    }
}
