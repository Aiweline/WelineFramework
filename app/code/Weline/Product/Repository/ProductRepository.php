<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\NoopProductSearchProjectionMutationCoordinator;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Website product shard rows + publish optimistic lock (publish_version CAS).
 */
final class ProductRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): Product)|null */
    private readonly mixed $modelFactory;

    /** @var (\Closure(): string)|null */
    private readonly mixed $casTokenFactory;

    private readonly ProductSearchProjectionMutationCoordinatorInterface $projectionMutations;

    /**
     * @param (\Closure(int): Product)|null $modelFactory
     * @param (\Closure(): string)|null $casTokenFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
        ?callable $casTokenFactory = null,
        ?ProductSearchProjectionMutationCoordinatorInterface $projectionMutations = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
        $this->casTokenFactory = $casTokenFactory;
        $this->projectionMutations = $projectionMutations
            ?? ($modelFactory !== null
                ? new NoopProductSearchProjectionMutationCoordinator()
                : ObjectManager::getInstance(ProductSearchProjectionMutationCoordinatorInterface::class));
    }

    public function findById(int $websiteId, int $productId): ?Product
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Product::schema_fields_ID, $productId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function findBySku(int $websiteId, string $sku): ?Product
    {
        $this->assertWebsite($websiteId);
        $sku = trim($sku);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Product::schema_fields_SKU, $sku)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function findByGlobalUuid(int $websiteId, string $uuid): ?Product
    {
        $this->assertWebsite($websiteId);
        $uuid = trim($uuid);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Product::schema_fields_GLOBAL_PRODUCT_UUID, $uuid)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $websiteId): array
    {
        $this->assertWebsite($websiteId);
        $rows = $this->newModel($websiteId)
            ->clear()
            ->select()
            ->fetchArray();
        usort(
            $rows,
            static fn(array $left, array $right): int => (int)($left[Product::schema_fields_ID] ?? 0)
                <=> (int)($right[Product::schema_fields_ID] ?? 0),
        );
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $websiteId, array $data): Product
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $now = date('Y-m-d H:i:s');
        unset($data[Product::schema_fields_ID]);
        $model->clear()->setData(array_merge($data, [
            Product::schema_fields_STATUS => Product::STATUS_DRAFT,
            Product::schema_fields_PUBLISH_VERSION => 0,
            Product::schema_fields_CAS_TOKEN => '',
            Product::schema_fields_CREATED_AT => $now,
            Product::schema_fields_UPDATED_AT => $now,
        ]))->save();
        $id = (int)$model->getId();
        $loaded = $this->findById($websiteId, $id);
        if ($loaded === null) {
            throw new \RuntimeException(__('Product 写入后无法回读：%{1}', [$id]));
        }
        return $loaded;
    }

    public function updateSku(int $websiteId, int $productId, string $sku): Product
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException(__('Product SKU 不能为空'));
        }
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT,
            $productId,
            null,
            function () use ($websiteId, $productId, $sku): Product {
                $product = $this->findById($websiteId, $productId)
                    ?? throw new \InvalidArgumentException(__('Product 不存在：%{1}', [$productId]));
                $product->setData(Product::schema_fields_SKU, $sku);
                $product->setData(Product::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
                $product->save();

                return $this->findById($websiteId, $productId)
                    ?? throw new \RuntimeException(__('Product 更新后无法回读：%{1}', [$productId]));
            },
        );
    }

    /**
     * Optimistic publish: CAS on publish_version.
     *
     * @throws CatalogConflictException publish_version_conflict
     */
    public function publish(int $websiteId, int $productId, int $expectedVersion): Product
    {
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT,
            $productId,
            null,
            fn(): Product => $this->publishCurrent($websiteId, $productId, $expectedVersion),
        );
    }

    private function publishCurrent(int $websiteId, int $productId, int $expectedVersion): Product
    {
        $this->assertWebsite($websiteId);
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException(__('publish_version 不能为负数：%{1}', [$expectedVersion]));
        }
        $product = $this->findById($websiteId, $productId);
        if ($product === null) {
            throw new \InvalidArgumentException(__('Product 不存在：%{1}', [$productId]));
        }

        $current = (int)$product->getData(Product::schema_fields_PUBLISH_VERSION);
        if ($current !== $expectedVersion) {
            throw new CatalogConflictException(
                'publish_version_conflict',
                __('Product publish_version 冲突：expected=%{1} actual=%{2}', [$expectedVersion, $current]),
                [
                    'website_id' => $websiteId,
                    'product_id' => $productId,
                    'expected' => $expectedVersion,
                    'actual' => $current,
                ],
            );
        }

        $next = $expectedVersion + 1;
        $previousToken = (string)$product->getData(Product::schema_fields_CAS_TOKEN);
        $writerToken = $this->newCasToken();
        $candidate = $this->newModel($websiteId)->clear();
        $candidate->getQuery()
            ->where(Product::schema_fields_ID, $productId)
            ->where(Product::schema_fields_PUBLISH_VERSION, $expectedVersion)
            ->where(Product::schema_fields_CAS_TOKEN, $previousToken)
            ->update([
                Product::schema_fields_STATUS => Product::STATUS_PUBLISHED,
                Product::schema_fields_PUBLISH_VERSION => $next,
                Product::schema_fields_CAS_TOKEN => $writerToken,
                Product::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();

        // Adapter affected-row values are not portable. The durable token proves
        // that this exact invocation, rather than a racing writer, won the CAS.
        $reloaded = $this->findById($websiteId, $productId);
        if ($reloaded === null
            || (int)$reloaded->getData(Product::schema_fields_PUBLISH_VERSION) !== $next
            || !hash_equals(
                $writerToken,
                (string)$reloaded->getData(Product::schema_fields_CAS_TOKEN),
            )
        ) {
            throw new CatalogConflictException(
                'publish_version_conflict',
                __('Product publish CAS 失败：product_id=%{1}', [$productId]),
                [
                    'website_id' => $websiteId,
                    'product_id' => $productId,
                    'expected' => $expectedVersion,
                    'actual' => $reloaded === null
                        ? null
                        : (int)$reloaded->getData(Product::schema_fields_PUBLISH_VERSION),
                ],
            );
        }

        return $reloaded;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var Product $model */
        $model = ObjectManager::create(Product::class, [], false);
        return $model->forWebsite($websiteId);
    }

    private function newCasToken(): string
    {
        $token = $this->casTokenFactory !== null
            ? strtolower(trim((string)($this->casTokenFactory)()))
            : bin2hex(random_bytes(32));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw new \LogicException(__('Product CAS token factory 必须返回 32-64 位十六进制'));
        }
        return $token;
    }
}
