<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Db;

use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Setup\Db\Service\FieldBackupService;
use Weline\Framework\Setup\Data\Context;

/**
 * Alter 包装类
 * 
 * 自动为 addColumn 添加数据恢复功能，为 deleteColumn 添加数据备份功能
 */
class AlterWithBackup implements AlterInterface
{
    private AlterInterface $alter;
    private ModelSetup $modelSetup;
    private array $addedFields = []; // 记录添加的字段信息
    private array $deletedFields = []; // 记录删除的字段信息
    
    public function __construct(AlterInterface $alter, ModelSetup $modelSetup)
    {
        $this->alter = $alter;
        $this->modelSetup = $modelSetup;
    }
    
    public function forTable(string $table_name, string $primary_key, string $comment = '', string $new_table_name = ''): AlterInterface
    {
        $this->alter->forTable($table_name, $primary_key, $comment, $new_table_name);
        return $this;
    }
    
    public function alterColumn(string $old_field, string $field_name, string $after_field = '', string $type = '', string|int $length = 0, string $options = '', string $comment = ''): AlterInterface
    {
        $this->alter->alterColumn($old_field, $field_name, $after_field, $type, $length, $options, $comment);
        return $this;
    }
    
    public function deleteColumn(string $field_name): AlterInterface
    {
        // 记录要删除的字段，在 alter() 执行前备份
        $this->deletedFields[] = $field_name;
        $this->alter->deleteColumn($field_name);
        return $this;
    }
    
    public function addColumn(string $field_name, string $after_column, string $type, string|int $length, string $options, string $comment): AlterInterface
    {
        // 记录添加的字段信息，在 alter() 执行后恢复
        $this->addedFields[] = [
            'field_name' => $field_name,
            'after_column' => $after_column,
            'type' => $type,
            'length' => $length,
            'options' => $options,
            'comment' => $comment
        ];
        $this->alter->addColumn($field_name, $after_column, $type, $length, $options, $comment);
        return $this;
    }
    
    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = 'BTREE'): AlterInterface
    {
        $this->alter->addIndex($type, $name, $column, $comment, $index_method);
        return $this;
    }
    
    public function addAdditional(string $additional_sql = 'ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;'): AlterInterface
    {
        $this->alter->addAdditional($additional_sql);
        return $this;
    }
    
    public function addConstraints(string $constraints = ''): AlterInterface
    {
        $this->alter->addConstraints($constraints);
        return $this;
    }
    
    public function getTableColumns(string $table_name = ''): mixed
    {
        return $this->alter->getTableColumns($table_name);
    }
    
    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): AlterInterface
    {
        $this->alter->addForeignKey($FK_Name, $FK_Field, $references_table, $references_field, $on_delete, $on_update);
        return $this;
    }
    
    public function alter(): bool
    {
        $context = $this->modelSetup->getContext();
        $model = $this->modelSetup->getModel();
        
        if ($model === null) {
            // 如果没有模型，直接执行 alter
            return $this->alter->alter();
        }
        
        $tableName = $this->modelSetup->getTable();
        $primaryKey = $model->_primary_key ?? 'id';
        
        // 1. 先备份要删除的字段数据
        if (!empty($this->deletedFields) && $context) {
            $moduleName = $context->getModuleName();
            $version = $context->getNewVersion();
            
            $backupService = $this->modelSetup->getFieldBackupService();
            foreach ($this->deletedFields as $fieldName) {
                $backupService->backupFieldData($tableName, $fieldName, $primaryKey, $moduleName, $version);
            }
        }
        
        // 2. 执行 alter 操作
        $result = $this->alter->alter();
        
        // 3. 恢复添加的字段数据（如果有备份）
        if (!empty($this->addedFields)) {
            $backupService = $this->modelSetup->getFieldBackupService();
            
            if ($context) {
                $moduleName = $context->getModuleName();
                $version = $context->getNewVersion();
            } else {
                // 尝试从模型类名推断模块名
                $moduleName = $this->getModuleNameFromModel();
                $version = null; // 恢复所有版本
            }
            
            if ($moduleName) {
                foreach ($this->addedFields as $fieldInfo) {
                    $backupService->restoreFieldData($tableName, $fieldInfo['field_name'], $moduleName, $version);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 从模型类名推断模块名称
     */
    private function getModuleNameFromModel(): ?string
    {
        $model = $this->modelSetup->getModel();
        if ($model === null) {
            return null;
        }
        
        $className = get_class($model);
        // 从命名空间提取模块名，例如：GuoLaiRen\PageBuilder\Model\Page -> GuoLaiRen_PageBuilder
        if (preg_match('/^([A-Za-z0-9_]+)\\\\([A-Za-z0-9_]+)\\\\/', $className, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        
        return null;
    }
}
