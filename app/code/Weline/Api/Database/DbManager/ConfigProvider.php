<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Database\DbManager;

use Weline\Api\App\Env as ApiEnv;
use Weline\Framework\App\Env;
use Weline\Framework\Database\DbManager\ConfigProvider as FrameworkConfigProvider;

/**
 * API模块数据库配置提供者扩展
 * 
 * 支持表级别的数据库选择（沙盒模式）
 */
class ConfigProvider extends FrameworkConfigProvider
{
    private ?string $tableName = null;
    
    /**
     * 设置表名
     * 
     * @param string|null $tableName 表名
     * @return self
     */
    public function setTableName(?string $tableName): self
    {
        $this->tableName = $tableName;
        return $this;
    }
    
    /**
     * 获取表名
     * 
     * @return string|null
     */
    public function getTableName(): ?string
    {
        return $this->tableName;
    }
    
    /**
     * 获取配置信息（重写以支持表级别的数据库选择）
     * 
     * @return array|mixed
     */
    protected function getConfig(): mixed
    {
        // 如果设置了表名，使用API模块的Env扩展
        if ($this->tableName) {
            /** @var ApiEnv $env */
            $env = Env::getInstance();
            if ($env instanceof ApiEnv) {
                return $env->getDbConfig($this->tableName);
            }
        }
        
        // 否则使用父类逻辑
        return parent::getConfig();
    }
}

