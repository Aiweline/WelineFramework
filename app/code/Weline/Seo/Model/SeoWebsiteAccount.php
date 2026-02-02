<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 站点-SEO账户关联模型
 *
 * 用于管理站点与SEO账户的绑定关系，
 * 控制站点是否自动提交sitemap到搜索引擎。
 *
 * @package Weline_Seo
 */
class SeoWebsiteAccount extends Model
{
    public const table = 'weline_seo_website_account';

    /**
     * Primary key
     */
    public string $_primary_key = 'id';

    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];

    /**
     * 字段常量
     */
    public const fields_ID = 'id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_IS_AUTO_SUBMIT = 'is_auto_submit';
    public const fields_SITEMAP_FREQUENCY = 'sitemap_frequency';
    public const fields_CRAWL_FREQUENCY = 'crawl_frequency';
    public const fields_PRIORITY = 'priority';
    public const fields_CONFIG_JSON = 'config_json';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
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
        return self::fields_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('站点SEO账户关联表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '关联ID'
                )
                ->addColumn(
                    self::fields_WEBSITE_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '站点ID'
                )
                ->addColumn(
                    self::fields_ACCOUNT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    'SEO账户ID'
                )
                ->addColumn(
                    self::fields_IS_AUTO_SUBMIT,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否自动提交sitemap：1是，0否'
                )
                ->addColumn(
                    self::fields_SITEMAP_FREQUENCY,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "daily"',
                    'Sitemap生成频率：realtime/hourly/daily/weekly/monthly/manual'
                )
                ->addColumn(
                    self::fields_CRAWL_FREQUENCY,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "weekly"',
                    '抓取频率（changefreq）：always/hourly/daily/weekly/monthly/yearly/never'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_DECIMAL,
                    '3,2',
                    'default 0.50',
                    'URL优先级（0.0-1.0）'
                )
                ->addColumn(
                    self::fields_CONFIG_JSON,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '其他配置（JSON格式）'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'unique_website_account',
                    [self::fields_WEBSITE_ID, self::fields_ACCOUNT_ID],
                    '站点-账户组合唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_website',
                    [self::fields_WEBSITE_ID],
                    '站点索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_account',
                    [self::fields_ACCOUNT_ID],
                    '账户索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_auto_submit',
                    [self::fields_IS_AUTO_SUBMIT],
                    '自动提交索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            return;
        }

        // 修改唯一索引：从 website_id 改为 (website_id, account_id)，支持多平台绑定（v1.0.1）
        if ($setup->hasIndex('unique_website')) {
            $setup->alterTable()
                ->dropIndex('unique_website')
                ->alter();
        }
        
        if (!$setup->hasIndex('unique_website_account')) {
            $setup->alterTable()
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'unique_website_account',
                    [self::fields_WEBSITE_ID, self::fields_ACCOUNT_ID],
                    '站点-账户组合唯一索引'
                )
                ->alter();
        }
        
        if (!$setup->hasIndex('idx_website')) {
            $setup->alterTable()
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_website',
                    [self::fields_WEBSITE_ID],
                    '站点索引'
                )
                ->alter();
        }
        
        // 添加频率和配置字段（v1.0.2）
        if (!$setup->hasField(self::fields_SITEMAP_FREQUENCY)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_SITEMAP_FREQUENCY,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "daily"',
                    'Sitemap生成频率：realtime/hourly/daily/weekly/monthly/manual'
                )
                ->alter();
        }
        
        if (!$setup->hasField(self::fields_CRAWL_FREQUENCY)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_CRAWL_FREQUENCY,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "weekly"',
                    '抓取频率（changefreq）：always/hourly/daily/weekly/monthly/yearly/never'
                )
                ->alter();
        }
        
        if (!$setup->hasField(self::fields_PRIORITY)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_DECIMAL,
                    '3,2',
                    'default 0.50',
                    'URL优先级（0.0-1.0）'
                )
                ->alter();
        }
        
        if (!$setup->hasField(self::fields_CONFIG_JSON)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_CONFIG_JSON,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '其他配置（JSON格式）'
                )
                ->alter();
        }
    }

    /**
     * 获取站点ID
     */
    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_WEBSITE_ID);
    }

    /**
     * 获取账户ID
     */
    public function getAccountId(): int
    {
        return (int)$this->getData(self::fields_ACCOUNT_ID);
    }

    /**
     * 是否启用自动提交
     */
    public function isAutoSubmitEnabled(): bool
    {
        return (int)$this->getData(self::fields_IS_AUTO_SUBMIT) === 1;
    }

    /**
     * 根据站点ID获取所有绑定信息（支持多个平台）
     * 
     * @return array
     */
    public function getByWebsiteId(int $websiteId): array
    {
        return $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
    }

    /**
     * 根据站点ID和账户ID获取唯一绑定
     */
    public function getByWebsiteAndAccount(int $websiteId, int $accountId): ?self
    {
        $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_ACCOUNT_ID, $accountId)
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
            self::fields_IS_AUTO_SUBMIT => isset($config['is_auto_submit']) ? ($config['is_auto_submit'] ? 1 : 0) : 1,
            self::fields_SITEMAP_FREQUENCY => $config['sitemap_frequency'] ?? self::DEFAULT_SITEMAP_FREQUENCY,
            self::fields_CRAWL_FREQUENCY => $config['crawl_frequency'] ?? self::DEFAULT_CRAWL_FREQUENCY,
            self::fields_PRIORITY => $config['priority'] ?? self::DEFAULT_PRIORITY,
        ];
        
        // 处理额外配置
        if (isset($config['config']) && is_array($config['config'])) {
            $data[self::fields_CONFIG_JSON] = json_encode($config['config'], JSON_UNESCAPED_UNICODE);
        }
        
        if ($existing) {
            $existing->setData($data)->save();
            return $existing;
        }
        
        $data[self::fields_WEBSITE_ID] = $websiteId;
        $data[self::fields_ACCOUNT_ID] = $accountId;
        
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
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_ACCOUNT_ID, $accountId)
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
            $accountId = (int)($binding[self::fields_ACCOUNT_ID] ?? 0);
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
            ->where(self::fields_IS_AUTO_SUBMIT, 1)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取 Sitemap 生成频率
     */
    public function getSitemapFrequency(): string
    {
        return $this->getData(self::fields_SITEMAP_FREQUENCY) ?: self::DEFAULT_SITEMAP_FREQUENCY;
    }

    /**
     * 设置 Sitemap 生成频率
     */
    public function setSitemapFrequency(string $frequency): self
    {
        $this->setData(self::fields_SITEMAP_FREQUENCY, $frequency);
        return $this;
    }

    /**
     * 获取抓取频率
     */
    public function getCrawlFrequency(): string
    {
        return $this->getData(self::fields_CRAWL_FREQUENCY) ?: self::DEFAULT_CRAWL_FREQUENCY;
    }

    /**
     * 设置抓取频率
     */
    public function setCrawlFrequency(string $frequency): self
    {
        $this->setData(self::fields_CRAWL_FREQUENCY, $frequency);
        return $this;
    }

    /**
     * 获取优先级
     */
    public function getPriority(): float
    {
        $priority = $this->getData(self::fields_PRIORITY);
        return $priority !== null ? (float)$priority : self::DEFAULT_PRIORITY;
    }

    /**
     * 设置优先级
     */
    public function setPriority(float $priority): self
    {
        // 限制在 0.0 - 1.0 之间
        $priority = max(0.0, min(1.0, $priority));
        $this->setData(self::fields_PRIORITY, $priority);
        return $this;
    }

    /**
     * 获取额外配置
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG_JSON);
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
        $this->setData(self::fields_CONFIG_JSON, json_encode($config, JSON_UNESCAPED_UNICODE));
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
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}
