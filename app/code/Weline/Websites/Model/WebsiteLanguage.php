<?php

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteCacheInvalidationService;
#[Table(comment: '网站-语言关联表')]
#[Index(name: 'idx_website_id', columns: ['website_id'], comment: '网站ID索引')]
#[Index(name: 'idx_language_code', columns: ['language_code'], comment: '语言代码索引')]
#[Index(name: 'uk_website_language', columns: ['website_id', 'language_code'], type: 'UNIQUE', comment: '网站语言唯一索引')]
class WebsiteLanguage extends Model
{
    public const schema_table = 'weline_websites_website_language';
    public const schema_primary_key = 'website_language_id';

    private ?int $invalidationPreviousWebsiteId = null;
    private ?int $invalidationDeletedWebsiteId = null;


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '关联ID')]
    public const schema_fields_ID = 'website_language_id';
    #[Col('int', 11, nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LANGUAGE_CODE = 'language_code';

    public function setWebsiteLanguageId(int $id): self
    {
        $this->setData(self::schema_fields_ID, $id);
        return $this;
    }

    public function getWebsiteLanguageId(): int
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

    public function setLanguageCode(string $languageCode): self
    {
        $this->setData(self::schema_fields_LANGUAGE_CODE, $languageCode);
        return $this;
    }

    public function getLanguageCode(): string
    {
        return (string)$this->getData(self::schema_fields_LANGUAGE_CODE);
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
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
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        
        // 插入新的关联
        if (!empty($languageCodes)) {
            $data = [];
            foreach ($languageCodes as $code) {
                if (!empty($code)) {
                    $data[] = [
                        self::schema_fields_WEBSITE_ID => $websiteId,
                        self::schema_fields_LANGUAGE_CODE => $code,
                    ];
                }
            }
            if (!empty($data)) {
                $this->clearData(true);
                $this->insert($data)->fetch();
            }
        }

        $this->clearWebsiteLanguageCaches($websiteId);

        return $this;
    }

    /**
     * Public cache clear for services that upsert language rows outside Model
     * save/delete hooks (e.g. WebsiteLanguageAssignment::ensureAssigned).
     */
    public function clearWebsiteLanguageCaches(int $websiteId): void
    {
        $this->invalidateWebsiteLanguage($websiteId);
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
            $this->invalidateWebsiteLanguage($websiteId);
            if ($this->invalidationPreviousWebsiteId !== null
                && $this->invalidationPreviousWebsiteId !== $websiteId) {
                $this->invalidateWebsiteLanguage($this->invalidationPreviousWebsiteId);
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
                $this->invalidateWebsiteLanguage($this->invalidationDeletedWebsiteId);
            }
        } finally {
            $this->invalidationDeletedWebsiteId = null;
        }
    }

    private function invalidateWebsiteLanguage(int $websiteId): void
    {
        ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateWebsite(
            $this->getConnection(),
            $websiteId,
            ['language'],
        );
    }
}

