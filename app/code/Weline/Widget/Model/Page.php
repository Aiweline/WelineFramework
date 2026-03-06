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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 页面模型 - 存储使用 w:widget 标签组织的页面内容 */
#[Table(comment: '可视化编辑器页面表')]
#[Index(name: 'idx_handle', columns: ['handle'], type: 'UNIQUE')]
class Page extends AbstractModel
{
    public const schema_table = 'weline_widget_page';
    public const schema_primary_key = 'page_id';

    #[Col(type: 'integer', length: 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '页面ID')]
    public const schema_fields_ID = 'page_id';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: false, comment: '页面句柄')]
    public const schema_fields_HANDLE = 'handle';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'text', nullable: true, comment: '内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col(type: 'text', nullable: true, comment: '元数据JSON')]
    public const schema_fields_META_DATA = 'meta_data';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'enabled', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    public const fields_ID = 'page_id';
    public const fields_TITLE = 'title';
    public const fields_HANDLE = 'handle';
    public const fields_CONTENT = 'content';
    public const fields_META_DATA = 'meta_data';
    public const fields_STATUS = 'status';
    public const fields_CREATE_TIME = 'create_time';
    public const fields_UPDATE_TIME = 'update_time';
/**
     * 获取页面内容（已渲染的 HTML）
     *
     * @return string
     */
    public function getRenderedContent(): string
    {
        $content = $this->getData(self::schema_fields_CONTENT) ?? '';
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
        $this->setData(self::schema_fields_CONTENT, $content);
        return $this;
    }
    /**
     * 获取元数据
     *
     * @return array
     */
    public function getMetaData(): array
    {
        $metaData = $this->getData(self::schema_fields_META_DATA) ?? '{}';
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
        $this->setData(self::schema_fields_META_DATA, json_encode($metaData, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}
