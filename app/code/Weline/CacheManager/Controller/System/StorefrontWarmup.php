<?php

declare(strict_types=1);

namespace Weline\CacheManager\Controller\System;

use Weline\CacheManager\Service\StorefrontWarmupAdminService;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_CacheManager::storefront_warmup', '自动预热进度', 'fire', '店面 FPC 自动预热看板与负载参数', 'Weline_CacheManager::cache_service')]
class StorefrontWarmup extends \Weline\Framework\App\Controller\BackendPageController
{
    public function __construct(
        private readonly StorefrontWarmupAdminService $adminService
    ) {
    }

    #[Acl('Weline_CacheManager::storefront_warmup_index', '查看自动预热进度', 'list', '查看预热相位、进度与机器负载')]
    public function index()
    {
        $this->assign('status', $this->adminService->status());

        return $this->fetch();
    }
}
