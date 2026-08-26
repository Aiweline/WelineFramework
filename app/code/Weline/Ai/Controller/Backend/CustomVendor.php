<?php
declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\Provider\CustomVendor as CustomVendorModel;
use Weline\Ai\Service\Provider\CustomVendorService;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

/**
 * 本地/自定义 AI 供应商管理
 */
#[Acl('Weline_Ai::ai_custom_vendor', '本地AI供应商', 'server', '本地/自定义AI供应商管理', 'Weline_Backend::ai_group')]
class CustomVendor extends BackendPageController
{
    private CustomVendorService $customVendorService;

    public function __construct(CustomVendorService $customVendorService)
    {
        $this->customVendorService = $customVendorService;
    }

    #[Acl('Weline_Ai::ai_custom_vendor_list', '查看本地供应商', 'list', '查看本地/自定义供应商列表')]
    public function index(): string
    {
        if ($this->request->isAjax() || $this->request->getGet('format') === 'json') {
            return $this->listJson();
        }

        $this->assign('activeTab', 'custom_vendor');
        $this->assign('presets', $this->customVendorService->getPresets());
        $this->assign('vendors', $this->customVendorService->listRows(null));
        return $this->fetch();
    }

    #[Acl('Weline_Ai::ai_custom_vendor_list', '查看本地供应商', 'list', '查看本地/自定义供应商列表')]
    public function listJson(): string
    {
        $rows = $this->customVendorService->listRows(null);
        $data = [];
        foreach ($rows as $row) {
            $code = (string)($row[CustomVendorModel::schema_fields_CODE] ?? '');
            $refs = $this->customVendorService->countReferences($code);
            $data[] = [
                'id' => (int)($row[CustomVendorModel::schema_fields_ID] ?? 0),
                'code' => $code,
                'name' => (string)($row[CustomVendorModel::schema_fields_NAME] ?? ''),
                'base_url' => (string)($row[CustomVendorModel::schema_fields_BASE_URL] ?? ''),
                'driver' => (string)($row[CustomVendorModel::schema_fields_DRIVER] ?? CustomVendorModel::DRIVER_OPENAI_COMPAT),
                'is_active' => (int)($row[CustomVendorModel::schema_fields_IS_ACTIVE] ?? 0),
                'description' => (string)($row[CustomVendorModel::schema_fields_DESCRIPTION] ?? ''),
                'test_model' => (string)($row[CustomVendorModel::schema_fields_TEST_MODEL] ?? ''),
                'accounts' => $refs['accounts'],
                'models' => $refs['models'],
                'updated_at' => (int)($row[CustomVendorModel::schema_fields_UPDATED_AT] ?? 0),
            ];
        }

        return $this->fetchJson(['success' => true, 'data' => $data]);
    }

    #[Acl('Weline_Ai::ai_custom_vendor_edit', '编辑本地供应商', 'edit', '编辑本地/自定义供应商')]
    public function editOffcanvas(): string
    {
        $this->layoutType = 'default.blank';
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
        $meta = is_array($this->getData('meta')) ? $this->getData('meta') : [];
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $this->assign('meta', $meta);

        $id = (int)$this->request->getGet('id');
        $vendor = null;
        if ($id > 0) {
            /** @var CustomVendorModel $vendor */
            $vendor = ObjectManager::getInstance(CustomVendorModel::class)->load($id);
            if (!$vendor->getId()) {
                return '<div class="w-alert" data-tone="danger">' . __('自定义供应商不存在') . '</div>';
            }
        }
        $this->assign('vendor', $vendor);
        $this->assign('presets', $this->customVendorService->getPresets());
        $vendorModels = [];
        if ($vendor && $vendor->getId()) {
            $vendorModels = $this->customVendorService->listModelsForVendor(
                (string)$vendor->getData(CustomVendorModel::schema_fields_CODE)
            );
        }
        $this->assign('vendorModels', $vendorModels);
        return $this->fetch('offcanvas_edit');
    }

