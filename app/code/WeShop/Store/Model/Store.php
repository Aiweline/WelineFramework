<?php

declare(strict_types=1);

namespace WeShop\Store\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

#[Table(comment: '店铺表')]
#[Index(name: 'idx_website_id', columns: ['website_id'], type: 'KEY', comment: '网站ID索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
class Store extends Model
{
    public const schema_table       = 'weshop_store';
    public const schema_primary_key = 'store_id';
    public const indexer            = 'store_indexer';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys   = [
        self::schema_fields_WEBSITE_ID,
        self::schema_fields_NAME,
        self::schema_fields_CODE,
        self::schema_fields_STATUS,
        self::schema_fields_SORT_ORDER,
    ];

    #[Col('int', nullable: false, primaryKey: true, autoIncrement: true, comment: '店铺ID')]
    public const schema_fields_ID = 'store_id';
    #[Col('int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 255, nullable: false, comment: '店铺名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 64, nullable: false, comment: '店铺代号')]
    public const schema_fields_CODE = 'code';
    #[Col('smallint', nullable: false, default: 0, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: '地址')]
    public const schema_fields_ADDRESS = 'address';
    #[Col('varchar', 64, nullable: true, comment: '电话')]
    public const schema_fields_PHONE = 'phone';
    #[Col('varchar', 128, nullable: true, comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col('varchar', 255, nullable: true, comment: '网址')]
    public const schema_fields_WEBSITE = 'website';
    #[Col('varchar', 64, nullable: true, comment: '营业开始时间')]
    public const schema_fields_OPENING_HOURS = 'opening_hours';
    #[Col('varchar', 64, nullable: true, comment: '营业结束时间')]
    public const schema_fields_CLOSING_HOURS = 'closing_hours';
    #[Col('text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 255, nullable: true, comment: '图片')]
    public const schema_fields_IMAGE = 'image';
    #[Col('varchar', 32, nullable: true, comment: '纬度')]
    public const schema_fields_LATITUDE = 'latitude';
    #[Col('varchar', 32, nullable: true, comment: '经度')]
    public const schema_fields_LONGITUDE = 'longitude';
    #[Col('varchar', 32, nullable: true, comment: '语言/地区')]
    public const schema_fields_LOCAL = 'local';
    #[Col('varchar', 16, nullable: true, comment: '货币')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('varchar', 255, nullable: true, comment: 'SEO标题')]
    public const schema_fields_META_TITLE = 'meta_title';
    #[Col('varchar', 512, nullable: true, comment: 'SEO描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col('varchar', 255, nullable: true, comment: 'SEO关键词')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
    #[Col('int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    public const STATUS_ENABLED  = 1;
    public const STATUS_DISABLED = 0;

    public function save_after(): void
    {
        parent::save_after();
        $data = ['store' => $this, 'store_id' => $this->getId()];
        $this->getEventManager()->dispatch('WeShop_Store::store_save_after', $data);
    }

    public function delete_after(): void
    {
        parent::delete_after();
        $data = ['store_id' => $this->getOriginData(self::schema_fields_ID)];
        $this->getEventManager()->dispatch('WeShop_Store::store_delete_after', $data);
    }

    public function getEventManager(): \Weline\Framework\Event\EventsManager
    {
        return ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
    }

    public function getName(): string
    {
        return (string) $this->getData(self::schema_fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::schema_fields_CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getWebsiteId(): int
    {
        return (int) $this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getStatus(): int
    {
        return (int) $this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(int $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function isEnabled(): bool
    {
        return $this->getStatus() === self::STATUS_ENABLED;
    }

    public function getLocal(): string
    {
        return (string) $this->getData(self::schema_fields_LOCAL);
    }

    public function setLocal(string $local): static
    {
        return $this->setData(self::schema_fields_LOCAL, $local);
    }

    public function getCurrency(): string
    {
        return (string) $this->getData(self::schema_fields_CURRENCY);
    }

    public function setCurrency(string $currency): static
    {
        return $this->setData(self::schema_fields_CURRENCY, $currency);
    }

    public function getDescription(): string
    {
        return (string) $this->getData(self::schema_fields_DESCRIPTION);
    }

    public function getAddress(): string
    {
        return (string) $this->getData(self::schema_fields_ADDRESS);
    }

    public function getPhone(): string
    {
        return (string) $this->getData(self::schema_fields_PHONE);
    }

    public function getEmail(): string
    {
        return (string) $this->getData(self::schema_fields_EMAIL);
    }

    public function getImage(): string
    {
        return (string) $this->getData(self::schema_fields_IMAGE);
    }

    public function getWebsite(): ?Website
    {
        $websiteId = $this->getWebsiteId();
        if ($websiteId <= 0) {
            return null;
        }
        $website = ObjectManager::getInstance(Website::class);
        $website->load($websiteId);
        return $website->getId() ? $website : null;
    }

    public function getEnabledStores(): array
    {
        return $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED)
            ->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }

    public function getStoresByWebsiteId(int $websiteId): array
    {
        return $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED)
            ->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
}
