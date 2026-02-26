<?php
declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_Framework\Query;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CdnQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AdapterResolver $adapterResolver,
        private readonly AccountManager $accountManager,
        private readonly Account $accountModel,
        private readonly Domain $domainModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'cdn';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getAdapters'        => $this->getAdapters(),
            'getAccounts'        => $this->getAccounts($params),
            'getAccount'         => $this->getAccount($params),
            'saveAccount'        => $this->saveAccount($params),
            'deleteAccount'      => $this->deleteAccount($params),
            'setDefaultAccount'  => $this->setDefaultAccount($params),
            'getDefaultAccount'  => $this->getDefaultAccount($params),
            'getDomains'         => $this->getDomains($params),
            'ensureZone'         => $this->ensureZone($params),
            'testConnection'     => $this->testConnection($params),
            'getAdapterInfo'     => $this->getAdapterInfo($params),
            default => throw new \InvalidArgumentException(
                (string)__('CDN 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'cdn',
            'name'        => __('CDN 查询'),
            'description' => __('提供 CDN 适配器、账户管理、域名绑定等能力'),
            'module'      => 'Weline_Cdn',
            'operations'  => [
                [
                    'name'        => 'getAdapters',
                    'description' => __('获取所有可用的 CDN 适配器'),
                    'params'      => [],
                ],
                [
                    'name'        => 'getAccounts',
                    'description' => __('获取 CDN 账户列表'),
                    'params'      => [
                        ['name' => 'adapter', 'type' => 'string|null', 'required' => false, 'description' => __('按适配器过滤')],
                        ['name' => 'status', 'type' => 'string|null', 'required' => false, 'description' => __('按状态过滤')],
                    ],
                ],
                [
                    'name'        => 'getAccount',
                    'description' => __('获取单个 CDN 账户详情'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'saveAccount',
                    'description' => __('创建或更新 CDN 账户'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int|null', 'required' => false, 'description' => __('账户 ID（更新时必填）')],
                        ['name' => 'adapter', 'type' => 'string', 'required' => true, 'description' => __('适配器代码')],
                        ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => __('账户名称')],
                        ['name' => 'credentials', 'type' => 'array', 'required' => false, 'description' => __('凭证信息')],
                        ['name' => 'is_default', 'type' => 'bool', 'required' => false, 'description' => __('是否设为默认')],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => __('状态')],
                    ],
                ],
                [
                    'name'        => 'deleteAccount',
                    'description' => __('删除 CDN 账户'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'setDefaultAccount',
                    'description' => __('设置默认账户'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'getDefaultAccount',
                    'description' => __('获取指定适配器的默认账户'),
                    'params'      => [
                        ['name' => 'adapter', 'type' => 'string', 'required' => true, 'description' => __('适配器代码')],
                    ],
                ],
                [
                    'name'        => 'getDomains',
                    'description' => __('获取账户关联的域名列表'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'ensureZone',
                    'description' => __('确保域名在 CDN 中存在（创建或返回已有 Zone）'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'testConnection',
                    'description' => __('测试账户连接'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'getAdapterInfo',
                    'description' => __('获取适配器详细信息'),
                    'params'      => [
                        ['name' => 'adapter', 'type' => 'string', 'required' => true, 'description' => __('适配器代码')],
                    ],
                ],
            ],
        ];
    }

    private function getAdapters(): array
    {
        $adapters = [];
        foreach ($this->adapterResolver->getAllAdapters() as $adapter) {
            $adapters[] = [
                'code'        => $adapter->getAdapterCode(),
                'name'        => $adapter->getAdapterName(),
                'description' => $adapter->getDescription(),
            ];
        }
        return $adapters;
    }

    private function getAccounts(array $params): array
    {
        $model = clone $this->accountModel;
        $model->clearQuery();

        $adapter = $params['adapter'] ?? null;
        $status = $params['status'] ?? null;

        if ($adapter !== null && $adapter !== '') {
            $model->where(Account::fields_ADAPTER, (string)$adapter);
        }
        if ($status !== null && $status !== '') {
            $model->where(Account::fields_STATUS, (string)$status);
        }

        $records = $model->select()->fetchArray();
        $accounts = [];
        foreach ($records as $record) {
            $accounts[] = [
                'account_id'  => (int)($record[Account::fields_ACCOUNT_ID] ?? 0),
                'adapter'     => (string)($record[Account::fields_ADAPTER] ?? ''),
                'name'        => (string)($record[Account::fields_NAME] ?? ''),
                'description' => (string)($record[Account::fields_DESCRIPTION] ?? ''),
                'is_default'  => (bool)($record[Account::fields_IS_DEFAULT] ?? false),
                'status'      => (string)($record[Account::fields_STATUS] ?? ''),
            ];
        }
        return $accounts;
    }

    private function getAccount(array $params): ?array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return null;
        }

        $account = clone $this->accountModel;
        $account->clearQuery();
        $account->load($accountId);

        if (!$account->getId()) {
            return null;
        }

        return [
            'account_id'  => (int)$account->getData(Account::fields_ACCOUNT_ID),
            'adapter'     => (string)$account->getData(Account::fields_ADAPTER),
            'name'        => (string)$account->getData(Account::fields_NAME),
            'description' => (string)$account->getData(Account::fields_DESCRIPTION),
            'is_default'  => (bool)$account->getData(Account::fields_IS_DEFAULT),
            'status'      => (string)$account->getData(Account::fields_STATUS),
            'credentials' => $account->getCredentialsArray(),
        ];
    }

    private function saveAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $adapter = (string)($params['adapter'] ?? '');
        $name = (string)($params['name'] ?? '');

        if ($adapter === '' || $name === '') {
            return ['success' => false, 'message' => (string)__('适配器代码和账户名称不能为空')];
        }

        $adapterInstance = $this->adapterResolver->getAdapter($adapter);
        if ($adapterInstance === null) {
            return ['success' => false, 'message' => (string)__('未找到 CDN 适配器：%{1}', $adapter)];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();

            if ($accountId > 0) {
                $account->load($accountId);
                if (!$account->getId()) {
                    return ['success' => false, 'message' => (string)__('账户不存在：%{1}', (string)$accountId)];
                }
            }

            $account->setData(Account::fields_ADAPTER, $adapter);
            $account->setData(Account::fields_NAME, $name);

            if (isset($params['description'])) {
                $account->setData(Account::fields_DESCRIPTION, (string)$params['description']);
            }
            if (isset($params['credentials']) && is_array($params['credentials'])) {
                $account->setData(Account::fields_CREDENTIALS, json_encode($params['credentials'], JSON_UNESCAPED_UNICODE));
            }
            if (isset($params['status'])) {
                $account->setData(Account::fields_STATUS, (string)$params['status']);
            }

            $account->save();

            if (isset($params['is_default']) && $params['is_default']) {
                $this->accountManager->setDefaultAccount((int)$account->getId());
            }

            $action = $accountId > 0 ? __('更新') : __('创建');
            return [
                'success'    => true,
                'message'    => (string)__('账户%{1}成功', (string)$action),
                'account_id' => (int)$account->getId(),
            ];
        } catch (\Throwable $e) {
            Env::log_error('cdn_query', (string)__('保存账户失败：%{1}', $e->getMessage()));
            return ['success' => false, 'message' => (string)__('保存失败：%{1}', $e->getMessage())];
        }
    }

    private function deleteAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账户 ID 无效')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->load($accountId);

            if (!$account->getId()) {
                return ['success' => false, 'message' => (string)__('账户不存在')];
            }

            $account->delete();
            return ['success' => true, 'message' => (string)__('账户已删除')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('删除失败：%{1}', $e->getMessage())];
        }
    }

    private function setDefaultAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账户 ID 无效')];
        }

        try {
            $this->accountManager->setDefaultAccount($accountId);
            return ['success' => true, 'message' => (string)__('已设为默认账户')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getDefaultAccount(array $params): ?array
    {
        $adapter = (string)($params['adapter'] ?? '');
        if ($adapter === '') {
            return null;
        }

        $account = $this->accountManager->getDefaultAccount($adapter);
        if ($account === null) {
            return null;
        }

        return [
            'account_id'  => (int)$account->getData(Account::fields_ACCOUNT_ID),
            'adapter'     => (string)$account->getData(Account::fields_ADAPTER),
            'name'        => (string)$account->getData(Account::fields_NAME),
            'is_default'  => true,
            'status'      => (string)$account->getData(Account::fields_STATUS),
        ];
    }

    private function getDomains(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return [];
        }

        return $this->accountManager->getAccountDomains($accountId);
    }

    private function ensureZone(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        $accountId = (int)($params['account_id'] ?? 0);

        if ($domain === '' || $accountId <= 0) {
            return ['success' => false, 'message' => (string)__('域名和账户 ID 不能为空')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->load($accountId);

            if (!$account->getId()) {
                return ['success' => false, 'message' => (string)__('账户不存在')];
            }

            $adapterCode = (string)$account->getData(Account::fields_ADAPTER);
            $adapter = $this->adapterResolver->getAdapter($adapterCode);

            if ($adapter === null) {
                return ['success' => false, 'message' => (string)__('适配器不存在：%{1}', $adapterCode)];
            }

            $credentials = $account->getCredentialsArray();
            $zoneInfo = $adapter->ensureZone($domain, $credentials);

            return [
                'success' => true,
                'zone_id' => $zoneInfo['zone_id'] ?? '',
                'data'    => $zoneInfo,
            ];
        } catch (\Throwable $e) {
            Env::log_error('cdn_query', (string)__('ensureZone 失败：%{1}', $e->getMessage()));
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function testConnection(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账户 ID 无效')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->load($accountId);

            if (!$account->getId()) {
                return ['success' => false, 'message' => (string)__('账户不存在')];
            }

            $adapterCode = (string)$account->getData(Account::fields_ADAPTER);
            $adapter = $this->adapterResolver->getAdapter($adapterCode);

            if ($adapter === null) {
                return ['success' => false, 'message' => (string)__('适配器不存在')];
            }

            $credentials = $account->getCredentialsArray();

            if (empty($credentials)) {
                return ['success' => false, 'message' => (string)__('账户凭证为空')];
            }

            return ['success' => true, 'message' => (string)__('账户配置有效')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getAdapterInfo(array $params): ?array
    {
        $adapterCode = (string)($params['adapter'] ?? '');
        if ($adapterCode === '') {
            return null;
        }

        $adapter = $this->adapterResolver->getAdapter($adapterCode);
        if ($adapter === null) {
            return null;
        }

        return [
            'code'        => $adapter->getAdapterCode(),
            'name'        => $adapter->getAdapterName(),
            'description' => $adapter->getDescription(),
        ];
    }
}
