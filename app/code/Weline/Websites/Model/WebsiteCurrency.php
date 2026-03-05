<?php
namespace Weline\Websites\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '网站-货币关联表')]
#[Index(name: 'idx_website_id', columns: ['website_id'], comment: '网站ID索引')]
#[Index(name: 'idx_currency_code', columns: ['currency_code'], comment: '货币代码索引')]
#[Index(name: 'uk_website_currency', columns: ['website_id', 'currency_code'], type: 'UNIQUE', comment: '网站货币唯一索引')]
class WebsiteCurrency extends Model
{
    public const schema_table = 'weline_websites_website_currency';
    public const schema_primary_key = 'website_currency_id';

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
        
        return $this;
    }
}
