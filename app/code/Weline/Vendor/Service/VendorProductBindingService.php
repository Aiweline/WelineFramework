<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Api\ProductIdentityResolverInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorProductBindingRecord;

/** Durable Vendor↔Store↔Product binding using Product's public identity contract. */
final class VendorProductBindingService
{
    public const ERROR_ALREADY_BOUND = 'vendor_product_already_bound';
    public const ERROR_NOT_BOUND = 'vendor_product_not_bound';
    public const ERROR_SKU = 'vendor_product_sku_required';
    public const ERROR_PRODUCT_NOT_FOUND = 'vendor_product_identity_not_found';
    public const ERROR_STORE = 'vendor_product_store_required';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $bindings = null;
    /** @var array<string, ProductIdentity> */
    private array $testProducts = [];
    /** @var (\Closure(): VendorProductBindingRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorProductBindingRecord)|null $recordFactory */
    public function __construct(
        private readonly VendorEligibilityService $eligibility,
        private readonly ?ProductIdentityResolverInterface $products = null,
        ?callable $recordFactory = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->bindings = [];
        }
    }

    public static function forTesting(?VendorEligibilityService $eligibility = null): self
    {
        return new self(
            $eligibility ?? VendorEligibilityService::forTesting(),
            useMemory: true,
        );
    }

    public function registerProductForTesting(ProductIdentity $identity): void
    {
        if ($this->bindings === null) {
            throw new \LogicException('registerProductForTesting requires explicit memory mode');
        }
        $this->testProducts[$identity->sku] = $identity;
    }

    /**
     * @param array{vendor_id:string,website_id:int,store_id:int,product_sku:string,required_environment?:string} $input
     * @return array<string, mixed>
     */
    public function bind(array $input): array
    {
        $vendorId = trim((string) ($input['vendor_id'] ?? ''));
        $websiteId = (int) ($input['website_id'] ?? -1);
        $storeId = (int) ($input['store_id'] ?? 0);
        VendorIdentity::assertWebsiteId($websiteId);
        if ($storeId <= 0) {
            throw new VendorConflictException(self::ERROR_STORE, __('Product 绑定必须指定 Store'));
        }
        $sku = trim((string) ($input['product_sku'] ?? ''));
        if ($sku === '') {
            throw new VendorConflictException(self::ERROR_SKU, __('Product SKU 必填'));
        }
        $identity = $this->resolveProduct($sku);
        if ($identity === null) {
            throw new VendorConflictException(
                self::ERROR_PRODUCT_NOT_FOUND,
                __('Product identity 不存在：%{1}', [$sku]),
            );
        }

        $request = [
            'vendor_id' => $vendorId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
        ];
        if (array_key_exists('required_environment', $input) && $input['required_environment'] !== null) {
            $request['required_environment'] = (string) $input['required_environment'];
        }
        $eligible = $this->eligibility->assertEligible($request);
        $existing = $this->find($vendorId, $websiteId, $storeId, $identity->registryId);
        if ($existing !== null && (string) $existing['status'] === 'bound') {
            throw new VendorConflictException(
                self::ERROR_ALREADY_BOUND,
                __('Product 已绑定 Vendor：%{1}', [$identity->sku]),
            );
        }
        $row = [
            'vendor_id' => $vendorId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'product_registry_id' => $identity->registryId,
            'product_sku' => $identity->sku,
            'global_product_uuid' => $identity->globalProductUuid,
            'environment' => $eligible['vendor']['environment'],
            'status' => 'bound',
            'binding_version' => $existing !== null ? ((int) $existing['binding_version'] + 1) : 1,
            'bound_at' => date('Y-m-d H:i:s'),
            'unbound_at' => null,
        ];
        return $this->write($row, $existing !== null);
    }

    /** @return array<string, mixed> */
    public function unbind(string $vendorId, int $websiteId, string $sku, ?int $storeId = null): array
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $row = $this->findBySku($vendorId, $websiteId, $sku, $storeId);
        if ($row === null || (string) $row['status'] !== 'bound') {
            throw new VendorConflictException(self::ERROR_NOT_BOUND, __('Product 未绑定 Vendor：%{1}', [$sku]));
        }
        $row['status'] = 'unbound';
        $row['binding_version'] = (int) $row['binding_version'] + 1;
        $row['unbound_at'] = date('Y-m-d H:i:s');
        return $this->write($row, true);
    }

    public function isBound(string $vendorId, int $websiteId, string $sku, ?int $storeId = null): bool
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $row = $this->findBySku($vendorId, $websiteId, $sku, $storeId);
        return $row !== null && (string) $row['status'] === 'bound';
    }

    public function bindingCount(): int
    {
        if ($this->bindings !== null) {
            return count(array_filter(
                $this->bindings,
                static fn (array $row): bool => (string) $row['status'] === 'bound',
            ));
        }
        return count(
            $this->newRecord()->clear()
                ->where(VendorProductBindingRecord::schema_fields_STATUS, 'bound')
                ->select()
                ->fetchArray(),
        );
    }

    /** @return array<string, mixed>|null */
    private function find(
        string $vendorId,
        int $websiteId,
        int $storeId,
        int $registryId,
    ): ?array {
        if ($this->bindings !== null) {
            return $this->bindings[$this->key($vendorId, $websiteId, $storeId, $registryId)] ?? null;
        }
        $model = $this->findModel($vendorId, $websiteId, $storeId, $registryId);
        return $model?->getData();
    }

    private function findModel(
        string $vendorId,
        int $websiteId,
        int $storeId,
        int $registryId,
    ): ?VendorProductBindingRecord {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorProductBindingRecord::schema_fields_VENDOR_ID, trim($vendorId))
            ->where(VendorProductBindingRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->where(VendorProductBindingRecord::schema_fields_STORE_ID, $storeId)
            ->where(VendorProductBindingRecord::schema_fields_PRODUCT_REGISTRY_ID, $registryId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @return array<string, mixed>|null */
    private function findBySku(
        string $vendorId,
        int $websiteId,
        string $sku,
        ?int $storeId,
    ): ?array {
        if ($this->bindings !== null) {
            foreach ($this->bindings as $row) {
                if ((string) $row['vendor_id'] === trim($vendorId)
                    && (int) $row['website_id'] === $websiteId
                    && (string) $row['product_sku'] === trim($sku)
                    && ($storeId === null || (int) $row['store_id'] === $storeId)
                ) {
                    return $row;
                }
            }
            return null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorProductBindingRecord::schema_fields_VENDOR_ID, trim($vendorId))
            ->where(VendorProductBindingRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->where(VendorProductBindingRecord::schema_fields_PRODUCT_SKU, trim($sku));
        if ($storeId !== null) {
            $model->where(VendorProductBindingRecord::schema_fields_STORE_ID, $storeId);
        }
        $model->find()->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function write(array $row, bool $update): array
    {
        if ($this->bindings !== null) {
            $this->bindings[$this->key(
                (string) $row['vendor_id'],
                (int) $row['website_id'],
                (int) $row['store_id'],
                (int) $row['product_registry_id'],
            )] = $row;
            return $row;
        }
        $model = $update
            ? $this->findModel(
                (string) $row['vendor_id'],
                (int) $row['website_id'],
                (int) $row['store_id'],
                (int) $row['product_registry_id'],
            )
            : null;
        $model ??= $this->newRecord();
        if (!$update) {
            $model->clear();
        }
        $model->setData([
            VendorProductBindingRecord::schema_fields_VENDOR_ID => $row['vendor_id'],
            VendorProductBindingRecord::schema_fields_WEBSITE_ID => $row['website_id'],
            VendorProductBindingRecord::schema_fields_STORE_ID => $row['store_id'],
            VendorProductBindingRecord::schema_fields_PRODUCT_REGISTRY_ID => $row['product_registry_id'],
            VendorProductBindingRecord::schema_fields_PRODUCT_SKU => $row['product_sku'],
            VendorProductBindingRecord::schema_fields_PRODUCT_UUID => $row['global_product_uuid'],
            VendorProductBindingRecord::schema_fields_ENVIRONMENT => $row['environment'],
            VendorProductBindingRecord::schema_fields_STATUS => $row['status'],
            VendorProductBindingRecord::schema_fields_BINDING_VERSION => $row['binding_version'],
            VendorProductBindingRecord::schema_fields_BOUND_AT => $row['bound_at'],
            VendorProductBindingRecord::schema_fields_UNBOUND_AT => $row['unbound_at'],
        ])->save();
        $saved = $this->find(
            (string) $row['vendor_id'],
            (int) $row['website_id'],
            (int) $row['store_id'],
            (int) $row['product_registry_id'],
        );
        if ($saved === null) {
            throw new \RuntimeException(__('Vendor Product 绑定写入后无法回读'));
        }
        return $saved;
    }

    private function resolveProduct(string $sku): ?ProductIdentity
    {
        if ($this->bindings !== null) {
            return $this->testProducts[$sku] ?? null;
        }
        $resolver = $this->products ?? ObjectManager::getInstance(ProductIdentityResolverInterface::class);
        if (!$resolver instanceof ProductIdentityResolverInterface) {
            throw new \LogicException('ProductIdentityResolverInterface binding is unavailable');
        }
        return $resolver->resolveBySku($sku);
    }

    private function key(string $vendorId, int $websiteId, int $storeId, int $registryId): string
    {
        return trim($vendorId) . ':' . $websiteId . ':' . $storeId . ':' . $registryId;
    }

    private function newRecord(): VendorProductBindingRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorProductBindingRecord::class, [], false);
    }
}
