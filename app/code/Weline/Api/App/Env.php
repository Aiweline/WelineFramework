<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\App;

use Weline\Framework\App\Env as FrameworkEnv;

/**
 * API模块环境扩展
 * 
 * 支持表级别的数据库选择（沙盒模式）
 */
class Env extends FrameworkEnv
{
    /**
     * 认证相关表白名单（始终使用正式数据库）
     */
    private static array $authTables = [
        'w_backend_user',
        'w_backend_user_token',
        'w_api_user',
        'w_api_user_token',
        'w_frontend_user',
        'w_frontend_user_token',
    ];
    
    /**
     * 获取数据库配置（根据表名选择）
     * 
     * @param string|null $tableName 表名，如果提供则根据表名选择数据库
     * @return array
     */
    public function getDbConfig(?string $tableName = null): array
    {
        // 如果是认证相关表，始终使用正式数据库
        if ($tableName && in_array($tableName, self::$authTables)) {
            // 强制使用正式数据库配置（忽略SANDBOX模式）
            $db_conf = $this->config['db'] ?? [];
            if (empty($db_conf) || !isset($db_conf['master'])) {
                // 如果正式数据库配置不存在，回退到父类方法
                return parent::getDbConfig();
            }
            return $db_conf;
        }
        
        // 其他表使用父类逻辑（支持沙盒模式）
        return parent::getDbConfig();
    }
    
    /**
     * 检查表是否为认证相关表
     * 
     * @param string $tableName 表名
     * @return bool
     */
    public static function isAuthTable(string $tableName): bool
    {
        return in_array($tableName, self::$authTables);
    }
    
    /**
     * 添加认证相关表
     * 
     * @param string $tableName 表名
     * @return void
     */
    public static function addAuthTable(string $tableName): void
    {
        if (!in_array($tableName, self::$authTables)) {
            self::$authTables[] = $tableName;
        }
    }
}

