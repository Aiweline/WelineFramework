<?php
declare(strict_types=1);

namespace Weline\Ai\Extends\Module\Weline_Framework\Query;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\Provider\ModelListingProviderInterface;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

final class AiProviderAccountQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly SessionFactory $sessionFactory
    ) {
    }

    public function getProviderName(): string
    {
        return 'aiProviderAccount';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $this->assertBackendSession();

        return match ($operation) {
            'listAccounts' => $this->listAccounts($params),
            'getAccount' => $this->getAccount($params),
            'saveAccount' => $this->saveAccount($this->payload($params)),
            'testConnection' => $this->testConnection($this->payload($params)),
            'remoteModelsForSelect' => $this->remoteModelsForSelect($params),
            'getUsageList' => $this->getUsageList($params),
            'toggleActive' => $this->toggleActive($params),
            'deleteAccount' => $this->deleteAccount($params),
            default => throw new \InvalidArgumentException('Unsupported AI provider account operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'aiProviderAccount',
            'name' => 'AI provider account backend operations',
            'description' => 'Backend-only AI provider account management operations for the supplier account UI.',
            'module' => 'Weline_Ai',
            'operations' => [
                $this->operation('listAccounts', 'read', [
                    'page' => ['type' => 'int', 'min' => 1],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 100],
                    'search' => ['type' => 'string', 'max_length' => 200],
                    'provider_code' => ['type' => 'string', 'max_length' => 50],
                ]),
                $this->operation('getAccount', 'read', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->operation('saveAccount', 'write', [
                    'payload' => ['type' => 'map', 'required' => true],
                ]),
                $this->operation('testConnection', 'write', [
                    'payload' => ['type' => 'map', 'required' => true],
                ]),
                $this->operation('remoteModelsForSelect', 'read', [
                    'provider_code' => ['type' => 'string', 'max_length' => 50],
                    'account_id' => ['type' => 'int', 'min' => 0],
                    'api_key' => ['type' => 'string', 'max_length' => 4096],
                    'base_url' => ['type' => 'string', 'max_length' => 1024],
                    'require_remote' => ['type' => 'bool'],
                ]),
                $this->operation('getUsageList', 'read', [
                    'page' => ['type' => 'int', 'min' => 1],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 100],
                    'account_id' => ['type' => 'int', 'min' => 0],
                    'date_from' => ['type' => 'string', 'max_length' => 30],
                    'date_to' => ['type' => 'string', 'max_length' => 30],
                ]),
                $this->operation('toggleActive', 'write', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->operation('deleteAccount', 'write', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
            ],
        ];
    }

    private function operation(string $name, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'frontend' => true,
            'mode' => $mode,
            'graph' => false,
            'cost' => 1,
            'auth' => 'backend',
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }

    private function assertBackendSession(): void
    {
        $session = $this->sessionFactory->createBackendSession();
        if (!$session->isLoggedIn() || (int)($session->getUserId() ?? 0) <= 0) {
            throw new \RuntimeException('Backend login required.');
        }
    }

    private function payload(array $params): array
    {
        return is_array($params['payload'] ?? null) ? $params['payload'] : [];
    }

    private function listAccounts(array $params): array
    {
        try {
            $page = max(1, (int)($params['page'] ?? 1));
            $limit = max(1, min(100, (int)($params['limit'] ?? 20)));
            $search = trim((string)($params['search'] ?? ''));
            $providerCode = $this->normalizeProviderCode((string)($params['provider_code'] ?? ''));

            /** @var Account $counter */
            $counter = ObjectManager::getInstance(Account::class, [], false);
            if ($search !== '') {
                $counter->where(Account::schema_fields_ACCOUNT_NAME, "%{$search}%", 'like');
            }
            if ($providerCode !== '') {
                $counter->where(Account::schema_fields_PROVIDER_CODE, $providerCode);
            }
            $total = $counter->count();

            /** @var Account $accountModel */
            $accountModel = ObjectManager::getInstance(Account::class, [], false);
            if ($search !== '') {
                $accountModel->where(Account::schema_fields_ACCOUNT_NAME, "%{$search}%", 'like');
            }
            if ($providerCode !== '') {
                $accountModel->where(Account::schema_fields_PROVIDER_CODE, $providerCode);
            }

            $accounts = $accountModel->order(Account::schema_fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetch()
                ->getItems();

            $formatted = [];
            foreach ($accounts as $account) {
                $data = is_object($account) && method_exists($account, 'getData') ? $account->getData() : (array)$account;
                $formatted[] = $this->formatAccountForList($data);
            }

            return [
                'success' => true,
                'data' => $formatted,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function getAccount(array $params): array
    {
        try {
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) {
                return $this->failure('Account ID is required.');
            }

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class, [], false)->load($id);
            if (!$account->getId()) {
                return $this->failure('Account does not exist.');
            }

            $data = $this->normalizeAccountBaseUrlForOutput($account->getData());
            if (!empty($data[Account::schema_fields_API_KEY])) {
                $apiKey = (string)$data[Account::schema_fields_API_KEY];
                $len = strlen($apiKey);
                $data['api_key_masked'] = $len > 8 ? str_repeat('*', max(0, $len - 4)) . substr($apiKey, -4) : str_repeat('*', $len);
            } else {
                $data['api_key_masked'] = '';
            }
            $data[Account::schema_fields_API_KEY] = '';
            $data[Account::schema_fields_PROXY_CONFIG] = $this->decodeJsonMap($data[Account::schema_fields_PROXY_CONFIG] ?? null);

            return ['success' => true, 'data' => $data];
        } catch (\Throwable $throwable) {
            return $this->failure('Failed to load account: ' . $throwable->getMessage());
        }
    }

    private function saveAccount(array $data): array
    {
        try {
            $id = (int)($data['id'] ?? 0);

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class, [], false);
            if ($id > 0) {
                $account->load($id);
                if (!$account->getId()) {
                    throw new \RuntimeException('Account does not exist.');
                }
            }

            if (empty($data['provider_code'])) {
                throw new \RuntimeException('Provider is required.');
            }
            if (empty($data['account_name'])) {
                throw new \RuntimeException('Account name is required.');
            }
            if (empty($data['api_key']) && !$account->getId()) {
                throw new \RuntimeException('API key is required.');
            }

            $providerCode = $this->normalizeProviderCode((string)$data['provider_code']);
            $account->setData(Account::schema_fields_PROVIDER_CODE, $providerCode);
            $account->setData(Account::schema_fields_ACCOUNT_NAME, (string)$data['account_name']);
            if (!empty($data['api_key'])) {
                $account->setEncryptedApiKey((string)$data['api_key']);
            }
            if (!empty($data['api_secret'])) {
                $account->setData(Account::schema_fields_API_SECRET, (string)$data['api_secret']);
            }
            $account->setData(Account::schema_fields_BASE_URL, $this->normalizeProviderBaseUrl($providerCode, (string)($data['base_url'] ?? '')));
            $account->setData(Account::schema_fields_BALANCE, (float)($data['balance'] ?? 0));
            $account->setData(Account::schema_fields_CURRENCY, (string)($data['currency'] ?? 'USD'));
            $account->setData(Account::schema_fields_IS_ACTIVE, $this->truthy($data['is_active'] ?? false) ? 1 : 0);
            $account->setData(Account::schema_fields_PROXY_CONFIG, $this->buildProxyConfig($data));
            if (!$account->getId()) {
                $account->setData(Account::schema_fields_CREATED_AT, time());
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_PENDING);
            }
            $account->setData(Account::schema_fields_UPDATED_AT, time());
            $account->save();

            if ($this->truthy($data['is_default'] ?? false)) {
                $this->accountService->setDefaultAccount($account);
            }

            return [
                'success' => true,
                'message' => 'Account saved successfully.',
                'account_id' => $account->getId(),
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function testConnection(array $data): array
    {
        try {
            if ($this->truthy($data['test_only'] ?? false)) {
                return $this->testConnectionOnly($data);
            }

            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException('Account ID is invalid.');
            }

            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class, [], false)->load($id);
            if (!$account->getId()) {
                throw new \RuntimeException('Account does not exist.');
            }

            $modelCode = trim((string)($data['model_code'] ?? ''));
            $result = $this->accountService->testConnection(
                $account,
                $modelCode !== '' ? $modelCode : null,
                $this->buildConnectionTestOptions($data)
            );

            $account->reset()->load($id);
            if (empty($result['success']) && $account->getData(Account::schema_fields_IS_ACTIVE)) {
                $account->setData(Account::schema_fields_IS_ACTIVE, 0);
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_FAILED);
                $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, $result['message'] ?? '');
                $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
                $account->save();
            }

            $result['connection_status'] = $account->getData(Account::schema_fields_CONNECTION_STATUS);
            $result['connection_test_time'] = $account->getData(Account::schema_fields_CONNECTION_TEST_TIME);
            $result['connection_test_message'] = $account->getData(Account::schema_fields_CONNECTION_TEST_MESSAGE);
            return $result;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function testConnectionOnly(array $data): array
    {
        if (empty($data['provider_code'])) {
            throw new \RuntimeException('Provider is required.');
        }
        if (empty($data['api_key'])) {
            throw new \RuntimeException('API key is required.');
        }

        $providerCode = $this->normalizeProviderCode((string)$data['provider_code']);
        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class, [], false);
        $account->setData(Account::schema_fields_PROVIDER_CODE, $providerCode);
        $account->setEncryptedApiKey((string)$data['api_key']);
        $account->setData(Account::schema_fields_BASE_URL, $this->normalizeProviderBaseUrl($providerCode, (string)($data['base_url'] ?? '')));
        $account->setData(Account::schema_fields_PROXY_CONFIG, $this->buildProxyConfig($data));

        $modelCode = trim((string)($data['model_code'] ?? ''));
        return $this->accountService->testConnection(
            $account,
            $modelCode !== '' ? $modelCode : null,
            $this->buildConnectionTestOptions($data)
        );
    }

    private function remoteModelsForSelect(array $params): array
    {
        try {
            $providerCode = $this->normalizeProviderCode((string)($params['provider_code'] ?? ''));
            $accountId = (int)($params['account_id'] ?? 0);
            $apiKey = trim((string)($params['api_key'] ?? ''));
            $baseUrl = trim((string)($params['base_url'] ?? ''));
            $requireRemote = (bool)($params['require_remote'] ?? false);

            if ($providerCode === '') {
                return $this->failure('Provider is required.');
            }

            $config = VendorConfigManager::getProviderConfig($providerCode);
            if (!$config) {
                return $this->failure('Provider does not exist: ' . $providerCode);
            }

            $modelsApi = $config['models_api'] ?? [];
            if (!is_array($modelsApi) || empty($modelsApi['path'])) {
                return [
                    'success' => false,
                    'unsupported' => true,
                    'message' => 'This provider does not configure a models API.',
                ];
            }

            if ($accountId > 0) {
                /** @var Account $account */
                $account = ObjectManager::getInstance(Account::class, [], false)->load($accountId);
                if (!$account->getId() || $this->normalizeProviderCode((string)$account->getData(Account::schema_fields_PROVIDER_CODE)) !== $providerCode) {
                    return $this->failure('Provider account does not exist or does not belong to this provider.');
                }
                $apiKey = $account->getDecryptedApiKey();
                $baseUrl = (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($config['base_url'] ?? ''));
            } elseif ($apiKey === '') {
                $account = $this->accountService->getAvailableAccount($providerCode);
                if (!$account || !$account->getId()) {
                    /** @var Account $account */
                    $account = ObjectManager::getInstance(Account::class, [], false)
                        ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
                        ->where(Account::schema_fields_IS_ACTIVE, 1)
                        ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
                        ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
                        ->order(Account::schema_fields_CREATED_AT, 'DESC')
                        ->find()
                        ->fetch();
                }
                if ($account && $account->getId()) {
                    $apiKey = $account->getDecryptedApiKey();
                    $baseUrl = (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($config['base_url'] ?? ''));
                }
            }

            if ($apiKey === '') {
                return $this->failure('Select a provider account or enter an API key before loading models.');
            }

            $baseUrl = $this->normalizeProviderBaseUrl($providerCode, $baseUrl !== '' ? $baseUrl : (string)($config['base_url'] ?? ''));
            $provider = $this->accountService->getProviderInstance($providerCode);
            if (!$provider instanceof ModelListingProviderInterface || !$provider->supportsModelsApi()) {
                return [
                    'success' => false,
                    'unsupported' => true,
                    'message' => 'This provider class does not support a models API.',
                ];
            }

            $remoteError = '';
            try {
                $models = $provider->listRemoteModels(array_replace($config, [
                    'provider_code' => $providerCode,
                    'api_key' => $apiKey,
                    'base_url' => $baseUrl,
                    'models_api' => $modelsApi,
                ]), [
                    'provider_code' => $providerCode,
                    'models_api' => $modelsApi,
                ]);
            } catch (\Throwable $throwable) {
                $models = [];
                $remoteError = $throwable->getMessage();
            }

            $source = 'models';
            if ($models === [] && !$requireRemote) {
                $models = $this->normalizeProviderPresetModels(is_array($config['models'] ?? null) ? $config['models'] : []);
                $source = $models === [] ? 'empty' : 'preset';
            }
            if ($models === []) {
                return [
                    'success' => false,
                    'data' => [],
                    'unsupported' => false,
                    'message' => $remoteError !== '' ? $remoteError : 'The models API returned no available models.',
                    'source' => $source,
                    'default_model_code' => (string)($config['test_model'] ?? ''),
                ];
            }

            return [
                'success' => true,
                'data' => $models,
                'unsupported' => false,
                'source' => $source,
                'default_model_code' => $this->resolveDefaultModelCode($models, (string)($config['test_model'] ?? '')),
                'warning' => $remoteError,
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function getUsageList(array $params): array
    {
        try {
            $page = max(1, (int)($params['page'] ?? 1));
            $limit = max(1, min(100, (int)($params['limit'] ?? 20)));
            $accountId = (int)($params['account_id'] ?? 0);
            $dateFrom = trim((string)($params['date_from'] ?? ''));
            $dateTo = trim((string)($params['date_to'] ?? ''));

            /** @var UsageRecord $usageModel */
            $usageModel = ObjectManager::getInstance(UsageRecord::class, [], false);
            $this->applyUsageFilters($usageModel, $accountId, $dateFrom, $dateTo);
            $total = $usageModel->count();

            /** @var UsageRecord $recordModel */
            $recordModel = ObjectManager::getInstance(UsageRecord::class, [], false);
            $this->applyUsageFilters($recordModel, $accountId, $dateFrom, $dateTo);
            $records = $recordModel->order(UsageRecord::schema_fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetchArray();
            if (!is_array($records)) {
                $records = [];
            }

            foreach ($records as &$record) {
                $record['created_at_formatted'] = date('Y-m-d H:i:s', (int)($record['created_at'] ?? 0));
                $record['total_cost_formatted'] = ($record['currency'] ?? 'USD') . ' ' . number_format((float)($record['total_cost'] ?? 0), 6);
                $record['request_time_formatted'] = !empty($record['request_time']) ? number_format((float)$record['request_time'] / 1000, 2) . 's' : '-';
            }
            unset($record);

            return [
                'success' => true,
                'data' => $records,
                'total' => $total,
                'stats' => $this->calculateUsageStats($accountId, $dateFrom, $dateTo),
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function toggleActive(array $params): array
    {
        try {
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException('Account ID is invalid.');
            }
            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class, [], false)->load($id);
            if (!$account->getId()) {
                throw new \RuntimeException('Account does not exist.');
            }

            $isActive = !$account->getData(Account::schema_fields_IS_ACTIVE);
            if ($isActive && $account->getData(Account::schema_fields_CONNECTION_STATUS) !== Account::STATUS_SUCCESS) {
                throw new \RuntimeException('Test the connection successfully before activating this account.');
            }

            $account->setData(Account::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
            $account->setData(Account::schema_fields_UPDATED_AT, time());
            $account->save();

            return [
                'success' => true,
                'message' => $isActive ? 'Account activated.' : 'Account disabled.',
                'is_active' => $isActive,
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function deleteAccount(array $params): array
    {
        try {
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException('Account ID is invalid.');
            }
            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class, [], false)->load($id);
            if (!$account->getId()) {
                throw new \RuntimeException('Account does not exist.');
            }

            /** @var UsageRecord $usageRecord */
            $usageRecord = ObjectManager::getInstance(UsageRecord::class, [], false);
            if ($usageRecord->where(UsageRecord::schema_fields_ACCOUNT_ID, $id)->count() > 0) {
                throw new \RuntimeException('This account has usage records and cannot be deleted.');
            }

            $account->delete()->fetch();
            return ['success' => true, 'message' => 'Account deleted successfully.'];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function formatAccountForList(array $data): array
    {
        $data['balance'] = $data['balance'] ?? 0;
        $data['currency'] = $data['currency'] ?? 'USD';
        $data['total_spent'] = $data['total_spent'] ?? 0;
        $data['connection_status'] = $data['connection_status'] ?? Account::STATUS_PENDING;
        $data['created_at'] = $data['created_at'] ?? time();
        $data['provider_code'] = $this->normalizeProviderCode((string)($data['provider_code'] ?? ''));
        $data = $this->normalizeAccountBaseUrlForOutput($data);
        $providers = $this->accountService->getSupportedProviders();
        $data['provider_name'] = $providers[$data['provider_code']]['name'] ?? $data['provider_code'];
        $data['balance_formatted'] = $data['currency'] . ' ' . number_format((float)$data['balance'], 2);
        $data['total_spent_formatted'] = $data['currency'] . ' ' . number_format((float)$data['total_spent'], 2);
        $data['connection_status_text'] = $this->connectionStatusText((string)$data['connection_status']);
        $data['created_at_formatted'] = date('Y-m-d H:i:s', (int)$data['created_at']);
        $apiKey = (string)($data['api_key'] ?? '');
        $data['api_key_masked'] = $apiKey !== '' ? substr($apiKey, 0, 6) . str_repeat('*', 20) . substr($apiKey, -4) : '*******';
        $data['api_key'] = '';
        return $data;
    }

    private function normalizeProviderCode(string $providerCode): string
    {
        return strtolower(trim($providerCode));
    }

    private function normalizeProviderBaseUrl(string $providerCode, string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        if ($this->normalizeProviderCode($providerCode) === 'vectorengine') {
            $baseUrl = str_replace('://api.vectorengine.ai', '://api.vectorengine.cn', $baseUrl);
            foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations', '/models'] as $suffix) {
                if (str_ends_with($baseUrl, $suffix)) {
                    $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                    break;
                }
            }
            if (!preg_match('#/v\d+(?:beta)?$#', $baseUrl)) {
                $baseUrl .= '/v1';
            }
        }
        return $baseUrl;
    }

    private function normalizeAccountBaseUrlForOutput(array $data): array
    {
        if (array_key_exists(Account::schema_fields_BASE_URL, $data)) {
            $data[Account::schema_fields_BASE_URL] = $this->normalizeProviderBaseUrl(
                (string)($data[Account::schema_fields_PROVIDER_CODE] ?? ''),
                (string)$data[Account::schema_fields_BASE_URL]
            );
        }
        return $data;
    }

    private function buildProxyConfig(array $data): ?string
    {
        if (!$this->truthy($data['proxy_enabled'] ?? false)) {
            return null;
        }

        return json_encode([
            'enabled' => true,
            'type' => (string)($data['proxy_type'] ?? 'http'),
            'host' => (string)($data['proxy_host'] ?? ''),
            'port' => (string)($data['proxy_port'] ?? ''),
            'username' => (string)($data['proxy_username'] ?? ''),
            'password' => (string)($data['proxy_password'] ?? ''),
        ]);
    }

    private function buildConnectionTestOptions(array $data): array
    {
        $options = [];
        $primaryModality = trim((string)($data['primary_modality'] ?? ''));
        if ($primaryModality !== '') {
            $options['primary_modality'] = AiModel::normalizePrimaryModality($primaryModality);
        }
        if (is_array($data['capabilities'] ?? null)) {
            $options['capabilities'] = $data['capabilities'];
        }
        if (is_array($data['provider_config'] ?? null)) {
            $options['provider_config'] = $data['provider_config'];
        }
        return $options;
    }

    private function normalizeProviderPresetModels(array $models): array
    {
        $items = [];
        foreach ($models as $model) {
            if (is_scalar($model)) {
                $code = trim((string)$model);
                if ($code !== '') {
                    $items[] = ['value' => $code, 'label' => $code, 'code' => $code, 'name' => $code, 'primary_modality' => ''];
                }
                continue;
            }
            if (!is_array($model)) {
                continue;
            }
            $code = trim((string)($model['code'] ?? $model['id'] ?? $model['model'] ?? $model['name'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string)($model['name'] ?? $code));
            $items[] = [
                'value' => $code,
                'label' => $name !== $code ? ($name . ' (' . $code . ')') : $code,
                'code' => $code,
                'name' => $name,
                'description' => (string)($model['description'] ?? ''),
                'max_tokens' => (int)($model['max_tokens'] ?? 0),
                'context_window' => (int)($model['context_window'] ?? 0),
                'primary_modality' => (string)($model['primary_modality'] ?? ''),
            ];
        }
        return $items;
    }

    private function resolveDefaultModelCode(array $models, string $configuredDefault): string
    {
        $configuredDefault = trim($configuredDefault);
        $first = '';
        foreach ($models as $model) {
            $code = trim((string)($model['code'] ?? $model['value'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($first === '') {
                $first = $code;
            }
            if ($configuredDefault !== '' && strcasecmp($code, $configuredDefault) === 0) {
                return $code;
            }
        }
        return $first;
    }

    private function applyUsageFilters(UsageRecord $model, int $accountId, string $dateFrom, string $dateTo): void
    {
        if ($accountId > 0) {
            $model->where(UsageRecord::schema_fields_ACCOUNT_ID, $accountId);
        }
        if ($dateFrom !== '') {
            $model->where(UsageRecord::schema_fields_CREATED_AT, strtotime($dateFrom), '>=');
        }
        if ($dateTo !== '') {
            $model->where(UsageRecord::schema_fields_CREATED_AT, strtotime($dateTo . ' 23:59:59'), '<=');
        }
    }

    private function calculateUsageStats(int $accountId, string $dateFrom, string $dateTo): array
    {
        /** @var UsageRecord $usageModel */
        $usageModel = ObjectManager::getInstance(UsageRecord::class, [], false);
        $this->applyUsageFilters($usageModel, $accountId, $dateFrom, $dateTo);
        $stats = $usageModel->fields(
            'COUNT(*) AS total_requests,' .
            'SUM(' . UsageRecord::schema_fields_TOTAL_TOKENS . ') AS total_tokens,' .
            'SUM(' . UsageRecord::schema_fields_TOTAL_COST . ') AS total_cost,' .
            'AVG(' . UsageRecord::schema_fields_TOTAL_COST . ') AS avg_cost,' .
            'SUM(CASE WHEN ' . UsageRecord::schema_fields_STATUS . ' = "success" THEN 1 ELSE 0 END) AS success_count,' .
            'SUM(CASE WHEN ' . UsageRecord::schema_fields_STATUS . ' = "failed" THEN 1 ELSE 0 END) AS failed_count'
        )->find()->fetch();

        $data = is_object($stats) && method_exists($stats, 'getData') ? $stats->getData() : (is_array($stats) ? $stats : []);
        $totalRequests = (int)($data['total_requests'] ?? 0);
        $successCount = (int)($data['success_count'] ?? 0);

        return [
            'total_requests' => $totalRequests,
            'total_tokens' => (int)($data['total_tokens'] ?? 0),
            'total_cost' => number_format((float)($data['total_cost'] ?? 0), 6),
            'avg_cost' => number_format((float)($data['avg_cost'] ?? 0), 6),
            'success_rate' => $totalRequests > 0 ? round($successCount / $totalRequests * 100, 2) : 0,
        ];
    }

    private function connectionStatusText(string $status): string
    {
        return match ($status) {
            Account::STATUS_PENDING => 'Pending test',
            Account::STATUS_SUCCESS => 'Connected',
            Account::STATUS_FAILED => 'Connection failed',
            'testing' => 'Testing',
            default => 'Unknown',
        };
    }

    private function decodeJsonMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
