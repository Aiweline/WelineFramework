<?php

namespace WeShop\Store\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Websites\Model\Website;

class Store extends Model
{
    public const table = 'weshop_store';
    public const primary_key = 'store_id';
    public const indexer = 'store_indexer';
    public array $_unit_primary_keys = ['store_id'];
    public array $_index_sort_keys = ['website_id', 'name', 'code', 'status', 'sort_order'];
    
    public const fields_ID = 'store_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_NAME = 'name';
    public const fields_CODE = 'code';
    public const fields_STATUS = 'status';
    public const fields_ADDRESS = 'address';
    public const fields_PHONE = 'phone';
    public const fields_EMAIL = 'email';
    public const fields_WEBSITE = 'website';
    public const fields_OPENING_HOURS = 'opening_hours';
    public const fields_CLOSING_HOURS = 'closing_hours';
    public const fields_DESCRIPTION = 'description';
    public const fields_IMAGE = 'image';
    public const fields_LATITUDE = 'latitude';
    public const fields_LONGITUDE = 'longitude';
    public const fields_LOCAL = 'local';
    public const fields_CURRENCY = 'currency'; # 默认货币
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
    public const fields_SORT_ORDER = 'sort_order';

    // 状态常量
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;
    
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('店铺表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '店铺ID'
                )
                ->addColumn(
                    self::fields_WEBSITE_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '网站ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'unique',
                    '店铺名称'
                )
                ->addColumn(
                    self::fields_CODE,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'unique',
                    '店铺代码'
                )
                ->addColumn(
                    self::fields_OPENING_HOURS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '营业时间',
                )
                ->addColumn(
                    self::fields_LATITUDE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '纬度',
                )
                ->addColumn(
                    self::fields_LONGITUDE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '经度',
                )
                ->addColumn(
                    self::fields_ADDRESS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '地址',
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '描述',
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '状态',
                )
                ->addColumn(
                    self::fields_PHONE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '电话',
                )
                ->addColumn(
                    self::fields_EMAIL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '邮箱',
                )
                ->addColumn(
                    self::fields_CLOSING_HOURS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '关闭时间',
                )
                ->addColumn(
                    self::fields_WEBSITE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'unique',
                    '网站',
                )
                ->addColumn(
                    self::fields_IMAGE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '图片',
                )
                ->addColumn(
                    self::fields_LOCAL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '默认区域码',
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '货币',
                )
                ->addColumn(
                    self::fields_META_TITLE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    "default ''",
                    'Meta标题'
                )
                ->addColumn(
                    self::fields_META_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Meta描述'
                )
                ->addColumn(
                    self::fields_META_KEYWORDS,
                    TableInterface::column_type_VARCHAR,
                    500,
                    "default ''",
                    'Meta关键词'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '排序'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_website_id',
                    self::fields_WEBSITE_ID,
                    '网站ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '状态索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加新字段
        if (!$setup->hasField(self::fields_META_TITLE)) {
            $setup->alterTable()
                ->addColumn(self::fields_META_TITLE, '', TableInterface::column_type_VARCHAR, 255, "default ''", 'Meta标题')
                ->alter();
        }
        if (!$setup->hasField(self::fields_META_DESCRIPTION)) {
            $setup->alterTable()
                ->addColumn(self::fields_META_DESCRIPTION, '', TableInterface::column_type_TEXT, 0, '', 'Meta描述')
                ->alter();
        }
        if (!$setup->hasField(self::fields_META_KEYWORDS)) {
            $setup->alterTable()
                ->addColumn(self::fields_META_KEYWORDS, '', TableInterface::column_type_VARCHAR, 500, "default ''", 'Meta关键词')
                ->alter();
        }
        if (!$setup->hasField(self::fields_SORT_ORDER)) {
            $setup->alterTable()
                ->addColumn(self::fields_SORT_ORDER, '', TableInterface::column_type_INTEGER, 11, 'default 0', '排序')
                ->alter();
        }
    }

    /**
     * 保存后触发事件
     */
    public function save_after(): void
    {
        parent::save_after();
        $data = [
            'store' => $this,
            'store_id' => $this->getId()
        ];
        $this->getEventManager()->dispatch('WeShop_Store::store_save_after', $data);
    }

    /**
     * 删除后触发事件
     */
    public function delete_after(): void
    {
        parent::delete_after();
        $data = [
            'store_id' => $this->getOriginData(self::fields_ID)
        ];
        $this->getEventManager()->dispatch('WeShop_Store::store_delete_after', $data);
    }

    /**
     * 获取事件管理器
     */
    protected function getEventManager(): \Weline\Framework\Event\EventsManager
    {
        return ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
    }

    // ===== Getters and Setters =====

    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::fields_CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_CODE, $code);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::fields_WEBSITE_ID, $websiteId);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::fields_STATUS);
    }

    public function setStatus(int $status): static
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function isEnabled(): bool
    {
        return $this->getStatus() === self::STATUS_ENABLED;
    }

    public function getLocal(): string
    {
        return (string)$this->getData(self::fields_LOCAL);
    }

    public function setLocal(string $local): static
    {
        return $this->setData(self::fields_LOCAL, $local);
    }

    public function getCurrency(): string
    {
        return (string)$this->getData(self::fields_CURRENCY);
    }

    public function setCurrency(string $currency): static
    {
        return $this->setData(self::fields_CURRENCY, $currency);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }

    public function getAddress(): string
    {
        return (string)$this->getData(self::fields_ADDRESS);
    }

    public function getPhone(): string
    {
        return (string)$this->getData(self::fields_PHONE);
    }

    public function getEmail(): string
    {
        return (string)$this->getData(self::fields_EMAIL);
    }

    public function getImage(): string
    {
        return (string)$this->getData(self::fields_IMAGE);
    }

    /**
     * 获取关联的网站模型
     */
    public function getWebsite(): ?Website
    {
        $websiteId = $this->getWebsiteId();
        if ($websiteId <= 0) {
            return null;
        }
        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class);
        $website->load($websiteId);
        return $website->getId() ? $website : null;
    }

    /**
     * 获取启用的店铺列表
     */
    public function getEnabledStores(): array
    {
        return $this->reset()
            ->where(self::fields_STATUS, self::STATUS_ENABLED)
            ->order(self::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 根据网站ID获取店铺列表
     */
    public function getStoresByWebsiteId(int $websiteId): array
    {
        return $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, self::STATUS_ENABLED)
            ->order(self::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
}
