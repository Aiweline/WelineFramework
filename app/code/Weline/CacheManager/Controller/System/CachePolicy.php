<?php

declare(strict_types=1);

namespace Weline\CacheManager\Controller\System;

use Weline\CacheManager\Service\CacheAdminService;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_CacheManager::cache_policy', '缓存策略', 'mdi mdi-tune-variant', '运行时缓存 TTL 与共享 Memory 策略', 'Weline_CacheManager::cache_service')]
class CachePolicy extends \Weline\Admin\Controller\BaseController
{
    public function __construct(
        private readonly RuntimeCachePolicy $runtimeCachePolicy,
        private readonly CacheAdminService $cacheAdminService
    ) {
    }

    #[Acl('Weline_CacheManager::cache_policy_index', '缓存策略设置', 'mdi mdi-tune-variant', '查看运行时缓存策略')]
    public function index()
    {
        $this->assign('config', $this->runtimeCachePolicy->getConfig());
        $this->assign('defaults', $this->runtimeCachePolicy->defaults());

        return $this->fetch();
    }

    #[Acl('Weline_CacheManager::cache_policy_save', '保存缓存策略', 'mdi mdi-content-save', '保存运行时缓存策略')]
    public function postSave()
    {
        try {
            $config = $this->request->getPost('config', []);
            if (!\is_array($config)) {
                $config = [];
            }

            $this->runtimeCachePolicy->saveConfig($config);
            $clearResult = $this->cacheAdminService->clearAll(false);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('缓存策略已保存，非持久缓存已清理'),
                'data' => [
                    'config' => $this->runtimeCachePolicy->getConfig(),
                    'clear' => $clearResult,
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => $throwable->getMessage(),
                'data' => null,
            ]);
        }
    }
}
