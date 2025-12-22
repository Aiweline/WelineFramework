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
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * SEO 关键词趋势模型
 * 
 * @package Weline_Seo
 */
class SeoKeywordTrend extends Model
{
    public const table = 'weline_seo_keyword_trend';
    public const fields_ID = 'trend_id';
    public const fields_KEYWORD_ID = 'keyword_id';
    public const fields_PLATFORM = 'platform';
    public const fields_TREND_VALUE = 'trend_value';
    public const fields_TREND_DATE = 'trend_date';
    public const fields_REGION = 'region';
    public const fields_CREATED_AT = 'created_at';

    // 平台常量
    public const PLATFORM_GOOGLE = 'google';
    public const PLATFORM_BAIDU = 'baidu';
    public const PLATFORM_BING = 'bing';
    public const PLATFORM_360 = '360';
    public const PLATFORM_SOGOU = 'sogou';
    public const PLATFORM_SHENMA = 'shenma';

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('SEO关键词趋势表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '趋势ID'
                )
                ->addColumn(
                    self::fields_KEYWORD_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '关键词ID'
                )
                ->addColumn(
                    self::fields_PLATFORM,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '平台：google, baidu, bing等'
                )
                ->addColumn(
                    self::fields_TREND_VALUE,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '趋势值（热度）'
                )
                ->addColumn(
                    self::fields_TREND_DATE,
                    TableInterface::column_type_DATE,
                    0,
                    'not null',
                    '趋势日期'
                )
                ->addColumn(
                    self::fields_REGION,
                    TableInterface::column_type_VARCHAR,
                    10,
                    "default 'global'",
                    '地区代码'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_keyword_id',
                    self::fields_KEYWORD_ID,
                    '关键词ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_platform',
                    self::fields_PLATFORM,
                    '平台索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_trend_date',
                    self::fields_TREND_DATE,
                    '趋势日期索引'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_keyword_platform_date',
                    [self::fields_KEYWORD_ID, self::fields_PLATFORM, self::fields_TREND_DATE, self::fields_REGION],
                    '关键词平台日期唯一索引'
                )
                ->create();
        }
    }

    /**
     * 开发模式设置
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级数据表
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 保存前处理
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
    }

    // ===== Getters and Setters =====

    public function getKeywordId(): int
    {
        return (int)$this->getData(self::fields_KEYWORD_ID);
    }

    public function setKeywordId(int $keywordId): self
    {
        return $this->setData(self::fields_KEYWORD_ID, $keywordId);
    }

    public function getPlatform(): string
    {
        return (string)$this->getData(self::fields_PLATFORM);
    }

    public function setPlatform(string $platform): self
    {
        return $this->setData(self::fields_PLATFORM, $platform);
    }

    public function getTrendValue(): int
    {
        return (int)$this->getData(self::fields_TREND_VALUE);
    }

    public function setTrendValue(int $trendValue): self
    {
        return $this->setData(self::fields_TREND_VALUE, $trendValue);
    }

    public function getTrendDate(): string
    {
        return (string)$this->getData(self::fields_TREND_DATE);
    }

    public function setTrendDate(string $trendDate): self
    {
        return $this->setData(self::fields_TREND_DATE, $trendDate);
    }

    public function getRegion(): string
    {
        return (string)$this->getData(self::fields_REGION);
    }

    public function setRegion(string $region): self
    {
        return $this->setData(self::fields_REGION, $region);
    }
}

