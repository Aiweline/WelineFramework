<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Setup;

use Weline\Acl\Api\Resource\WhitelistServiceInterface;
use Weline\Api\Model\ApiUser;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * API Module Installation Script
 *
 * Handles database schema creation and initial data setup for the Weline_Api module.
 * Connection is obtained via Setup::getDb() (set by setModuleContext before setup runs).
 *
 * @package Weline_Api
 */
class Install implements InstallInterface
{
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
        
        // 创建 w_api_user_role 表（API用户角色关联表）
        
        // 创建 w_api_user_token 表（API用户令牌表）
        
        // 创建 w_api_security_log 表（安全日志表）
        
        // 创建 w_api_sandbox_test 表（沙盒测试表）

        // 插入API白名单路径
         // API认证接口白名单路径（前端API）
         $apiWhiteListPaths = [
             'api/rest/v1/auth/login',
             'api/rest/v1/auth/exchange',
             'api/rest/v1/auth/refresh',
             'api/rest/v1/auth/token-info',
             'api/rest/v1/auth/logout',
             'api/rest/v1/auth/me',
         ];
         
         // 后端API认证接口白名单路径（后端API路径不包含api_admin前缀，因为路由注册时已经移除了）
         $backendApiWhiteListPaths = [
             'api/rest/v1/backend/auth/login',
             'api/rest/v1/backend/auth/refresh',
             'api/rest/v1/backend/auth/logout',
             'api/rest/v1/backend/auth/me',
             'api/rest/v1/backend/auth/token-info',
         ];
         
         // ObjectManager 对 *Factory 会自动 create()；对 Interface 走工厂桥接。勿再 ->create()。
         $whitelist = ObjectManager::getInstance(WhitelistServiceInterface::class);
         $whitelist->upsertPaths(array_merge($apiWhiteListPaths, $backendApiWhiteListPaths), 'api');

         // 调用ApiUser模型，插入初始数据
         /**@var ApiUser $apiUser */
         $apiUser = ObjectManager::getInstance(ApiUser::class);
         // 检查是否已存在admin用户
         $existingUser = $apiUser->clear()->load('username', 'admin');
         if (!$existingUser->getId()) {
             $apiUser->clear()
                ->setUsername('admin')
                ->setEmail('admin@example.com')
                ->setPassword(bin2hex(random_bytes(32)))
                ->autoGenerateApiCredentials()
                ->setTokenExpireTime(604800)
                ->setRefreshTokenExpireTime(2592000)
               ->setIsEnabled(false)
               ->setIsDeleted(false)
                ->setIpWhitelistEnabled(false)
                ->setAllowedIps([])
                ->setUserAgentRestrictionEnabled(false)
                ->setAllowedUserAgents([])
                ->setSandboxAccount(false)
                ->setData(ApiUser::schema_fields_created_at, date('Y-m-d H:i:s'))
                ->setData(ApiUser::schema_fields_updated_at, date('Y-m-d H:i:s'))
                ->save(); 
         }
    }
}
