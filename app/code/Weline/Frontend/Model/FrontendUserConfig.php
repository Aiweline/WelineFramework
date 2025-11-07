<?php

declare(strict_types=1);

namespace Weline\Frontend\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 前端用户配置模型 - 存储用户的主题、语言等个性化配置
 */
class FrontendUserConfig extends Model
{
    public const fields_ID = 'config_id';
    public const fields_USER_ID = 'user_id';
    public const fields_CONFIG_KEY = 'config_key';
    public const fields_CONFIG_VALUE = 'config_value';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
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
        // 可以在这里添加升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->getPrinting()->setup('安装前端用户配置表...', $setup->getTable());
            $setup->createTable('前端用户配置')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '用户ID')
                ->addColumn(self::fields_CONFIG_KEY, TableInterface::column_type_VARCHAR, 100, 'not null', '配置键')
                ->addColumn(self::fields_CONFIG_VALUE, TableInterface::column_type_TEXT, 0, '', '配置值')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_TIMESTAMP, 0, 'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_config', [self::fields_USER_ID, self::fields_CONFIG_KEY])
                ->create();
        }
    }
    
    /**
     * 获取用户配置
     * 
     * @param int $userId 用户ID
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getUserConfig(int $userId, string $key, $default = null)
    {
        $config = $this->clear()
            ->where(self::fields_USER_ID, $userId)
            ->where(self::fields_CONFIG_KEY, $key)
            ->find()->fetch();
        
        if ($config && $config->getId()) {
            return $config->getData(self::fields_CONFIG_VALUE);
        }
        
        return $default;
    }
    
    /**
     * 设置用户配置
     * 
     * @param int $userId 用户ID
     * @param string $key 配置键
     * @param mixed $value 配置值
     * @return bool
     */
    public function setUserConfig(int $userId, string $key, $value): bool
    {
        try {
            $config = $this->clear()
                ->where(self::fields_USER_ID, $userId)
                ->where(self::fields_CONFIG_KEY, $key)
                ->find()->fetch();
            
            if ($config && $config->getId()) {
                // 更新现有配置
                $config->setData(self::fields_CONFIG_VALUE, $value)
                    ->save();
            } else {
                // 创建新配置
                $newConfig = new self();
                $newConfig->setData(self::fields_USER_ID, $userId)
                    ->setData(self::fields_CONFIG_KEY, $key)
                    ->setData(self::fields_CONFIG_VALUE, $value)
                    ->save();
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 删除用户配置
     * 
     * @param int $userId 用户ID
     * @param string $key 配置键
     * @return bool
     */
    public function deleteUserConfig(int $userId, string $key): bool
    {
        try {
            $this->where(self::fields_USER_ID, $userId)
                ->where(self::fields_CONFIG_KEY, $key)
                ->delete();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取用户所有配置
     * 
     * @param int $userId 用户ID
     * @return array
     */
    public function getAllUserConfigs(int $userId): array
    {
        $configs = $this->clear()
            ->where(self::fields_USER_ID, $userId)
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($configs as $config) {
            $key = $config->getData(self::fields_CONFIG_KEY);
            $value = $config->getData(self::fields_CONFIG_VALUE);
            $result[$key] = $value;
        }
        
        return $result;
    }
}
