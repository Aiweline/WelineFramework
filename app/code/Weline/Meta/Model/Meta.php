<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Meta extends AbstractModel
{
    public const table = 'w_meta';
    
    const fields_ID = 'meta_id';
    const fields_NAMESPACE = 'namespace';
    const fields_META_TYPE = 'meta_type';
    const fields_META_IDENTIFY = 'meta_identify';
    const fields_FILE_PATH = 'file_path';
    const fields_FILE_FULL_PATH = 'file_full_path';
    const fields_AREA = 'area';
    const fields_CATEGORY = 'category';
    const fields_META_DATA = 'meta_data';
    const fields_SETTING = 'setting';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['meta_id'];

    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = ['namespace', 'meta_type', 'meta_identify', 'file_path'];

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('元数据表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '元数据ID'
                )
                ->addColumn(
                    self::fields_NAMESPACE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '命名空间：theme, module, api等'
                )
                ->addColumn(
                    self::fields_META_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '元数据类型：layout, component, partial等'
                )
                ->addColumn(
                    self::fields_META_IDENTIFY,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '元数据标识：可能是id、文件名、路径等'
                )
                ->addColumn(
                    self::fields_FILE_PATH,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'default null',
                    '文件相对路径'
                )
                ->addColumn(
                    self::fields_FILE_FULL_PATH,
                    TableInterface::column_type_VARCHAR,
                    1000,
                    'default null',
                    '文件完整路径'
                )
                ->addColumn(
                    self::fields_AREA,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default null',
                    '区域：frontend, backend'
                )
                ->addColumn(
                    self::fields_CATEGORY,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'default null',
                    '分类：account, product, cart等'
                )
                ->addColumn(
                    self::fields_META_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '元数据JSON（存储所有字段的原始值）'
                )
                ->addColumn(
                    self::fields_SETTING,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '设置JSON（存储参数配置，如 param: {title: ""}）'
                )
                // 唯一索引：防止重复
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_namespace_type_identify',
                    [self::fields_NAMESPACE, self::fields_META_TYPE, self::fields_META_IDENTIFY],
                    '唯一索引：命名空间+类型+标识'
                )
                // 普通索引：提升查询效率
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_namespace',
                    self::fields_NAMESPACE,
                    '命名空间索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_type',
                    self::fields_META_TYPE,
                    '类型索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_file_path',
                    self::fields_FILE_PATH,
                    '文件路径索引'
                )
                // 复合索引：提升常见查询组合的效率
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_namespace_type',
                    [self::fields_NAMESPACE, self::fields_META_TYPE],
                    '复合索引：命名空间+类型'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_namespace_area',
                    [self::fields_NAMESPACE, self::fields_AREA],
                    '复合索引：命名空间+区域'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_type_area',
                    [self::fields_META_TYPE, self::fields_AREA],
                    '复合索引：类型+区域'
                )
                ->addAdditional('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'元数据表\'')
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
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 检查表是否存在
        if (!$setup->tableExist()) {
            return;
        }
        
        // 检查字段是否存在
        if ($setup->hasField(self::fields_SETTING)) {
            return;
        }
        
        // 使用 SQL 直接添加 setting 字段（在 meta_data 字段之后）
        $tableName = $setup->getTable();
        $sql = "ALTER TABLE {$tableName} ADD `" . self::fields_SETTING . "` TEXT NULL DEFAULT NULL COMMENT '设置JSON（存储参数配置，如 param: {title: \"\"}）' AFTER `" . self::fields_META_DATA . "`";
        try {
            $setup->query($sql);
        } catch (\Exception $e) {
            // 如果字段已存在，忽略错误
            if (strpos($e->getMessage(), 'Duplicate column name') === false && 
                strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }

    /**
     * 生成翻译键
     * 
     * @param string $group 分组，默认info
     * @param string $field 字段名，默认name
     * @return string 翻译键，格式：@meta::{namespace}.{type}.{identify}.{group}.{field}
     */
    public function generateTranslationKey(string $group = 'info', string $field = 'name'): string
    {
        $namespace = $this->getData(self::fields_NAMESPACE);
        $type = $this->getData(self::fields_META_TYPE);
        $identify = $this->getData(self::fields_META_IDENTIFY);
        
        return "@meta::{$namespace}.{$type}.{$identify}.{$group}.{$field}";
    }

    /**
     * 保存元数据并收集翻译
     * 
     * @param array $metaData 元数据数组，应包含info字段
     * @return $this
     */
    public function saveMeta(array $metaData): static
    {
        // 保存元数据
        $this->save();
        
        // 收集翻译词
        $translations = [];
        if (!empty($metaData['info']) && is_array($metaData['info'])) {
            foreach ($metaData['info'] as $field => $value) {
                if (!empty($value)) {
                    $translations[] = [
                        'word' => $this->generateTranslationKey('info', $field),
                        'translate' => $value
                    ];
                }
            }
        }
        
        // 触发翻译收集事件（由I18n模块监听并处理）
        if (!empty($translations)) {
            $this->getEventManager()->dispatch('Weline_I18n::collect_translations', [
                'translations' => $translations,
                'module' => 'Weline_Meta' // 标识翻译词来源模块
            ]);
        }
        
        return $this;
    }

    /**
     * 根据命名空间和类型加载
     * 
     * @param string $namespace 命名空间
     * @param string $type 类型
     * @return $this
     */
    public function loadByNamespaceAndType(string $namespace, string $type): static
    {
        $this->where(self::fields_NAMESPACE, $namespace)
             ->where(self::fields_META_TYPE, $type)
             ->fetch();
        return $this;
    }

    /**
     * 根据文件路径加载
     * 
     * @param string $filePath 文件路径
     * @return $this
     */
    public function loadByFilePath(string $filePath): static
    {
        return $this->load(self::fields_FILE_PATH, $filePath);
    }
}

