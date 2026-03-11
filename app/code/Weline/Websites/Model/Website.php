<?php

namespace Weline\Websites\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '网站表')]
#[Index(name: 'uk_name', columns: ['name'], type: 'UNIQUE')]
#[Index(name: 'uk_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'uk_url', columns: ['url'], type: 'UNIQUE')]
#[Index(name: 'idx_scope', columns: ['scope'])]
class Website extends Model
{
    /** 默认网站代码，底层禁止删除 */
    public const CODE_DEFAULT = 'default';

    public const schema_table = 'weline_websites_website';
    public const schema_primary_key = 'website_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '网站ID')]
    public const schema_fields_ID = 'website_id';
    #[Col('varchar', 128, nullable: false, unique: true, comment: '网站名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 20, nullable: false, unique: true, comment: '网站代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 128, nullable: false, unique: true, comment: '网站链接')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 20, nullable: true, comment: '默认货币')]
    public const schema_fields_DEFAULT_CURRENCY = 'default_currency';
    #[Col('varchar', 20, nullable: true, comment: '默认语言')]
    public const schema_fields_DEFAULT_LANGUAGE = 'default_language';
    #[Col('varchar', 60, nullable: false, comment: '默认时区')]
    public const schema_fields_DEFAULT_TIMEZONE = 'default_timezone';
    #[Col('varchar', 100, nullable: true, default: '', comment: '业务scope标识，如page_builder、catalog等')]
    public const schema_fields_SCOPE = 'scope';


    /**
     * 删除前：默认网站不允许删除（底层强制）
     */
    public function delete_before(): void
    {
        parent::delete_before();
        $code = $this->getData(self::schema_fields_CODE);
        if ($code === '' || $code === null) {
            $id = $this->getWebsiteId();
            if ($id > 0) {
                $one = ObjectManager::getInstance(self::class, [], false);
                $one->clearQuery()->where(self::schema_fields_ID, $id)->find()->fetch();
                $code = $one->getData(self::schema_fields_CODE);
            }
        }
        if ($code === self::CODE_DEFAULT) {
            throw new \RuntimeException(__('默认网站不允许删除'));
        }
    }

    /**
     * 保存前处理URL
     * 自动添加协议前缀：如果URL不以 http:// 或 https:// 开头，自动添加 http://
     */
    public function save_before(): void
    {
        parent::save_before();
        
        $url = $this->getData(self::schema_fields_URL);
        if (!empty($url) && is_string($url)) {
            $url = trim($url);
            if (!preg_match('/^https?:\/\//i', $url)) {
                $this->setData(self::schema_fields_URL, 'http://' . $url);
            }
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
            w_cache('website')->clear();
        } catch (\Throwable $e) {
            // 缓存清除失败，静默处理
        }
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::schema_fields_ID, $websiteId);
        return $this;
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setName(string $name): self
    {
        $this->setData(self::schema_fields_NAME, $name);
        return $this;
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::schema_fields_CODE, $code);
        return $this;
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }

    public function setUrl(string $url): self
    {
        // 自动添加协议前缀：如果URL不以 http:// 或 https:// 开头，自动添加 http://
        $url = trim($url);
        if (!empty($url) && !preg_match('/^https?:\/\//i', $url)) {
            $url = 'http://' . $url;
        }
        $this->setData(self::schema_fields_URL, $url);
        return $this;
    }

    public function getUrl(): string
    {
        return (string)$this->getData(self::schema_fields_URL);
    }

    public function setDefaultCurrency(?string $currency): self
    {
        $this->setData(self::schema_fields_DEFAULT_CURRENCY, $currency);
        return $this;
    }

    public function getDefaultCurrency(): ?string
    {
        $currency = $this->getData(self::schema_fields_DEFAULT_CURRENCY);
        return $currency ? (string)$currency : null;
    }

    public function setDefaultLanguage(?string $language): self
    {
        $this->setData(self::schema_fields_DEFAULT_LANGUAGE, $language);
        return $this;
    }

    public function getDefaultLanguage(): ?string
    {
        $language = $this->getData(self::schema_fields_DEFAULT_LANGUAGE);
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
        $this->setData(self::schema_fields_DEFAULT_TIMEZONE, $timezone);
        return $this;
    }

    public function getDefaultTimezone(): string
    {
        return (string)$this->getData(self::schema_fields_DEFAULT_TIMEZONE);
    }

    /**
     * 设置业务范围标识
     * 
     * @param string $scope 业务范围标识，如 page_builder、catalog 等
     * @return self
     */
    public function setScope(string $scope): self
    {
        $this->setData(self::schema_fields_SCOPE, $scope);
        return $this;
    }

    /**
     * 获取业务范围标识
     * 
     * @return string
     */
    public function getScope(): string
    {
        return (string)$this->getData(self::schema_fields_SCOPE);
    }
}