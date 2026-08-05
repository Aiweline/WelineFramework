<?php

namespace Weline\Websites\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteCacheInvalidationService;
#[Table(comment: '网站表')]
#[Index(name: 'uk_name', columns: ['name'], type: 'UNIQUE')]
#[Index(name: 'uk_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'uk_url', columns: ['url'], type: 'UNIQUE')]
#[Index(name: 'idx_scope', columns: ['scope'])]
class Website extends Model
{
    /** 默认网站 ID，保留给安装兜底站点，不分配给普通业务站点 */
    public const ID_DEFAULT = 0;
    /** 默认网站代码，底层禁止删除 */
    public const CODE_DEFAULT = 'default';

    public const use_main_db_master = true;
    public const schema_table = 'weline_websites_website';
    public const schema_primary_key = 'website_id';

    private ?string $invalidationPreviousCode = null;
    private ?string $invalidationDeletedCode = null;
    private bool $deletePrepared = false;

    /**
     * Website 与其 default Store/Channel 必须同事务提交。
     *
     * AbstractModel 会在模型 SQL 提交后才调用 save_after()；因此这里增加
     * Website 专属外层事务，让 save_after() 中的 Scope 补种仍处于同一连接。
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

    /** Keep parent validation, child-reference validation and DELETE in one owner transaction. */
    public function delete(): static
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->getConnection();
        $delete = function (): static {
            $this->prepareDelete();
            try {
                return parent::delete();
            } finally {
                $this->deletePrepared = false;
                $this->invalidationDeletedCode = null;
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


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '网站ID')]
    public const schema_fields_ID = 'website_id';
    #[Col('varchar', 128, nullable: false, unique: true, comment: '网站名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, nullable: false, unique: true, comment: '网站代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 128, nullable: false, unique: true, comment: '网站链接')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 20, nullable: true, comment: '默认货币')]
    public const schema_fields_DEFAULT_CURRENCY = 'default_currency';
    #[Col('varchar', 20, nullable: true, comment: '默认语言')]
    public const schema_fields_DEFAULT_LANGUAGE = 'default_language';
    #[Col('varchar', 60, nullable: false, comment: '默认时区')]
    public const schema_fields_DEFAULT_TIMEZONE = 'default_timezone';
    #[Col('varchar', 100, nullable: true, default: '', comment: '业务scope标识，如catalog等')]
    public const schema_fields_SCOPE = 'scope';


    /**
     * 删除前：默认网站不允许删除（底层强制）
     */
    public function delete_before(): void
    {
        parent::delete_before();
        if (!$this->deletePrepared) {
            throw new \LogicException(__('网站删除必须经过拥有者事务边界'));
        }
    }

    /**
     * 保存前处理URL
     * 自动添加协议前缀：如果URL不以 http:// 或 https:// 开头，自动添加 http://
     */
    public function save_before(): void
    {
        parent::save_before();
        $websiteId = $this->hasData(self::schema_fields_ID)
            ? $this->getData(self::schema_fields_ID)
            : null;
        if ($websiteId === self::ID_DEFAULT || $websiteId === (string)self::ID_DEFAULT) {
            // save_before runs after parent::save() has loaded an optional data argument.
            // Website 0 is a persisted identity, not an auto-increment placeholder.
            $this->setData(self::schema_fields_ID, self::ID_DEFAULT, true);
        }
        $this->invalidationPreviousCode = null;
        if ($this->hasData(self::schema_fields_ID)) {
            $existing = clone $this;
            $row = $existing->clearData()->clearQuery()
                ->where(self::schema_fields_ID, (int)$this->getData(self::schema_fields_ID))
                ->find()
                ->fetchArray();
            if (is_array($row) && array_is_list($row)) {
                $row = $row[0] ?? null;
            }
            if (is_array($row)) {
                $this->invalidationPreviousCode = trim((string)($row[self::schema_fields_CODE] ?? '')) ?: null;
            }
        }

        $url = $this->getData(self::schema_fields_URL);
        if (!empty($url) && is_string($url)) {
            $url = trim($url);
            if (!preg_match('/^https?:\/\//i', $url)) {
                $this->setData(self::schema_fields_URL, 'http://' . $url);
            }
        }
    }

    /**
     * 保存后清除网站缓存
     * 当网站数据更新时，清除缓存的网站列表，确保下次请求时重新加载最新数据
     */
    public function save_after()
    {
        parent::save_after();
        try {
            if ($this->hasData(self::schema_fields_ID)) {
                ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateWebsite(
                    $this->getConnection(),
                    (int)$this->getData(self::schema_fields_ID),
                    ['website'],
                    $this->invalidationPreviousCode,
                );
                // 与 Website 保存使用同一连接；补种失败必须让上层事务回滚，禁止留下残缺 Scope。
                ObjectManager::getInstance(\Weline\Websites\Service\StoreChannelSeedService::class)
                    ->ensureDefaultsForWebsite(
                        (int)$this->getData(self::schema_fields_ID),
                        (string)$this->getData(self::schema_fields_NAME),
                        $this->getConnection(),
                    );
            }
        } finally {
            $this->invalidationPreviousCode = null;
        }
    }

    public function delete_after(): void
    {
        parent::delete_after();
        try {
            if ($this->invalidationDeletedCode !== null) {
                ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateDeletedWebsite(
                    $this->getConnection(),
                    $this->invalidationDeletedCode,
                );
            }
        } finally {
            $this->invalidationDeletedCode = null;
        }
    }

