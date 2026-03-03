<?php

declare(strict_types=1);

/**
 * 缓存管理控制器
 * 
 * 提供缓存池的管理功能
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\CacheManager\Controller\System;

use Weline\Framework\App\Env;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_CacheManager::system_cache', '缓存管理', 'mdi mdi-database-cog-outline', '系统缓存状态管理')]
class Cache extends \Weline\Admin\Controller\BaseController
{
    private CacheManager $cacheManager;

    public function __construct()
    {
        parent::__construct();
        $this->cacheManager = ObjectManager::getInstance(CacheManager::class);
    }

    #[Acl('Weline_CacheManager::system_cache_index', '缓存列表', 'mdi mdi-view-list', '查看缓存列表')]
    public function index()
    {
        /** @var \Weline\CacheManager\Model\Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
        $caches = $cacheModel->pagination(
            $this->request->getParam('page', 1),
            $this->request->getParam('pageSize', 10),
            $this->request->getParams()
        )->select()->fetch();
        
        $poolStats = $this->cacheManager->getAllStats();
        
        $this->assign('caches', $caches->getItems());
        $this->assign('pagination', $caches->getPagination());
        $this->assign('total', $caches->getPaginationData()['totalSize']);
        $this->assign('poolStats', $poolStats);
        
        return $this->fetch();
    }

    #[Acl('Weline_CacheManager::system_cache_status', '更新缓存状态', 'mdi mdi-toggle-switch', '启用或禁用缓存')]
    public function postStatus()
    {
        $identity = $this->request->getParam('identity');
        if ($identity === null || $identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }
        $identity = (string) $identity;
        $cache = ($this->request->getParam('cache') === 'false') ? 0 : 1;
        
        /** @var \Weline\CacheManager\Model\Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(\Weline\CacheManager\Model\Cache::class);
        
        try {
            $exists = $cacheModel->where('identity', $identity)->find()->fetch();
            if (!$exists || !$exists->getId()) {
                return $this->fetchJson(['code' => 403, 'msg' => __('该缓存项不存在或无权修改'), 'data' => null]);
            }
            $cacheModel->where('identity', $identity)->update(['status' => $cache])->fetch();
            
            $cacheEnv = Env::getInstance()->getConfig('cache');
            $pools = $cacheEnv['pools'] ?? [];
            if (!isset($pools[$identity])) {
                $pools[$identity] = [];
            }
            $pools[$identity]['enabled'] = (bool) $cache;
            $cacheEnv['pools'] = $pools;
            
            if (!Env::getInstance()->setConfig('cache', $cacheEnv)) {
                return $this->fetchJson(['code' => 500, 'msg' => __('env 配置写入失败，请检查 app/etc/env.php 权限'), 'data' => $cache]);
            }
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => null]);
        }
        
        return $this->fetchJson(['code' => 200, 'msg' => __('操作成功！'), 'data' => $cache]);
    }

    #[Acl('Weline_CacheManager::system_cache_clear', '清理缓存', 'mdi mdi-delete', '清理指定缓存池')]
    public function postClear()
    {
        $identity = $this->request->getParam('identity');
        
        if ($identity === null || $identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }
        
        $identity = (string) $identity;
        
        try {
            if (!$this->cacheManager->hasPool($identity)) {
                return $this->fetchJson(['code' => 404, 'msg' => __('缓存池 %{1} 不存在', $identity), 'data' => null]);
            }
            
            $pool = $this->cacheManager->pool($identity);
            
            if ($pool->isPermanent() && !$this->request->getParam('force')) {
                return $this->fetchJson([
                    'code' => 403,
                    'msg' => __('缓存池 %{1} 为持久缓存，需要强制清理（force=1）', $identity),
                    'data' => null
                ]);
            }
            
            $pool->clear();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('缓存池 %{1} 已清理', $identity), 'data' => null]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 500, 'msg' => $exception->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_clear_all', '清理所有缓存', 'mdi mdi-delete-sweep', '清理所有非持久缓存池')]
    public function postClearAll()
    {
        try {
            $force = (bool) $this->request->getParam('force', false);
            
            if ($force) {
                $this->cacheManager->flushAll();
                $msg = __('已强制清理所有缓存池（包括持久缓存）');
            } else {
                $this->cacheManager->clearAll();
                $msg = __('已清理所有非持久缓存池');
            }
            
            return $this->fetchJson(['code' => 200, 'msg' => $msg, 'data' => null]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 500, 'msg' => $exception->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_stats', '缓存统计', 'mdi mdi-chart-bar', '查看缓存池统计信息')]
    public function getStats()
    {
        try {
            $stats = $this->cacheManager->getAllStats();
            
            return $this->fetchJson(['code' => 200, 'msg' => 'success', 'data' => $stats]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 500, 'msg' => $exception->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_pool_stats', '单池统计', 'mdi mdi-chart-pie', '查看单个缓存池统计')]
    public function getPoolStats()
    {
        $identity = $this->request->getParam('identity');
        
        if ($identity === null || $identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }
        
        try {
            if (!$this->cacheManager->hasPool($identity)) {
                return $this->fetchJson(['code' => 404, 'msg' => __('缓存池 %{1} 不存在', $identity), 'data' => null]);
            }
            
            $pool = $this->cacheManager->pool($identity);
            $stats = $pool->getStats();
            
            return $this->fetchJson(['code' => 200, 'msg' => 'success', 'data' => $stats]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 500, 'msg' => $exception->getMessage(), 'data' => null]);
        }
    }
}
