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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 沙盒测试模型
 * 用于测试沙盒数据库功能
 */
#[Table(comment: '沙盒测试表')]
#[Index(name: 'idx_w_api_sandbox_test_environment', columns: ['environment'], comment: '环境')]
#[Index(name: 'idx_w_api_sandbox_test_created_at', columns: ['created_at'], comment: '创建时间')]
class SandboxTest extends Model
{
    public const schema_table = 'm_api_sandbox_test';
    public const schema_primary_key = 'id';
#[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '测试名称')]
    public const schema_fields_name = 'name';
    #[Col(type: 'text', nullable: true, comment: '测试内容')]
    public const schema_fields_content = 'content';
    #[Col(type: 'varchar', length: 50, nullable: false, default: 'sandbox', comment: '环境标识（sandbox/production）')]
    public const schema_fields_environment = 'environment';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
    
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


