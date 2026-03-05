<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
/** 站点-SEO账户关联模型 - 管理站点与SEO账户绑定、sitemap自动提交 */
#[Table(comment: '站点SEO账户关联表')]
#[Index(name: 'unique_website_account', columns: ['website_id', 'account_id'], type: 'UNIQUE')]
#[Index(name: 'idx_website', columns: ['website_id'])]
#[Index(name: 'idx_account', columns: ['account_id'])]
#[Index(name: 'idx_auto_submit', columns: ['is_auto_submit'])]
class SeoWebsiteAccount extends Model
{

    public const schema_table = 'weline_seo_website_account';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '关联ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 0, nullable: false, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 0, nullable: false, comment: 'SEO账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否自动提交sitemap')]
    public const schema_fields_IS_AUTO_SUBMIT = 'is_auto_submit';
    #[Col('varchar', 20, nullable: false, default: 'daily', comment: 'Sitemap生成频率')]
    public const schema_fields_SITEMAP_FREQUENCY = 'sitemap_frequency';
    #[Col('varchar', 20, nullable: false, default: 'weekly', comment: '抓取频率')]
    public const schema_fields_CRAWL_FREQUENCY = 'crawl_frequency';
    #[Col('decimal', '3,2', nullable: false, default: 0.50, comment: 'URL优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('text', comment: '其他配置JSON')]
    public const schema_fields_CONFIG_JSON = 'config_json';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    
    /**
     * Sitemap 生成频率常量
     */
    public const FREQUENCY_REALTIME = 'realtime';   // 实时
    public const FREQUENCY_HOURLY = 'hourly';       // 每小时
    public const FREQUENCY_DAILY = 'daily';         // 每天
    public const FREQUENCY_WEEKLY = 'weekly';       // 每周
    public const FREQUENCY_MONTHLY = 'monthly';     // 每月
    public const FREQUENCY_MANUAL = 'manual';       // 手动
    
    /**
     * 抓取频率常量（Google changefreq）
     */
    public const CRAWL_ALWAYS = 'always';
    public const CRAWL_HOURLY = 'hourly';
    public const CRAWL_DAILY = 'daily';
    public const CRAWL_WEEKLY = 'weekly';
    public const CRAWL_MONTHLY = 'monthly';
    public const CRAWL_YEARLY = 'yearly';
    public const CRAWL_NEVER = 'never';
    
    /**
     * 默认配置
     */
    public const DEFAULT_SITEMAP_FREQUENCY = self::FREQUENCY_DAILY;
    public const DEFAULT_CRAWL_FREQUENCY = self::CRAWL_WEEKLY;
    public const DEFAULT_PRIORITY = 0.5;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
/**
     * 获取站点ID
     */
    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    /**
     * 获取账户ID
     */
    public function getAccountId(): int
    {
        return (int)$this->getData(self::schema_fields_ACCOUNT_ID);
    }

