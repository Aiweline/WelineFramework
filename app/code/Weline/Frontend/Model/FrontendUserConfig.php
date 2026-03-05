<?php

declare(strict_types=1);

namespace Weline\Frontend\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 前端用户配置模型 - 存储用户的主题、语言等个性化配置
 */
#[Table(comment: '前端用户配置')]
#[Index(name: 'idx_user_config', columns: ['user_id', 'config_key'])]
class FrontendUserConfig extends Model
{

    public const schema_table = 'frontend_user_config';
    public const schema_primary_key = 'config_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'config_id';
    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('varchar', 100, nullable: false, comment: '配置键')]
    public const schema_fields_CONFIG_KEY = 'config_key';
    #[Col('text', comment: '配置值')]
    public const schema_fields_CONFIG_VALUE = 'config_value';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
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
            ->where(self::schema_fields_USER_ID, $userId)
            ->where(self::schema_fields_CONFIG_KEY, $key)
            ->find()->fetch();
        
        if ($config && $config->getId()) {
            return $config->getData(self::schema_fields_CONFIG_VALUE);
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
                ->where(self::schema_fields_USER_ID, $userId)
                ->where(self::schema_fields_CONFIG_KEY, $key)
                ->find()->fetch();
            
            if ($config && $config->getId()) {
                // 更新现有配置
                $config->setData(self::schema_fields_CONFIG_VALUE, $value)
                    ->save();
            } else {
                // 创建新配置
                $newConfig = new self();
                $newConfig->setData(self::schema_fields_USER_ID, $userId)
                    ->setData(self::schema_fields_CONFIG_KEY, $key)
                    ->setData(self::schema_fields_CONFIG_VALUE, $value)
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
            $this->where(self::schema_fields_USER_ID, $userId)
                ->where(self::schema_fields_CONFIG_KEY, $key)
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
            ->where(self::schema_fields_USER_ID, $userId)
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($configs as $config) {
            $key = $config->getData(self::schema_fields_CONFIG_KEY);
            $value = $config->getData(self::schema_fields_CONFIG_VALUE);
            $result[$key] = $value;
        }
        
        return $result;
    }
}

