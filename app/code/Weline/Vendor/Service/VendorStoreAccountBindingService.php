<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorStoreAccountBindingRecord;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Store-scoped provider account binding.
 *
 * Account references are identifiers, never credentials. dev/test Stores are
 * sandbox-only even when a caller asks for a live binding.
 */
final class VendorStoreAccountBindingService
{
    public const STATUS_BOUND = 'bound';
    public const STATUS_REVOKED = 'revoked';
    public const ERROR_STORE_NOT_FOUND = 'vendor_account_store_not_found';
    public const ERROR_STORE_WEBSITE = 'vendor_account_store_website_mismatch';
    public const ERROR_STORE_INACTIVE = 'vendor_account_store_inactive';
    public const ERROR_LIVE_ON_TEST = 'vendor_live_account_forbidden_on_test_store';
    public const ERROR_ENV_MISMATCH = 'vendor_account_environment_mismatch';
    public const ERROR_REF = 'vendor_account_ref_invalid';
    public const ERROR_ALREADY = 'vendor_account_already_bound';
    public const ERROR_NOT_BOUND = 'vendor_account_not_bound';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;
    /** @var array<int, StoreSummary> */
    private array $testStores = [];
    /** @var (\Closure(): VendorStoreAccountBindingRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorStoreAccountBindingRecord)|null $recordFactory */
    public function __construct(
        private readonly VendorRegistryStore $registry,
        private readonly VendorAuthorizationService $authorization,
        private readonly ?StoreCatalogInterface $stores = null,
        ?callable $recordFactory = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(
        VendorRegistryStore $registry,
        VendorAuthorizationService $authorization,
    ): self {
        return new self($registry, $authorization, useMemory: true);
    }

    public function registerStoreForTesting(StoreSummary $store): void
    {
        if ($this->rows === null) {
            throw new \LogicException('registerStoreForTesting requires explicit memory mode');
        }
        $this->testStores[$store->id] = $store;
    }

    /**
     * @param array{vendor_id:string,website_id:int,store_id:int,environment:string,account_ref:string} $input
     * @return array<string, mixed>
     */
    public function bind(array $input): array
    {
        $vendorId = trim((string) ($input['vendor_id'] ?? ''));
        $websiteId = (int) ($input['website_id'] ?? -1);
        $storeId = (int) ($input['store_id'] ?? 0);
        VendorIdentity::assertWebsiteId($websiteId);
        $environment = VendorIdentity::assertEnvironment((string) ($input['environment'] ?? ''));
        $accountRef = trim((string) ($input['account_ref'] ?? ''));
        if ($accountRef === '' || strlen($accountRef) > 255
            || !str_starts_with($accountRef, $environment . ':')
        ) {
            throw new VendorConflictException(
                self::ERROR_REF,
                __('账户引用必须使用 %{1}: 前缀且不能包含凭证', [$environment]),
            );
        }

        $vendor = $this->registry->get($vendorId);
        if ((string) $vendor['status'] !== VendorIdentity::STATUS_ACTIVE) {
            throw new VendorConflictException(
                VendorEligibilityService::ERROR_DISABLED,
                __('Vendor 已禁用：%{1}', [$vendorId]),
            );
        }
        $this->authorization->assertAuthorized($vendorId, $websiteId);
        if ((string) $vendor['environment'] !== $environment) {
            throw new VendorConflictException(
                self::ERROR_ENV_MISMATCH,
                __('Vendor 与账户 environment 不匹配'),
            );
        }
        $store = $this->resolveStore($storeId);
        $this->assertStoreEligible($store, $websiteId);
        if (in_array($store->storeMode, ['dev', 'test'], true)
            && $environment !== VendorIdentity::ENV_SANDBOX
        ) {
            throw new VendorConflictException(
                self::ERROR_LIVE_ON_TEST,
                __('dev/test Store 只能绑定 sandbox 账户'),
                ['store_id' => $storeId, 'store_mode' => $store->storeMode],
            );
        }

        $existing = $this->find($vendorId, $websiteId, $storeId);
        $hash = hash('sha256', $accountRef);
        if ($existing !== null && (string) $existing['status'] === self::STATUS_BOUND) {
            if (hash_equals((string) $existing['account_ref_hash'], $hash)
                && (string) $existing['environment'] === $environment
            ) {
                return $existing;
            }
            throw new VendorConflictException(
                self::ERROR_ALREADY,
                __('Store 已绑定不同的 Vendor 账户'),
            );
        }
        $row = [
            'vendor_id' => $vendorId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'store_mode_snapshot' => $store->storeMode,
            'environment' => $environment,
            'account_ref' => $accountRef,
            'account_ref_hash' => $hash,
            'status' => self::STATUS_BOUND,
            'binding_version' => $existing !== null ? ((int) $existing['binding_version'] + 1) : 1,
            'bound_at' => date('Y-m-d H:i:s'),
            'revoked_at' => null,
        ];
        return $this->write($row, $existing !== null);
    }

    /** @return array<string, mixed> */
    public function assertBound(
        string $vendorId,
        int $websiteId,
        int $storeId,
        ?string $requiredEnvironment = null,
    ): array {
        VendorIdentity::assertWebsiteId($websiteId);
        $row = $this->find($vendorId, $websiteId, $storeId);
        if ($row === null || (string) $row['status'] !== self::STATUS_BOUND) {
            throw new VendorConflictException(
                self::ERROR_NOT_BOUND,
                __('Vendor 未绑定 Store 账户'),
                ['vendor_id' => $vendorId, 'website_id' => $websiteId, 'store_id' => $storeId],
            );
        }
        if ($requiredEnvironment !== null
            && (string) $row['environment'] !== VendorIdentity::assertEnvironment($requiredEnvironment)
        ) {
            throw new VendorConflictException(self::ERROR_ENV_MISMATCH, __('账户 environment 不匹配'));
        }
        $store = $this->resolveStore($storeId);
        $this->assertStoreEligible($store, $websiteId);
        if (in_array($store->storeMode, ['dev', 'test'], true)
            && (string) $row['environment'] !== VendorIdentity::ENV_SANDBOX
        ) {
            throw new VendorConflictException(self::ERROR_LIVE_ON_TEST, __('test Store 的 live 账户绑定无效'));
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public function revoke(string $vendorId, int $websiteId, int $storeId): array
    {
        $row = $this->assertBound($vendorId, $websiteId, $storeId);
        $row['status'] = self::STATUS_REVOKED;
        $row['binding_version'] = (int) $row['binding_version'] + 1;
        $row['revoked_at'] = date('Y-m-d H:i:s');
        return $this->write($row, true);
    }

    /** @return array<string, mixed>|null */
    private function find(string $vendorId, int $websiteId, int $storeId): ?array
    {
        if ($this->rows !== null) {
            return $this->rows[$this->key($vendorId, $websiteId, $storeId)] ?? null;
        }
        $model = $this->findModel($vendorId, $websiteId, $storeId);
        return $model?->getData();
    }

    private function findModel(
        string $vendorId,
        int $websiteId,
        int $storeId,
    ): ?VendorStoreAccountBindingRecord {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorStoreAccountBindingRecord::schema_fields_VENDOR_ID, trim($vendorId))
            ->where(VendorStoreAccountBindingRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->where(VendorStoreAccountBindingRecord::schema_fields_STORE_ID, $storeId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function write(array $row, bool $update): array
    {
        if ($this->rows !== null) {
            $this->rows[$this->key((string) $row['vendor_id'], (int) $row['website_id'], (int) $row['store_id'])] = $row;
            return $row;
        }
        $model = $update
            ? $this->findModel((string) $row['vendor_id'], (int) $row['website_id'], (int) $row['store_id'])
            : null;
        $model ??= $this->newRecord();
        if (!$update) {
            $model->clear();
        }
        $model->setData([
            VendorStoreAccountBindingRecord::schema_fields_VENDOR_ID => $row['vendor_id'],
            VendorStoreAccountBindingRecord::schema_fields_WEBSITE_ID => $row['website_id'],
            VendorStoreAccountBindingRecord::schema_fields_STORE_ID => $row['store_id'],
            VendorStoreAccountBindingRecord::schema_fields_STORE_MODE => $row['store_mode_snapshot'],
            VendorStoreAccountBindingRecord::schema_fields_ENVIRONMENT => $row['environment'],
            VendorStoreAccountBindingRecord::schema_fields_ACCOUNT_REF => $row['account_ref'],
            VendorStoreAccountBindingRecord::schema_fields_ACCOUNT_REF_HASH => $row['account_ref_hash'],
            VendorStoreAccountBindingRecord::schema_fields_STATUS => $row['status'],
            VendorStoreAccountBindingRecord::schema_fields_BINDING_VERSION => $row['binding_version'],
            VendorStoreAccountBindingRecord::schema_fields_BOUND_AT => $row['bound_at'],
            VendorStoreAccountBindingRecord::schema_fields_REVOKED_AT => $row['revoked_at'],
        ])->save();
        $saved = $this->find((string) $row['vendor_id'], (int) $row['website_id'], (int) $row['store_id']);
        if ($saved === null) {
            throw new \RuntimeException(__('Vendor Store 账户绑定写入后无法回读'));
        }
        return $saved;
    }

    private function resolveStore(int $storeId): StoreSummary
    {
        $store = $this->rows !== null
            ? ($this->testStores[$storeId] ?? null)
            : $this->storeCatalog()->byId($storeId);
        if (!$store instanceof StoreSummary) {
            throw new VendorConflictException(
                self::ERROR_STORE_NOT_FOUND,
                __('Store 不存在：%{1}', [$storeId]),
            );
        }
        return $store;
    }

    private function assertStoreEligible(StoreSummary $store, int $websiteId): void
    {
        if ($store->websiteId !== $websiteId) {
            throw new VendorConflictException(self::ERROR_STORE_WEBSITE, __('Store 不属于目标 Website'));
        }
        if (!$store->enabled || $store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
            throw new VendorConflictException(self::ERROR_STORE_INACTIVE, __('Store 已停用或不在 active 生命周期'));
        }
        if (!in_array($store->storeMode, ['normal', 'dev', 'test'], true)) {
            throw new VendorConflictException(self::ERROR_STORE_INACTIVE, __('Store mode 无效'));
        }
    }

    private function storeCatalog(): StoreCatalogInterface
    {
        $catalog = $this->stores ?? ObjectManager::getInstance(StoreCatalogInterface::class);
        if (!$catalog instanceof StoreCatalogInterface) {
            throw new \LogicException('StoreCatalogInterface binding is unavailable');
        }
        return $catalog;
    }

    private function key(string $vendorId, int $websiteId, int $storeId): string
    {
        return trim($vendorId) . ':' . $websiteId . ':' . $storeId;
    }

    private function newRecord(): VendorStoreAccountBindingRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorStoreAccountBindingRecord::class, [], false);
    }
}
