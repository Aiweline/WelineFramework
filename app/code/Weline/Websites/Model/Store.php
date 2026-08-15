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
use Weline\Websites\Service\StoreLifecycleService;
use Weline\Websites\Service\WebsiteCacheInvalidationService;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

/**
 * Store（店铺）：Website 之下的独立经营单元。
 *
 * Scope 三段主键的第二段。`store_mode` 是创建后不可变的只读属性
 * （normal|dev|test），default Store 受底层保护不可删除。
 */
#[Table(comment: '网站店铺表')]
#[Index(name: 'uk_website_store_code', columns: ['website_id', 'code'], type: 'UNIQUE')]
#[Index(name: 'idx_store_website', columns: ['website_id'])]
class Store extends Model
{
    /** 默认店铺代码，底层禁止删除 */
    public const CODE_DEFAULT = 'default';

    public const MODE_NORMAL = 'normal';
    public const MODE_DEV = 'dev';
    public const MODE_TEST = 'test';
    public const MODES = [self::MODE_NORMAL, self::MODE_DEV, self::MODE_TEST];
    public const CODE_MAX_LENGTH = 64;
    public const NAME_MAX_LENGTH = 128;

    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_TOMBSTONE = 'tombstone';

    public const use_main_db_master = true;
    public const schema_table = 'weline_websites_store';
    public const schema_primary_key = 'store_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '店铺ID')]
    public const schema_fields_ID = 'store_id';
    #[Col('int', 11, nullable: false, comment: '所属网站ID（0 为系统默认站，合法）')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false, comment: '店铺代码（站内唯一）')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 128, nullable: false, comment: '店铺名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 16, nullable: false, default: self::MODE_NORMAL, comment: '店铺模式 normal|dev|test（创建后不可变）')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否网站默认店铺')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '状态 1启用 0停用')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 255, nullable: true, comment: '店铺独立入口URL（可选，规范 Origin 与路径匹配）')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 16, nullable: false, default: self::LIFECYCLE_ACTIVE, comment: '生命周期 active|tombstone')]
    public const schema_fields_LIFECYCLE_STATUS = 'lifecycle_status';
    #[Col('datetime', nullable: true, comment: '转为墓碑的 UTC 时间')]
    public const schema_fields_TOMBSTONED_AT = 'tombstoned_at';

    /**
     * 将 save_before() 的生命周期锁定读与保存放在同一个主库事务内。
     */
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

    /**
     * Store 只允许转为 tombstone；物理清除属于后续受控 purge 流程。
     */
    public function delete(): static
    {
        ObjectManager::getInstance(StoreLifecycleService::class)->tombstone($this);
        return $this;
    }

