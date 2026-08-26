<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\CustomVendor;
use Weline\Framework\Manager\ObjectManager;

/**
 * 自定义/本地供应商登记与目录合成
 */
class CustomVendorService
{
    /**
     * 新建表单快捷预设（仅填表，不强制写入）
     *
     * @return array<int, array{code:string,name:string,base_url:string,description:string}>
     */
    public function getPresets(): array
    {
        return [
            [
                'code' => 'ollama',
                'name' => 'Ollama',
                'base_url' => 'http://127.0.0.1:11434/v1',
                'description' => '本机 Ollama OpenAI 兼容接口',
            ],
            [
                'code' => 'lmstudio',
                'name' => 'LM Studio',
                'base_url' => 'http://127.0.0.1:1234/v1',
                'description' => '本机 LM Studio OpenAI 兼容接口',
            ],
            [
                'code' => 'vllm',
                'name' => 'vLLM',
                'base_url' => 'http://127.0.0.1:8000/v1',
                'description' => '本机/内网 vLLM OpenAI 兼容接口',
            ],
        ];
    }

    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[\s\-]+/', '_', $code) ?? '';
        $code = preg_replace('/[^a-z0-9_]/', '', $code) ?? '';
        $code = preg_replace('/_+/', '_', $code) ?? '';

