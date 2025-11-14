<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 沙盒测试模型
 * 
 * 用于测试沙盒数据库功能
 */
class SandboxTest extends Model
{
    public const fields_ID = 'id';
    public const fields_name = 'name';
    public const fields_content = 'content';
    public const fields_environment = 'environment';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';
    
    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = 'w_api_sandbox_test';
        $this->_id_field_name = 'id';
    }
    
    /**
     * 模型设置
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 表创建在 Setup/Install.php 中处理
    }
    
    /**
     * 模型升级
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }
    
    /**
     * 模型安装
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 安装逻辑
    }
    
    /**
     * 获取名称
     */
    public function getName(): string
    {
        return (string)($this->getData('name') ?? '');
    }
    
    /**
     * 获取内容
     */
    public function getContent(): string
    {
        return (string)($this->getData('content') ?? '');
    }
    
    /**
     * 获取环境
     */
    public function getEnvironment(): string
    {
        return (string)($this->getData('environment') ?? 'sandbox');
    }
    
    /**
     * 获取创建时间
     */
    public function getCreatedAt(): string
    {
        return (string)($this->getData('created_at') ?? '');
    }
    
    /**
     * 获取更新时间
     */
    public function getUpdatedAt(): string
    {
        return (string)($this->getData('updated_at') ?? '');
    }
}