    public function save_before(): void
    {
        parent::save_before();

        [$existing, $lockedParentWebsite] = $this->loadExistingAndWebsiteForUpdate();
        $this->assertWritableLifecycle($existing);
        $code = self::normalizeCode((string)$this->valueForSave(self::schema_fields_CODE, $existing));
        if ($code === '') {
            throw new \RuntimeException(__('店铺代码不能为空'));
        }
        if (mb_strlen($code, 'UTF-8') > self::CODE_MAX_LENGTH) {
            throw new \RuntimeException(__('店铺代码不能超过 %{1} 个字符', [self::CODE_MAX_LENGTH]));
        }
        $this->setData(self::schema_fields_CODE, $code);

        $name = trim((string)$this->valueForSave(self::schema_fields_NAME, $existing));
        if ($name === '') {
            throw new \RuntimeException(__('店铺名称不能为空'));
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH) {
            throw new \RuntimeException(__('店铺名称不能超过 %{1} 个字符', [self::NAME_MAX_LENGTH]));
        }
        $this->setData(self::schema_fields_NAME, $name);

        $mode = strtolower(trim((string)$this->valueForSave(
            self::schema_fields_STORE_MODE,
            $existing,
            self::MODE_NORMAL,
        )));
        if (!in_array($mode, self::MODES, true)) {
            throw new \RuntimeException(__('非法店铺模式：%{1}（仅允许 normal|dev|test）', [$mode]));
        }
        $this->setData(self::schema_fields_STORE_MODE, $mode);

        $websiteId = self::nonNegativeInteger(
            $this->valueForSave(self::schema_fields_WEBSITE_ID, $existing),
            __('店铺必须显式归属 Website（website_id=0 是合法默认站）'),
        );
        $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
        if ($existing !== null
            && (int)$existing->getData(self::schema_fields_WEBSITE_ID) !== $websiteId) {
            throw new \RuntimeException(__('店铺创建后不允许迁移到其他 Website'));
        }
        if ($lockedParentWebsite === null) {
            $this->requireParentWebsite($websiteId);
        }

        $isDefault = self::binaryFlag(
            $this->valueForSave(self::schema_fields_IS_DEFAULT, $existing, 0),
            __('店铺默认标记只能是 0 或 1'),
        );
        $status = self::binaryFlag(
            $this->valueForSave(self::schema_fields_STATUS, $existing, 1),
            __('店铺状态只能是 0 或 1'),
        );
        $this->setData(self::schema_fields_IS_DEFAULT, $isDefault);
        $this->setData(self::schema_fields_STATUS, $status);

        if (($code === self::CODE_DEFAULT) !== ($isDefault === 1)) {
            throw new \RuntimeException(__('默认店铺必须同时满足 code=default 且 is_default=1'));
        }
        if ($isDefault === 1 && $mode !== self::MODE_NORMAL) {
            throw new \RuntimeException(__('默认店铺的 store_mode 必须为 normal'));
        }
        if ($isDefault === 1 && $status !== 1) {
            throw new \RuntimeException(__('默认店铺不允许停用'));
        }

        if ($existing !== null) {
            $existingMode = (string)$existing->getData(self::schema_fields_STORE_MODE);
            if ($existingMode !== $mode) {
                throw new \RuntimeException(__('店铺模式创建后不可变更：%{1} → %{2} 被拒绝', [$existingMode, $mode]));
            }
            if ((int)$existing->getData(self::schema_fields_IS_DEFAULT) === 1
                && $code !== (string)$existing->getData(self::schema_fields_CODE)) {
                throw new \RuntimeException(__('默认店铺代码不允许修改'));
            }
        }

        $rawUrl = (string)$this->valueForSave(self::schema_fields_URL, $existing, '');
        $url = '';
        if ($rawUrl !== '') {
            try {
                $url = CanonicalStorefrontUrl::fromStoreUrl($rawUrl)->toString();
                if (strlen($url) > 255) {
                    throw new \InvalidArgumentException('Canonical Store URL exceeds the schema limit.');
                }
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException(
                    (string)__('店铺独立入口 URL 必须是不含账号、查询或片段的规范 http/https 地址'),
                    0,
                    $exception,
                );
            }
        }
        $this->setData(self::schema_fields_URL, $url !== '' ? $url : null);
    }

    public function save_after(): void
    {
        parent::save_after();
        ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateWebsite(
            $this->getConnection(),
            (int)$this->getData(self::schema_fields_WEBSITE_ID),
            ['catalog'],
        );
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?: '';
        return trim($code, '_-');
    }

    /** @return array{0: ?self, 1: ?Website} */
    private function loadExistingAndWebsiteForUpdate(): array
    {
        if (!$this->hasData(self::schema_fields_ID)) {
            return [null, null];
        }
        $id = (int)$this->getData(self::schema_fields_ID);
        if ($id <= 0) {
            throw new \RuntimeException(__('店铺 ID 必须是正整数'));
        }
        $probe = $this->loadExistingRow($id, false);
        $websiteId = self::nonNegativeInteger(
            $probe->getData(self::schema_fields_WEBSITE_ID),
            __('店铺必须显式归属 Website（website_id=0 是合法默认站）'),
        );
        $website = $this->requireParentWebsite($websiteId);
        $current = $this->loadExistingRow($id, true);
        if ((int)$current->getData(self::schema_fields_WEBSITE_ID) !== $websiteId) {
            throw new \RuntimeException(__('店铺父 Website 在更新锁定期间发生变化'));
        }
        return [$current, $website];
    }

    private function loadExistingRow(int $id, bool $lockingRead): self
    {
        $existing = clone $this;
        $existing->clearData()->clearQuery()->where(self::schema_fields_ID, $id);
        if ($lockingRead && $this->supportsForUpdate()) {
            $existing->additional('FOR UPDATE');
        }
        $existing->find()->fetch();
        if (!$existing->hasData(self::schema_fields_ID)) {
            throw new \RuntimeException(__('要更新的店铺不存在'));
        }
        return $existing;
    }

