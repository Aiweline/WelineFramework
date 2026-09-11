<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Dropship\Service\DropshipWarehouseMapService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

#[Acl('Weline_Dropship::commerce:dropship:warehouses', '仓与国家映射', 'map', '范围级仓映射', 'Weline_Dropship::commerce:dropship:group')]
class Warehouse extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:warehouses_index', '查看仓映射', 'map', '查看仓与国家映射')]
    public function index(): string
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

        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $channels->registerAllProviders();
        $providers = [];
        foreach ($channels->getProviders() as $provider) {
            $meta = $provider->getDisplayMetadata();
            $providers[] = [
                'code' => $provider->getCode(),
                'title' => (string)($meta['title'] ?? $provider->getCode()),
            ];
        }

        /** @var DropshipWarehouseMapService $svc */
        $svc = ObjectManager::getInstance(DropshipWarehouseMapService::class);
        $this->assign('page_title', __('仓与国家映射'));
        $this->assign('maps', $svc->listAll());
        $this->assign('providers', $providers);
        $this->assign('selected_scope', $storageScope);

        return $this->fetch();
    }

    #[Acl('Weline_Dropship::commerce:dropship:warehouses_save', '保存仓映射', 'map', '保存仓与国家映射')]
    public function postSave(): string
    {
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $country = strtoupper(trim((string)$this->request->getPost('cj_country_code', '')));
        $storageId = trim((string)$this->request->getPost('cj_storage_id', ''));
        $localWarehouseId = (int)$this->request->getPost('local_warehouse_id', 0);

        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        try {
            $resolved = $targetScopeService->resolveFromInput([
                'target_scope' => (string)$this->request->getPost('target_scope', ''),
                'website_code' => (string)$this->request->getPost('website_code', ''),
                'store_code' => (string)$this->request->getPost('store_code', ''),
                'channel_code' => (string)$this->request->getPost('channel_code', ''),
            ], false);
        } catch (\Throwable $e) {
            $this->getMessage()->error(__('作用范围无效：%{1}', $e->getMessage()));

            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index'));
        }

        /** @var ScopeIdentity $identity */
        $identity = $resolved['identity'];
        $claims = $identity->toArray();
        $websiteId = $claims['website_id'];
        if ($websiteId === null) {
            $this->getMessage()->error(__('仓映射需要选择具体网站范围，不能使用全局'));

            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index'));
        }
        $websiteId = (int)$websiteId;
        $storeCode = trim((string)($claims['store_code'] ?? ($resolved['store_code'] ?? '')));
        $channelCode = trim((string)($claims['channel_code'] ?? ($resolved['channel_code'] ?? '')));
        if ($channelCode === 'default') {
            $channelCode = '';
        }

        /** @var StoreCatalogInterface $storeCatalog */
        $storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);
        $storeId = 0;
        if ($storeCode !== '' && $storeCode !== 'default') {
            $summary = $storeCatalog->byCode($websiteId, $storeCode);
            if ($summary === null) {
                $this->getMessage()->error(__('未找到店铺：%{1}', $storeCode));

                return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index', [
                    'target_scope' => (string)($resolved['storage_scope'] ?? ''),
                ]));
            }
            $storeId = (int)$summary->id;
        } else {
            $default = $storeCatalog->defaultStore($websiteId);
            if ($default !== null) {
                $storeId = (int)$default->id;
            }
        }

        if ($providerCode === '' || $country === '' || $localWarehouseId <= 0) {
            $this->getMessage()->error(__('请填写供应商、国家代码与本地仓 ID'));

            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index', [
                'target_scope' => (string)($resolved['storage_scope'] ?? ''),
            ]));
        }

        $data = [
            DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID => $websiteId,
            DropshipScopeWarehouseMap::schema_fields_STORE_ID => $storeId,
            DropshipScopeWarehouseMap::schema_fields_CHANNEL => $channelCode,
            DropshipScopeWarehouseMap::schema_fields_CJ_COUNTRY_CODE => $country,
            DropshipScopeWarehouseMap::schema_fields_CJ_STORAGE_ID => $storageId,
            DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID => $localWarehouseId,
            DropshipScopeWarehouseMap::schema_fields_ENABLED => 1,
            DropshipScopeWarehouseMap::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ];
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $existing = $model->clear()
            ->where(DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE, $data[DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE])
            ->where(DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID, $data[DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID])
            ->where(DropshipScopeWarehouseMap::schema_fields_STORE_ID, $data[DropshipScopeWarehouseMap::schema_fields_STORE_ID])
            ->find()->fetch();
        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();
        } else {
            $data[DropshipScopeWarehouseMap::schema_fields_CREATED_AT] = date('Y-m-d H:i:s');
            $model->clear()->setData($data)->save();
        }
        $this->getMessage()->success(__('已保存映射'));

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index', [
            'target_scope' => (string)($resolved['storage_scope'] ?? ''),
        ]));
    }
}
