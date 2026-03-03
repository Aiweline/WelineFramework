<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Model;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class BackendUserConfig extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'id';
    public const fields_user_id = 'user_id';
    public const fields_value = 'value';
    public const fields_key = 'key';
    public const fields_module = 'module';
    public const fields_name = 'name';

    private array $config = [];
    private array $default_config = [];

    public array $_index_sort_keys = [self::fields_ID, self::fields_user_id, self::fields_key, self::fields_name, self::fields_module];
    public array $_unit_primary_keys = [self::fields_user_id, self::fields_key];

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
    //    $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, null, 'not null default 0', '管理员ID')
                ->addColumn(self::fields_key, TableInterface::column_type_VARCHAR, 50, 'not null', '配置key')
                ->addColumn(self::fields_value, TableInterface::column_type_TEXT, 0, '', '配置信息')
                ->addColumn(self::fields_module, TableInterface::column_type_VARCHAR, 255, 'not null', '模组')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '配置名')
                # 建立唯一索引：管理员ID和key的组合必须唯一
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_user_key', [self::fields_user_id, self::fields_key], '管理员配置唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_module', self::fields_module, '模组索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_name, '配置名')
                ->addAdditional('ENGINE=InnoDB;')
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
            /**@var AuthenticatedSessionInterface $userSession */
            $userSession = SessionFactory::getInstance()->createBackendSession();
            return $this->clear()->where(self::fields_user_id, $userSession->getUserId())
                ->where(self::fields_key, $key)
                ->find()
                ->fetchArray()['value'] ?? '';
        }
        $self_config_key = self::key($key, $module, $name);
        if (isset($this->config[$self_config_key])) {
            return $this->config[$self_config_key];
        }
        # 读取用户全部配置
        /**@var AuthenticatedSessionInterface $userSession */
        $userSession = SessionFactory::getInstance()->createBackendSession();
        $this->reset()
            ->where(self::fields_user_id, $userSession->getUserId())
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
     * @param string $name
     * @param bool $check
     * @return bool
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
        /**@var AuthenticatedSessionInterface $userSession */
        $userSession = SessionFactory::getInstance()->createBackendSession();
        return (bool)$this->clear()
            ->setData(self::fields_key, $key, true)
            ->setData(self::fields_value, $value)
            ->setData(self::fields_user_id, $userSession->getUserId(), true)
            ->setData(self::fields_module, $module, true)
            ->setData(self::fields_name, $name, true)
            ->save(true);
    }

    private static function key(string $key, string $module = '', string $name = ''): string
    {
        return ($module ? $module . '::' : '') . ($name ? $name . '::' : '') . $key;
    }

    /**
     * 设置默认配置
     * @param string $key
     * @param string $value
     * @param string $module
     * @param string $name
     * @param bool $check
     * @return bool|int
     * @throws \Exception
     */
    public function setDefaultConfig(string $key, string $value, string $module, string $name, $check = true): bool|int
    {
        if ($check) {
            # 检测模组
            $moduleInfo = Env::getInstance()->getModuleInfo($module);
            if (!$moduleInfo) {
                if (DEV) {
                    throw new \Exception(__('找不到模组: %{1}', $module));
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
            ->save();
    }

    public function save(string|array|bool|AbstractModel $data = [], string|array|null $sequence = ''): bool|int
    {
        $this->forceCheck();
        return parent::save($data, $sequence);
    }
}