    private function requireParentWebsite(int $websiteId): Website
    {
        $website = ObjectManager::getInstance(Website::class, [], false);
        $website->setConnection($this->getConnection())->clearData()->clearQuery();
        $sql = 'SELECT * FROM ' . $website->getTable()
            . ' WHERE ' . Website::schema_fields_ID . ' = :website_id';
        if ($this->supportsForUpdate()) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->getConnection()->getConnector()->getWrappedConnection()->prepare($sql);
        $statement->execute(['website_id' => $websiteId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || !array_key_exists(Website::schema_fields_ID, $row)) {
            throw new \RuntimeException(__('店铺所属 Website 不存在'));
        }
        $website->setData($row);
        return $website;
    }

    private function assertActiveWriteIntent(WriteIntentTransactionCoordinatorInterface $transactions): void
    {
        if ($this->isSqlite() && !$transactions->isWriteIntent($this->getConnection())) {
            throw new \LogicException('websites_store_sqlite_write_intent_required');
        }
    }

    private function isSqlite(): bool
    {
        return strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType()) === 'sqlite';
    }

    private function assertWritableLifecycle(?self $existing): void
    {
        if ($existing === null) {
            $lifecycle = strtolower(trim((string)$this->valueForSave(
                self::schema_fields_LIFECYCLE_STATUS,
                null,
                self::LIFECYCLE_ACTIVE,
            )));
            $tombstonedAt = $this->valueForSave(self::schema_fields_TOMBSTONED_AT, null);
            if ($lifecycle !== self::LIFECYCLE_ACTIVE || $tombstonedAt !== null) {
                throw new \RuntimeException(__('新店铺不允许伪造墓碑生命周期'));
            }
            $this->setData(self::schema_fields_LIFECYCLE_STATUS, self::LIFECYCLE_ACTIVE);
            $this->setData(self::schema_fields_TOMBSTONED_AT, null);
            return;
        }

        $existingLifecycle = strtolower(trim((string)$existing->getData(self::schema_fields_LIFECYCLE_STATUS)));
        if ($existingLifecycle === self::LIFECYCLE_TOMBSTONE) {
            throw new \RuntimeException(__('已转为墓碑的店铺不允许普通保存或重新启用'));
        }
        if ($existingLifecycle !== self::LIFECYCLE_ACTIVE
            || $existing->getData(self::schema_fields_TOMBSTONED_AT) !== null) {
            throw new \RuntimeException(__('店铺生命周期状态与墓碑时间不一致'));
        }

        $requestedLifecycle = strtolower(trim((string)$this->valueForSave(
            self::schema_fields_LIFECYCLE_STATUS,
            $existing,
        )));
        $requestedTombstonedAt = $this->valueForSave(self::schema_fields_TOMBSTONED_AT, $existing);
        if ($requestedLifecycle !== self::LIFECYCLE_ACTIVE || $requestedTombstonedAt !== null) {
            throw new \RuntimeException(__('普通保存不允许修改店铺墓碑字段'));
        }
        $this->setData(self::schema_fields_LIFECYCLE_STATUS, self::LIFECYCLE_ACTIVE);
        $this->setData(self::schema_fields_TOMBSTONED_AT, null);
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
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

    public function getStoreId(): int
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

    public function setStoreMode(string $mode): static
    {
        return $this->setData(self::schema_fields_STORE_MODE, $mode);
    }

    public function getStoreMode(): string
    {
        return (string)($this->getData(self::schema_fields_STORE_MODE) ?: self::MODE_NORMAL);
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

    public function getLifecycleStatus(): string
    {
        return (string)($this->getData(self::schema_fields_LIFECYCLE_STATUS) ?: self::LIFECYCLE_ACTIVE);
    }

    public function isTombstoned(): bool
    {
        return $this->getLifecycleStatus() === self::LIFECYCLE_TOMBSTONE;
    }

    public function getTombstonedAt(): ?string
    {
        $value = $this->getData(self::schema_fields_TOMBSTONED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setUrl(?string $url): static
    {
        return $this->setData(self::schema_fields_URL, $url);
    }

    public function getUrl(): ?string
    {
        $url = $this->getData(self::schema_fields_URL);
        return $url ? (string)$url : null;
    }
}
