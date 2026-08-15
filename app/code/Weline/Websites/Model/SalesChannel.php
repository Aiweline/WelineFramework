<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteCacheInvalidationService;

/**
 * SalesChannel（销售渠道）：Store 之下的渠道维度，Scope 三段主键的第三段。
 *
 * 每个 Store 恒有一个 default 渠道；default 渠道受底层保护不可删除。
 */
#[Table(comment: '店铺销售渠道表')]
#[Index(name: 'uk_store_channel_code', columns: ['store_id', 'code'], type: 'UNIQUE')]
#[Index(name: 'idx_channel_website', columns: ['website_id'])]
#[Index(name: 'idx_channel_store', columns: ['store_id'])]
class SalesChannel extends Model
{
    private ?int $catalogInvalidationDeletedWebsiteId = null;
    private bool $catalogDeletePrepared = false;

    /** 默认渠道代码，底层禁止删除 */
    public const CODE_DEFAULT = 'default';
    public const CODE_MAX_LENGTH = 64;
    public const NAME_MAX_LENGTH = 128;

    public const use_main_db_master = true;
    public const schema_table = 'weline_websites_sales_channel';
    public const schema_primary_key = 'channel_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '渠道ID')]
    public const schema_fields_ID = 'channel_id';
    #[Col('int', 11, nullable: false, comment: '所属网站ID（0 合法）')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 11, nullable: false, comment: '所属店铺ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('varchar', 64, nullable: false, comment: '渠道代码（店铺内唯一）')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 128, nullable: false, comment: '渠道名称')]
    public const schema_fields_NAME = 'name';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否店铺默认渠道')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '状态 1启用 0停用')]
    public const schema_fields_STATUS = 'status';

