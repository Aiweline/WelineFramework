<?php

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class WebsiteCurrency extends Model
{
    public const fields_ID = 'website_currency_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_CURRENCY_CODE = 'currency_code';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('网站-货币关联表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '关联ID')
            ->addColumn(self::fields_WEBSITE_ID, TableInterface::column_type_INTEGER, 11, 'not null', '网站ID')
            ->addColumn(self::fields_CURRENCY_CODE, TableInterface::column_type_VARCHAR, 3, 'not null', '货币代码')
            ->addIndex(TableInterface::index_type_KEY, 'idx_website_id', self::fields_WEBSITE_ID, '网站ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_currency_code', self::fields_CURRENCY_CODE, '货币代码索引')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_website_currency', [self::fields_WEBSITE_ID, self::fields_CURRENCY_CODE], '网站货币唯一索引')
            ->create();
    }

    public function setWebsiteCurrencyId(int $id): self
    {
        $this->setData(self::fields_ID, $id);
        return $this;
    }

    public function getWebsiteCurrencyId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::fields_WEBSITE_ID, $websiteId);
        return $this;
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_WEBSITE_ID);
    }

    public function setCurrencyCode(string $currencyCode): self
    {
        $this->setData(self::fields_CURRENCY_CODE, $currencyCode);
        return $this;
    }

    public function getCurrencyCode(): string
    {
        return (string)$this->getData(self::fields_CURRENCY_CODE);
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
            ->where(self::fields_WEBSITE_ID, $websiteId)
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
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        
        // 插入新的关联
        if (!empty($currencyCodes)) {
            $data = [];
            foreach ($currencyCodes as $code) {
                if (!empty($code)) {
                    $data[] = [
                        self::fields_WEBSITE_ID => $websiteId,
                        self::fields_CURRENCY_CODE => $code,
                    ];
                }
            }
            if (!empty($data)) {
                $this->clearQuery()->insert($data)->fetch();
            }
        }
        
        return $this;
    }
}

