<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * API Module Installation Script
 * 
 * Handles database schema creation and initial data setup for the Weline_Api module.
 * 
 * @package Weline_Api
 */
class Install implements InstallInterface
{
    private ConnectionFactory $connectionFactory;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * Execute installation
     * 
     * Creates all required database tables and indexes for the API module.
     * 
     * @param Setup $setup Setup instance
     * @param Context $context Installation context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        $connection = $setup->getDb();
        
        // 创建 w_api_user 表（API用户表，独立于BackendUser）
        $connection->createTable('w_api_user', 'API用户表')
            ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '用户ID')
            ->addColumn('username', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, 'not null', '用户名')
            ->addColumn('email', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '邮箱')
            ->addColumn('password', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '密码（加密存储）')
            ->addColumn('api_key', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 64, 'not null', 'API密钥（用于令牌交换）')
            ->addColumn('api_secret', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'API密钥（加密存储）')
            ->addColumn('token_expire_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 604800', '访问令牌有效期（秒，默认7天）')
            ->addColumn('refresh_token_expire_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 2592000', '刷新令牌有效期（秒，默认30天）')
            ->addColumn('is_enabled', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用（1=启用，0=禁用）')
            ->addColumn('is_deleted', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否删除（1=已删除，0=未删除）')
            ->addColumn('ip_whitelist_enabled', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否启用IP白名单（1=启用，0=禁用）')
            ->addColumn('allowed_ips', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '允许的IP地址列表（JSON格式或换行分隔）')
            ->addColumn('user_agent_restriction_enabled', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否启用用户代理限制（1=启用，0=禁用）')
            ->addColumn('allowed_user_agents', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '允许的用户代理列表（JSON格式或换行分隔）')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
            ->addIndex('UNIQUE', 'idx_w_api_user_username', ['username'])
            ->addIndex('UNIQUE', 'idx_w_api_user_email', ['email'])
            ->addIndex('UNIQUE', 'idx_w_api_user_api_key', ['api_key'])
            ->addIndex('INDEX', 'idx_w_api_user_is_enabled', ['is_enabled'])
            ->addIndex('INDEX', 'idx_w_api_user_is_deleted', ['is_deleted'])
            ->addIndex('INDEX', 'idx_w_api_user_ip_whitelist_enabled', ['ip_whitelist_enabled'])
            ->addIndex('INDEX', 'idx_w_api_user_user_agent_restriction_enabled', ['user_agent_restriction_enabled'])
            ->create();
        
        // 创建 w_api_user_role 表（API用户角色关联表）
        $connection->createTable('w_api_user_role', 'API用户角色关联表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', 'API用户ID')
            ->addColumn('role_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '角色ID')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addIndex('UNIQUE', 'idx_w_api_user_role_user_role', ['user_id', 'role_id'])
            ->addIndex('INDEX', 'idx_w_api_user_role_user_id', ['user_id'])
            ->addIndex('INDEX', 'idx_w_api_user_role_role_id', ['role_id'])
            ->create();
        
        // 创建 w_api_user_token 表（API用户令牌表）
        $connection->createTable('w_api_user_token', 'API用户令牌表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '用户ID')
            ->addColumn('token', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '令牌值')
            ->addColumn('type', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '令牌类型（access_token/refresh_token/pass_token）')
            ->addColumn('token_expire_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '过期时间（Unix时间戳）')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addIndex('UNIQUE', 'idx_w_api_user_token_token', ['token'])
            ->addIndex('INDEX', 'idx_w_api_user_token_user_id', ['user_id'])
            ->addIndex('INDEX', 'idx_w_api_user_token_type', ['type'])
            ->addIndex('INDEX', 'idx_w_api_user_token_expire_time', ['token_expire_time'])
            ->create();
        
        // 创建 w_api_security_log 表（安全日志表）
        $connection->createTable('w_api_security_log', 'API安全日志表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '用户ID（如果有）')
            ->addColumn('request_ip', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 45, '', '请求IP')
            ->addColumn('user_agent', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 500, '', 'User-Agent')
            ->addColumn('violation_type', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '违规类型（cookie_violation/ip_whitelist/user_agent_restriction）')
            ->addColumn('request_path', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 500, '', '请求路径')
            ->addColumn('details', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '详细信息（JSON格式）')
            ->addColumn('has_cookie', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否包含Cookie（仅记录是否存在，不记录具体内容）')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '请求时间')
            ->addIndex('INDEX', 'idx_w_api_security_log_user_id', ['user_id'])
            ->addIndex('INDEX', 'idx_w_api_security_log_request_ip', ['request_ip'])
            ->addIndex('INDEX', 'idx_w_api_security_log_violation_type', ['violation_type'])
            ->addIndex('INDEX', 'idx_w_api_security_log_created_at', ['created_at'])
            ->create();
        
        // 创建 w_api_sandbox_test 表（沙盒测试表）
        $connection->createTable('w_api_sandbox_test', '沙盒测试表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '测试名称')
            ->addColumn('content', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '测试内容')
            ->addColumn('environment', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null default \'sandbox\'', '环境标识（sandbox/production）')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
            ->addIndex('INDEX', 'idx_w_api_sandbox_test_environment', ['environment'])
            ->addIndex('INDEX', 'idx_w_api_sandbox_test_created_at', ['created_at'])
            ->create();
    }
}

