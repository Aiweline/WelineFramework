<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI国际化内容数据模型
 * 
 * 功能：
 * - 管理AI生成内容的多语言版本
 * - 支持提示词和响应的国际化
 * - 内容上下文管理
 * - 多语言内容缓存
 */
class AiI18nContent extends Model
{
    public const table = 'ai_i18n_ai_content';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_CONTENT_TYPE = 'content_type';
    public const fields_CONTENT_KEY = 'content_key';
    public const fields_LOCALE_CODE = 'locale_code';
    public const fields_CONTENT_VALUE = 'content_value';
    public const fields_CONTEXT = 'context';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 内容类型常量
    public const TYPE_PROMPT = 'prompt';
    public const TYPE_RESPONSE = 'response';
    public const TYPE_ERROR = 'error';
    public const TYPE_LABEL = 'label';
    public const TYPE_MESSAGE = 'message';

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
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_CONTENT_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '内容类型')
                ->addColumn(self::fields_CONTENT_KEY, TableInterface::column_type_VARCHAR, 255, 'not null', '内容键')
                ->addColumn(self::fields_LOCALE_CODE, TableInterface::column_type_VARCHAR, 10, 'not null', '语言代码')
                ->addColumn(self::fields_CONTENT_VALUE, TableInterface::column_type_TEXT, null, 'not null', '内容值')
                ->addColumn(self::fields_CONTEXT, TableInterface::column_type_VARCHAR, 255, 'null', '上下文')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_content_type', self::fields_CONTENT_TYPE, '内容类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_content_key', self::fields_CONTENT_KEY, '内容键索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_locale_code', self::fields_LOCALE_CODE, '语言代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_context', self::fields_CONTEXT, '上下文索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_content_locale', [self::fields_CONTENT_KEY, self::fields_LOCALE_CODE], '内容语言唯一索引')
                ->create();
        }
    }

    /**
     * 获取内容类型
     * 
     * @return string
     */
    public function getContentType(): string
    {
        return $this->getData(self::fields_CONTENT_TYPE) ?? '';
    }

    /**
     * 获取内容键
     * 
     * @return string
     */
    public function getContentKey(): string
    {
        return $this->getData(self::fields_CONTENT_KEY) ?? '';
    }

    /**
     * 获取语言代码
     * 
     * @return string
     */
    public function getLocaleCode(): string
    {
        return $this->getData(self::fields_LOCALE_CODE) ?? '';
    }

    /**
     * 获取内容值
     * 
     * @return string
     */
    public function getContentValue(): string
    {
        return $this->getData(self::fields_CONTENT_VALUE) ?? '';
    }

    /**
     * 获取上下文
     * 
     * @return string
     */
    public function getContext(): string
    {
        return $this->getData(self::fields_CONTEXT) ?? '';
    }

    /**
     * 设置内容值
     * 
     * @param string $value
     * @return $this
     */
    public function setContentValue(string $value): self
    {
        $this->setData(self::fields_CONTENT_VALUE, $value);
        return $this;
    }

    /**
     * 设置上下文
     * 
     * @param string $context
     * @return $this
     */
    public function setContext(string $context): self
    {
        $this->setData(self::fields_CONTEXT, $context);
        return $this;
    }

    /**
     * 获取内容类型显示名称
     * 
     * @return string
     */
    public function getContentTypeDisplayName(): string
    {
        $typeNames = [
            self::TYPE_PROMPT => '提示词',
            self::TYPE_RESPONSE => '响应',
            self::TYPE_ERROR => '错误信息',
            self::TYPE_LABEL => '标签',
            self::TYPE_MESSAGE => '消息'
        ];

        return $typeNames[$this->getContentType()] ?? $this->getContentType();
    }

    /**
     * 保存前的数据处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
