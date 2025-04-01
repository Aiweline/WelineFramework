<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Pixel extends Model
{

    public const fields_ID = 'pixel_id';
    public const fields_URL = 'url';
    public const fields_MODULE = 'module';
    public const fields_NAME = 'name';
    public const fields_REFERER = 'referer';
    public const fields_SOURCE = 'source';
    public const fields_USER_ID = 'user_id';
    public const fields_USER_AGENT = 'user_agent';
    public const fields_EVENT = 'event';
    # 网站
    public const fields_WEBSITE_ID = 'website_id';
    # 语言
    public const fields_LANG = 'lang';
    # 货币
    public const fields_CURRENCY = 'currency';
    # 价值
    public const fields_VALUE = 'value';

    public const fields_BROWSER_INFO = 'browser_info';
    public const fields_CRON_DEAL = 'cron_deal';

    public static function getUnDeaPixels()
    {
        return obj(self::class)->reset()->where(self::fields_CRON_DEAL, 0)->select()->fetchArray();
    }


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
//        $setup->dropTable();
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('weline 访客像素统计')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_BIGINT,
                0,
                'primary key auto_increment',
                'ID'
            )
            ->addColumn(
                self::fields_URL,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'URL'
            )
            ->addColumn(
                self::fields_MODULE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '模块'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '名称'
            )
            ->addColumn(
                self::fields_REFERER,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'referer来源'
            )
            ->addColumn(
                self::fields_SOURCE,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '来源'
            )
            ->addColumn(
                self::fields_USER_ID,
                TableInterface::column_type_INTEGER,
                0,
                '',
                '用户ID'
            )
            ->addColumn(
                self::fields_USER_AGENT,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '用户代理'
            )
            ->addColumn(
                self::fields_EVENT,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '事件'
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null',
                '网站ID'
            )
            ->addColumn(
                self::fields_LANG,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '语言'
            )
            ->addColumn(
                self::fields_CURRENCY,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '货币'
            )
            ->addColumn(
                self::fields_VALUE,
                TableInterface::column_type_INTEGER,
                0,
                'not null',
                '价值'
            )
            ->addColumn(
                self::fields_BROWSER_INFO,
                TableInterface::column_type_JSON,
                null,
                '',
                '浏览器信息'
            )
            ->addColumn(
                self::fields_CRON_DEAL,
                TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '定时处理：0未处理 1已处理'
            )
            // 事件
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_event',
                self::fields_EVENT,
                '事件名索引'
            )
            // 货币
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_currency',
                self::fields_CURRENCY,
                '货币索引'
            )
            // 语言
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_lang',
                self::fields_LANG,
                '语言索引'
            )
            // 网站
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_website_id',
                self::fields_WEBSITE_ID,
                '网站索引'
            )
            // 模块
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_module',
                self::fields_MODULE,
                '模块索引'
            )
            // 来源
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_source',
                self::fields_SOURCE,
                '来源索引'
            )
            // 定时处理hash索引
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_cron_deal',
                self::fields_CRON_DEAL,
                '定时处理hash索引'
            )
            ->create();
    }

    public function getPixelId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    public function getUrl(): string
    {
        return (string)$this->getData(self::fields_URL);
    }

    public function getModule(): string
    {
        return (string)$this->getData(self::fields_MODULE);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    public function getReferer(): string
    {
        return (string)$this->getData(self::fields_REFERER);
    }

    public function getSource(): string
    {
        return (string)$this->getData(self::fields_SOURCE);
    }

    public function getUserId(): int
    {
        return (int)$this->getData(self::fields_USER_ID);
    }

    public function getUserAgent(): string
    {
        return (string)$this->getData(self::fields_USER_AGENT);
    }

    public function getEvent(): string
    {
        return (string)$this->getData(self::fields_EVENT);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_WEBSITE_ID);
    }

    public function getLang(): string
    {
        return (string)$this->getData(self::fields_LANG);
    }

    public function getCurrency(): string
    {
        return (string)$this->getData(self::fields_CURRENCY);
    }

    public function getValue(): int
    {
        return (int)$this->getData(self::fields_VALUE);
    }

    public function getBrowserInfo(): array
    {
        return (array)$this->getData(self::fields_BROWSER_INFO);
    }

    public function getCronDeal(): int
    {
        return (int)$this->getData(self::fields_CRON_DEAL);
    }

    public function setPixelId(int $pixel_id): static
    {
        return $this->setData(self::fields_ID, $pixel_id);
    }

    public function setUrl(string $url): static
    {
        return $this->setData(self::fields_URL, $url);
    }

    public function setModule(string $module): static
    {
        return $this->setData(self::fields_MODULE, $module);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function setReferer(string $referer): static
    {
        return $this->setData(self::fields_REFERER, $referer);
    }

    public function setSource(string $source): static
    {
        return $this->setData(self::fields_SOURCE, $source);
    }

    public function setUserId(int $user_id): static
    {
        return $this->setData(self::fields_USER_ID, $user_id);
    }

    public function setUserAgent(string $user_agent): static
    {
        return $this->setData(self::fields_USER_AGENT, $user_agent);
    }

    public function setEvent(string $event): static
    {
        return $this->setData(self::fields_EVENT, $event);
    }

    public function setWebsiteId(int $website_id): static
    {
        return $this->setData(self::fields_WEBSITE_ID, $website_id);
    }

    public function setLang(string $lang): static
    {
        return $this->setData(self::fields_LANG, $lang);
    }

    public function setCurrency(string $currency): static
    {
        return $this->setData(self::fields_CURRENCY, $currency);
    }

    public function setValue(int $value): static
    {
        return $this->setData(self::fields_VALUE, $value);
    }

    public function setBrowserInfo(array $browser_info): static
    {
        return $this->setData(self::fields_BROWSER_INFO, $browser_info);
    }

    public function setCronDeal(int $cron_deal): static
    {
        return $this->setData(self::fields_CRON_DEAL, $cron_deal);
    }

}