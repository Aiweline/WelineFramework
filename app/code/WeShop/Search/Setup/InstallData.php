<?php

declare(strict_types=1);

namespace WeShop\Search\Setup;

use WeShop\Search\Model\SearchEngineConfig;
use WeShop\Search\Service\SearchEngineDriverRegistry;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索模块安装数据
 * 设置默认的 Meilisearch 搜索引擎配置
 */
class InstallData
{
    /**
     * 安装默认配置
     * 
     * @return void
     */
    public function install(): void
    {
        $this->registerDrivers();
        $this->installDefaultConfig();
    }
    
    /**
     * 注册搜索引擎驱动
     * 
     * @return void
     */
    private function registerDrivers(): void
    {
        try {
            /** @var SearchEngineDriverRegistry $registry */
            $registry = ObjectManager::getInstance(SearchEngineDriverRegistry::class);
            
            // 注册 Meilisearch 驱动（默认）
            $registry->updateDrivers([
                'meilisearch' => \WeShop\Search\Engine\MeilisearchEngine::class,
            ]);
            
        } catch (\Exception $e) {
            error_log("注册搜索引擎驱动失败: " . $e->getMessage());
        }
    }
    
    /**
     * 安装默认配置
     * 
     * @return void
     */
    private function installDefaultConfig(): void
    {
        try {
            /** @var SearchEngineConfig $configModel */
            $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
            
            // 检查是否已存在默认配置
            $existing = $configModel->clear()
                ->where(SearchEngineConfig::fields_ENGINE_TYPE, SearchEngineConfig::ENGINE_MEILISEARCH)
                ->where(SearchEngineConfig::fields_SCOPE, 'default')
                ->find()
                ->fetch();
            
            if ($existing->getId()) {
                // 已存在配置，跳过
                return;
            }
            
            // 创建默认 Meilisearch 配置
            $defaultConfig = [
                'host' => 'http://127.0.0.1:7700',
                'api_key' => null,
                'index_name' => 'products',
            ];
            
            $configModel->saveConfig(
                SearchEngineConfig::ENGINE_MEILISEARCH,
                'default',
                $defaultConfig,
                true, // 启用
                100   // 高优先级
            );
            
        } catch (\Exception $e) {
            error_log("安装默认搜索配置失败: " . $e->getMessage());
        }
    }
}
