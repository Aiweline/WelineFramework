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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '元数据表')]
#[Index(name: 'uk_namespace_type_identify', columns: ['namespace', 'meta_type', 'meta_identify'], type: 'UNIQUE')]
#[Index(name: 'idx_namespace', columns: ['namespace'])]
#[Index(name: 'idx_meta_type', columns: ['meta_type'])]
#[Index(name: 'idx_file_path', columns: ['file_path'])]
#[Index(name: 'idx_namespace_type', columns: ['namespace', 'meta_type'])]
#[Index(name: 'idx_namespace_area', columns: ['namespace', 'area'])]
#[Index(name: 'idx_type_area', columns: ['meta_type', 'area'])]
class Meta extends AbstractModel
{
    public const schema_table = 'w_meta';
    public const schema_primary_key = 'meta_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '元数据ID')]
    public const schema_fields_ID = 'meta_id';
    #[Col('varchar', 100, nullable: false, comment: '命名空间')]
    public const schema_fields_NAMESPACE = 'namespace';
    #[Col('varchar', 50, nullable: false, comment: '元数据类型')]
    public const schema_fields_META_TYPE = 'meta_type';
    #[Col('varchar', 255, nullable: false, comment: '元数据标识')]
    public const schema_fields_META_IDENTIFY = 'meta_identify';
    #[Col('varchar', 500, comment: '文件相对路径')]
    public const schema_fields_FILE_PATH = 'file_path';
    #[Col('varchar', 1000, comment: '文件完整路径')]
    public const schema_fields_FILE_FULL_PATH = 'file_full_path';
    #[Col('varchar', 50, comment: '区域')]
    public const schema_fields_AREA = 'area';
    #[Col('varchar', 100, comment: '分类')]
    public const schema_fields_CATEGORY = 'category';
    #[Col('text', comment: '元数据JSON')]
    public const schema_fields_META_DATA = 'meta_data';
    #[Col('text', comment: '设置JSON')]
    public const schema_fields_SETTING = 'setting';
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['meta_id'];
    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = ['namespace', 'meta_type', 'meta_identify', 'file_path'];
/**
     * 生成翻译键
     * 
     * @param string $group 分组，默认info
     * @param string $field 字段名，默认name
     * @return string 翻译键，格式：@meta::{namespace}.{type}.{identify}.{group}.{field}
     */
    public function generateTranslationKey(string $group = 'info', string $field = 'name'): string
    {
        $namespace = $this->getData(self::schema_fields_NAMESPACE);
        $type = $this->getData(self::schema_fields_META_TYPE);
        $identify = $this->getData(self::schema_fields_META_IDENTIFY);
        
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
        $this->where(self::schema_fields_NAMESPACE, $namespace)
             ->where(self::schema_fields_META_TYPE, $type)
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
        return $this->load(self::schema_fields_FILE_PATH, $filePath);
    }
}