    /**
     * 是否启用自动提交
     */
    public function isAutoSubmitEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_AUTO_SUBMIT) === 1;
    }

    /**
     * 根据站点ID获取所有绑定信息（支持多个平台）
     * 
     * @return array
     */
    public function getByWebsiteId(int $websiteId): array
    {
        return $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
    }

    /**
     * 根据站点ID和账户ID获取唯一绑定
     */
    public function getByWebsiteAndAccount(int $websiteId, int $accountId): ?self
    {
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->find()
            ->fetch();
        
        return $this->getId() ? $this : null;
    }

    /**
     * 绑定站点与账户
     * 
     * @param int $websiteId 站点ID
     * @param int $accountId SEO账户ID
     * @param array $config 配置参数
     *   - is_auto_submit: bool 是否自动提交
     *   - sitemap_frequency: string Sitemap生成频率
     *   - crawl_frequency: string 抓取频率
     *   - priority: float URL优先级
     *   - config: array 其他配置
     * @return self
     */
    public function bindWebsiteAccount(int $websiteId, int $accountId, array $config = []): self
    {
        $existing = $this->getByWebsiteAndAccount($websiteId, $accountId);
        
        // 准备数据（使用默认值）
        $data = [
            self::schema_fields_IS_AUTO_SUBMIT => isset($config['is_auto_submit']) ? ($config['is_auto_submit'] ? 1 : 0) : 1,
            self::schema_fields_SITEMAP_FREQUENCY => $config['sitemap_frequency'] ?? self::DEFAULT_SITEMAP_FREQUENCY,
            self::schema_fields_CRAWL_FREQUENCY => $config['crawl_frequency'] ?? self::DEFAULT_CRAWL_FREQUENCY,
            self::schema_fields_PRIORITY => $config['priority'] ?? self::DEFAULT_PRIORITY,
        ];
        
        // 处理额外配置
        if (isset($config['config']) && is_array($config['config'])) {
            $data[self::schema_fields_CONFIG_JSON] = json_encode($config['config'], JSON_UNESCAPED_UNICODE);
        }
        
        if ($existing) {
            $existing->setData($data)->save();
            return $existing;
        }
        
        $data[self::schema_fields_WEBSITE_ID] = $websiteId;
        $data[self::schema_fields_ACCOUNT_ID] = $accountId;
        
        $this->reset()->setData($data)->save();
        
        return $this;
    }

    /**
     * 解绑站点与账户
     */
    public function unbindWebsiteAccount(int $websiteId, int $accountId): bool
    {
        // 先查询并fetch数据
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->find()
            ->fetch();
        
        // 如果找到记录，删除它
        if ($this->getId()) {
            // 必须调用 fetch() 来执行 DELETE SQL
            $this->delete()->fetch();
            return true;
        }
        
        return false;
    }

    /**
     * 获取站点绑定的所有平台代码
     * 
     * @param int $websiteId
     * @return string[] 平台代码数组 ['google', 'bing', ...]
     */
    public function getWebsitePlatforms(int $websiteId): array
    {
        $bindings = $this->getByWebsiteId($websiteId);
        $platforms = [];
        
        if (empty($bindings)) {
            return [];
        }
        
        $seoAccountModel = ObjectManager::getInstance(SeoAccount::class);
        
        foreach ($bindings as $binding) {
            $accountId = (int)($binding[self::schema_fields_ACCOUNT_ID] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            
            $account = $seoAccountModel->reset()->load($accountId);
            if (!$account->getId() || !$account->isActive()) {
                continue;
            }
            
            $platform = $account->getPlatform();
            if ($platform && !in_array($platform, $platforms)) {
                $platforms[] = $platform;
            }
        }
        
        return $platforms;
    }

    /**
     * 获取所有启用自动提交的绑定
     */
    public function getAutoSubmitBindings(): array
    {
        return $this->reset()
            ->where(self::schema_fields_IS_AUTO_SUBMIT, 1)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取 Sitemap 生成频率
     */
    public function getSitemapFrequency(): string
    {
        return $this->getData(self::schema_fields_SITEMAP_FREQUENCY) ?: self::DEFAULT_SITEMAP_FREQUENCY;
    }

    /**
     * 设置 Sitemap 生成频率
     */
    public function setSitemapFrequency(string $frequency): self
    {
        $this->setData(self::schema_fields_SITEMAP_FREQUENCY, $frequency);
        return $this;
    }

    /**
     * 获取抓取频率
     */
    public function getCrawlFrequency(): string
    {
        return $this->getData(self::schema_fields_CRAWL_FREQUENCY) ?: self::DEFAULT_CRAWL_FREQUENCY;
    }

    /**
     * 设置抓取频率
     */
    public function setCrawlFrequency(string $frequency): self
    {
        $this->setData(self::schema_fields_CRAWL_FREQUENCY, $frequency);
        return $this;
    }

    /**
     * 获取优先级
     */
    public function getPriority(): float
    {
        $priority = $this->getData(self::schema_fields_PRIORITY);
        return $priority !== null ? (float)$priority : self::DEFAULT_PRIORITY;
    }

    /**
     * 设置优先级
     */
    public function setPriority(float $priority): self
    {
        // 限制在 0.0 - 1.0 之间
        $priority = max(0.0, min(1.0, $priority));
        $this->setData(self::schema_fields_PRIORITY, $priority);
        return $this;
    }

    /**
     * 获取额外配置
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG_JSON);
        if (is_string($config) && !empty($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 设置额外配置
     */
    public function setConfig(array $config): self
    {
        $this->setData(self::schema_fields_CONFIG_JSON, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取所有可用的 Sitemap 生成频率选项
     */
    public static function getSitemapFrequencyOptions(): array
    {
        return [
            self::FREQUENCY_REALTIME => '实时',
            self::FREQUENCY_HOURLY => '每小时',
            self::FREQUENCY_DAILY => '每天',
            self::FREQUENCY_WEEKLY => '每周',
            self::FREQUENCY_MONTHLY => '每月',
            self::FREQUENCY_MANUAL => '手动',
        ];
    }

    /**
     * 获取所有可用的抓取频率选项
     */
    public static function getCrawlFrequencyOptions(): array
    {
        return [
            self::CRAWL_ALWAYS => '总是（Always）',
            self::CRAWL_HOURLY => '每小时（Hourly）',
            self::CRAWL_DAILY => '每天（Daily）',
            self::CRAWL_WEEKLY => '每周（Weekly）',
            self::CRAWL_MONTHLY => '每月（Monthly）',
            self::CRAWL_YEARLY => '每年（Yearly）',
            self::CRAWL_NEVER => '从不（Never）',
        ];
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}

