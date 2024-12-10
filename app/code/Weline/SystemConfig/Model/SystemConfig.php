<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\SystemConfig\Model;

use Weline\Backend\Cache\BackendCache;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class SystemConfig extends \Weline\Framework\Database\Model
{
    public const primary_key = 'key';

    public const fields_KEY = 'key';
    public const fields_VALUE = 'v';
    public const fields_MODULE = 'module';
    public const fields_AREA = 'area';

    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';

    public array $_index_sort_keys = ['key', 'module'];
    public array $_unit_primary_keys = ['key', 'module'];

    static $configs = [];

    public function __init()
    {
        parent::__init();
        if (!isset($this->_cache)) {
            $this->_cache = ObjectManager::getInstance(BackendCache::class);
        }
    }

    public function getConfigByModule(string $module, string $area = self::area_FRONTEND): array|null
    {
        if (isset(self::$configs[$area][$module])) {
            return self::$configs[$area][$module];
        }
        self::$configs[$area][$module] = $this->clear()->reset()->where([['area', $area], ['module', $module]])->select()->fetchArray();
        return self::$configs[$area][$module];
    }

    /**
     * @DESC          # 获取配置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 21:25
     * 参数区：
     *
     * @param string $key
     * @param string $area
     * @param string $module
     *
     * @return mixed
     */
    public function getConfig(string $key, string $module, string $area): mixed
    {
        $cache_key = 'system_config_cache_' . $key . '_' . $area . '_' . $module;
        $result = $this->_cache->get($cache_key);
        if ($result) {
            return $result;
        }
        $result = null;
        if(str_contains($key,'.')){
            $keys = explode('.',$key);
            $key = array_shift($keys);
            $config_value = $this->clear()->reset()->where([['key', $key], ['area', $area], ['module', $module]])->find()->fetch();
            if (isset($config_value['v'])) {
                $config_value = json_decode($config_value['v'],true);
                $result = $config_value[$key]??'';
                foreach ($keys as $key) {
                    if (isset($config_value[$key])) {
                        $result = $config_value[$key];
                    }else{
                        $result = null;
                        break;
                    }
                }
            }
        }else{
            $config_value = $this->clear()->reset()->where([['key', $key], ['area', $area], ['module', $module]])->find()->fetch();
            if (isset($config_value['v'])) {
                $result = $config_value['v'];
            }
        }
        $this->_cache->set($cache_key, $result);
        return $result;
    }

    /**
     * @DESC          # 设置配置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 21:25
     * 参数区：
     *
     * @param string $key
     * @param string $value
     * @param string $module
     * @param string $area
     *
     * @return bool
     * @throws Exception
     */
    public function setConfig(string $key, string $value, string $module, string $area): bool
    {
        $cache_key = 'system_config_cache_' . $key . '_' . $area . '_' . $module;
        try {
            $this->setData(['key' => $key, 'area' => $area, 'module' => $module, 'v' => $value])
                ->forceCheck()
                ->save();
            # 设置配置缓存
            $this->_cache->set($cache_key, $value);
            return true;
        } catch (\ReflectionException|Core $e) {
            throw new Exception($e->getMessage());
        }
    }

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
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->getPrinting()->printing('安装', $setup->getTable());
            $setup->createTable('系统配置表')
                ->addColumn(self::fields_KEY, TableInterface::column_type_VARCHAR, 120, 'primary key', '键')
                ->addColumn(self::fields_VALUE, TableInterface::column_type_TEXT, 0, '', '值')
                ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 120, 'not null', '模块')
                ->addColumn(self::fields_AREA, TableInterface::column_type_VARCHAR, 120, "NOT NULL DEFAULT 'frontend'", '区域：backend/frontend')
                ->addIndex(\Weline\Framework\Database\Connection\Api\Sql\TableInterface::index_type_KEY,
                    'idx_key',
                    self::fields_KEY,
                    '键名索引')
                ->addIndex(\Weline\Framework\Database\Connection\Api\Sql\TableInterface::index_type_KEY,
                    'idx_module',
                    self::fields_MODULE,
                    '模型名索引'
                )
                ->create();
        }
    }
}