    #[Acl('Weline_Ai::ai_custom_vendor_save', '保存本地供应商', 'save', '保存本地/自定义供应商')]
    public function save(): string
    {
        try {
            $data = $this->request->getParams();
            if ($this->request->isPost()) {
                $body = $this->request->getBodyParams(true);
                if (is_array($body) && $body !== []) {
                    $data = array_merge($data, $body);
                }
            }
            $id = (int)($data['id'] ?? 0);
            $modelsPayload = $this->parseModelsPayload($data);
            $removedIds = $this->parseRemovedModelIds($data);

            // 若未填测试模型，用模型列表第一项补齐
            if (trim((string)($data['test_model'] ?? '')) === '' && $modelsPayload !== []) {
                $data['test_model'] = (string)($modelsPayload[0]['model_code'] ?? '');
            }

            $vendor = $this->customVendorService->saveFromRequest($data, $id);
            $code = (string)$vendor->getData(CustomVendorModel::schema_fields_CODE);
            $baseUrl = (string)$vendor->getData(CustomVendorModel::schema_fields_BASE_URL);
            $account = $this->customVendorService->ensureDefaultLocalAccount($code, $baseUrl);
            $sync = $this->customVendorService->syncVendorModels(
                $code,
                $baseUrl,
                $modelsPayload,
                $removedIds,
                $account
            );

            $msg = __('本地供应商已保存');
            if (($sync['saved'] ?? 0) > 0 || ($sync['deleted'] ?? 0) > 0) {
                $msg = __(
                    '本地供应商已保存（模型 %{1} 个，删除 %{2} 个）',
                    [(int)($sync['saved'] ?? 0), (int)($sync['deleted'] ?? 0)]
                );
            }

            return $this->fetchJson([
                'success' => true,
                'message' => $msg,
                'id' => (int)$vendor->getId(),
                'code' => $code,
                'models' => $sync['models'] ?? [],
                'account_id' => (int)$account->getId(),
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Ai::ai_custom_vendor_save', '测试本地供应商连通性', 'test', '测试本地/自定义供应商连通性')]
    public function testConnection(): string
    {
        try {
            $data = $this->request->getParams();
            if ($this->request->isPost()) {
                $body = $this->request->getBodyParams(true);
                if (is_array($body) && $body !== []) {
                    $data = array_merge($data, $body);
                }
            }
            $apiKey = trim((string)($data['api_key'] ?? ''));
            $id = (int)($data['id'] ?? 0);
            $modelCode = '';
            if ($id > 0) {
                $result = $this->customVendorService->testSavedVendorById($id, $apiKey);
            } else {
                $baseUrl = trim((string)($data['base_url'] ?? ''));
                $modelCode = trim((string)($data['model_code'] ?? $data['test_model'] ?? ''));
                $result = $this->customVendorService->testCompatEndpoint($baseUrl, $modelCode, $apiKey);
            }
            if (!empty($result['success'])) {
                $result['message'] = $result['message'] ?? __('连通性测试成功');
                if (!isset($result['model_code']) || trim((string)$result['model_code']) === '') {
                    $result['model_code'] = $modelCode;
                }
            }
            return $this->fetchJson($result);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function parseModelsPayload(array $data): array
    {
        if (isset($data['models']) && is_array($data['models'])) {
            return array_values($data['models']);
        }
        $raw = trim((string)($data['models_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(__('模型列表格式无效'));
        }

        return array_values($decoded);
    }

    /**
     * @param array<string,mixed> $data
     * @return list<int>
     */
    private function parseRemovedModelIds(array $data): array
    {
        if (isset($data['removed_model_ids']) && is_array($data['removed_model_ids'])) {
            return array_values(array_map('intval', $data['removed_model_ids']));
        }
        $raw = trim((string)($data['removed_model_ids_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }

    #[Acl('Weline_Ai::ai_custom_vendor_delete', '删除本地供应商', 'trash', '删除本地/自定义供应商')]
    public function postDelete(): string
    {
        try {
            $id = (int)($this->request->getParam('id') ?? $this->request->getPost('id') ?? 0);
            if ($id <= 0) {
                throw new \InvalidArgumentException(__('参数错误'));
            }
            $this->customVendorService->deleteById($id);
            return $this->fetchJson(['success' => true, 'message' => __('已删除')]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Acl('Weline_Ai::ai_custom_vendor_save', '切换本地供应商状态', 'switch', '启用或停用本地供应商')]
    public function toggleActive(): string
    {
        try {
            $id = (int)($this->request->getParam('id') ?? $this->request->getPost('id') ?? 0);
            $active = (int)($this->request->getParam('is_active') ?? $this->request->getPost('is_active') ?? 0) === 1 ? 1 : 0;
            /** @var CustomVendorModel $vendor */
            $vendor = ObjectManager::getInstance(CustomVendorModel::class)->load($id);
            if (!$vendor->getId()) {
                throw new \InvalidArgumentException(__('自定义供应商不存在'));
            }
            $vendor->setData(CustomVendorModel::schema_fields_IS_ACTIVE, $active);
            $vendor->setData(CustomVendorModel::schema_fields_UPDATED_AT, time());
            $vendor->save();
            VendorConfigManager::clearCache();
            return $this->fetchJson([
                'success' => true,
                'message' => $active ? __('已启用') : __('已停用'),
                'is_active' => $active,
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
