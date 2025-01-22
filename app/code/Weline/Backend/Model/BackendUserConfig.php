<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Model;

use Weline\Backend\Session\BackendSession;
use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Db\Ddl\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class BackendUserConfig extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'user_id';
    public const fields_user_id = 'user_id';
    public const fields_value = 'value';
    public const fields_key = 'key';
    public const fields_module = 'module';
    public const fields_name = 'name';

    private array $config = [];
    private array $default_config = [];

    public array $_index_sort_keys = [self::fields_ID, self::fields_key, self::fields_name, self::fields_module];
    public array $_unit_primary_keys = [self::fields_ID, self::fields_key];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        //        $setup->dropTable();
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
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'not null default 0', '管理员ID')
                ->addColumn(self::fields_key, TableInterface::column_type_VARCHAR, 248, 'not null', '配置key')
                ->addColumn(self::fields_value, TableInterface::column_type_TEXT, 0, '', '配置信息')
                ->addColumn(self::fields_module, TableInterface::column_type_VARCHAR, 255, 'not null', '模组')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '配置名')
                # 建立联合索引
                ->addConstraints(
                    'PRIMARY KEY (`' . self::fields_ID . '`,`' . self::fields_key . '`) USING BTREE'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_module', self::fields_module, '模组索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_name, '配置名')
                ->addAdditional('ENGINE=MyIsam;')
                ->create();
        }
    }

    /** 返回配置
     * @param string $key
     * @param bool $real
     * @return string
     */
    public function getConfig(string $key, string $module = '', string $name = '', bool $real = false): string
    {
        if (CLI) {
            return $this->getDefaultConfig($key);
        }
        if ($real) {
            /**@var BackendSession $userSession */
            $userSession = ObjectManager::getInstance(BackendSession::class);
            return $this->clear()->where(self::fields_user_id, $userSession->getLoginUserID())
                ->where(self::fields_key, $key)
                ->find()
                ->fetchArray()['value'] ?? '';
        }
        $self_config_key = self::key($key, $module, $name);
        if (isset($this->config[$self_config_key])) {
            return $this->config[$self_config_key];
        }
        # 读取用户全部配置
        /**@var BackendSession $userSession */
        $userSession = ObjectManager::getInstance(BackendSession::class);
        $this->reset()
            ->where(self::fields_user_id, $userSession->getLoginUserID())
            ->where(self::fields_key, $key);
        if ($module) {
            $this->where(self::fields_module, $module);
        }
        if ($name) {
            $this->where(self::fields_name, $name);
        }
        $config = $this
            ->find()
            ->fetchArray();
        $this->config[$self_config_key] = $config['value'] ?? '';
        return $this->config[$self_config_key];
    }

    public function getDefaultConfig(string $key): string
    {
        if (isset($this->default_config[$key])) {
            return $this->default_config[$key];
        }
        # 读取默认配置
        try {
            $config = $this->clear()
                ->where(self::fields_user_id, 0)
                ->find()
                ->fetchArray();
        } catch (\Throwable $e) {
            $config = null;
        }
        $this->default_config[$key] = $config['value'] ?? '';
        return $this->default_config[$key];
    }

    /**
     * 设置用户配置
     * @param string $key
     * @param string $value
     * @param string $module
     * @throws \Exception
     */
    public function setConfig(string $key, string $value, string $module, string $name, $check = true): bool
    {
        $this->config[self::key($key, $module, $name)] = $value;
        if (CLI) {
            return $this->setDefaultConfig($key, $value, $module, $name);
        }
        if ($check) {
            # 检测模组
            $moduleInfo = Env::getInstance()->getModuleInfo($module);
            if (!$moduleInfo) {
                if (DEV) {
                    throw new \Exception('找不到模组' . $module);
                }
                return false;
            }
        }

        # 设置用户配置
        /**@var BackendSession $userSession */
        $userSession = ObjectManager::getInstance(BackendSession::class);
        return (bool)$this->clear()
            ->setData(self::fields_key, $key, true)
            ->setData(self::fields_value, $value, true)
            ->setData(self::fields_user_id, $userSession->getLoginUserID(), true)
            ->setData(self::fields_module, $module, true)
            ->setData(self::fields_name, $name, true)
            ->save(true);
    }

    private static function key(string $key, string $module = '', string $name = ''): string
    {
        $key = ($module ? $module . '::' : '') . ($name ? $name . '::' : '') . $key;
        return $key;
    }

    /**
     * 设置默认配置
     * @param string $key
     * @param string $value
     * @param string $module
     * @return bool
     * @throws \Exception
     */
    public function setDefaultConfig(string $key, string $value, string $module, string $name, $check = true): bool|int
    {
        if ($check) {
            # 检测模组
            $moduleInfo = Env::getInstance()->getModuleInfo($module);
            if (!$moduleInfo) {
                if (DEV) {
                    throw new \Exception('找不到模组' . $module);
                }
                return false;
            }
        }
        # 设置默认配置
        return (bool)$this->clear()
            ->setData(self::fields_key, $key, true)
            ->setData(self::fields_value, $value)
            ->setData(self::fields_user_id, 0, true)
            ->setData(self::fields_module, $module, true)
            ->setData(self::fields_name, $name, true)
            ->save(true);
    }

    public function save(array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        $this->forceCheck();
        return parent::save($data, $sequence);
    }
}