        return trim($code, '_');
    }

    public function assertCodeAvailable(string $code, ?int $excludeId = null): void
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            throw new \InvalidArgumentException(__('供应商代码不能为空'));
        }
        if (strlen($code) > 50) {
            throw new \InvalidArgumentException(__('供应商代码过长'));
        }

        $builtin = VendorConfigManager::getBuiltinProviderCodes();
        if (in_array($code, $builtin, true)) {
            throw new \InvalidArgumentException(__('供应商代码已被内置供应商占用: %{1}', [$code]));
        }

        /** @var CustomVendor $model */
        $model = ObjectManager::getInstance(CustomVendor::class);
        $existing = $model->clear()
            ->where(CustomVendor::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        if ($existing && $existing->getId()) {
            if ($excludeId === null || (int)$existing->getId() !== $excludeId) {
                throw new \InvalidArgumentException(__('供应商代码已存在: %{1}', [$code]));
            }
        }
    }

    /**
     * @return list<array>
     */
    public function listRows(?bool $activeOnly = null): array
    {
        /** @var CustomVendor $model */
        $model = ObjectManager::getInstance(CustomVendor::class);
        $query = $model->clear();
        if ($activeOnly === true) {
            $query->where(CustomVendor::schema_fields_IS_ACTIVE, 1);
        } elseif ($activeOnly === false) {
            $query->where(CustomVendor::schema_fields_IS_ACTIVE, 0);
        }

        $rows = $query
            ->order(CustomVendor::schema_fields_UPDATED_AT, 'DESC')
            ->order(CustomVendor::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? $rows : [];
    }

    /**
     * 将自定义行合成 VendorConfigManager 同形配置
     *
     * @param array|CustomVendor $row
     */
    public function toVendorConfig(array|CustomVendor $row): array
    {
        if ($row instanceof CustomVendor) {
            $data = $row->getData();
            $extra = $row->getConfig();
        } else {
            $data = $row;
            $raw = $data[CustomVendor::schema_fields_CONFIG] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $extra = is_array($decoded) ? $decoded : [];
            } else {
                $extra = is_array($raw) ? $raw : [];
            }
        }

        $code = $this->normalizeCode((string)($data[CustomVendor::schema_fields_CODE] ?? ''));
        $name = trim((string)($data[CustomVendor::schema_fields_NAME] ?? $code));
        $baseUrl = rtrim(trim((string)($data[CustomVendor::schema_fields_BASE_URL] ?? '')), '/');
        $testModel = trim((string)($data[CustomVendor::schema_fields_TEST_MODEL] ?? ''));
        if ($testModel === '') {
            $testModel = (string)($extra['test_model'] ?? 'default');
        }

        $modelsApi = $extra['models_api'] ?? [
            'path' => '/models',
            'auth_type' => 'bearer',
            'data_key' => 'data',
            'id_key' => 'id',
            'name_key' => 'id',
            'desc_key' => 'description',
            'context_key' => 'context_window',
            'max_tokens_key' => 'max_tokens',
        ];

        return [
            'name' => $name !== '' ? $name : $code,
            'code' => $code,
            'base_url' => $baseUrl,
            'models_prefix' => $extra['models_prefix'] ?? [],
            'test_model' => $testModel,
            'api_key_field' => 'api_key',
            'base_url_field' => 'base_url',
            'model_field' => 'model',
            'description' => (string)($data[CustomVendor::schema_fields_DESCRIPTION] ?? ''),
            'source' => CustomVendor::SOURCE_CUSTOM,
            'driver' => CustomVendor::DRIVER_OPENAI_COMPAT,
            'price_currency' => (string)($extra['price_currency'] ?? 'USD'),
            'models_api' => is_array($modelsApi) ? $modelsApi : [],
            'models' => is_array($extra['models'] ?? null) ? $extra['models'] : [],
            'supported_features' => $extra['supported_features'] ?? [
                'chat' => true,
                'completion' => true,
                'embedding' => false,
                'image' => false,
                'audio' => false,
            ],
            'account_setup_guide' => $extra['account_setup_guide'] ?? [
                'title' => '本地/自定义供应商接入',
                'summary' => '用于本机或内网 OpenAI 兼容服务（如 Ollama、LM Studio、vLLM）。',
                'steps' => [
                    '确认本地模型服务已启动，并开启 OpenAI 兼容 API。',
                    '在「本地供应商」中登记代码与 Base URL。',
                    '在「供应商账户」中选择该供应商，API Key 可留空。',
                    '在「模型」中手工填写模型代码（如 llama3.2）并保存。',
                ],
                'field_hints' => [
                    'api_key' => '本地服务通常可留空；若服务要求 Key 再填写',
                    'base_url' => '例如 http://127.0.0.1:11434/v1',
                ],
                'notes' => [
                    '连接失败时请确认本机服务已启动且 Base URL 可达。',
                ],
            ],
        ];
    }

    public function isCustomProviderCode(string $providerCode): bool
    {
        $providerCode = $this->normalizeCode($providerCode);
        if ($providerCode === '') {
            return false;
        }
        $config = VendorConfigManager::getProviderConfig($providerCode);
        if (is_array($config) && (($config['source'] ?? '') === CustomVendor::SOURCE_CUSTOM)) {
            return true;
        }

        /** @var CustomVendor $model */
        $model = ObjectManager::getInstance(CustomVendor::class);
        $row = $model->clear()
            ->where(CustomVendor::schema_fields_CODE, $providerCode)
            ->find()
            ->fetch();

        return (bool)($row && $row->getId());
    }

    /**
     * @return array{accounts:int,models:int}
     */
    public function countReferences(string $providerCode): array
    {
        $providerCode = $this->normalizeCode($providerCode);
        /** @var Account $accountModel */
        $accountModel = ObjectManager::getInstance(Account::class);
        $accounts = (int)$accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->count();

        /** @var AiModel $aiModel */
        $aiModel = ObjectManager::getInstance(AiModel::class);
        $models = (int)$aiModel->clear()
            ->where(AiModel::schema_fields_SUPPLIER, $providerCode)
            ->count();

        return ['accounts' => $accounts, 'models' => $models];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveFromRequest(array $data, int $id = 0): CustomVendor
    {
        /** @var CustomVendor $vendor */
        $vendor = ObjectManager::getInstance(CustomVendor::class);
        if ($id > 0) {
            $vendor->load($id);
            if (!$vendor->getId()) {
                throw new \InvalidArgumentException(__('自定义供应商不存在'));
            }
        }

        $code = $this->normalizeCode((string)($data['code'] ?? ($vendor->getData(CustomVendor::schema_fields_CODE) ?? '')));
        if ($id > 0) {
            // 编辑锁定 code
            $code = $this->normalizeCode((string)$vendor->getData(CustomVendor::schema_fields_CODE));
        } else {
            $this->assertCodeAvailable($code);
        }

        $name = trim((string)($data['name'] ?? ''));
        $baseUrl = rtrim(trim((string)($data['base_url'] ?? '')), '/');
        if ($name === '') {
            throw new \InvalidArgumentException(__('请输入显示名称'));
        }
        if ($baseUrl === '') {
            throw new \InvalidArgumentException(__('请输入默认 Base URL'));
        }

        $driver = trim((string)($data['driver'] ?? CustomVendor::DRIVER_OPENAI_COMPAT));
        if ($driver === '') {
            $driver = CustomVendor::DRIVER_OPENAI_COMPAT;
        }
        if ($driver !== CustomVendor::DRIVER_OPENAI_COMPAT) {
            throw new \InvalidArgumentException(__('本期仅支持 openai_compat 驱动'));
        }

        $extra = $vendor->getConfig();
        if (isset($data['config']) && is_array($data['config'])) {
            $extra = array_replace($extra, $data['config']);
        }

        $now = time();
        $vendor->setData(CustomVendor::schema_fields_CODE, $code);
        $vendor->setData(CustomVendor::schema_fields_NAME, $name);
        $vendor->setData(CustomVendor::schema_fields_BASE_URL, $baseUrl);
        $vendor->setData(CustomVendor::schema_fields_DRIVER, $driver);
        $vendor->setData(CustomVendor::schema_fields_IS_ACTIVE, (int)($data['is_active'] ?? 1) === 1 ? 1 : 0);
        $vendor->setData(CustomVendor::schema_fields_DESCRIPTION, trim((string)($data['description'] ?? '')));
        $vendor->setData(CustomVendor::schema_fields_TEST_MODEL, trim((string)($data['test_model'] ?? '')));
        $vendor->setData(CustomVendor::schema_fields_CONFIG, json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $vendor->setData(CustomVendor::schema_fields_UPDATED_AT, $now);
        if (!$vendor->getId()) {
            $vendor->setData(CustomVendor::schema_fields_CREATED_AT, $now);
        }
        $vendor->save();

        VendorConfigManager::clearCache();

        return $vendor;
    }

    /**
     * @return list<array{id:int,model_code:string,name:string,is_active:int,connection_test_status:string}>
     */
    public function listModelsForVendor(string $providerCode): array
    {
        $providerCode = $this->normalizeCode($providerCode);
        if ($providerCode === '') {
            return [];
        }

        /** @var AiModel $aiModel */
        $aiModel = ObjectManager::getInstance(AiModel::class);
        $rows = $aiModel->clear()
            ->where(AiModel::schema_fields_SUPPLIER, $providerCode)
            ->order(AiModel::schema_fields_UPDATED_AT, 'DESC')
            ->order(AiModel::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)($row[AiModel::schema_fields_ID] ?? 0),
                'model_code' => (string)($row[AiModel::schema_fields_MODEL_CODE] ?? ''),
                'name' => (string)($row[AiModel::schema_fields_NAME] ?? ''),
                'is_active' => (int)($row[AiModel::schema_fields_IS_ACTIVE] ?? 0),
                'connection_test_status' => (string)($row[AiModel::schema_fields_CONNECTION_TEST_STATUS] ?? 'pending'),
            ];
        }

        return $out;
    }

    /**
     * 确保自定义供应商有可用本地账户（空 Key + 展示余额）
     */
    public function ensureDefaultLocalAccount(string $providerCode, string $baseUrl): Account
    {
        $providerCode = $this->normalizeCode($providerCode);
        $baseUrl = rtrim(trim($baseUrl), '/');

        /** @var Account $accountModel */
        $accountModel = ObjectManager::getInstance(Account::class);
        $existing = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
            ->order(Account::schema_fields_ID, 'ASC')
            ->find()
            ->fetch();

        $now = time();
        if ($existing && $existing->getId()) {
            $changed = false;
            if ($baseUrl !== '' && (string)$existing->getData(Account::schema_fields_BASE_URL) !== $baseUrl) {
                $existing->setData(Account::schema_fields_BASE_URL, $baseUrl);
                $changed = true;
            }
            if ((int)$existing->getData(Account::schema_fields_IS_ACTIVE) !== 1) {
                $existing->setData(Account::schema_fields_IS_ACTIVE, 1);
                $changed = true;
            }
            if ((float)$existing->getData(Account::schema_fields_BALANCE) <= 0) {
                $existing->setData(Account::schema_fields_BALANCE, 999999);
                $changed = true;
            }
            $status = (string)$existing->getData(Account::schema_fields_CONNECTION_STATUS);
            if ($status !== Account::STATUS_SUCCESS && $status !== Account::STATUS_FAILED) {
                $existing->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
                $changed = true;
            }
            if ($changed) {
                $existing->setData(Account::schema_fields_UPDATED_AT, $now);
                $existing->save();
            }

            return $existing;
        }

        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        $account->setData(Account::schema_fields_PROVIDER_CODE, $providerCode);
        $account->setData(Account::schema_fields_ACCOUNT_NAME, $providerCode . ' local');
        $account->setEncryptedApiKey('');
        $account->setData(Account::schema_fields_BASE_URL, $baseUrl);
        $account->setData(Account::schema_fields_BALANCE, 999999);
        $account->setData(Account::schema_fields_CURRENCY, 'USD');
        $account->setData(Account::schema_fields_IS_ACTIVE, 1);
        $account->setData(Account::schema_fields_IS_DEFAULT, 1);
        // 本地兼容端点允许空 Key；创建即视为可调度，避免适配器绑定里整供应商标「未关联账户」
        $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
        $account->setData(Account::schema_fields_CREATED_AT, $now);
        $account->setData(Account::schema_fields_UPDATED_AT, $now);
        $account->save();

        return $account;
    }

    /**
     * 同步供应商下模型：upsert + 删除标记项
     *
     * @param list<array<string,mixed>> $models
     * @param list<int|string> $removedIds
     * @return array{saved:int,deleted:int,models:list<array>}
     */
    public function syncVendorModels(
        string $providerCode,
        string $baseUrl,
        array $models,
        array $removedIds = [],
        ?Account $account = null
    ): array {
        $providerCode = $this->normalizeCode($providerCode);
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($providerCode === '') {
            throw new \InvalidArgumentException(__('供应商代码不能为空'));
        }

        $account = $account ?? $this->ensureDefaultLocalAccount($providerCode, $baseUrl);
        $accountId = (int)$account->getId();
        $deleted = 0;
        $saved = 0;
        $now = time();

        foreach ($removedIds as $rid) {
            $id = (int)$rid;
            if ($id <= 0) {
                continue;
            }
            /** @var AiModel $row */
            $row = ObjectManager::getInstance(AiModel::class)->load($id);
            if (!$row->getId()) {
                continue;
            }
            if ($this->normalizeCode((string)$row->getData(AiModel::schema_fields_SUPPLIER)) !== $providerCode) {
                continue;
            }
            $row->delete();
            $deleted++;
        }

        $seenCodes = [];
        foreach ($models as $item) {
            if (!is_array($item)) {
                continue;
            }
            $modelCode = trim((string)($item['model_code'] ?? ''));
            if ($modelCode === '') {
                continue;
            }
            $codeKey = strtolower($modelCode);
            if (isset($seenCodes[$codeKey])) {
                throw new \InvalidArgumentException(__('模型代码重复: %{1}', [$modelCode]));
            }
            $seenCodes[$codeKey] = true;

            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                $name = $modelCode;
            }
            $id = (int)($item['id'] ?? 0);
            $tested = !empty($item['tested']) || ((string)($item['connection_test_status'] ?? '') === 'success');
            // 本地/自定义已登记模型默认可选；仅当显式传 is_active=0 时保持停用
            if (\array_key_exists('is_active', $item)) {
                $wantActive = !empty($item['is_active']) || $tested;
            } else {
                $wantActive = true;
            }

            /** @var AiModel $model */
            $model = ObjectManager::getInstance(AiModel::class);
            if ($id > 0) {
                $model->load($id);
                if ($model->getId()
                    && $this->normalizeCode((string)$model->getData(AiModel::schema_fields_SUPPLIER)) !== $providerCode
                ) {
                    throw new \InvalidArgumentException(__('模型不属于当前供应商'));
                }
            }
            if (!$model->getId()) {
                $found = $model->clear()
                    ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
                    ->find()
                    ->fetch();
                if ($found && $found->getId()) {
                    $existingSupplier = $this->normalizeCode((string)$found->getData(AiModel::schema_fields_SUPPLIER));
                    if ($existingSupplier !== '' && $existingSupplier !== $providerCode) {
                        throw new \InvalidArgumentException(__(
                            '模型代码已被其他供应商占用: %{1}（%{2}）',
                            [$modelCode, $existingSupplier]
                        ));
                    }
                    $model = $found;
                } else {
                    $model = ObjectManager::getInstance(AiModel::class);
                }
            }

            $providerConfig = [
                'base_url' => $baseUrl,
                'model' => $modelCode,
                'provider_model_code' => $modelCode,
                'model_id' => $modelCode,
                'account_id' => $accountId,
                'api_key' => '',
            ];
            $config = [
                'base_url' => $baseUrl,
                'api_url' => $baseUrl,
                'model' => $modelCode,
                'api_key' => '',
            ];

            $model->setData(AiModel::schema_fields_SUPPLIER, $providerCode);
            $model->setData(AiModel::schema_fields_MODEL_CODE, $modelCode);
            $model->setData(AiModel::schema_fields_NAME, $name);
            if (!$model->getData(AiModel::schema_fields_VERSION)) {
                $model->setData(AiModel::schema_fields_VERSION, '1.0');
            }
            $model->setData(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT);
            $model->setData(AiModel::schema_fields_MODEL_SOURCE, AiModel::SOURCE_LOCAL);
            $model->setData(AiModel::schema_fields_STATUS, AiModel::STATUS_ACTIVE);
            $model->setData(AiModel::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode($providerConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $model->setData(AiModel::schema_fields_UPDATED_AT, $now);
            if (!$model->getId()) {
                $model->setData(AiModel::schema_fields_CREATED_AT, $now);
                $model->setData(AiModel::schema_fields_CONNECTION_TEST_STATUS, 'pending');
            }

            if ($tested) {
                $model->setData(AiModel::schema_fields_CONNECTION_TEST_STATUS, 'success');
                $model->setData(AiModel::schema_fields_CONNECTION_TEST_TIME, $now);
                $model->setData(AiModel::schema_fields_PROVIDER_TEST_STATUS, 'success');
                $model->setData(AiModel::schema_fields_PROVIDER_TEST_TIME, $now);
            }

            $model->setData(AiModel::schema_fields_IS_ACTIVE, $wantActive ? 1 : 0);
            $model->save();
            $saved++;
        }

        if ($saved > 0) {
            $this->markLocalAccountReady($account);
        }

        return [
            'saved' => $saved,
            'deleted' => $deleted,
            'models' => $this->listModelsForVendor($providerCode),
        ];
    }

    /**
     * 本地账户允许空 API Key；同步模型后标记为可调度（适配器绑定 / ConfigResolver）
     */
    private function markLocalAccountReady(Account $account): void
    {
        $changed = false;
        if ((int)$account->getData(Account::schema_fields_IS_ACTIVE) !== 1) {
            $account->setData(Account::schema_fields_IS_ACTIVE, 1);
            $changed = true;
        }
        $status = (string)$account->getData(Account::schema_fields_CONNECTION_STATUS);
        // failed 保持，需重新测通；pending/空 → success，便于适配器可选
        if ($status !== Account::STATUS_SUCCESS && $status !== Account::STATUS_FAILED) {
            $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
            $changed = true;
        }
        if ($changed) {
            $account->setData(Account::schema_fields_UPDATED_AT, time());
            $account->save();
        }
    }

    /**
     * 按已保存供应商 ID 做一键连通性测试（解析 Base URL + 默认/首个模型）
     *
     * @return array{success:bool,message:string,response?:mixed,model_code?:string}
     */
    public function testSavedVendorById(int $id, string $apiKey = ''): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException(__('参数错误'));
        }

        /** @var CustomVendor $vendor */
        $vendor = ObjectManager::getInstance(CustomVendor::class)->load($id);
        if (!$vendor->getId()) {
            throw new \InvalidArgumentException(__('自定义供应商不存在'));
        }

        $baseUrl = trim((string)$vendor->getData(CustomVendor::schema_fields_BASE_URL));
        $modelCode = trim((string)$vendor->getData(CustomVendor::schema_fields_TEST_MODEL));
        if ($modelCode === '') {
            $code = (string)$vendor->getData(CustomVendor::schema_fields_CODE);
            foreach ($this->listModelsForVendor($code) as $model) {
                $candidate = trim((string)($model['model_code'] ?? ''));
                if ($candidate !== '') {
                    $modelCode = $candidate;
                    break;
                }
            }
        }

        $result = $this->testCompatEndpoint($baseUrl, $modelCode, $apiKey);
        if (!empty($result['success'])) {
            $result['model_code'] = $modelCode;
        }

        return $result;
    }

    /**
     * 对 OpenAI 兼容端点做连通性测试（无需先落库供应商）
     *
     * @return array{success:bool,message:string,response?:mixed}
     */
    public function testCompatEndpoint(string $baseUrl, string $modelCode, string $apiKey = ''): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $modelCode = trim($modelCode);
        if ($baseUrl === '') {
            throw new \InvalidArgumentException(__('请输入默认 Base URL'));
        }
        if ($modelCode === '') {
            throw new \InvalidArgumentException(__('请填写要测试的模型代码'));
        }

        /** @var AiModel $testModel */
        $testModel = ObjectManager::getInstance(AiModel::class);
        $testModel->setData([
            AiModel::schema_fields_SUPPLIER => 'custom_draft',
            AiModel::schema_fields_MODEL_CODE => $modelCode,
            AiModel::schema_fields_NAME => $modelCode,
            AiModel::schema_fields_PRIMARY_MODALITY => AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            AiModel::schema_fields_MODEL_SOURCE => AiModel::SOURCE_LOCAL,
            AiModel::schema_fields_CONFIG => json_encode([
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'model' => $modelCode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            AiModel::schema_fields_PROVIDER_CONFIG => json_encode([
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'model' => $modelCode,
                'provider_model_code' => $modelCode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        /** @var OpenAiProvider $provider */
        $provider = ObjectManager::getInstance(OpenAiProvider::class);
        try {
            $result = $provider->testConnection($testModel, [
                'max_tokens' => 64,
                'temperature' => 0,
                'test_mode' => true,
                'timeout' => 12,
            ]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('连通性测试失败'),
            ];
        }

        if (!is_array($result)) {
            return ['success' => false, 'message' => __('测试失败')];
        }

        return $result;
    }

    public function deleteById(int $id): void
    {
        /** @var CustomVendor $vendor */
        $vendor = ObjectManager::getInstance(CustomVendor::class)->load($id);
        if (!$vendor->getId()) {
            throw new \InvalidArgumentException(__('自定义供应商不存在'));
        }

        $code = $this->normalizeCode((string)$vendor->getData(CustomVendor::schema_fields_CODE));
        $refs = $this->countReferences($code);
        if ($refs['accounts'] > 0 || $refs['models'] > 0) {
            throw new \InvalidArgumentException(__(
                '无法删除：仍有 %{1} 个账户、%{2} 个模型引用该供应商，请先解绑。',
                [$refs['accounts'], $refs['models']]
            ));
        }

        $vendor->delete();
        VendorConfigManager::clearCache();
    }
}
