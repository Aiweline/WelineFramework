<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 页面模型
 * 存储使用 w:widget 标签组织的页面内容
 */
class Page extends AbstractModel
{
    public const fields_ID = 'page_id';
    public const fields_TITLE = 'title';
    public const fields_HANDLE = 'handle';
    public const fields_CONTENT = 'content';
    public const fields_META_DATA = 'meta_data';
    public const fields_STATUS = 'status';
    public const fields_CREATE_TIME = 'created_at';
    public const fields_UPDATE_TIME = 'updated_at';

    /**
     * 安装数据库表
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级数据库表
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 未来版本升级逻辑
    }

    /**
     * 创建数据库表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('可视化编辑器页面表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '页面ID'
            )
            ->addColumn(
                self::fields_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面标题'
            )
            ->addColumn(
                self::fields_HANDLE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null unique',
                '页面标识（唯一）'
            )
            ->addColumn(
                self::fields_CONTENT,
                TableInterface::column_type_TEXT,
                0,
                'not null',
                '页面内容（w:widget 标签）'
            )
            ->addColumn(
                self::fields_META_DATA,
                TableInterface::column_type_TEXT,
                0,
                '',
                '元数据（JSON 格式）'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                'default \'draft\'',
                '页面状态（draft, published）'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '更新时间'
            )
            ->create();
    }

    /**
     * 获取页面内容（已渲染的 HTML）
     *
     * @return string
     */
    public function getRenderedContent(): string
    {
        $content = $this->getData(self::fields_CONTENT) ?? '';
        // 这里可以添加标签处理逻辑，将 w:widget 标签渲染为 HTML
        return $content;
    }

    /**
     * 设置页面内容
     *
     * @param string $content w:widget 标签字符串
     * @return $this
     */
    public function setContent(string $content): static
    {
        $this->setData(self::fields_CONTENT, $content);
        return $this;
    }

    /**
     * 获取元数据
     *
     * @return array
     */
    public function getMetaData(): array
    {
        $metaData = $this->getData(self::fields_META_DATA) ?? '{}';
        $decoded = json_decode($metaData, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 设置元数据
     *
     * @param array $metaData
     * @return $this
     */
    public function setMetaData(array $metaData): static
    {
        $this->setData(self::fields_META_DATA, json_encode($metaData, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}

