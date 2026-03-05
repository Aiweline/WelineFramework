<?php

declare(strict_types=1);

/**
 * 缓存升级观察者
 * 
 * 在 setup:upgrade 时同步缓存池配置到数据库
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\CacheManager\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;

class UpgradeCache implements \Weline\Framework\Event\ObserverInterface
{
    private CacheManager $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            $this->syncPoolsToDatabase();
        } catch (\Throwable $e) {
            w_log_error('UpgradeCache failed: ' . $e->getMessage(), [], 'CacheManager::UpgradeCache');
        }
    }

    /**
     * 同步缓存池到数据库
     */
    private function syncPoolsToDatabase(): void
    {
        $identities = $this->cacheManager->getPoolIdentities();
        
        foreach ($identities as $identity) {
            try {
                $pool = $this->cacheManager->pool($identity);
                $stats = $pool->getStats();
                
                $this->savePoolToDatabase([
                    'identity' => $identity,
                    'name' => $stats['adapter'] ?? 'Unknown',
                    'tip' => $stats['tip'] ?? '',
                    'permanent' => $stats['permanent'] ?? false,
                    'ttl' => $stats['default_ttl'] ?? 1800,
                ]);
            } catch (\Throwable $e) {
                w_log_error("Sync pool '{$identity}' failed: " . $e->getMessage(), [], 'CacheManager::UpgradeCache');
            }
        }
    }

    /**
     * 保存缓存池到数据库
     */
    private function savePoolToDatabase(array $poolInfo): void
    {
        /** @var \Weline\CacheManager\Model\Cache $cacheModel */
        $cacheModel = ObjectManager::make(\Weline\CacheManager\Model\Cache::class);
        
        $existing = $cacheModel
            ->where($cacheModel::schema_fields_IDENTITY, $poolInfo['identity'])
            ->find()
            ->fetch();
        
        $data = [
            $cacheModel::schema_fields_NAME => $poolInfo['name'],
            $cacheModel::schema_fields_IDENTITY => $poolInfo['identity'],
            $cacheModel::schema_fields_Module => 'Weline_Framework',
            $cacheModel::schema_fields_FILE => 'CacheManager Pool',
            $cacheModel::schema_fields_TYPE => 0,
            $cacheModel::schema_fields_Status => 1,
            $cacheModel::schema_fields_Permanently => $poolInfo['permanent'] ? 1 : 0,
            $cacheModel::schema_fields_DESCRIPTION => $poolInfo['tip'],
        ];
        
        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();
        } else {
            /** @var \Weline\CacheManager\Model\Cache $newCache */
            $newCache = ObjectManager::make(\Weline\CacheManager\Model\Cache::class);
            $newCache->setData($data)->save();
        }
    }
}
