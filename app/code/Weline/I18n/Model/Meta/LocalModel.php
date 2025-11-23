<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Model\Meta;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\LocalModel as BaseLocalModel;
use Weline\I18n\LocalModelInterface;
use Weline\Meta\Model\Meta;

/**
 * Meta模型的本地化模型
 * 
 * 用于为Meta模型提供名称信息的翻译支持
 * 使用方式：
 * <w:local model="Weline\I18n\Model\Meta\LocalModel" field="name" id="theme.frontend.layouts.default"></w:local>
 * 或者使用 w:meta 标签（省略 model）：
 * <w:meta>theme.frontend.layouts.default.name</w:meta>
 */

/**
 * Meta模型的本地化模型
 * 
 * 用于为Meta模型提供名称信息的翻译支持
 * 使用方式：
 * <w:local model="Weline\I18n\Model\Meta\LocalModel" field="name" id="theme.frontend.layouts.default"></w:local>
 * 或者使用 w:meta 标签（省略 model）：
 * <w:meta>theme.frontend.layouts.default.name</w:meta>
 */
class LocalModel extends BaseLocalModel implements LocalModelInterface
{
    public const table = 'w_meta_local';
    
    const fields_META_IDENTIFY = 'meta_identify';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = [self::fields_META_IDENTIFY, self::fields_local_code];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = [self::fields_META_IDENTIFY, self::fields_local_code];
    
    /**
     * 初始化
     */
    public function __init()
    {
        parent::__init();
    }
    
    /**
     * 安装表结构
     */
    public function install(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('Meta本地化表')
                ->addColumn(
                    self::fields_META_IDENTIFY,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    '255',
                    'NOT NULL',
                    'Meta标识（对应w_meta表的meta_identify字段）'
                )
                ->addColumn(
                    self::fields_local_code,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    '10',
                    'NOT NULL',
                    '语言代码'
                )
                ->addColumn(
                    self::fields_name,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    '255',
                    'NOT NULL',
                    'Meta名称（翻译后的名称）'
                )
                ->addColumn(
                    self::fields_config,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                    null,
                    '',
                    '配置数据（JSON格式，支持嵌套字段翻译）'
                )
                ->addConstraints('PRIMARY KEY (' . self::fields_META_IDENTIFY . ', ' . self::fields_local_code . ')')
                ->addIndex(
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT,
                    'idx_meta_identify',
                    self::fields_META_IDENTIFY
                )
                ->addIndex(
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT,
                    'idx_local_code',
                    self::fields_local_code
                )
                ->create();
        }
    }
    
    /**
     * 根据 meta_identify 加载本地化数据
     * 
     * @param string $metaIdentify Meta标识
     * @return $this
     */
    public function loadByMetaIdentify(string $metaIdentify): static
    {
        $this->where(self::fields_META_IDENTIFY, $metaIdentify)->fetch();
        return $this;
    }
    
    /**
     * 设置 Meta 标识
     * 
     * @param string $metaIdentify
     * @return $this
     */
    public function setMetaIdentify(string $metaIdentify): static
    {
        $this->setData(self::fields_META_IDENTIFY, $metaIdentify);
        return $this;
    }
    
    /**
     * 获取 Meta 标识
     * 
     * @return string|null
     */
    public function getMetaIdentify(): ?string
    {
        return $this->getData(self::fields_META_IDENTIFY);
    }
    
    /**
     * 根据 meta_identify 和字段路径获取翻译值
     * 
     * @param string $metaIdentify Meta标识，如：theme.frontend.layouts.default
     * @param string $fieldPath 字段路径，如：name 或 meta.name
     * @param string|null $locale 语言代码，如果为null则使用当前语言
     * @return string|null 翻译后的值
     */
    public static function getTranslatedValue(string $metaIdentify, string $fieldPath = 'name', ?string $locale = null): ?string
    {
        // 获取当前语言
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        /** @var LocalModel $localModel */
        $localModel = ObjectManager::getInstance(self::class);
        $localModel->where(self::fields_META_IDENTIFY, $metaIdentify)
                   ->where(self::fields_local_code, $locale)
                   ->find()
                   ->fetch();
        
        if (!$localModel->getMetaIdentify()) {
            // 如果没有找到翻译，尝试从 Meta 表获取默认值
            /** @var Meta $metaModel */
            $metaModel = ObjectManager::getInstance(Meta::class);
            $metaModel->where(Meta::fields_META_IDENTIFY, $metaIdentify)->find()->fetch();
            
            if ($metaModel->getId()) {
                $metaData = json_decode($metaModel->getData(Meta::fields_META_DATA) ?? '{}', true);
                
                // 尝试从 meta_data 中获取字段值
                if ($fieldPath === 'name' || $fieldPath === 'meta.name') {
                    // 查找 name 字段
                    if (isset($metaData['name'])) {
                        return $metaData['name'];
                    }
                    // 尝试从 meta.name 获取
                    if (isset($metaData['meta']['name'])) {
                        return $metaData['meta']['name'];
                    }
                }
                
                // 如果字段路径包含点号，尝试从嵌套结构中获取
                if (strpos($fieldPath, '.') !== false) {
                    $keys = explode('.', $fieldPath);
                    $value = $metaData;
                    foreach ($keys as $key) {
                        if (isset($value[$key])) {
                            $value = $value[$key];
                        } else {
                            return null;
                        }
                    }
                    return is_string($value) ? $value : null;
                }
            }
            
            return null;
        }
        
        // 如果字段路径是 name，直接返回 name 字段
        if ($fieldPath === 'name') {
            return $localModel->getName();
        }
        
        // 如果字段路径包含点号，尝试从 config 中获取
        if (strpos($fieldPath, '.') !== false) {
            return $localModel->getConfigValue($fieldPath);
        }
        
        // 尝试从 config 中获取
        return $localModel->getConfigValue($fieldPath);
    }
}

