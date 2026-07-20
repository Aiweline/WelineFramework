<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialPlatformAccount;
use Weline\Social\Model\SocialWebsiteAccount;

class SocialWebsiteAccountService
{
    public function __construct(
        private readonly SocialAccountService $accountService,
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWebsites(): array
    {
        if (!\function_exists('w_query')) {
            return [];
        }

        try {
            $rows = w_query('websites', 'getWebsiteList', [], 'backend');
        } catch (\Throwable) {
            return [];
        }

        $websites = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            // website_id=0 is the system default site and must remain selectable.
            if (!\array_key_exists('website_id', $row) && !\array_key_exists('id', $row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? $row['id'] ?? -1);
            if ($websiteId < 0) {
                continue;
            }
            $websites[] = [
                'website_id' => $websiteId,
                'scope_type' => SocialWebsiteAccount::SCOPE_TYPE_WEBSITE,
                'scope_id' => $websiteId,
                'name' => (string)($row['name'] ?? ''),
                'code' => (string)($row['code'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
            ];
        }

        return $websites;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listScopes(): array
    {
        $items = [];
        foreach ($this->listWebsites() as $website) {
            $scope = $this->normalizeScopeParams([
                'scope_type' => SocialWebsiteAccount::SCOPE_TYPE_WEBSITE,
                'scope_id' => (int)($website['website_id'] ?? 0),
            ], false);
            // Keep website_id=0 (system default site); only reject missing/negative IDs.
            if (!\array_key_exists('scope_id', $scope) || (int)$scope['scope_id'] < 0) {
                continue;
            }
            $child = [
                'scope_type' => $scope['scope_type'],
                'scope_id' => $scope['scope_id'],
                'child_scope_type' => SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT,
                'child_scope_id' => 0,
                'child_scope_label' => (string)__('站点默认'),
                'child_scope_code' => 'default',
                'scope_key' => $this->scopeKey([
                    'scope_type' => $scope['scope_type'],
                    'scope_id' => $scope['scope_id'],
                    'child_scope_type' => SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT,
                    'child_scope_id' => 0,
                ]),
            ];
            $items[] = [
                'scope_type' => $scope['scope_type'],
                'scope_id' => $scope['scope_id'],
                'scope_code' => (string)($website['code'] ?? ''),
                'scope_label' => $scope['scope_label'],
                'scope_title' => (string)($website['name'] ?? ''),
                'website_id' => (int)($website['website_id'] ?? 0),
                'url' => (string)($website['url'] ?? ''),
                'children' => [$child],
            ];
        }

        return [[
            'scope_type' => SocialWebsiteAccount::SCOPE_TYPE_WEBSITE,
            'scope_label' => (string)__('站点'),
            'scope_level_label' => (string)__('一级范围'),
            'child_level_label' => (string)__('二级范围'),
            'items' => $items,
        ]];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function listRelations(array $params = []): array
    {
        return $this->listScopeRelations($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function listScopeRelations(array $params = []): array
    {
        $this->hydrateLegacyWebsiteScopeRows();

        $includeDisabled = $this->toBool($params['include_disabled'] ?? false);
        $scope = $this->normalizeScopeParams($params, false);
        $query = $this->newRelation()->reset();
        if ((int)$scope['scope_id'] > 0) {
            $this->applyScopeWhere($query, $scope);
        } elseif ((int)($params['website_id'] ?? 0) > 0) {
            $query->where(SocialWebsiteAccount::schema_fields_WEBSITE_ID, (int)$params['website_id']);
        }
        if (!$includeDisabled) {
            $query->where(SocialWebsiteAccount::schema_fields_STATUS, SocialWebsiteAccount::STATUS_ACTIVE);
        }

        $rows = $query
            ->order(SocialWebsiteAccount::schema_fields_SCOPE_TYPE, 'ASC')
            ->order(SocialWebsiteAccount::schema_fields_SCOPE_ID, 'ASC')
            ->order(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_TYPE, 'ASC')
            ->order(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_ID, 'ASC')
            ->order(SocialWebsiteAccount::schema_fields_SORT_ORDER, 'ASC')
            ->order(SocialWebsiteAccount::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $relations = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $relations[] = $this->enrichRelation($row);
        }

        return $relations;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function saveWebsiteAccountDefaults(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? 0);
        $params['scope_type'] = SocialWebsiteAccount::SCOPE_TYPE_WEBSITE;
        $params['scope_id'] = $websiteId;
        $params['child_scope_type'] = SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT;
        $params['child_scope_id'] = 0;

        $result = $this->saveScopeAccountDefaults($params);
        $result['website'] = $result['scope']['website'] ?? null;
        $result['message'] = (string)__('站点默认社媒账户已保存。');

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function saveScopeAccountDefaults(array $params): array
    {
        $scope = $this->normalizeScopeParams($params, true);
        $accountIds = $this->normalizeAccountIds($params['account_ids'] ?? []);
        $sortOrders = \is_array($params['sort_orders'] ?? null) ? $params['sort_orders'] : [];
        $now = \date('Y-m-d H:i:s');

        $existingRows = $this->newRelation()->reset();
        $this->applyScopeWhere($existingRows, $scope);
        $existingRows = $existingRows->select()->fetchArray();
        $existingByAccount = [];
        foreach (\is_array($existingRows) ? $existingRows : [] as $row) {
            if (\is_array($row)) {
                $existingByAccount[(int)($row[SocialWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0)] = $row;
            }
        }

        $selected = \array_flip($accountIds);
        foreach ($existingByAccount as $accountId => $row) {
            if (isset($selected[$accountId])) {
                continue;
            }
            $relation = $this->loadRelation((int)($row[SocialWebsiteAccount::schema_fields_ID] ?? 0));
            if (!$relation instanceof SocialWebsiteAccount) {
                continue;
            }
            $relation->setData(SocialWebsiteAccount::schema_fields_STATUS, SocialWebsiteAccount::STATUS_DISABLED)
                ->setData(SocialWebsiteAccount::schema_fields_IS_DEFAULT, 0)
                ->setData(SocialWebsiteAccount::schema_fields_UPDATED_AT, $now)
                ->save();
        }

        $saved = 0;
        foreach ($accountIds as $offset => $accountId) {
            $account = $this->accountService->getAccount($accountId);
            if (!$account instanceof SocialPlatformAccount) {
                continue;
            }
            $relation = $this->findRelation($scope, $accountId) ?? $this->newRelation();
            if (!$relation->getId()) {
                $relation->setData(SocialWebsiteAccount::schema_fields_CREATED_AT, $now);
            }
            $sortOrder = (int)($sortOrders[(string)$accountId] ?? $sortOrders[$accountId] ?? (($offset + 1) * 10));
            $relation->setData(SocialWebsiteAccount::schema_fields_WEBSITE_ID, (int)$scope['website_id'])
                ->setData(SocialWebsiteAccount::schema_fields_SCOPE_TYPE, (string)$scope['scope_type'])
                ->setData(SocialWebsiteAccount::schema_fields_SCOPE_ID, (int)$scope['scope_id'])
                ->setData(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_TYPE, (string)$scope['child_scope_type'])
                ->setData(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_ID, (int)$scope['child_scope_id'])
                ->setData(SocialWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)
                ->setData(SocialWebsiteAccount::schema_fields_PLATFORM_CODE, (string)$account->getData(SocialPlatformAccount::schema_fields_PLATFORM_CODE))
                ->setData(SocialWebsiteAccount::schema_fields_IS_DEFAULT, 1)
                ->setData(SocialWebsiteAccount::schema_fields_SORT_ORDER, $sortOrder)
                ->setData(SocialWebsiteAccount::schema_fields_STATUS, SocialWebsiteAccount::STATUS_ACTIVE)
                ->setData(SocialWebsiteAccount::schema_fields_UPDATED_AT, $now)
                ->save();
            $saved++;
        }

        return [
            'success' => true,
            'message' => (string)__('范围默认社媒账户已保存。'),
            'scope' => $scope,
            'saved_count' => $saved,
            'relations' => $this->listScopeRelations($scope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebsiteDefaultAccounts(int $websiteId, bool $publishOnly = false): array
    {
        $result = $this->getScopeDefaultAccounts([
            'scope_type' => SocialWebsiteAccount::SCOPE_TYPE_WEBSITE,
            'scope_id' => $websiteId,
            'child_scope_type' => SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT,
            'child_scope_id' => 0,
        ], $publishOnly);
        $result['website'] = $result['scope']['website'] ?? null;

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getScopeDefaultAccounts(array $params, bool $publishOnly = false): array
    {
        try {
            $scope = $this->normalizeScopeParams($params, true);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'scope' => null,
                'accounts' => [],
                'skipped' => [],
            ];
        }

        $this->hydrateLegacyWebsiteScopeRows();

        $rows = $this->newRelation()->reset();
        $this->applyScopeWhere($rows, $scope);
        $rows = $rows
            ->where(SocialWebsiteAccount::schema_fields_STATUS, SocialWebsiteAccount::STATUS_ACTIVE)
            ->where(SocialWebsiteAccount::schema_fields_IS_DEFAULT, 1)
            ->order(SocialWebsiteAccount::schema_fields_SORT_ORDER, 'ASC')
            ->order(SocialWebsiteAccount::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $accounts = [];
        $skipped = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $accountId = (int)($row[SocialWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
            $account = $this->accountService->getAccountSafeArray($accountId);
            if ($account === []) {
                $skipped[] = ['account_id' => $accountId, 'reason' => (string)__('账户不存在。')];
                continue;
            }
            $account['relation'] = $this->enrichRelation($row);
            if ($publishOnly && !$this->isAccountPublishable($account)) {
                $skipped[] = [
                    'account_id' => $accountId,
                    'account_name' => (string)($account['account_name'] ?? ''),
                    'platform_code' => (string)($account['platform_code'] ?? ''),
                    'scope' => $scope,
                    'reason' => (string)__('账户未启用发布或凭据未通过检测。'),
                ];
                continue;
            }
            $accounts[] = $account;
        }

        return [
            'success' => true,
            'scope' => $scope,
            'website' => $scope['website'] ?? null,
            'accounts' => $accounts,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resolvePublishAccounts(array $params): array
    {
        $allSites = $this->toBool($params['all_sites'] ?? false);
        $websiteIds = $this->normalizeWebsiteIds($params);
        $websites = $this->listWebsites();
        $scopes = [];

        if ($allSites) {
            $websiteIds = \array_values(\array_map(
                static fn(array $website): int => (int)($website['website_id'] ?? 0),
                $websites
            ));
        }
        $websiteIds = \array_values(\array_filter(\array_unique(\array_map('intval', $websiteIds))));

        if ($websiteIds !== []) {
            foreach ($websiteIds as $websiteId) {
                $scopes[] = $this->normalizeScopeParams([
                    'scope_type' => SocialWebsiteAccount::SCOPE_TYPE_WEBSITE,
                    'scope_id' => $websiteId,
                    'child_scope_type' => SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT,
                    'child_scope_id' => 0,
                ], true);
            }
        } elseif (!$allSites && $this->hasScopeParams($params)) {
            $scopes[] = $this->normalizeScopeParams($params, true);
        }

        $accounts = [];
        $skipped = [];
        $accountIds = [];
        $selectedWebsites = [];
        foreach ($scopes as $scope) {
            $defaults = $this->getScopeDefaultAccounts($scope, true);
            if (!empty($defaults['website']) && \is_array($defaults['website'])) {
                $selectedWebsites[(int)($defaults['website']['website_id'] ?? 0)] = $defaults['website'];
            }
            foreach ((array)($defaults['accounts'] ?? []) as $account) {
                if (!\is_array($account)) {
                    continue;
                }
                $accountId = (int)($account['account_id'] ?? 0);
                if ($accountId <= 0 || isset($accounts[$accountId])) {
                    continue;
                }
                $accounts[$accountId] = $account;
                $accountIds[] = $accountId;
            }
            foreach ((array)($defaults['skipped'] ?? []) as $skip) {
                if (\is_array($skip)) {
                    $skip['scope'] = $scope;
                    $skip['website_id'] = (int)($scope['website_id'] ?? 0);
                    $skipped[] = $skip;
                }
            }
        }

        return [
            'success' => true,
            'all_sites' => $allSites,
            'scope' => $scopes[0] ?? null,
            'scopes' => $scopes,
            'website_ids' => $websiteIds,
            'websites' => \array_values($selectedWebsites),
            'account_ids' => $accountIds,
            'accounts' => \array_values($accounts),
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function normalizeScopeParams(array $params, bool $requireScope = true): array
    {
        if (\is_array($params['scope'] ?? null)) {
            $params = \array_merge($params, $params['scope']);
        }

        $scopeType = \strtolower(\trim((string)($params['scope_type'] ?? '')));
        $hasExplicitScopeId = \array_key_exists('scope_id', $params) || \array_key_exists('website_id', $params);
        $scopeId = (int)($params['scope_id'] ?? $params['website_id'] ?? 0);
        if ($scopeType === '' && \array_key_exists('website_id', $params)) {
            $scopeType = SocialWebsiteAccount::SCOPE_TYPE_WEBSITE;
        }
        if ($scopeType === '') {
            $scopeType = SocialWebsiteAccount::SCOPE_TYPE_WEBSITE;
        }
        if ($scopeType !== SocialWebsiteAccount::SCOPE_TYPE_WEBSITE) {
            throw new \InvalidArgumentException((string)__('暂不支持的社媒配置范围：%{1}', [$scopeType]));
        }

        if ($requireScope && !$hasExplicitScopeId) {
            throw new \InvalidArgumentException((string)__('请选择一级范围。'));
        }
        if ($scopeId < 0) {
            throw new \InvalidArgumentException((string)__('请选择一级范围。'));
        }

        $childScopeType = \strtolower(\trim((string)($params['child_scope_type'] ?? '')));
        if ($childScopeType === '' || $childScopeType === 'default') {
            $childScopeType = SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT;
        }
        $childScopeId = (int)($params['child_scope_id'] ?? 0);

        $website = null;
        if ($hasExplicitScopeId || $requireScope) {
            $website = $this->getWebsite($scopeId);
            if ($requireScope && $website === null) {
                throw new \InvalidArgumentException((string)__('站点不存在。'));
            }
        }

        $scopeLabel = $website !== null
            ? \trim((string)($website['name'] ?? '') . ' · ' . (string)($website['code'] ?? ''), " \t\n\r\0\x0B·")
            : '';
        if ($scopeLabel === '' && $hasExplicitScopeId && $scopeId >= 0) {
            $scopeLabel = (string)__('站点 #%{1}', [(string)$scopeId]);
        }
        $childLabel = $childScopeType === SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT
            ? (string)__('站点默认')
            : (string)__('%{1} #%{2}', [$childScopeType, (string)$childScopeId]);

        $scope = [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'child_scope_type' => $childScopeType,
            'child_scope_id' => $childScopeId,
            'website_id' => $scopeType === SocialWebsiteAccount::SCOPE_TYPE_WEBSITE ? $scopeId : 0,
            'scope_label' => $scopeLabel,
            'child_scope_label' => $childLabel,
            'website' => $website,
        ];
        $scope['scope_key'] = $this->scopeKey($scope);
        $scope['display_label'] = \trim($scopeLabel . ' / ' . $childLabel, " \t\n\r\0\x0B/");

        return $scope;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getWebsite(int $websiteId): ?array
    {
        if ($websiteId < 0 || !\function_exists('w_query')) {
            return null;
        }
        try {
            $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId], 'backend');
        } catch (\Throwable) {
            $website = null;
        }
        if (!\is_array($website) || !\array_key_exists('website_id', $website)) {
            return null;
        }
        $resolvedId = (int)$website['website_id'];
        if ($resolvedId < 0) {
            return null;
        }

        return [
            'website_id' => $resolvedId,
            'scope_type' => SocialWebsiteAccount::SCOPE_TYPE_WEBSITE,
            'scope_id' => $resolvedId,
            'name' => (string)($website['name'] ?? ''),
            'code' => (string)($website['code'] ?? ''),
            'url' => (string)($website['url'] ?? ''),
        ];
    }

    private function findRelation(array $scope, int $accountId): ?SocialWebsiteAccount
    {
        $relation = $this->newRelation();
        $relation->reset();
        $this->applyScopeWhere($relation, $scope);
        $relation->where(SocialWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)
            ->find()
            ->fetch();

        return $relation->getId() ? $relation : null;
    }

    private function loadRelation(int $relationId): ?SocialWebsiteAccount
    {
        if ($relationId <= 0) {
            return null;
        }
        $relation = $this->newRelation();
        $relation->load($relationId);

        return $relation->getId() ? $relation : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichRelation(array $row): array
    {
        $scope = [
            'scope_type' => (string)($row['scope_type'] ?? $row[SocialWebsiteAccount::schema_fields_SCOPE_TYPE] ?? SocialWebsiteAccount::SCOPE_TYPE_WEBSITE),
            'scope_id' => (int)($row['scope_id'] ?? $row[SocialWebsiteAccount::schema_fields_SCOPE_ID] ?? $row['website_id'] ?? $row[SocialWebsiteAccount::schema_fields_WEBSITE_ID] ?? 0),
            'child_scope_type' => (string)($row['child_scope_type'] ?? $row[SocialWebsiteAccount::schema_fields_CHILD_SCOPE_TYPE] ?? SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT),
            'child_scope_id' => (int)($row['child_scope_id'] ?? $row[SocialWebsiteAccount::schema_fields_CHILD_SCOPE_ID] ?? 0),
        ];
        $scope['scope_key'] = $this->scopeKey($scope);

        return [
            'relation_id' => (int)($row['relation_id'] ?? $row[SocialWebsiteAccount::schema_fields_ID] ?? 0),
            'website_id' => (int)($row['website_id'] ?? $row[SocialWebsiteAccount::schema_fields_WEBSITE_ID] ?? $scope['scope_id']),
            'scope_type' => $scope['scope_type'],
            'scope_id' => $scope['scope_id'],
            'child_scope_type' => $scope['child_scope_type'],
            'child_scope_id' => $scope['child_scope_id'],
            'scope_key' => $scope['scope_key'],
            'account_id' => (int)($row['account_id'] ?? $row[SocialWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0),
            'platform_code' => (string)($row['platform_code'] ?? $row[SocialWebsiteAccount::schema_fields_PLATFORM_CODE] ?? ''),
            'is_default' => (int)($row['is_default'] ?? $row[SocialWebsiteAccount::schema_fields_IS_DEFAULT] ?? 0),
            'sort_order' => (int)($row['sort_order'] ?? $row[SocialWebsiteAccount::schema_fields_SORT_ORDER] ?? 1000),
            'status' => (string)($row['status'] ?? $row[SocialWebsiteAccount::schema_fields_STATUS] ?? ''),
        ];
    }

    private function applyScopeWhere(SocialWebsiteAccount $query, array $scope): void
    {
        $query->where(SocialWebsiteAccount::schema_fields_SCOPE_TYPE, (string)$scope['scope_type'])
            ->where(SocialWebsiteAccount::schema_fields_SCOPE_ID, (int)$scope['scope_id'])
            ->where(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_TYPE, (string)$scope['child_scope_type'])
            ->where(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_ID, (int)$scope['child_scope_id']);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopeKey(array $scope): string
    {
        return \implode(':', [
            (string)($scope['scope_type'] ?? SocialWebsiteAccount::SCOPE_TYPE_WEBSITE),
            (string)(int)($scope['scope_id'] ?? 0),
            (string)($scope['child_scope_type'] ?? SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT),
            (string)(int)($scope['child_scope_id'] ?? 0),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function hasScopeParams(array $params): bool
    {
        return isset($params['scope'])
            || isset($params['scope_type'])
            || isset($params['scope_id'])
            || isset($params['child_scope_type'])
            || isset($params['child_scope_id'])
            || isset($params['website_id']);
    }

    private function hydrateLegacyWebsiteScopeRows(): void
    {
        try {
            $rows = $this->newRelation()->reset()
                ->where(SocialWebsiteAccount::schema_fields_SCOPE_ID, 0)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return;
        }

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $websiteId = (int)($row[SocialWebsiteAccount::schema_fields_WEBSITE_ID] ?? $row['website_id'] ?? 0);
            $relationId = (int)($row[SocialWebsiteAccount::schema_fields_ID] ?? $row['relation_id'] ?? 0);
            if ($websiteId <= 0 || $relationId <= 0) {
                continue;
            }
            $relation = $this->loadRelation($relationId);
            if (!$relation instanceof SocialWebsiteAccount) {
                continue;
            }
            $relation->setData(SocialWebsiteAccount::schema_fields_SCOPE_TYPE, SocialWebsiteAccount::SCOPE_TYPE_WEBSITE)
                ->setData(SocialWebsiteAccount::schema_fields_SCOPE_ID, $websiteId)
                ->setData(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_TYPE, SocialWebsiteAccount::CHILD_SCOPE_TYPE_WEBSITE_DEFAULT)
                ->setData(SocialWebsiteAccount::schema_fields_CHILD_SCOPE_ID, 0)
                ->save();
        }
    }

    private function isAccountPublishable(array $account): bool
    {
        return (string)($account['status'] ?? '') === SocialPlatformAccount::STATUS_ACTIVE
            && (int)($account['publish_enabled'] ?? 0) === 1
            && (string)($account['test_status'] ?? '') === SocialPlatformAccount::TEST_STATUS_PASSED;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeAccountIds(mixed $value): array
    {
        return \array_values(\array_filter(\array_unique(\array_map('intval', (array)$value))));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, int>
     */
    private function normalizeWebsiteIds(array $params): array
    {
        $ids = [];
        if (isset($params['website_ids'])) {
            $ids = \array_merge($ids, (array)$params['website_ids']);
        }
        if (isset($params['website_id'])) {
            $ids[] = $params['website_id'];
        }

        return \array_values(\array_filter(\array_unique(\array_map('intval', $ids))));
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        $normalized = \strtolower(\trim((string)$value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function newRelation(): SocialWebsiteAccount
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialWebsiteAccount::class);
    }
}