    /** Keep the Channel write and catalog generation in one owner transaction. */
    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->getConnection();
        if ($transactions->isActive($connection)) {
            try {
                $this->assertActiveWriteIntent($transactions);
                return parent::save($data, $sequence);
            } catch (\Throwable $exception) {
                $transactions->markRollbackOnly($connection, $exception);
                throw $exception;
            }
        }
        return $transactions->runWrite($connection, fn(): bool|int => parent::save($data, $sequence));
    }

    /** Keep deletion and its catalog generation in one owner transaction. */
    public function delete(): static
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->getConnection();
        $delete = function (): static {
            // TransactionContext owns one query per connection. Resolve all
            // deletion facts before AbstractModel prepares DELETE; any query
            // from delete_before() would otherwise replace that pending SQL.
            $this->prepareCatalogDelete();
            try {
                return parent::delete();
            } finally {
                $this->catalogDeletePrepared = false;
                $this->catalogInvalidationDeletedWebsiteId = null;
            }
        };
        if ($transactions->isActive($connection)) {
            try {
                $this->assertActiveWriteIntent($transactions);
                return $delete();
            } catch (\Throwable $exception) {
                $transactions->markRollbackOnly($connection, $exception);
                throw $exception;
            }
        }
        return $transactions->runWrite($connection, $delete);
    }

    public function save_before(): void
    {
        parent::save_before();

        [$existing, $lockedParentStore] = $this->loadExistingAndParentForUpdate();
        $code = Store::normalizeCode((string)$this->valueForSave(self::schema_fields_CODE, $existing));
        if ($code === '') {
            throw new \RuntimeException(__('渠道代码不能为空'));
        }
        if (mb_strlen($code, 'UTF-8') > self::CODE_MAX_LENGTH) {
            throw new \RuntimeException(__('渠道代码不能超过 %{1} 个字符', [self::CODE_MAX_LENGTH]));
        }
        $this->setData(self::schema_fields_CODE, $code);

        $name = trim((string)$this->valueForSave(self::schema_fields_NAME, $existing));
        if ($name === '') {
            throw new \RuntimeException(__('渠道名称不能为空'));
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH) {
            throw new \RuntimeException(__('渠道名称不能超过 %{1} 个字符', [self::NAME_MAX_LENGTH]));
        }
        $this->setData(self::schema_fields_NAME, $name);

        $websiteId = self::nonNegativeInteger(
            $this->valueForSave(self::schema_fields_WEBSITE_ID, $existing),
            __('渠道必须显式归属 Website（website_id=0 是合法默认站）'),
        );
        $storeId = self::positiveInteger(
            $this->valueForSave(self::schema_fields_STORE_ID, $existing),
            __('渠道必须归属一个明确的店铺'),
        );
        $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
        $this->setData(self::schema_fields_STORE_ID, $storeId);

        if ($existing !== null
            && ((int)$existing->getData(self::schema_fields_WEBSITE_ID) !== $websiteId
                || (int)$existing->getData(self::schema_fields_STORE_ID) !== $storeId)) {
            throw new \RuntimeException(__('渠道创建后不允许迁移到其他 Website 或店铺'));
        }

        $store = $lockedParentStore ?? $this->requireActiveParentStore($storeId);
        if ($store->getWebsiteId() !== $websiteId) {
            throw new \RuntimeException(__('渠道 website_id 必须与所属店铺一致'));
        }

        $isDefault = self::binaryFlag(
            $this->valueForSave(self::schema_fields_IS_DEFAULT, $existing, 0),
            __('渠道默认标记只能是 0 或 1'),
        );
        $status = self::binaryFlag(
            $this->valueForSave(self::schema_fields_STATUS, $existing, 1),
            __('渠道状态只能是 0 或 1'),
        );
        $this->setData(self::schema_fields_IS_DEFAULT, $isDefault);
        $this->setData(self::schema_fields_STATUS, $status);
        if (($code === self::CODE_DEFAULT) !== ($isDefault === 1)) {
            throw new \RuntimeException(__('默认渠道必须同时满足 code=default 且 is_default=1'));
        }
        if ($isDefault === 1 && $status !== 1) {
            throw new \RuntimeException(__('默认渠道不允许停用'));
        }

        if ($existing !== null) {
            if ((int)$existing->getData(self::schema_fields_IS_DEFAULT) === 1
                && $code !== (string)$existing->getData(self::schema_fields_CODE)) {
                throw new \RuntimeException(__('默认渠道代码不允许修改'));
            }
        }
    }

    public function delete_before(): void
    {
        parent::delete_before();
        if (!$this->catalogDeletePrepared) {
            throw new \LogicException(__('销售渠道删除必须经过拥有者事务边界'));
        }
    }

    private function prepareCatalogDelete(): void
    {
        $probe = $this->loadExistingForDelete(false);
        $storeId = self::positiveInteger(
            $probe->getData(self::schema_fields_STORE_ID),
            __('渠道必须归属一个明确的店铺'),
        );
        $store = $this->requireActiveParentStore($storeId);
        $row = $this->loadExistingForDelete(true);
        if ((int)$row->getData(self::schema_fields_STORE_ID) !== $storeId) {
            throw new \RuntimeException(__('渠道父店铺在删除锁定期间发生变化'));
        }
        if ((int)$row->getData(self::schema_fields_WEBSITE_ID) !== $store->getWebsiteId()) {
            throw new \RuntimeException(__('渠道 website_id 必须与所属店铺一致'));
        }
        if ((int)$row->getData(self::schema_fields_IS_DEFAULT) === 1
            || (string)$row->getData(self::schema_fields_CODE) === self::CODE_DEFAULT) {
            throw new \RuntimeException(__('默认渠道不允许删除'));
        }
        $this->catalogInvalidationDeletedWebsiteId = (int)$row->getData(self::schema_fields_WEBSITE_ID);
        $this->catalogDeletePrepared = true;
    }

    public function save_after(): void
    {
        parent::save_after();
        $this->invalidateCatalog((int)$this->getData(self::schema_fields_WEBSITE_ID));
    }

    public function delete_after(): void
    {
        parent::delete_after();
        try {
            if ($this->catalogInvalidationDeletedWebsiteId !== null) {
                $this->invalidateCatalog($this->catalogInvalidationDeletedWebsiteId);
            }
        } finally {
            $this->catalogInvalidationDeletedWebsiteId = null;
        }
    }

    private function invalidateCatalog(int $websiteId): void
    {
        ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateWebsite(
            $this->getConnection(),
            $websiteId,
            ['catalog'],
        );
    }

    private function requireActiveParentStore(int $storeId): Store
    {
        $store = ObjectManager::getInstance(Store::class, [], false);
        $store->setConnection($this->getConnection())->clearData()->clearQuery();
        $sql = 'SELECT * FROM ' . $store->getTable()
            . ' WHERE ' . Store::schema_fields_ID . ' = :store_id';
        if ($this->supportsForUpdate()) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->getConnection()->getConnector()->getWrappedConnection()->prepare($sql);
        $statement->execute(['store_id' => $storeId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || !array_key_exists(Store::schema_fields_ID, $row)) {
            throw new \RuntimeException(__('渠道所属店铺不存在'));
        }
        $store->setData($row);
        if ((string)$store->getData(Store::schema_fields_LIFECYCLE_STATUS) !== Store::LIFECYCLE_ACTIVE
            || $store->getData(Store::schema_fields_TOMBSTONED_AT) !== null) {
            throw new \RuntimeException(__('非活动店铺下不允许新增、更新或删除销售渠道'));
        }

        return $store;
    }

    private function assertActiveWriteIntent(WriteIntentTransactionCoordinatorInterface $transactions): void
    {
        if ($this->isSqlite() && !$transactions->isWriteIntent($this->getConnection())) {
            throw new \LogicException('websites_channel_sqlite_write_intent_required');
        }
    }

    private function isSqlite(): bool
    {
        return strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType()) === 'sqlite';
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    /** @return array{0: ?self, 1: ?Store} */
    private function loadExistingAndParentForUpdate(): array
    {
        if (!$this->hasData(self::schema_fields_ID)) {
            return [null, null];
        }
        $id = (int)$this->getData(self::schema_fields_ID);
        if ($id <= 0) {
            throw new \RuntimeException(__('渠道 ID 必须是正整数'));
        }
        $probe = $this->loadExistingRow($id, false, __('要更新的渠道不存在'));
        $storeId = self::positiveInteger(
            $probe->getData(self::schema_fields_STORE_ID),
            __('渠道必须归属一个明确的店铺'),
        );
        $store = $this->requireActiveParentStore($storeId);
        $current = $this->loadExistingRow($id, true, __('要更新的渠道不存在'));
        if ((int)$current->getData(self::schema_fields_STORE_ID) !== $storeId) {
            throw new \RuntimeException(__('渠道父店铺在更新锁定期间发生变化'));
        }
        return [$current, $store];
    }

    private function loadExistingForDelete(bool $lockingRead): self
    {
        if (!$this->hasData(self::schema_fields_ID)) {
            throw new \RuntimeException(__('删除渠道前必须加载明确的渠道记录'));
        }
        $id = (int)$this->getData(self::schema_fields_ID);
        if ($id <= 0) {
            throw new \RuntimeException(__('删除渠道前必须提供有效渠道 ID'));
        }
        // delete() has already prepared its DELETE query before delete_before()
        // runs. A shallow clone shares that bound query and clearQuery() would
        // erase the pending DELETE, turning the operation into a silent no-op.
        // Use an independent model while keeping the owner connection instead.
        $row = ObjectManager::getInstance(self::class, [], false);
        $row->setConnection($this->getConnection())
            ->where(self::schema_fields_ID, $id);
        if ($lockingRead && $this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        if (!$row->hasData(self::schema_fields_ID)) {
            throw new \RuntimeException(__('要删除的渠道不存在'));
        }
        return $row;
    }

    private function loadExistingRow(int $id, bool $lockingRead, string $missingMessage): self
    {
        $existing = clone $this;
        $existing->clearData()->clearQuery()->where(self::schema_fields_ID, $id);
        if ($lockingRead && $this->supportsForUpdate()) {
            $existing->additional('FOR UPDATE');
        }
        $existing->find()->fetch();
        if (!$existing->hasData(self::schema_fields_ID)) {
            throw new \RuntimeException($missingMessage);
        }
        return $existing;
    }

    private function valueForSave(string $field, ?self $existing, mixed $default = null): mixed
    {
        if ($this->hasData($field)) {
            return $this->getData($field);
        }
        if ($existing !== null && $existing->hasData($field)) {
            return $existing->getData($field);
        }
        return $default;
    }

    private static function nonNegativeInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int)$value;
        }
        throw new \RuntimeException($message);
    }

    private static function positiveInteger(mixed $value, string $message): int
    {
        $value = self::nonNegativeInteger($value, $message);
        if ($value === 0) {
            throw new \RuntimeException($message);
        }
        return $value;
    }

    private static function binaryFlag(mixed $value, string $message): int
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 0;
        }
        throw new \RuntimeException($message);
    }

    public function getChannelId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function setStoreId(int $storeId): static
    {
        return $this->setData(self::schema_fields_STORE_ID, $storeId);
    }

    public function getStoreId(): int
    {
        return (int)$this->getData(self::schema_fields_STORE_ID);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setIsDefault(bool $isDefault): static
    {
        return $this->setData(self::schema_fields_IS_DEFAULT, $isDefault ? 1 : 0);
    }

    public function isDefault(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_DEFAULT) === 1;
    }

    public function setStatus(bool $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status ? 1 : 0);
    }

    public function isEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_STATUS) === 1;
    }
}
