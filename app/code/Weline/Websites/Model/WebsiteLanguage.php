<?php

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class WebsiteLanguage extends Model
{
    public const fields_ID = 'website_language_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_LANGUAGE_CODE = 'language_code';

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
        $setup->createTable('网站-语言关联表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '关联ID')
            ->addColumn(self::fields_WEBSITE_ID, TableInterface::column_type_INTEGER, 11, 'not null', '网站ID')
            ->addColumn(self::fields_LANGUAGE_CODE, TableInterface::column_type_VARCHAR, 20, 'not null', '语言代码')
            ->addIndex(TableInterface::index_type_KEY, 'idx_website_id', self::fields_WEBSITE_ID, '网站ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_language_code', self::fields_LANGUAGE_CODE, '语言代码索引')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_website_language', [self::fields_WEBSITE_ID, self::fields_LANGUAGE_CODE], '网站语言唯一索引')
            ->create();
    }

    public function setWebsiteLanguageId(int $id): self
    {
        $this->setData(self::fields_ID, $id);
        return $this;
    }

    public function getWebsiteLanguageId(): int
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

    public function setLanguageCode(string $languageCode): self
    {
        $this->setData(self::fields_LANGUAGE_CODE, $languageCode);
        return $this;
    }

    public function getLanguageCode(): string
    {
        return (string)$this->getData(self::fields_LANGUAGE_CODE);
    }

    /**
     * 获取网站的所有关联语言代码
     * 
     * @param int $websiteId
     * @return array
     */
    public function getWebsiteLanguageCodes(int $websiteId): array
    {
        $languages = $this->clearQuery()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetch()
            ->getItems();
        
        $codes = [];
        foreach ($languages as $language) {
            $codes[] = $language->getLanguageCode();
        }
        
        return $codes;
    }

    /**
     * 设置网站的关联语言
     * 
     * @param int $websiteId
     * @param array $languageCodes
     * @return self
     */
    public function setWebsiteLanguages(int $websiteId, array $languageCodes): self
    {
        // 先删除旧的关联
        $this->clearQuery()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        
        // 插入新的关联
        if (!empty($languageCodes)) {
            $data = [];
            foreach ($languageCodes as $code) {
                if (!empty($code)) {
                    $data[] = [
                        self::fields_WEBSITE_ID => $websiteId,
                        self::fields_LANGUAGE_CODE => $code,
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