    private function prepareDelete(): void
    {
        $this->invalidationDeletedCode = null;
        $row = ObjectManager::getInstance(self::class, [], false);
        $row->setConnection($this->getConnection())->clearData()->clearQuery();
        if ($this->hasData(self::schema_fields_ID)) {
            $websiteId = (int)$this->getData(self::schema_fields_ID);
            if ($websiteId < self::ID_DEFAULT) {
                throw new \RuntimeException(__('删除网站前必须提供有效 Website ID'));
            }
            $row->where(self::schema_fields_ID, $websiteId);
        } else {
            $code = trim((string)$this->getData(self::schema_fields_CODE));
            if ($code === '') {
                throw new \RuntimeException(__('删除网站前必须加载明确的网站记录'));
            }
            $row->where(self::schema_fields_CODE, $code);
        }
        if ($this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        if (!$row->hasData(self::schema_fields_ID)) {
            throw new \RuntimeException(__('要删除的网站不存在'));
        }

        $websiteId = (int)$row->getData(self::schema_fields_ID);
        $websiteCode = trim((string)$row->getData(self::schema_fields_CODE));
        $requestedCode = trim((string)$this->getData(self::schema_fields_CODE));
        if ($requestedCode !== '' && $requestedCode !== $websiteCode) {
            throw new \RuntimeException(__('网站删除身份不一致'));
        }
        if ($websiteId === self::ID_DEFAULT || $websiteCode === self::CODE_DEFAULT) {
            throw new \RuntimeException(__('默认网站不允许删除'));
        }

        foreach ([Store::class, SalesChannel::class] as $childClass) {
            $child = ObjectManager::getInstance($childClass, [], false);
            $child->setConnection($this->getConnection())->clearData()->clearQuery()
                ->where($childClass::schema_fields_WEBSITE_ID, $websiteId)
                ->find()->fetch();
            if ($child->hasData($childClass::schema_fields_ID)) {
                throw new \RuntimeException(__('网站仍有 Store 或 SalesChannel 引用，不允许物理删除'));
            }
        }

        $this->clearData()->setData($row->getModelData());
        $this->invalidationDeletedCode = $websiteCode;
        $this->deletePrepared = true;
    }

    private function assertActiveWriteIntent(WriteIntentTransactionCoordinatorInterface $transactions): void
    {
        if ($this->isSqlite() && !$transactions->isWriteIntent($this->getConnection())) {
            throw new \LogicException('websites_website_sqlite_write_intent_required');
        }
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function isSqlite(): bool
    {
        return strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType()) === 'sqlite';
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::schema_fields_ID, $websiteId);
        return $this;
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setName(string $name): self
    {
        $this->setData(self::schema_fields_NAME, $name);
        return $this;
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::schema_fields_CODE, $code);
        return $this;
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }

    public function setUrl(string $url): self
    {
        // 自动添加协议前缀：如果URL不以 http:// 或 https:// 开头，自动添加 http://
        $url = trim($url);
        if (!empty($url) && !preg_match('/^https?:\/\//i', $url)) {
            $url = 'http://' . $url;
        }
        $this->setData(self::schema_fields_URL, $url);
        return $this;
    }

    public function getUrl(): string
    {
        return (string)$this->getData(self::schema_fields_URL);
    }

    public function setDefaultCurrency(?string $currency): self
    {
        $this->setData(self::schema_fields_DEFAULT_CURRENCY, $currency);
        return $this;
    }

    public function getDefaultCurrency(): ?string
    {
        $currency = $this->getData(self::schema_fields_DEFAULT_CURRENCY);
        return $currency ? (string)$currency : null;
    }

    public function setDefaultLanguage(?string $language): self
    {
        $this->setData(self::schema_fields_DEFAULT_LANGUAGE, $language);
        return $this;
    }

    public function getDefaultLanguage(): ?string
    {
        $language = $this->getData(self::schema_fields_DEFAULT_LANGUAGE);
        return $language ? (string)$language : null;
    }

    /**
     * 获取网站的关联货币代码列表
     *
     * @return array
     */
    public function getCurrencyCodes(): array
    {
        if (!$this->hasData(self::schema_fields_ID)) {
            return [];
        }
        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
        return $websiteCurrency->getWebsiteCurrencyCodes($this->getWebsiteId());
    }

    /**
     * 获取网站的关联语言代码列表
     *
     * @return array
     */
    public function getLanguageCodes(): array
    {
        if (!$this->hasData(self::schema_fields_ID)) {
            return [];
        }
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        return $websiteLanguage->getWebsiteLanguageCodes($this->getWebsiteId());
    }

    public function setDefaultTimezone(string $timezone): self
    {
        $this->setData(self::schema_fields_DEFAULT_TIMEZONE, $timezone);
        return $this;
    }

    public function getDefaultTimezone(): string
    {
        return (string)$this->getData(self::schema_fields_DEFAULT_TIMEZONE);
    }

    /**
     * 设置业务范围标识
     *
     * @param string $scope 业务范围标识，如 catalog 等
     * @return self
     */
    public function setScope(string $scope): self
    {
        $this->setData(self::schema_fields_SCOPE, $scope);
        return $this;
    }

    /**
     * 获取业务范围标识
     *
     * @return string
     */
    public function getScope(): string
    {
        return (string)$this->getData(self::schema_fields_SCOPE);
    }
}
