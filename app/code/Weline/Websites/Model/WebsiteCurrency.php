<?php
namespace Weline\Websites\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteCacheInvalidationService;
#[Table(comment: '网站-货币关联表')]
#[Index(name: 'idx_website_id', columns: ['website_id'], comment: '网站ID索引')]
#[Index(name: 'idx_currency_code', columns: ['currency_code'], comment: '货币代码索引')]
#[Index(name: 'uk_website_currency', columns: ['website_id', 'currency_code'], type: 'UNIQUE', comment: '网站货币唯一索引')]
class WebsiteCurrency extends Model
{
    public const schema_table = 'weline_websites_website_currency';
    public const schema_primary_key = 'website_currency_id';

    private ?int $invalidationPreviousWebsiteId = null;
    private ?int $invalidationDeletedWebsiteId = null;

    #[Col(type: 'int', nullable: false, primaryKey: true, autoIncrement: true, comment: '关联ID')]
    public const schema_fields_ID = 'website_currency_id';
    #[Col(type: 'int', nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '货币代码')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    public const fields_ID = 'website_currency_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_CURRENCY_CODE = 'currency_code';

    public function setWebsiteCurrencyId(int $id): self
    {
        $this->setData(self::schema_fields_ID, $id);
        return $this;
    }
    public function getWebsiteCurrencyId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
        return $this;
    }
    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }
    public function setCurrencyCode(string $currencyCode): self
    {
        $this->setData(self::schema_fields_CURRENCY_CODE, $currencyCode);
        return $this;
    }
    public function getCurrencyCode(): string
    {
        return (string)$this->getData(self::schema_fields_CURRENCY_CODE);
    }
    /**
     * 获取网站的所有关联货币代码
     * 
     * @param int $websiteId
     * @return array
     */
    public function getWebsiteCurrencyCodes(int $websiteId): array
    {
        $currencies = $this->clearQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetch()
            ->getItems();
        
        $codes = [];
        foreach ($currencies as $currency) {
            $codes[] = $currency->getCurrencyCode();
        }
        
        return $codes;
    }
    /**
     * 设置网站的关联货币
     * 
     * @param int $websiteId
     * @param array $currencyCodes
     * @return self
     */
    public function setWebsiteCurrencies(int $websiteId, array $currencyCodes): self
    {
        // 先删除旧的关联
        $this->clearQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        
        // 插入新的关联
        if (!empty($currencyCodes)) {
            $data = [];
            foreach ($currencyCodes as $code) {
                if (!empty($code)) {
                    $data[] = [
                        self::schema_fields_WEBSITE_ID => $websiteId,
                        self::schema_fields_CURRENCY_CODE => $code,
                    ];
                }
            }
            if (!empty($data)) {
                $this->clearData(true);
                $this->insert($data)->fetch();
            }
        }

        $this->invalidateWebsiteCurrency($websiteId);

        return $this;
    }

    public function save_before(): void
    {
        parent::save_before();
        $this->invalidationPreviousWebsiteId = null;
        $id = (int)$this->getData(self::schema_fields_ID);
        if ($id > 0) {
            $existing = clone $this;
            $row = $existing->clearData()->clearQuery()
                ->where(self::schema_fields_ID, $id)
                ->find()
                ->fetchArray();
            if (is_array($row) && array_is_list($row)) {
                $row = $row[0] ?? null;
            }
            if (is_array($row) && array_key_exists(self::schema_fields_WEBSITE_ID, $row)) {
                $this->invalidationPreviousWebsiteId = (int)$row[self::schema_fields_WEBSITE_ID];
            }
        }
    }

    public function save_after(): void
    {
        parent::save_after();
        try {
            $websiteId = $this->getWebsiteId();
            $this->invalidateWebsiteCurrency($websiteId);
            if ($this->invalidationPreviousWebsiteId !== null
                && $this->invalidationPreviousWebsiteId !== $websiteId) {
                $this->invalidateWebsiteCurrency($this->invalidationPreviousWebsiteId);
            }
        } finally {
            $this->invalidationPreviousWebsiteId = null;
        }
    }

    public function delete_before(): void
    {
        parent::delete_before();
        $this->invalidationDeletedWebsiteId = $this->hasData(self::schema_fields_WEBSITE_ID)
            ? (int)$this->getData(self::schema_fields_WEBSITE_ID)
            : null;
    }

    public function delete_after(): void
    {
        parent::delete_after();
        try {
            if ($this->invalidationDeletedWebsiteId !== null) {
                $this->invalidateWebsiteCurrency($this->invalidationDeletedWebsiteId);
            }
        } finally {
            $this->invalidationDeletedWebsiteId = null;
        }
    }

    private function invalidateWebsiteCurrency(int $websiteId): void
    {
        ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateWebsite(
            $this->getConnection(),
            $websiteId,
            ['currency'],
        );
    }
}
