<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Meta 本地化模型
 * 用于存储 Meta 的多语言翻译数据
 */
class MetaLocal extends AbstractModel
{
    public const table = 'w_meta_local';
    
    const fields_META_ID = 'meta_id';
    const fields_META_IDENTIFY = 'meta_identify';
    const fields_LOCALE_CODE = 'locale_code';
    const fields_CONFIG_KEY = 'config_key';
    const fields_CONFIG_VALUE = 'config_value';

    /**
     * 主键字段（复合主键）
     */
    public array $_unit_primary_keys = ['meta_id', 'locale_code', 'config_key'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['meta_id', 'locale_code', 'config_key'];

    /**
     * 初始化
     */
    public function __init()
    {
        parent::__init();
        // 使用第一个主键字段作为 identity_field，避免框架使用默认的 'id'
        $this->_primary_key = self::fields_META_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('Meta本地化翻译表')
                ->addColumn(
                    self::fields_META_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'NOT NULL',
                    'Meta ID（对应 m_w_meta 表的 meta_id 字段）'
                )
                ->addColumn(
                    self::fields_META_IDENTIFY,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'NOT NULL',
                    'Meta标识（对应 m_w_meta 表的 meta_identify 字段）'
                )
                ->addColumn(
                    self::fields_LOCALE_CODE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'NOT NULL',
                    '语言代码（如 zh_Hans_CN, en_US）'
                )
                ->addColumn(
                    self::fields_CONFIG_KEY,
                    TableInterface::column_type_VARCHAR,
                    128,
                    'NOT NULL',
                    '配置键（如 name, description, param.title）'
                )
                ->addColumn(
                    self::fields_CONFIG_VALUE,
                    TableInterface::column_type_TEXT,
                    null,
                    '',
                    '配置值（翻译后的具体值）'
                )
                ->addConstraints('PRIMARY KEY (' . self::fields_META_ID . ', ' . self::fields_LOCALE_CODE . ', ' . self::fields_CONFIG_KEY . ')')
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_meta_identify_locale_key',
                    [self::fields_META_IDENTIFY, self::fields_LOCALE_CODE, self::fields_CONFIG_KEY],
                    '唯一索引：Meta标识+语言代码+配置键'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_id',
                    self::fields_META_ID,
                    'Meta ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_identify',
                    self::fields_META_IDENTIFY,
                    'Meta标识索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_locale_code',
                    self::fields_LOCALE_CODE,
                    '语言代码索引'
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式下每次都会执行）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 在开发模式下，如果表已存在，先删除再重建（确保包含最新的字段）
        if (defined('DEV') && DEV && $setup->tableExist()) {
            $setup->dropTable();
        }
        $this->install($setup, $context);
    }

    /**
     * 获取 Meta ID
     */
    public function getMetaId(): ?int
    {
        return $this->getData(self::fields_META_ID);
    }

    /**
     * 设置 Meta ID
     */
    public function setMetaId(int $metaId): static
    {
        return $this->setData(self::fields_META_ID, $metaId);
    }

    /**
     * 获取 Meta 标识
     */
    public function getMetaIdentify(): ?string
    {
        return $this->getData(self::fields_META_IDENTIFY);
    }

    /**
     * 设置 Meta 标识
     */
    public function setMetaIdentify(string $metaIdentify): static
    {
        return $this->setData(self::fields_META_IDENTIFY, $metaIdentify);
    }

    /**
     * 获取语言代码
     */
    public function getLocaleCode(): ?string
    {
        return $this->getData(self::fields_LOCALE_CODE);
    }

    /**
     * 设置语言代码
     */
    public function setLocaleCode(string $localeCode): static
    {
        return $this->setData(self::fields_LOCALE_CODE, $localeCode);
    }

    /**
     * 获取配置键
     */
    public function getConfigKey(): ?string
    {
        return $this->getData(self::fields_CONFIG_KEY);
    }

    /**
     * 设置配置键
     */
    public function setConfigKey(string $configKey): static
    {
        return $this->setData(self::fields_CONFIG_KEY, $configKey);
    }

    /**
     * 获取配置值
     */
    public function getConfigValue(): ?string
    {
        return $this->getData(self::fields_CONFIG_VALUE);
    }

    /**
     * 设置配置值
     */
    public function setConfigValue(string $configValue): static
    {
        return $this->setData(self::fields_CONFIG_VALUE, $configValue);
    }
}

