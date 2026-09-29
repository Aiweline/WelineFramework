<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Service\WarmupCollectService;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * CDN预热管理后台控制器
 *
 * @package Weline_Cdn
 */
#[AclAttribute('Weline_Cdn::cdn_warmup_manager', 'CDN预热管理', 'fire', 'CDN预热管理', 'Weline_Cdn::cdn_manager')]
class Warmup extends BackendController
{
    /**
     * 获取预热URL模型
     */
    private function getWarmupUrlModel(): WarmupUrl
    {
        return ObjectManager::getInstance(WarmupUrl::class);
    }

    /**
     * 获取预热执行器
     */
    private function getWarmupRunner(): WarmupRunner
    {
        return ObjectManager::getInstance(WarmupRunner::class);
    }

    /**
     * 获取预热Provider扫描器
     */
    private function getProviderScanner(): WarmupProviderScanner
    {
        return ObjectManager::getInstance(WarmupProviderScanner::class);
    }

    /**
     * @return array{storage_scope:string,site_id:?int,identity:?ScopeIdentity}
     */
    private function resolveSelectedScope(): array
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $resolved = $targetScopeService->resolveFromInput([
            'target_scope' => (string)$this->request->getGet('target_scope', ''),
            'scope' => (string)$this->request->getGet('scope', ''),
            'website_code' => (string)$this->request->getGet('website_code', ''),
            'store_code' => (string)$this->request->getGet('store_code', ''),
            'channel_code' => (string)$this->request->getGet('channel_code', ''),
        ], false);

        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');
        $identity = $resolved['identity'] ?? null;
        $siteId = null;
        if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
            $siteId = $identity->websiteId;
        }

        return [
            'storage_scope' => $storageScope,
            'site_id' => $siteId,
            'identity' => $identity instanceof ScopeIdentity ? $identity : null,
        ];
    }

    /**
     * 预热URL列表页面
     *
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_list', '查看预热URL列表', 'list', '查看预热URL列表')]
    public function index(): string
    {
        try {
            $scope = $this->resolveSelectedScope();
            $hasExplicit = trim((string)$this->request->getGet('target_scope', '')) !== ''
                || trim((string)$this->request->getGet('scope', '')) !== ''
                || array_key_exists('website_code', $this->request->getGet());

            if (!$hasExplicit) {
                return $this->redirect($this->request->getUrlBuilder()->getBackendUrl(
                    '*/backend/warmup',
                    [
                        'target_scope' => $scope['storage_scope'],
                    ]
                ));
            }

            /** @var WarmupCollectService $collect */
            $collect = ObjectManager::getInstance(WarmupCollectService::class);
            $providers = $collect->listProvidersMeta();

            $this->assign('providers', $providers);
            $this->assign('selected_scope', $scope['storage_scope']);
            $this->assign('target_scope', $scope['storage_scope']);
            $this->assign('selected_site_id', $scope['site_id']);

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载预热管理失败：%{1}', $e->getMessage()));
            $this->assign('providers', []);
            $this->assign('selected_scope', 'default.default.default');
            $this->assign('target_scope', 'default.default.default');
            $this->assign('selected_site_id', null);
            return $this->fetch();
        }
    }

    /**
     * 统计信息页面
     *
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_statistics', '查看统计信息', 'chart', '查看预热统计信息')]
    public function statistics(): string
    {
        try {
            // 按模块统计
            $stats = $this->getWarmupUrlModel()->reset()
                ->select(WarmupUrl::schema_fields_MODULE . ', COUNT(*) as total_count, SUM(' . WarmupUrl::schema_fields_PROCESSED_COUNT . ') as total_processed, SUM(' . WarmupUrl::schema_fields_SUCCESS_COUNT . ') as total_success, SUM(' . WarmupUrl::schema_fields_FAIL_COUNT . ') as total_fail')
                ->group(WarmupUrl::schema_fields_MODULE)
                ->fetch()
                ->getItems();

            $statistics = [];
            foreach ($stats as $stat) {
                $module = $stat->getData(WarmupUrl::schema_fields_MODULE);
                $total = (int)$stat->getData('total_count');
                $processed = (int)$stat->getData('total_processed');
                $success = (int)$stat->getData('total_success');
                $fail = (int)$stat->getData('total_fail');

                $statistics[] = [
                    'module' => $module,
                    'total_count' => $total,
                    'total_processed' => $processed,
                    'total_success' => $success,
                    'total_fail' => $fail,
                    'success_rate' => $processed > 0 ? round($success / $processed * 100, 2) : 0
                ];
            }

            $this->assign('statistics', $statistics);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载统计信息失败：%{1}', $e->getMessage()));
            $this->assign('statistics', []);
            return $this->fetch();
        }
    }

    /**
     * 手动触发预热任务
     *
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_execute', '执行预热任务', 'play', '手动触发预热任务')]
    public function execute(): string
    {
        $limit = (int)$this->request->getPost('limit', 50);
        $domainId = (int)$this->request->getPost('domain_id', 0);
        $provider = trim((string)$this->request->getPost('provider', ''));
        $targetScope = trim((string)$this->request->getPost('target_scope', ''));
        $siteId = null;
        if ($targetScope !== '') {
            try {
                /** @var SystemConfigTargetScopeService $scopeService */
                $scopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
                $resolved = $scopeService->resolveFromInput(['target_scope' => $targetScope], false);
                $identity = $resolved['identity'] ?? null;
                if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
                    $siteId = $identity->websiteId;
                }
            } catch (\Throwable $e) {
                // keep null site filter on resolve failure
            }
        } elseif ($this->request->getPost('site_id', '') !== '' && $this->request->getPost('site_id') !== null) {
            $siteId = (int)$this->request->getPost('site_id');
        }

        try {
            $result = $this->getWarmupRunner()->run(
                $limit,
                $domainId > 0 ? $domainId : null,
                $provider !== '' ? $provider : null,
                $siteId
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => __('预热任务执行完成'),
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('执行失败：%{1}', $e->getMessage())
            ]);
        }
    }

    #[AclAttribute('Weline_Cdn::cdn_warmup_collect', '收集预热URL', 'download', '从 WarmupProvider 收集 URL')]
    public function collect(): string
    {
        $provider = trim((string)$this->request->getPost('provider', ''));
        $domainId = (int)$this->request->getPost('domain_id', 0);
        $targetScope = trim((string)$this->request->getPost('target_scope', ''));
        $siteId = null;
        if ($targetScope !== '') {
            try {
                /** @var SystemConfigTargetScopeService $scopeService */
                $scopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
                $resolved = $scopeService->resolveFromInput(['target_scope' => $targetScope], false);
                $identity = $resolved['identity'] ?? null;
                if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
                    $siteId = $identity->websiteId;
                }
            } catch (\Throwable $e) {
                // keep null
            }
        } elseif ($this->request->getPost('site_id', '') !== '' && $this->request->getPost('site_id') !== null) {
            $siteId = (int)$this->request->getPost('site_id');
        }
        try {
            /** @var WarmupCollectService $service */
            $service = ObjectManager::getInstance(WarmupCollectService::class);
            $result = $service->collectProvider(
                $provider,
                $domainId > 0 ? $domainId : null,
                $siteId
            );
            return $this->jsonResponse([
                'success' => true,
                'message' => __('收集完成'),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('收集失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 启用/禁用URL预热
     *
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_toggle_enable', '启用/禁用预热', 'switch', '启用/禁用URL预热')]
    public function toggleEnable(): string
    {
        $id = (int)$this->request->getPost('id');
        $enabled = (int)$this->request->getPost('enabled', 1);

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('URL ID不能为空')
            ]);
        }

        try {
            $warmupUrl = $this->getWarmupUrlModel()->reset()->load($id);

            if (!$warmupUrl->getData(WarmupUrl::schema_fields_WARMUP_URL_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('URL不存在')
                ]);
            }

            $warmupUrl->setData(WarmupUrl::schema_fields_ENABLED, $enabled ? 1 : 0);
            $warmupUrl->save();

            Message::success($enabled ? __('URL已启用') : __('URL已禁用'));

            return $this->jsonResponse([
                'success' => true,
                'message' => $enabled ? __('URL已启用') : __('URL已禁用')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除预热URL
     *
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_warmup_delete', '删除预热URL', 'trash', '删除预热URL')]
    public function delete(): string
    {
        $id = (int)$this->request->getPost('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('URL ID不能为空')
            ]);
        }

        try {
            $warmupUrl = $this->getWarmupUrlModel()->reset()->load($id);

            if (!$warmupUrl->getData(WarmupUrl::schema_fields_WARMUP_URL_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('URL不存在')
                ]);
            }

            $warmupUrl->delete();

            Message::success(__('URL删除成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('URL删除成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }
}
