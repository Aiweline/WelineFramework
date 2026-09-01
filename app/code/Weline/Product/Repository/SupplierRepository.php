<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Supplier;
use Weline\Product\Service\ProductShardProvisioner;

final class SupplierRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): Supplier)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): Supplier)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function findById(int $websiteId, int $supplierId): ?Supplier
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Supplier::schema_fields_ID, $supplierId)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    public function findByCode(int $websiteId, string $code): ?Supplier
    {
        $this->assertWebsite($websiteId);
        $code = $this->normalizeCode($code);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Supplier::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(int $websiteId, ?string $status = null): array
    {
        $this->assertWebsite($websiteId);
        $query = $this->newModel($websiteId)->clear();
        if ($status !== null && $status !== '') {
            $query->where(Supplier::schema_fields_STATUS, $status);
        }
        $rows = $query->select()->fetchArray();
        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftPosition = (int)($left[Supplier::schema_fields_POSITION] ?? 0);
                $rightPosition = (int)($right[Supplier::schema_fields_POSITION] ?? 0);
                if ($leftPosition !== $rightPosition) {
                    return $leftPosition <=> $rightPosition;
                }

                return (int)($left[Supplier::schema_fields_ID] ?? 0)
                    <=> (int)($right[Supplier::schema_fields_ID] ?? 0);
            },
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $websiteId, array $data): Supplier
    {
        $this->assertWebsite($websiteId);
        $now = date('Y-m-d H:i:s');
        $payload = array_merge([
            Supplier::schema_fields_STATUS => Supplier::STATUS_ACTIVE,
            Supplier::schema_fields_POSITION => $this->nextPosition($websiteId),
            Supplier::schema_fields_CREATED_AT => $now,
            Supplier::schema_fields_UPDATED_AT => $now,
        ], $data);
        $payload[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID] = isset($payload[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID])
            ? $this->normalizeUuid((string)$payload[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID])
            : $this->newUuid();
        $payload[Supplier::schema_fields_CODE] = $this->normalizeCode((string)($payload[Supplier::schema_fields_CODE] ?? ''));
        $payload[Supplier::schema_fields_NAME] = trim((string)($payload[Supplier::schema_fields_NAME] ?? ''));
        if ($payload[Supplier::schema_fields_CODE] === '' || $payload[Supplier::schema_fields_NAME] === '') {
            throw new \InvalidArgumentException((string)__('供应商名称与编码不能为空'));
        }
        if ($this->findByCode($websiteId, $payload[Supplier::schema_fields_CODE]) !== null) {
            throw new \InvalidArgumentException((string)__('供应商编码已存在：%{1}', [$payload[Supplier::schema_fields_CODE]]));
        }

        $model = $this->newModel($websiteId);
        $model->clear()->setData($payload)->save();
        $id = (int)$model->getId();

        return $this->findById($websiteId, $id)
            ?? throw new \RuntimeException((string)__('供应商创建后无法回读：%{1}', [$id]));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFields(int $websiteId, int $supplierId, array $data): Supplier
    {
        $supplier = $this->findById($websiteId, $supplierId)
            ?? throw new \InvalidArgumentException((string)__('供应商不存在：%{1}', [$supplierId]));
        if (isset($data[Supplier::schema_fields_CODE])) {
            $code = $this->normalizeCode((string)$data[Supplier::schema_fields_CODE]);
            $existing = $this->findByCode($websiteId, $code);
            if ($existing !== null && (int)$existing->getId() !== $supplierId) {
                throw new \InvalidArgumentException((string)__('供应商编码已存在：%{1}', [$code]));
            }
            $data[Supplier::schema_fields_CODE] = $code;
        }
        if (isset($data[Supplier::schema_fields_NAME])) {
            $data[Supplier::schema_fields_NAME] = trim((string)$data[Supplier::schema_fields_NAME]);
            if ($data[Supplier::schema_fields_NAME] === '') {
                throw new \InvalidArgumentException((string)__('供应商名称不能为空'));
            }
        }
        if (isset($data[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID])) {
            $data[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID] = $this->normalizeUuid(
                (string)$data[Supplier::schema_fields_GLOBAL_SUPPLIER_UUID],
            );
        }
        $data[Supplier::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
        foreach ($data as $field => $value) {
            $supplier->setData((string)$field, $value);
        }
        $supplier->save();

        return $this->findById($websiteId, $supplierId)
            ?? throw new \RuntimeException((string)__('供应商更新后无法回读：%{1}', [$supplierId]));
    }

    public function disable(int $websiteId, int $supplierId): Supplier
    {
        return $this->updateFields($websiteId, $supplierId, [
            Supplier::schema_fields_STATUS => Supplier::STATUS_DISABLED,
        ]);
    }

    public function nextPosition(int $websiteId): int
    {
        $max = 0;
        foreach ($this->listAll($websiteId) as $row) {
            $max = max($max, (int)($row[Supplier::schema_fields_POSITION] ?? 0));
        }

        return $max + 1;
    }

    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9]+/', '-', $code) ?? '';
        $code = trim($code, '-');
        if ($code === '' || strlen($code) > 64) {
            throw new \InvalidArgumentException((string)__('供应商编码须为 1–64 位小写字母/数字/连字符'));
        }

        return $code;
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
            throw new \InvalidArgumentException((string)__('global_supplier_uuid 格式非法'));
        }

        return $uuid;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var Supplier $model */
        $model = ObjectManager::create(Supplier::class, [], false);

        return $model->forWebsite($websiteId);
    }
}
