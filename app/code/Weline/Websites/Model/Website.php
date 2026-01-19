<?php

namespace Weline\Websites\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
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
        // 修改默认货币和默认语言字段为可空
        if ($setup->tableExist()) {
            try {
                $tableName = $setup->getTable();
                $connection = $setup->getConnection();
                
                // 检查并修改default_currency字段
                try {
                    $connection->query("ALTER TABLE `{$tableName}` MODIFY COLUMN `default_currency` VARCHAR(20) NULL COMMENT '默认货币'");
                } catch (\Exception $e) {
                    // 字段可能已经修改过或不存在，忽略错误
                }
                
                // 检查并修改default_language字段
                try {
                    $connection->query("ALTER TABLE `{$tableName}` MODIFY COLUMN `default_language` VARCHAR(20) NULL COMMENT '默认语言'");
                } catch (\Exception $e) {
                    // 字段可能已经修改过或不存在，忽略错误
                }
            } catch (\Exception $e) {
                // 忽略错误，可能表不存在或其他问题
            }
        }
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
            ->addColumn(self::fields_DEFAULT_CURRENCY, TableInterface::column_type_VARCHAR, 20, "", '默认货币')
            ->addColumn(self::fields_DEFAULT_LANGUAGE, TableInterface::column_type_VARCHAR, 20, "", '默认语言')
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

    /**
     * 保存后清除网站缓存
     * 当网站数据更新时，清除缓存的网站列表，确保下次请求时重新加载最新数据
     */
    public function save_after()
    {
        parent::save_after();
        // 清除网站缓存
        try {
            $websiteCache = new \Weline\Websites\Cache\WebsiteCache();
            $websiteCache->clear();
        } catch (\Throwable $e) {
            // 缓存清除失败，静默处理
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

    public function setDefaultCurrency(?string $currency): self
    {
        $this->setData(self::fields_DEFAULT_CURRENCY, $currency);
        return $this;
    }

    public function getDefaultCurrency(): ?string
    {
        $currency = $this->getData(self::fields_DEFAULT_CURRENCY);
        return $currency ? (string)$currency : null;
    }

    public function setDefaultLanguage(?string $language): self
    {
        $this->setData(self::fields_DEFAULT_LANGUAGE, $language);
        return $this;
    }

    public function getDefaultLanguage(): ?string
    {
        $language = $this->getData(self::fields_DEFAULT_LANGUAGE);
        return $language ? (string)$language : null;
    }

    /**
     * 获取网站的关联货币代码列表
     * 
     * @return array
     */
    public function getCurrencyCodes(): array
    {
        if (!$this->getWebsiteId()) {
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
        if (!$this->getWebsiteId()) {
            return [];
        }
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        return $websiteLanguage->getWebsiteLanguageCodes($this->getWebsiteId());
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