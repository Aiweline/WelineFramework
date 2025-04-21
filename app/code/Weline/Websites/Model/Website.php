<?php

namespace Weline\Websites\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Website extends Model
{

    public const fields_ID = 'website_id';
    # 名称
    public const fields_NAME = 'name';
    # 代码
    public const fields_CODE = 'code';
    # 链接
    public const fields_URL = 'url';
    # 货币
    public const fields_DEFAULT_CURRENCY = 'default_currency';
    # 语言
    public const fields_DEFAULT_LANGUAGE = 'default_language';
    # 时区
    public const fields_DEFAULT_TIMEZONE = 'default_timezone';


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
        $setup->createTable('网站表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '网站ID')
            ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 128, "not null unique", '网站名称')
            ->addColumn(self::fields_CODE, TableInterface::column_type_VARCHAR, 20, "not null unique", '网站代码')
            ->addColumn(self::fields_URL, TableInterface::column_type_VARCHAR, 128, "not null unique", '网站链接')
            ->addColumn(self::fields_DEFAULT_CURRENCY, TableInterface::column_type_VARCHAR, 20, "not null", '默认货币')
            ->addColumn(self::fields_DEFAULT_LANGUAGE, TableInterface::column_type_VARCHAR, 20, "not null", '默认语言')
            ->addColumn(self::fields_DEFAULT_TIMEZONE, TableInterface::column_type_VARCHAR, 60, "not null", '默认时区')
            ->create();
        # 新建一个默认网站
        try {
            $this->setWebsiteId(1)
                ->setName('默认网站')
                ->setCode('default')
                ->setUrl('http://localhost')
                ->setDefaultCurrency('CNY')
                ->setDefaultLanguage('zh_Hans_CN')
                ->setDefaultTimezone('Asia/Shanghai')
                ->save(true);
        } catch (Exception $e) {

        }
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::fields_ID, $websiteId);
        return $this;
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    public function setName(string $name): self
    {
        $this->setData(self::fields_NAME, $name);
        return $this;
    }

    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::fields_CODE, $code);
        return $this;
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::fields_CODE);
    }

    public function setUrl(string $url): self
    {
        $this->setData(self::fields_URL, $url);
        return $this;
    }

    public function getUrl(): string
    {
        return (string)$this->getData(self::fields_URL);
    }

    public function setDefaultCurrency(string $currency): self
    {
        $this->setData(self::fields_DEFAULT_CURRENCY, $currency);
        return $this;
    }

    public function getDefaultCurrency(): string
    {
        return (string)$this->getData(self::fields_DEFAULT_CURRENCY);
    }

    public function setDefaultLanguage(string $language): self
    {
        $this->setData(self::fields_DEFAULT_LANGUAGE, $language);
        return $this;
    }

    public function getDefaultLanguage(): string
    {
        return (string)$this->getData(self::fields_DEFAULT_LANGUAGE);
    }

    public function setDefaultTimezone(string $timezone): self
    {
        $this->setData(self::fields_DEFAULT_TIMEZONE, $timezone);
        return $this;
    }

    public function getDefaultTimezone(): string
    {
        return (string)$this->getData(self::fields_DEFAULT_TIMEZONE);
    }
}