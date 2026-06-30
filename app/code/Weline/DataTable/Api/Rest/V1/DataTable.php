<?php

namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\DataTable\Exception\DataTableException;
use Weline\DataTable\Helper\ErrorHandler;

class DataTable extends BackendRestController
{


    /**
     * 获取数据表格数据
     */
    public function getData()
    {
        return $this->postData();
    }

    /**
     * 获取数据表格数据（POST方法）
     */
    public function postData()
    {
        $model = $this->request->getParam('model');
        $scope = $this->request->getParam('scope');
        $page = max(1, intval($this->request->getParam('page', 1)));
        $limit = max(1, min(100, intval($this->request->getParam('limit', 20))));
        $filters = $this->request->getParam('filters', []);
        $sort = $this->request->getParam('sort', []);
        $join = $this->request->getParam('join', '');
        $modelConfig = $this->request->getParam('model_config', []);

        try {
            // 验证参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model, 'scope' => $scope],
                ['model', 'scope']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);

            // 处理多模型配置
            if (!empty($modelConfig) && is_array($modelConfig) && !empty($modelConfig['models'])) {
                return $this->handleMultiModelData($modelConfig, $join, $page, $limit, $filters, $sort);
            }

            $modelInstance = w_obj($model);

            // 应用过滤器
            if (!empty($filters) && is_array($filters)) {
                foreach ($filters as $field => $value) {
                    if (!empty($value) && is_string($value)) {
                        // 根据字段类型选择合适的查询方式
                        if ($this->isNumericField($field)) {
                            $modelInstance->where($field, '=', $value);
                        } elseif ($this->isDateField($field)) {
                            $modelInstance->where($field, 'LIKE', $value . '%');
                        } else {
                            $modelInstance->where($field, 'LIKE', '%' . $value . '%');
                        }
                    }
                }
            }

            // 应用排序
            if (!empty($sort) && is_array($sort)) {
                foreach ($sort as $field => $direction) {
                    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                    $modelInstance->order($field, $direction);
                }
            }

            // 获取总数（在分页之前）
            $totalQuery = clone $modelInstance;
            $total = $totalQuery->count();

            // 应用分页
            $data = $modelInstance->pagination($page, $limit)->select()->fetch();

            return $this->success('数据获取成功', [
                'data' => $data ?: [],
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
                'has_next' => $page < ceil($total / $limit),
                'has_prev' => $page > 1
            ]);

        } catch (DataTableException $e) {
            $e->log('DataTable::postData');
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postData');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Throwable $e) {
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postData');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 获取模型字段信息
     */
    public function postFields()
    {
        $model = $this->request->getParam('model');
        $scope = $this->request->getParam('scope');
        $tableId = $this->request->getParam('table_id');
        
        try {
            // 验证必需参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model, 'scope' => $scope],
                ['model', 'scope']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);
            
            $modelInstance = w_obj($model);
            
            // 获取模型字段信息
            $columns = $modelInstance->columns();

            $primaryKey = method_exists($modelInstance, 'getPrimaryKey') ?
                $modelInstance->getPrimaryKey() : 'id';
            
            // 构建字段信息
            $allFields = [];
            $displayFields = [];
            $filterFields = [];
            
            // columns() 返回的是 SHOW FULL COLUMNS 的结果数组
            foreach ($columns as $column) {
                // 提取字段名
                $fieldName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? '') : $column;
                if (empty($fieldName) || !is_string($fieldName)) {
                    continue;
                }

                // 获取字段类型
                $fieldType = is_array($column) && isset($column['Type']) ? $column['Type'] : '';
                
                // 从数据库字段信息中获取标签和类型
                $fieldLabel = is_array($column) && isset($column['Comment']) && !empty($column['Comment']) 
                    ? $column['Comment'] 
                    : $this->getFieldLabel($fieldName);
                
                $fieldInfo = [
                    'name' => $fieldName,
                    'label' => $fieldLabel,
                    'type' => $this->getFieldType($fieldName, $fieldType),
                    'sortable' => true,
                    'searchable' => true,
                    'visible' => true,
                    'editable' => $fieldName !== $primaryKey,
                    'is_primary' => $fieldName === $primaryKey,
                    'primary_key' => $fieldName === $primaryKey,
                    'required' => $fieldName === $primaryKey,
                    'placeholder' => '请输入' . $fieldLabel,
                    'width' => $this->getFieldWidth($fieldName, $fieldType),
                    'min_width' => null,
                    'max_width' => null,
                    'resizable' => true,
                    'display_orderable' => true,
                    'template_defined' => false,
                    'field_defined' => false,
                    'from_field' => false
                ];
                
                $allFields[] = $fieldInfo;
                
                // 默认显示字段（排除一些系统字段）
                if (!in_array($fieldName, ['created_at', 'updated_at', 'deleted_at'])) {
                    $displayFields[] = $fieldInfo;
                }
                
                // 默认过滤字段（主键和名称字段）
                if (in_array($fieldName, [$primaryKey, 'name', 'title', 'username', 'email'])) {
                    $filterFields[] = $fieldInfo;
                }
            }
            
            // 尝试从缓存或配置中获取用户自定义的字段配置
            $cacheKey = "datatable_fields_{$scope}_{$tableId}";
            $cachedConfig = $this->getCache()->get($cacheKey);
            
            // 缓存字段配置（单独返回，不合并到默认字段中）
            $cachedDisplayFields = null;
            $cachedFilterFields = null;
            
            if ($cachedConfig) {
                $cachedFields = $cachedConfig['display_fields'] ?? [];
                $cachedFilterFieldsConfig = $cachedConfig['filter_fields'] ?? [];
                
                // 如果有缓存的显示字段配置，合并完整字段信息后单独返回
                if (!empty($cachedFields)) {
                    $cachedDisplayFields = $this->mergeFieldConfigs($allFields, $cachedFields);
                }
                
                // 如果有缓存的筛选字段配置，合并完整字段信息后单独返回
                if (!empty($cachedFilterFieldsConfig)) {
                    $cachedFilterFields = $this->mergeFieldConfigs($allFields, $cachedFilterFieldsConfig);
                }
            }
            
            return $this->success('字段信息获取成功', [
                'all_fields' => $allFields,
                'display_fields' => $displayFields,  // 默认显示字段（无模板时使用）
                'filter_fields' => $filterFields,    // 默认筛选字段
                'cached_display_fields' => $cachedDisplayFields,  // 缓存的显示字段配置
                'cached_filter_fields' => $cachedFilterFields,    // 缓存的筛选字段配置
                'primary_key' => $primaryKey,
                'scope' => $scope,
                'table_id' => $tableId
            ]);
            
        } catch (DataTableException $e) {
            $e->log('DataTable::postFields');
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postFields');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Throwable $e) {
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postFields');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 保存字段配置
     */
    public function postSaveConfig()
    {
        $model = $this->request->getParam('model');
        $scope = $this->request->getParam('scope');
        $tableId = $this->request->getParam('table_id');
        $displayFields = $this->request->getParam('display_fields', []);
        $filterFields = $this->request->getParam('filter_fields', []);
        
        try {
            $cacheKey = "datatable_fields_{$scope}_{$tableId}";
            $config = [
                'display_fields' => $displayFields,
                'filter_fields' => $filterFields,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $this->getCache()->set($cacheKey, $config, 3600); // 缓存1小时
            
            return $this->success('配置保存成功', [
                'scope' => $scope,
                'table_id' => $tableId
            ]);
            
        } catch (\Exception $e) {
            return $this->error('配置保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 清理字段配置
     */
    public function postClearConfig()
    {
        $model = $this->request->getParam('model');
        $scope = $this->request->getParam('scope');
        $tableId = $this->request->getParam('table_id');
        
        try {
            $cacheKey = "datatable_fields_{$scope}_{$tableId}";
            $this->getCache()->delete($cacheKey);
            
            return $this->success('配置清理成功', [
                'scope' => $scope,
                'table_id' => $tableId
            ]);
            
        } catch (\Exception $e) {
            return $this->error('配置清理失败: ' . $e->getMessage());
        }
    }

    /**
     * 保存数据表格数据
     */
    public function saveData()
    {
        $model = $this->request->getParam('model');
        $data = $this->request->getParam('data', []);
        
        try {
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }
            
            $modelInstance = new $model();
            
            if (isset($data['id']) && !empty($data['id'])) {
                // 更新
                $modelInstance->load($data['id'])->setData($data)->save();
                $message = '数据更新成功';
            } else {
                // 新增
                $modelInstance->setData($data)->save();
                $message = '数据添加成功';
            }
            
            return $this->success($message, [
                'id' => $modelInstance->getId()
            ]);
            
        } catch (\Exception $e) {
            return $this->error('数据保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查删除关联数据
     */
    public function postCheckDeleteRelations()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);
        
        try {
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }
            
            $checkIds = !empty($ids) && is_array($ids) ? $ids : [$id];
            $relations = [];
            $totalRelatedRecords = 0;
            
            foreach ($checkIds as $checkId) {
                if (empty($checkId)) {
                    continue;
                }
                
                $modelInstance = w_obj($model);
                $modelInstance->load($checkId);
                
                if (!$modelInstance->getId()) {
                    continue;
                }
                
                // 检查关联数据
                $relationData = $this->checkRelationData($modelInstance, $checkId);
                if (!empty($relationData)) {
                    $relations[$checkId] = $relationData;
                    foreach ($relationData as $relation) {
                        $totalRelatedRecords += $relation['count'];
                    }
                }
            }
            
            return $this->success('关联数据检查完成', [
                'has_relations' => !empty($relations),
                'total_related_records' => $totalRelatedRecords,
                'relations' => $relations,
                'check_ids' => $checkIds
            ]);
            
        } catch (\Exception $e) {
            return $this->error('关联数据检查失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除数据表格数据（增强版）
     */
    public function deleteData()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);
        $softDelete = $this->request->getParam('soft_delete', false);
        $cascadeDelete = $this->request->getParam('cascade_delete', false);
        $forceDelete = $this->request->getParam('force_delete', false);

        try {
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            // 处理批量删除
            if (!empty($ids) && is_array($ids)) {
                return $this->batchDeleteWithCascade($model, $ids, $softDelete, $cascadeDelete, $forceDelete);
            }

            // 单个删除
            if (empty($id)) {
                return $this->error('ID不能为空');
            }

            return $this->singleDeleteWithCascade($model, $id, $softDelete, $cascadeDelete, $forceDelete);

        } catch (\Exception $e) {
            return $this->error(__('数据删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 单个删除带级联处理
     */
    private function singleDeleteWithCascade(string $model, $id, bool $softDelete, bool $cascadeDelete, bool $forceDelete)
    {
        try {
            $modelInstance = w_obj($model);
            $modelInstance->load($id);
            
            if (!$modelInstance->getId()) {
                return $this->error(__('记录不存在'));
            }
            
            // 如果启用级联删除，先删除关联数据
            if ($cascadeDelete) {
                $this->cascadeDeleteRelations($modelInstance, $id, $softDelete);
            }
            
            // 删除主记录
            if ($forceDelete && method_exists($modelInstance, 'forceDelete')) {
                $result = $modelInstance->forceDelete();
                $message = __('记录已永久删除');
            } elseif ($softDelete && method_exists($modelInstance, 'softDelete')) {
                $result = $modelInstance->softDelete();
                $message = __('记录已移至回收站');
            } else {
                $result = $modelInstance->delete();
                $message = __('数据删除成功');
            }
            
            return $this->success($message, [
                'id' => $id,
                'cascade_delete' => $cascadeDelete
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 批量删除带级联处理
     */
    private function batchDeleteWithCascade(string $model, array $ids, bool $softDelete, bool $cascadeDelete, bool $forceDelete)
    {
        $successCount = 0;
        $failedCount = 0;
        $errors = [];
        
        foreach ($ids as $id) {
            try {
                $result = $this->singleDeleteWithCascade($model, $id, $softDelete, $cascadeDelete, $forceDelete);
                
                if ($result['code'] === 200) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $errors[] = "ID {$id}: " . $result['msg'];
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = "ID {$id}: " . $e->getMessage();
            }
        }
        
        $message = __('批量删除完成，成功: %{1}，失败: %{2}', [$successCount, $failedCount]);
        
                 return $this->success($message, [
             'success_count' => $successCount,
             'failed_count' => $failedCount,
             'errors' => $errors,
             'cascade_delete' => $cascadeDelete
         ]);
     }

    /**
     * 检查关联数据
     * 
     * @param mixed $modelInstance 模型实例
     * @param mixed $recordId 记录ID
     * @return array 关联数据信息
     */
    private function checkRelationData($modelInstance, $recordId): array
    {
        $relations = [];
        
        try {
            // 获取数据库连接
            $connection = $modelInstance->getConnection();
            $currentTable = $modelInstance->getTable();
            $primaryKey = method_exists($modelInstance, 'getPrimaryKey') ? 
                $modelInstance->getPrimaryKey() : 'id';
            
            // 获取所有表
            $tables = $connection->getTables();
            
            foreach ($tables as $table) {
                // 跳过当前表
                if ($table === $currentTable) {
                    continue;
                }
                
                // 检查是否有外键关联
                $foreignKeyFields = $this->findForeignKeyFields($connection, $table, $currentTable, $primaryKey);
                
                foreach ($foreignKeyFields as $foreignKey) {
                    try {
                        // 查询关联记录数量
                        $sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE `{$foreignKey}` = ?";
                        $result = $connection->query($sql, [$recordId]);
                        $count = $result->fetch()['count'] ?? 0;
                        
                        if ($count > 0) {
                            // 获取表的友好名称
                            $tableName = $this->getTableFriendlyName($table);
                            
                            $relations[] = [
                                'table' => $table,
                                'table_name' => $tableName,
                                'foreign_key' => $foreignKey,
                                'count' => (int)$count,
                                'description' => "表 {$tableName} 中有 {$count} 条关联记录"
                            ];
                        }
                    } catch (\Exception $e) {
                        w_log_error("Check relation error for table {$table}: " . $e->getMessage());
                    }
                }
            }
            
        } catch (\Exception $e) {
            w_log_error("Check relation data error: " . $e->getMessage());
        }
        
        return $relations;
    }
    
    /**
     * 查找外键字段
     * 
     * @param mixed $connection 数据库连接
     * @param string $table 要检查的表
     * @param string $referencedTable 被引用的表
     * @param string $referencedKey 被引用的主键
     * @return array 外键字段列表
     */
    private function findForeignKeyFields($connection, string $table, string $referencedTable, string $referencedKey): array
    {
        $foreignKeys = [];
        
        try {
            // 获取表的列信息
            $columns = $connection->getColumns($table);
            
            // 常见的外键命名模式
            $patterns = [
                $referencedKey, // 直接使用主键名
                str_replace('_id', '', $referencedTable) . '_id', // 表名_id
                substr($referencedTable, 0, -1) . '_id', // 去掉表名最后一个字符_id
                preg_replace('/^.*_/', '', $referencedTable) . '_id', // 去掉表前缀_id
            ];
            
            foreach ($columns as $column) {
                $columnName = $column['COLUMN_NAME'];
                
                // 检查是否匹配外键模式
                foreach ($patterns as $pattern) {
                    if (strtolower($columnName) === strtolower($pattern)) {
                        $foreignKeys[] = $columnName;
                        break;
                    }
                }
            }
            
        } catch (\Exception $e) {
            w_log_error("Find foreign keys error for table {$table}: " . $e->getMessage());
        }
        
        return array_unique($foreignKeys);
    }
    
    /**
     * 获取表的友好名称
     * 
     * @param string $table 表名
     * @return string 友好名称
     */
    private function getTableFriendlyName(string $table): string
    {
        // 移除表前缀
        $friendlyName = preg_replace('/^[a-z]+_/', '', $table);
        
        // 转换下划线为空格并首字母大写
        $friendlyName = ucwords(str_replace('_', ' ', $friendlyName));
        
        // 如果还是原来的表名，直接返回
        if ($friendlyName === $table) {
            return $table;
        }
        
        return $friendlyName;
    }
    
    /**
     * 级联删除关联数据
     * 
     * @param mixed $modelInstance 模型实例
     * @param mixed $recordId 记录ID
     * @param bool $softDelete 是否软删除
     * @return void
     */
    private function cascadeDeleteRelations($modelInstance, $recordId, bool $softDelete = false): void
    {
        try {
            $relations = $this->checkRelationData($modelInstance, $recordId);
            
            foreach ($relations as $relation) {
                $table = $relation['table'];
                $foreignKey = $relation['foreign_key'];
                $count = $relation['count'];
                
                if ($count > 0) {
                    // 尝试找到对应的模型类
                    $relatedModel = $this->findModelForTable($table);
                    
                    if ($relatedModel && class_exists($relatedModel)) {
                        // 使用模型删除（支持软删除）
                        $this->deleteRelatedRecordsByModel($relatedModel, $foreignKey, $recordId, $softDelete);
                    } else {
                        // 直接SQL删除
                        $this->deleteRelatedRecordsBySQL($modelInstance->getConnection(), $table, $foreignKey, $recordId);
                    }
                }
            }
            
        } catch (\Exception $e) {
            w_log_error("Cascade delete relations error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 根据表名查找对应的模型类
     * 
     * @param string $table 表名
     * @return string|null 模型类名
     */
    private function findModelForTable(string $table): ?string
    {
        // 尝试常见的模型命名模式
        $patterns = [
            'Weline\\DataTable\\Model\\' . ucfirst(str_replace('_', '', $table)),
            'App\\Model\\' . ucfirst(str_replace('_', '', $table)),
            // 可以根据项目结构添加更多模式
        ];
        
        foreach ($patterns as $pattern) {
            if (class_exists($pattern)) {
                return $pattern;
            }
        }
        
        return null;
    }
    
    /**
     * 使用模型删除关联记录
     * 
     * @param string $modelClass 模型类
     * @param string $foreignKey 外键字段
     * @param mixed $recordId 记录ID
     * @param bool $softDelete 是否软删除
     * @return void
     */
    private function deleteRelatedRecordsByModel(string $modelClass, string $foreignKey, $recordId, bool $softDelete): void
    {
        try {
            $relatedModel = w_obj($modelClass);
            $relatedRecords = $relatedModel->where($foreignKey, '=', $recordId)->select()->fetch();
            
            foreach ($relatedRecords as $record) {
                $modelInstance = w_obj($modelClass);
                $modelInstance->load($record['id'] ?? $record[0]);
                
                if ($softDelete && method_exists($modelInstance, 'softDelete')) {
                    $modelInstance->softDelete();
                } else {
                    $modelInstance->delete();
                }
            }
            
        } catch (\Exception $e) {
            w_log_error("Delete related records by model error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 使用SQL删除关联记录
     * 
     * @param mixed $connection 数据库连接
     * @param string $table 表名
     * @param string $foreignKey 外键字段
     * @param mixed $recordId 记录ID
     * @return void
     */
    private function deleteRelatedRecordsBySQL($connection, string $table, string $foreignKey, $recordId): void
    {
        try {
            $sql = "DELETE FROM `{$table}` WHERE `{$foreignKey}` = ?";
            $connection->query($sql, [$recordId]);
            
        } catch (\Exception $e) {
            w_log_error("Delete related records by SQL error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取数据表格配置
     */
    public function getConfig()
    {
        $model = $this->request->getParam('model');
        $scope = $this->request->getParam('scope');
        
        try {
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }
            
            $modelInstance = new $model();
            
            // 获取模型字段信息
            $fields = $modelInstance->columns();
            
            // 获取字段选项（如果有的话）
            $options = [];
            if (method_exists($modelInstance, 'getFieldOptions')) {
                $options = $modelInstance->getFieldOptions();
            }
            
            return $this->success('配置获取成功', [
                'fields' => $fields,
                'options' => $options,
                'scope' => $scope
            ]);
            
        } catch (\Exception $e) {
            return $this->error('配置获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取字段标签
     */
    private function getFieldLabel($fieldName)
    {
        // 确保字段名是字符串
        if (!is_string($fieldName)) {
            return 'Unknown';
        }

        $labels = [
            'id' => 'ID',
            'name' => '名称',
            'title' => '标题',
            'username' => '用户名',
            'email' => '邮箱',
            'phone' => '电话',
            'status' => '状态',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
            'deleted_at' => '删除时间'
        ];

        return $labels[$fieldName] ?? ucfirst($fieldName);
    }

    /**
     * 获取字段类型
     */
    private function getFieldType($fieldName, $dbType = '')
    {
        // 确保字段名是字符串
        if (!is_string($fieldName)) {
            return 'text';
        }

        // 如果提供了数据库类型，根据数据库类型推断
        if (!empty($dbType)) {
            $dbType = strtolower($dbType);
            if (strpos($dbType, 'int') !== false) {
                return 'number';
            } elseif (strpos($dbType, 'decimal') !== false || strpos($dbType, 'float') !== false || strpos($dbType, 'double') !== false) {
                return 'number';
            } elseif (strpos($dbType, 'date') !== false || strpos($dbType, 'time') !== false) {
                return 'datetime';
            } elseif (strpos($dbType, 'text') !== false) {
                return 'textarea';
            } elseif (strpos($dbType, 'enum') !== false || strpos($dbType, 'set') !== false) {
                return 'select';
            }
        }

        $types = [
            'id' => 'number',
            'name' => 'text',
            'title' => 'text',
            'username' => 'text',
            'email' => 'email',
            'phone' => 'tel',
            'status' => 'select',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime'
        ];

        return $types[$fieldName] ?? 'text';
    }

    /**
     * 获取字段宽度
     */
    private function getFieldWidth($fieldName, $dbType = '')
    {
        // 确保字段名是字符串
        if (!is_string($fieldName)) {
            return '120px';
        }

        // 如果提供了数据库类型，根据类型推断宽度
        if (!empty($dbType)) {
            $dbType = strtolower($dbType);
            if (strpos($dbType, 'int') !== false) {
                return '100px';
            } elseif (strpos($dbType, 'date') !== false || strpos($dbType, 'time') !== false) {
                return '180px';
            } elseif (strpos($dbType, 'text') !== false) {
                return '300px';
            }
        }

        $widths = [
            'id' => '80px',
            'name' => '150px',
            'title' => '200px',
            'username' => '120px',
            'email' => '200px',
            'phone' => '120px',
            'status' => '100px',
            'created_at' => '150px',
            'updated_at' => '150px',
            'deleted_at' => '150px'
        ];

        return $widths[$fieldName] ?? 'auto';
    }

    /**
     * 合并字段配置
     */
    private function mergeFieldConfigs($allFields, $customFields)
    {
        $fieldMap = [];
        foreach ($allFields as $field) {
            $fieldMap[$field['name']] = $field;
        }
        
        $mergedFields = [];
        foreach ($customFields as $customField) {
            $fieldName = $customField['name'];
            if (isset($fieldMap[$fieldName])) {
                // 合并配置，自定义配置优先
                $mergedFields[] = array_merge($fieldMap[$fieldName], $customField);
            } else {
                // 如果字段不存在于模型字段中，直接添加
                $mergedFields[] = $customField;
            }
        }
        
        return $mergedFields;
    }

    /**
     * 处理多模型数据查询
     * @param array $modelConfig 模型配置
     * @param string $join JOIN配置
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param array $filters 过滤条件
     * @param array $sort 排序条件
     * @return array
     */
    private function handleMultiModelData(array $modelConfig, string $join, int $page, int $limit, array $filters, array $sort): array
    {
        try {
            // 获取主模型
            $mainModel = '';
            $models = $modelConfig['models'] ?? [];

            if (empty($models)) {
                throw new \InvalidArgumentException('多模型配置为空');
            }

            // 第一个模型作为主模型
            $mainModel = reset($models);

            if (!class_exists($mainModel)) {
                    throw new \InvalidArgumentException(__('主模型类不存在: %{1}', $mainModel));
            }

            $modelInstance = w_obj($mainModel);

            // 处理JOIN查询
            if (!empty($join)) {
                return $this->handleJoinQuery($modelInstance, $models, $join, $page, $limit, $filters, $sort);
            }

            // 应用过滤器和排序（简化处理）
            $data = $modelInstance->pagination($page, $limit)->select()->fetch();
            $total = $modelInstance->count();

            return [
                'msg' => '多模型数据获取成功',
                'data' => [
                    'data' => $data ?: [],
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ],
                'code' => 200
            ];

        } catch (\Exception $e) {
            return [
                'msg' => '多模型数据获取失败: ' . $e->getMessage(),
                'data' => '',
                'code' => 404
            ];
        }
    }

    /**
     * 判断是否为数字字段
     * @param string $fieldName 字段名
     * @return bool
     */
    private function isNumericField(string $fieldName): bool
    {
        $numericFields = ['id', 'price', 'amount', 'quantity', 'count', 'number', 'age', 'year'];
        return in_array(strtolower($fieldName), $numericFields) ||
               preg_match('/_(id|price|amount|quantity|count|number)$/', strtolower($fieldName));
    }

    /**
     * 判断是否为日期字段
     * @param string $fieldName 字段名
     * @return bool
     */
    private function isDateField(string $fieldName): bool
    {
        $dateFields = ['created_at', 'updated_at', 'deleted_at', 'date', 'time', 'datetime'];
        return in_array(strtolower($fieldName), $dateFields) ||
               preg_match('/_(at|date|time)$/', strtolower($fieldName));
    }

    /**
     * 创建新记录
     */
    public function postCreate()
    {
        $model = $this->request->getParam('model');
        $data = $this->request->getParam('data', []);
        $dependencies = $this->request->getParam('dependencies', '');
        $useTransaction = $this->normalizeBooleanFlag($this->request->getParam('transaction', false));

        try {
            if (empty($model) || empty($data)) {
                return $this->error(__('缺少必需参数: model 和 data'));
            }

            // 检查是否为多表操作
            if (strpos($model, ',') !== false) {
                return $this->createMultiTableRecord($model, $data, $dependencies, $useTransaction);
            }

            // 单表操作
            return $this->createSingleTableRecord($model, $data, $useTransaction);

        } catch (\Exception $e) {
            w_log_error("DataTable Create Error: " . $e->getMessage());
            return $this->error(__('记录创建失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 创建单表记录
     */
    private function createSingleTableRecord(string $model, array $data, bool $useTransaction = false)
    {
        $operation = function() use ($model, $data) {
            if (!class_exists($model)) {
                    throw new \InvalidArgumentException(__('模型类不存在: %{1}', $model));
            }

            $modelInstance = w_obj($model);

            // 验证数据
            $validatedData = $this->validateData($data, $modelInstance);
            if ($validatedData === false) {
                throw new \InvalidArgumentException('数据验证失败');
            }

            // 设置数据
            foreach ($validatedData as $field => $value) {
                $modelInstance->setData($field, $value);
            }

            // 保存数据
            $result = $modelInstance->save();

            if (!$result) {
                throw new \RuntimeException(__('记录创建失败'));
            }

            return [
                'id' => $modelInstance->getId(),
                'data' => $modelInstance->getData()
            ];
        };

        try {
            if ($useTransaction) {
                $result = \Weline\DataTable\Helper\TransactionManager::executeInTransaction($operation, 'create_single_record');
            } else {
                $result = $operation();
            }

            return $this->success('记录创建成功', $result);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建多表记录
     */
    private function createMultiTableRecord(string $modelString, array $data, string $dependencies = '', bool $useTransaction = true)
    {
        $operation = function() use ($modelString, $data, $dependencies) {
            // 解析模型配置
            $modelConfig = $this->parseModelConfig($modelString);

            // 解析依赖关系
            $dependencyConfig = [];
            if (!empty($dependencies)) {
                $dependencyConfig = \Weline\DataTable\Helper\DependencyManager::parseDependencies($dependencies);
            } else {
                // 使用默认依赖关系（基于JOIN配置）
                $joinConfig = $this->request->getParam('join', '');
                $dependencyConfig = \Weline\DataTable\Helper\DependencyManager::getDefaultDependencies($modelConfig['models'], $joinConfig);
            }

            // 验证依赖关系
            \Weline\DataTable\Helper\DependencyManager::validateDependencies($dependencyConfig, $modelConfig['models']);

            // 计算保存顺序
            $tableAliases = array_keys($modelConfig['models']);
            $saveOrder = \Weline\DataTable\Helper\DependencyManager::calculateSaveOrder($dependencyConfig, $tableAliases);

            $savedResults = [];
            $allResults = [];

            // 按顺序保存每个表的数据
            foreach ($saveOrder as $tableAlias) {
                if (!isset($data[$tableAlias])) {
                    continue; // 跳过没有数据的表
                }

                $modelClass = $modelConfig['models'][$tableAlias];
                $tableData = $data[$tableAlias];

                // 应用依赖关系
                $tableData = \Weline\DataTable\Helper\DependencyManager::applyDependencies(
                    [$tableAlias => $tableData],
                    $dependencyConfig,
                    $savedResults
                )[$tableAlias];

                // 检查模型类是否存在
                if (!class_exists($modelClass)) {
                    throw new \InvalidArgumentException(__('模型类不存在: %{1}', $modelClass));
                }

                // 实例化模型并保存
                $modelInstance = w_obj($modelClass);

                // 验证数据
                $validatedData = $this->validateData($tableData, $modelInstance);
                if ($validatedData === false) {
                    throw new \InvalidArgumentException("表 {$tableAlias} 的数据验证失败");
                }

                // 设置数据
                foreach ($validatedData as $field => $value) {
                    $modelInstance->setData($field, $value);
                }

                // 保存数据
                $result = $modelInstance->save();

                if (!$result) {
                    throw new \RuntimeException(__('表 %{1} 的记录创建失败', $tableAlias));
                }

                $savedResults[$tableAlias] = [
                    'id' => $modelInstance->getId(),
                    'data' => $modelInstance->getData()
                ];

                $allResults[$tableAlias] = $savedResults[$tableAlias];
            }

            return $allResults;
        };

        try {
            $result = \Weline\DataTable\Helper\TransactionManager::executeInTransaction($operation, 'create_multi_table_record');

            return $this->success('多表记录创建成功', $result);

        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新记录
     */
    public function postUpdate()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $data = $this->request->getParam('data', []);

        try {
            if (empty($model) || empty($id) || empty($data)) {
                return $this->error(__('缺少必需参数: model、id 和 data'));
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            $modelInstance = w_obj($model);

            // 查找记录
            $record = $this->loadRecordById($model, $id);
            if ($record === null) {
                return $this->error(__('记录不存在'));
            }

            // 验证数据
            $validatedData = $this->validateData($data, $modelInstance);
            if ($validatedData === false) {
                return $this->error('数据验证失败');
            }

            // 更新数据
            foreach ($validatedData as $field => $value) {
                $record->setData($field, $value);
            }

            // 保存数据
            $result = $record->save();

            if ($result) {
                return $this->success(__('记录更新成功'), [
                    'id' => $record->getId(),
                    'data' => $record->getData()
                ]);
            } else {
                return $this->error(__('记录更新失败'));
            }

        } catch (\Exception $e) {
            w_log_error("DataTable Update Error: " . $e->getMessage());
            return $this->error(__('记录更新失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 批量更新记录
     * 路由: datatable/rest/v1/data-table/batch-update
     * 方法: POST
     */
    public function postBatchUpdate()
    {
        $model = $this->request->getParam('model');
        $ids = $this->request->getParam('ids', []);
        $data = $this->request->getParam('data', []);

        try {
            // 验证必需参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model, 'ids' => $ids, 'data' => $data],
                ['model', 'ids', 'data']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);

            if (empty($ids) || !is_array($ids)) {
                throw DataTableException::validationFailed('ids参数必须是非空数组');
            }

            if (empty($data) || !is_array($data)) {
                throw DataTableException::validationFailed('data参数必须是非空数组');
            }

            $modelInstance = w_obj($model);
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            // 使用事务处理批量更新
            $operation = function() use ($model, $ids, $data, &$successCount, &$failedCount, &$errors) {
                foreach ($ids as $id) {
                    try {
                        $record = $this->loadRecordById($model, $id);
                        if ($record === null) {
                            $failedCount++;
                            $errors[] = "ID {$id}: 记录不存在";
                            continue;
                        }

                        // 验证数据
                        $validatedData = $this->validateData($data, $record);
                        if ($validatedData === false) {
                            $failedCount++;
                            $errors[] = "ID {$id}: 数据验证失败";
                            continue;
                        }

                        // 更新数据
                        foreach ($validatedData as $field => $value) {
                            $record->setData($field, $value);
                        }

                        // 保存数据
                        if ($record->save()) {
                            $successCount++;
                        } else {
                            $failedCount++;
                            $errors[] = "ID {$id}: 保存失败";
                        }
                    } catch (\Throwable $e) {
                        $failedCount++;
                        $errors[] = "ID {$id}: " . $e->getMessage();
                    }
                }
            };

            \Weline\DataTable\Helper\TransactionManager::executeInTransaction($operation, 'batch_update');

            $message = __('批量更新完成，成功: %{1}，失败: %{2}', [$successCount, $failedCount]);

            return $this->success($message, [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'total_count' => count($ids),
                'errors' => $errors
            ]);

        } catch (DataTableException $e) {
            $e->log('DataTable::postBatchUpdate');
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postBatchUpdate');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Throwable $e) {
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postBatchUpdate');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 批量状态变更
     * 路由: datatable/rest/v1/data-table/batch-status
     * 方法: POST
     */
    public function postBatchStatus()
    {
        $model = $this->request->getParam('model');
        $ids = $this->request->getParam('ids', []);
        $statusField = $this->request->getParam('status_field', 'status');
        $statusValue = $this->request->getParam('status_value');

        try {
            // 验证必需参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model, 'ids' => $ids, 'status_value' => $statusValue],
                ['model', 'ids', 'status_value']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);

            if (empty($ids) || !is_array($ids)) {
                throw DataTableException::validationFailed('ids参数必须是非空数组');
            }

            $modelInstance = w_obj($model);
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            // 使用事务处理批量状态变更
            $operation = function() use ($model, $ids, $statusField, $statusValue, &$successCount, &$failedCount, &$errors) {
                foreach ($ids as $id) {
                    try {
                        $record = $this->loadRecordById($model, $id);
                        if ($record === null) {
                            $failedCount++;
                            $errors[] = "ID {$id}: 记录不存在";
                            continue;
                        }

                        // 设置状态值
                        $record->setData($statusField, $statusValue);

                        // 保存数据
                        if ($record->save()) {
                            $successCount++;
                        } else {
                            $failedCount++;
                            $errors[] = "ID {$id}: 保存失败";
                        }
                    } catch (\Throwable $e) {
                        $failedCount++;
                        $errors[] = "ID {$id}: " . $e->getMessage();
                    }
                }
            };

            \Weline\DataTable\Helper\TransactionManager::executeInTransaction($operation, 'batch_status');

            $message = __('批量状态变更完成，成功: %{1}，失败: %{2}', [$successCount, $failedCount]);

            return $this->success($message, [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'total_count' => count($ids),
                'status_field' => $statusField,
                'status_value' => $statusValue,
                'errors' => $errors
            ]);

        } catch (DataTableException $e) {
            $e->log('DataTable::postBatchStatus');
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postBatchStatus');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Throwable $e) {
            $errorResponse = ErrorHandler::handleException($e, 'DataTable::postBatchStatus');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 删除记录
     */
    public function postDelete()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);

        try {
            if (empty($model) || (empty($id) && empty($ids))) {
                return $this->error(__('缺少必需参数: model 和 id/ids'));
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            $modelInstance = w_obj($model);
            $deletedCount = 0;

            // 批量删除
            if (!empty($ids) && is_array($ids)) {
                foreach ($ids as $deleteId) {
                    $record = $this->loadRecordById($model, $deleteId);
                    if ($record && $record->delete()) {
                        $deletedCount++;
                    }
                }
            } else {
                // 单个删除
                $record = $this->loadRecordById($model, $id);
                if ($record === null) {
                    return $this->error(__('记录不存在'));
                }

                if ($record->delete()) {
                    $deletedCount = 1;
                }
            }

            if ($deletedCount > 0) {
                return $this->success(__('成功删除 %{1} 条记录', $deletedCount), [
                    'deleted_count' => $deletedCount
                ]);
            } else {
                return $this->error(__('删除失败'));
            }

        } catch (\Throwable $e) {
            w_log_error("DataTable Delete Error: " . $e->getMessage());
            return $this->error(__('记录删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 验证数据
     * @param array $data 数据
     * @param object $modelInstance 模型实例
     * @return array|false 验证后的数据或false
     */
    private function validateData(array $data, $modelInstance)
    {
        try {
            $validatedData = [];

            // 获取模型字段信息
            $columns = $modelInstance->columns();
            $fields = [];
            foreach ($columns as $column) {
                // columns() 返回的是包含字段详细信息的数组
                $fieldName = $column['Field'] ?? '';
                if ($fieldName) {
                    $fields[$fieldName] = $column;
                }
            }

            // 验证每个字段
            foreach ($data as $field => $value) {
                // 跳过系统字段
                if (in_array($field, ['created_at', 'updated_at'])) {
                    continue;
                }

                // 基本验证
                if (isset($fields[$field])) {
                    $column = $fields[$field];

                    // 必填验证
                    if ($column['Null'] === 'NO' && empty($value) && $value !== '0') {
                        w_log_error("Field {$field} is required but empty");
                        return false;
                    }

                    // 类型验证
                    if (!$this->validateFieldType($value, $column['Type'])) {
                        w_log_error("Field {$field} type validation failed");
                        return false;
                    }
                }

                $validatedData[$field] = $value;
            }

            return $validatedData;

        } catch (\Exception $e) {
            w_log_error("Data validation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证字段类型
     * @param mixed $value 值
     * @param string $type 数据库类型
     * @return bool
     */
    private function validateFieldType($value, string $type): bool
    {
        if (empty($value) && $value !== '0') {
            return true; // 空值由必填验证处理
        }

        // 简单的类型验证
        if (strpos($type, 'int') !== false) {
            return is_numeric($value);
        } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) {
            return is_numeric($value);
        } elseif (strpos($type, 'date') !== false) {
            return strtotime($value) !== false;
        }

        return true; // 其他类型暂时通过
    }

    /**
     * 获取缓存实例
     */
    private function getCache(): \Weline\Framework\Cache\Contract\CachePoolInterface
    {
        return w_cache('default');
    }

    /**
     * 解析模型配置
     *
     * @param string $modelString 模型字符串，格式：Model1 as alias1, Model2 as alias2
     * @return array 解析后的模型配置
     */
    private function parseModelConfig(string $modelString): array
    {
        $models = [];
        $modelParts = explode(',', $modelString);

        foreach ($modelParts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (strpos($part, ' as ') !== false) {
                // 有别名的情况：Model as alias
                [$modelClass, $alias] = explode(' as ', $part, 2);
                $models[trim($alias)] = trim($modelClass);
            } else {
                // 没有别名的情况，使用类名作为别名
                $modelClass = trim($part);
                $className = basename(str_replace('\\', '/', $modelClass));
                $models[$className] = $modelClass;
            }
        }

        return ['models' => $models];
    }

    /**
     * 处理JOIN查询
     * 
     * @param mixed $modelInstance 模型实例
     * @param array $models 模型配置
     * @param string $join JOIN配置
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param array $filters 过滤条件
     * @param array $sort 排序条件
     * @return array
     */
    private function handleJoinQuery($modelInstance, array $models, string $join, int $page, int $limit, array $filters, array $sort): array
    {
        try {
            // 解析JOIN配置
            $joinParts = $this->parseJoinConfig($join);
            
            // 构建查询字段
            $selectFields = $this->buildSelectFields($models);
            
            // 应用JOIN
            foreach ($joinParts as $joinPart) {
                $modelInstance = $this->applyJoin($modelInstance, $joinPart);
            }
            
            // 应用过滤器
            if (!empty($filters) && is_array($filters)) {
                foreach ($filters as $field => $value) {
                    if (!empty($value) && is_string($value)) {
                        // 处理带表别名的字段
                        if (strpos($field, '.') !== false) {
                            [$tableAlias, $fieldName] = explode('.', $field, 2);
                            $modelInstance->where($field, 'LIKE', '%' . $value . '%');
                        } else {
                            $modelInstance->where($field, 'LIKE', '%' . $value . '%');
                        }
                    }
                }
            }
            
            // 应用排序
            if (!empty($sort) && is_array($sort)) {
                foreach ($sort as $field => $direction) {
                    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                    $modelInstance->order($field, $direction);
                }
            }
            
            // 获取总数（在分页之前）
            $totalQuery = clone $modelInstance;
            $total = $totalQuery->count();
            
            // 应用分页并获取数据
            $data = $modelInstance->pagination($page, $limit)->select($selectFields)->fetch();
            
            return [
                'msg' => '多表JOIN查询成功',
                'data' => [
                    'data' => $data ?: [],
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ],
                'code' => 200
            ];
            
        } catch (\Exception $e) {
            w_log_error("JOIN Query Error: " . $e->getMessage());
            return [
                'msg' => 'JOIN查询失败: ' . $e->getMessage(),
                'data' => '',
                'code' => 404
            ];
        }
    }

    /**
     * 解析JOIN配置
     * 
     * @param string $join JOIN配置字符串
     * @return array 解析后的JOIN配置
     */
    private function parseJoinConfig(string $join): array
    {
        $joinParts = [];
        $joins = explode(',', $join);
        
        foreach ($joins as $joinStr) {
            $joinStr = trim($joinStr);
            if (empty($joinStr)) {
                continue;
            }
            
            // 解析JOIN类型和条件
            // 支持格式：left a.id = b.a_id, inner a.id = c.a_id
            if (preg_match('/^(left|right|inner)?\s*([^=]+)=([^=]+)$/i', $joinStr, $matches)) {
                $joinType = strtoupper(trim($matches[1] ?: 'LEFT'));
                $leftCondition = trim($matches[2]);
                $rightCondition = trim($matches[3]);
                
                $joinParts[] = [
                    'type' => $joinType,
                    'left' => $leftCondition,
                    'right' => $rightCondition,
                    'condition' => $leftCondition . ' = ' . $rightCondition
                ];
            }
        }
        
        return $joinParts;
    }

    /**
     * 构建查询字段
     * 
     * @param array $models 模型配置
     * @return string 查询字段字符串
     */
    private function buildSelectFields(array $models): string
    {
        $selectFields = [];
        
        foreach ($models as $alias => $modelClass) {
            // 获取模型字段并加上别名前缀
            if (class_exists($modelClass)) {
                try {
                    $modelInstance = w_obj($modelClass);
                    
                    // 获取字段列表
                    $fields = $modelInstance->columns();
                    
                    // 添加别名前缀
                    foreach ($fields as $field) {
                        $selectFields[] = "{$alias}.{$field} AS {$alias}_{$field}";
                    }
                } catch (\Exception $e) {
                    w_log_error("Failed to get fields for model {$modelClass}: " . $e->getMessage());
                }
            }
        }
        
        return implode(', ', $selectFields);
    }

    /**
     * 应用JOIN条件
     * 
     * @param mixed $modelInstance 模型实例
     * @param array $joinPart JOIN配置部分
     * @return mixed 应用JOIN后的模型实例
     */
    private function applyJoin($modelInstance, array $joinPart)
    {
        try {
            $joinType = $joinPart['type'];
            $condition = $joinPart['condition'];
            
            // 根据ORM实现调用相应的JOIN方法
            // 这里需要根据具体的ORM实现来调整
            if (method_exists($modelInstance, 'join')) {
                return $modelInstance->join($condition, $joinType);
            } elseif (method_exists($modelInstance, 'leftJoin') && $joinType === 'LEFT') {
                return $modelInstance->leftJoin($condition);
            } elseif (method_exists($modelInstance, 'rightJoin') && $joinType === 'RIGHT') {
                return $modelInstance->rightJoin($condition);
            } elseif (method_exists($modelInstance, 'innerJoin') && $joinType === 'INNER') {
                return $modelInstance->innerJoin($condition);
            } else {
                // 如果没有专门的JOIN方法，使用原始SQL
                $modelInstance->where($condition, '', '', 'AND', true);
            }
            
            return $modelInstance;
            
        } catch (\Exception $e) {
            w_log_error("Apply JOIN Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 批量删除数据
     */
    private function batchDelete(string $model, array $ids, bool $softDelete = false)
    {
        try {
            $modelInstance = new $model();
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $modelInstance->load($id);

                    if ($softDelete && method_exists($modelInstance, 'softDelete')) {
                        $result = $modelInstance->softDelete();
                    } else {
                        $result = $modelInstance->delete();
                    }

                    if ($result) {
                        $successCount++;
                    } else {
                        $failedCount++;
                        $errors[] = "ID {$id} 删除失败";
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $errors[] = __('ID %{1} 删除失败: %{2}', [$id, $e->getMessage()]);
                }
            }

            $message = $softDelete ? __('批量移至回收站完成，成功: %{1}，失败: %{2}', [$successCount, $failedCount]) : __('批量删除完成，成功: %{1}，失败: %{2}', [$successCount, $failedCount]);

            if ($failedCount > 0) {
                return $this->success($message, [
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'errors' => $errors
                ]);
            } else {
                return $this->success($message, [
                    'success_count' => $successCount,
                    'failed_count' => $failedCount
                ]);
            }
        } catch (\Exception $e) {
            return $this->error(__('批量删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 导出数据
     */
    public function exportData()
    {
        $model = $this->request->getParam('model');
        $ids = $this->request->getParam('ids', []);
        $format = $this->request->getParam('format', 'excel');
        $fields = $this->request->getParam('fields', []);

        try {
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            if (empty($ids)) {
                return $this->error('没有选择要导出的数据');
            }

            $modelInstance = new $model();
            $data = [];

            // 获取要导出的数据
            foreach ($ids as $id) {
                $record = $modelInstance->load($id);
                if ($record->getId()) {
                    $data[] = $record->getData();
                }
            }

            if (empty($data)) {
                return $this->error('没有找到要导出的数据');
            }

            // 根据格式导出
            switch ($format) {
                case 'excel':
                    return $this->exportToExcel($data, $fields);
                case 'csv':
                    return $this->exportToCsv($data, $fields);
                case 'json':
                    return $this->exportToJson($data, $fields);
                default:
                    return $this->error('不支持的导出格式: ' . $format);
            }
        } catch (\Exception $e) {
            return $this->error('导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出为Excel格式
     */
    private function exportToExcel(array $data, array $fields = [])
    {
        // 这里可以集成PhpSpreadsheet或其他Excel库
        // 暂时返回CSV格式作为替代
        return $this->exportToCsv($data, $fields);
    }

    /**
     * 导出为CSV格式
     */
    private function exportToCsv(array $data, array $fields = [])
    {
        if (empty($data)) {
            return $this->error('没有数据可导出');
        }

        // 如果没有指定字段，使用第一行数据的所有字段
        if (empty($fields)) {
            $firstRow = reset($data);
            $fields = array_keys($firstRow);
        }

        // 生成CSV内容
        $csvContent = '';

        // 添加表头
        $headers = [];
        foreach ($fields as $field) {
            if (is_array($field) && isset($field['name'])) {
                $headers[] = $field['label'] ?? $field['name'];
            } else {
                $headers[] = $field;
            }
        }
        $csvContent .= implode(',', $headers) . "\n";

        // 添加数据行
        foreach ($data as $row) {
            $csvRow = [];
            foreach ($fields as $field) {
                $fieldName = is_array($field) ? $field['name'] : $field;
                $value = $row[$fieldName] ?? '';

                // 处理包含逗号或引号的值
                if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                    $value = '"' . str_replace('"', '""', $value) . '"';
                }
                $csvRow[] = $value;
            }
            $csvContent .= implode(',', $csvRow) . "\n";
        }

        // 设置响应头
        $filename = 'export_' . date('Y-m-d_H-i-s') . '.csv';

        return $this->success('导出成功', [
            'content' => $csvContent,
            'filename' => $filename,
            'content_type' => 'text/csv'
        ]);
    }

    /**
     * 导出为JSON格式
     */
    private function exportToJson(array $data, array $fields = [])
    {
        // 如果指定了字段，只导出指定字段
        if (!empty($fields)) {
            $filteredData = [];
            foreach ($data as $row) {
                $filteredRow = [];
                foreach ($fields as $field) {
                    $fieldName = is_array($field) ? $field['name'] : $field;
                    $filteredRow[$fieldName] = $row[$fieldName] ?? null;
                }
                $filteredData[] = $filteredRow;
            }
            $data = $filteredData;
        }

        $filename = 'export_' . date('Y-m-d_H-i-s') . '.json';

        return $this->success('导出成功', [
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'filename' => $filename,
            'content_type' => 'application/json'
        ]);
    }

    /**
     * 流式导出数据（支持大数据量）
     */
    public function streamExport()
    {
        $model = $this->request->getParam('model');
        $format = $this->request->getParam('format', 'csv');
        $fields = $this->request->getParam('fields', []);
        $conditions = $this->request->getParam('conditions', []);
        $pageSize = $this->request->getParam('page_size', 1000);

        try {
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            // 创建流式导出器
            $exporter = new \Weline\DataTable\Helper\StreamExporter();
            $exporter->setFormat($format)
                    ->setFields($fields)
                    ->setPageSize($pageSize);

            // 执行导出
            $result = $exporter->export($model, $conditions);

            if ($result['success']) {
                // 读取文件内容
                $content = file_get_contents($result['file_path']);

                // 清理临时文件
                unlink($result['file_path']);

                return $this->success('流式导出成功', [
                    'content' => base64_encode($content),
                    'filename' => $result['filename'],
                    'stats' => $result['stats'],
                    'content_type' => $this->getContentType($format)
                ]);
            } else {
                return $this->error('流式导出失败');
            }

        } catch (\Exception $e) {
            return $this->error('流式导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取内容类型
     */
    private function getContentType(string $format): string
    {
        switch ($format) {
            case 'excel':
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            case 'json':
                return 'application/json';
            case 'csv':
            default:
                return 'text/csv';
        }
    }

    /**
     * 获取导出进度
     */
    public function getExportProgress()
    {
        $sessionId = $this->request->getParam('session_id');

        try {
            // 这里可以从缓存或会话中获取导出进度
            // 暂时返回模拟数据
            return $this->success('获取进度成功', [
                'session_id' => $sessionId,
                'progress' => [
                    'current_page' => 5,
                    'total_pages' => 10,
                    'exported_records' => 5000,
                    'total_records' => 10000,
                    'percentage' => 50.0,
                    'elapsed_time' => 30.5,
                    'status' => 'processing'
                ]
            ]);

        } catch (\Exception $e) {
            return $this->error('获取进度失败: ' . $e->getMessage());
        }
    }

    /**
     * 上传图片文件
     */
    public function postUploadImage()
    {
        try {
            $fieldName = $this->request->getParam('field_name');
            $maxSize = $this->request->getParam('max_size', '2MB');
            $allowedTypes = $this->request->getParam('allowed_types', 'jpg,jpeg,png,gif,webp');
            $uploadPath = $this->request->getParam('upload_path', '/uploads/datatable/images/');

            if (empty($_FILES) || !isset($_FILES['file'])) {
                return $this->error('没有上传文件');
            }

            $file = $_FILES['file'];
            
            // 验证文件上传
            $uploadResult = $this->validateAndProcessImageUpload($file, $maxSize, $allowedTypes, $uploadPath);
            
            if ($uploadResult['success']) {
                return $this->success('图片上传成功', $uploadResult['data']);
            } else {
                return $this->error($uploadResult['message']);
            }

        } catch (\Exception $e) {
            return $this->error('图片上传失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取图片信息
     */
    public function postGetImageInfo()
    {
        try {
            $imagePath = $this->request->getParam('image_path');
            
            if (empty($imagePath)) {
                return $this->error('图片路径不能为空');
            }

            $imageInfo = $this->getImageDetailInfo($imagePath);
            
            if ($imageInfo) {
                return $this->success('获取图片信息成功', $imageInfo);
            } else {
                return $this->error('获取图片信息失败');
            }

        } catch (\Exception $e) {
            return $this->error('获取图片信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除图片文件
     */
    public function postDeleteImage()
    {
        try {
            $imagePath = $this->request->getParam('image_path');
            
            if (empty($imagePath)) {
                return $this->error('图片路径不能为空');
            }

            $result = $this->deleteImageFile($imagePath);
            
            if ($result) {
                return $this->success(__('图片删除成功'));
            } else {
                return $this->error(__('图片删除失败'));
            }

        } catch (\Exception $e) {
            return $this->error(__('图片删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 验证和处理图片上传
     */
    private function validateAndProcessImageUpload(array $file, string $maxSize, string $allowedTypes, string $uploadPath): array
    {
        try {
            // 检查上传错误
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return [
                    'success' => false,
                    'message' => $this->getUploadErrorMessage($file['error'])
                ];
            }

            // 验证文件大小
            $maxSizeBytes = $this->parseFileSize($maxSize);
            if ($file['size'] > $maxSizeBytes) {
                return [
                    'success' => false,
                    'message' => "文件大小超过限制 ({$maxSize})"
                ];
            }

            // 验证文件类型
            $allowedTypesArray = array_map('trim', explode(',', strtolower($allowedTypes)));
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedTypesArray)) {
                return [
                    'success' => false,
                    'message' => "不支持的文件格式，只支持: {$allowedTypes}"
                ];
            }

            // 验证是否为图片文件
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                return [
                    'success' => false,
                    'message' => '文件不是有效的图片格式'
                ];
            }

            // 创建上传目录
            $fullUploadPath = rtrim(\Weline\Framework\Env\WelineEnv::server('DOCUMENT_ROOT', ''), '/') . '/' . trim($uploadPath, '/');
            if (!is_dir($fullUploadPath)) {
                if (!mkdir($fullUploadPath, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => '无法创建上传目录'
                    ];
                }
            }

            // 生成唯一文件名
            $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $filePath = $fullUploadPath . '/' . $fileName;
            $webPath = '/' . trim($uploadPath, '/') . '/' . $fileName;

            // 移动文件
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return [
                    'success' => false,
                    'message' => '文件保存失败'
                ];
            }

            // 获取详细图片信息
            $detailedInfo = $this->getImageDetailInfo($filePath);

            return [
                'success' => true,
                'data' => [
                    'file_name' => $fileName,
                    'original_name' => $file['name'],
                    'file_path' => $filePath,
                    'web_path' => $webPath,
                    'file_size' => $file['size'],
                    'file_size_formatted' => $this->formatFileSize($file['size']),
                    'mime_type' => $imageInfo['mime'],
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1],
                    'extension' => $fileExtension,
                    'upload_time' => date('Y-m-d H:i:s'),
                    'detailed_info' => $detailedInfo
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '上传处理失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 获取图片详细信息
     */
    private function getImageDetailInfo(string $imagePath): ?array
    {
        try {
            if (!file_exists($imagePath)) {
                return null;
            }

            $fileSize = filesize($imagePath);
            $imageInfo = @getimagesize($imagePath);
            
            if ($imageInfo === false) {
                return null;
            }

            $info = [
                'file_name' => basename($imagePath),
                'file_path' => $imagePath,
                'file_size' => $fileSize,
                'file_size_formatted' => $this->formatFileSize($fileSize),
                'mime_type' => $imageInfo['mime'],
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'dimensions' => $imageInfo[0] . ' × ' . $imageInfo[1],
                'extension' => strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)),
                'aspect_ratio' => round($imageInfo[0] / $imageInfo[1], 2),
                'created_time' => date('Y-m-d H:i:s', filectime($imagePath)),
                'modified_time' => date('Y-m-d H:i:s', filemtime($imagePath))
            ];

            // 获取EXIF信息（如果存在）
            if (function_exists('exif_read_data') && in_array($info['extension'], ['jpg', 'jpeg', 'tiff'])) {
                $exifData = @exif_read_data($imagePath);
                if ($exifData) {
                    $info['exif'] = [
                        'camera' => $exifData['Model'] ?? null,
                        'datetime' => $exifData['DateTime'] ?? null,
                        'orientation' => $exifData['Orientation'] ?? null
                    ];
                }
            }

            return $info;

        } catch (\Exception $e) {
            w_log_error("Get image info error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 删除图片文件
     */
    private function deleteImageFile(string $imagePath): bool
    {
        try {
            if (file_exists($imagePath)) {
                return unlink($imagePath);
            }
            return true;
        } catch (\Exception $e) {
            w_log_error("Delete image error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 解析文件大小字符串为字节数
     */
    private function parseFileSize(string $sizeStr): int
    {
        $sizeStr = strtoupper(trim($sizeStr));
        $size = (int)$sizeStr;
        
        if (strpos($sizeStr, 'KB') !== false) {
            return $size * 1024;
        } elseif (strpos($sizeStr, 'MB') !== false) {
            return $size * 1024 * 1024;
        } elseif (strpos($sizeStr, 'GB') !== false) {
            return $size * 1024 * 1024 * 1024;
        }
        
        return $size;
    }

    /**
     * 格式化文件大小
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * 获取上传错误消息
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return '文件大小超过php.ini设置的限制';
            case UPLOAD_ERR_FORM_SIZE:
                return '文件大小超过表单设置的限制';
            case UPLOAD_ERR_PARTIAL:
                return '文件只上传了一部分';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '临时目录未设置';
            case UPLOAD_ERR_CANT_WRITE:
                return '文件写入失败';
            case UPLOAD_ERR_EXTENSION:
                return '文件上传被扩展程序阻止';
            default:
                                 return '未知上传错误';
         }
     }

    /**
     * 获取回收站数据
     */
    public function postRecycleBinData()
    {
        $model = $this->request->getParam('model');
        $scope = $this->request->getParam('scope');
        $page = max(1, intval($this->request->getParam('page', 1)));
        $limit = max(1, min(100, intval($this->request->getParam('limit', 20))));
        $filters = $this->request->getParam('filters', []);
        $sort = $this->request->getParam('sort', []);

        try {
            if (empty($model) || empty($scope)) {
                return $this->error(__('缺少必需参数: model 和 scope'));
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            $modelInstance = w_obj($model);

            // 检查是否支持软删除
            if (!method_exists($modelInstance, 'onlyTrashed')) {
                return $this->error('该模型不支持软删除功能');
            }

            // 只查询已软删除的记录
            $modelInstance = $modelInstance->onlyTrashed();

            // 应用过滤器
            if (!empty($filters) && is_array($filters)) {
                foreach ($filters as $field => $value) {
                    if (!empty($value) && is_string($value)) {
                        if ($this->isNumericField($field)) {
                            $modelInstance->where($field, '=', $value);
                        } elseif ($this->isDateField($field)) {
                            $modelInstance->where($field, 'LIKE', $value . '%');
                        } else {
                            $modelInstance->where($field, 'LIKE', '%' . $value . '%');
                        }
                    }
                }
            }

            // 应用排序
            if (!empty($sort) && is_array($sort)) {
                foreach ($sort as $field => $direction) {
                    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                    $modelInstance->order($field, $direction);
                }
            } else {
                // 默认按删除时间倒序
                $deleteField = method_exists($modelInstance, 'getSoftDeleteField') ? 
                    $modelInstance->getSoftDeleteField() : 
                    'deleted_at';
                $modelInstance->order($deleteField, 'DESC');
            }

            // 获取总数（在分页之前）
            $totalQuery = clone $modelInstance;
            $total = $totalQuery->count();

            // 应用分页
            $data = $modelInstance->pagination($page, $limit)->select()->fetch();

            // 处理软删除数据，添加额外信息
            $processedData = [];
            foreach ($data as $record) {
                $processedRecord = $record;
                $deleteField = method_exists($modelInstance, 'getSoftDeleteField') ? 
                    $modelInstance->getSoftDeleteField() : 
                    'deleted_at';
                
                if (isset($record[$deleteField])) {
                    $deletedAt = strtotime($record[$deleteField]);
                    $now = time();
                    $daysSinceDeleted = floor(($now - $deletedAt) / (24 * 60 * 60));
                    
                    $processedRecord['_recycle_info'] = [
                        'deleted_at' => $record[$deleteField],
                        'days_since_deleted' => $daysSinceDeleted,
                        'days_remaining' => max(0, 180 - $daysSinceDeleted),
                        'can_restore' => $daysSinceDeleted <= 180,
                        'will_auto_delete' => $daysSinceDeleted > 150
                    ];
                }
                
                $processedData[] = $processedRecord;
            }

            return $this->success('回收站数据获取成功', [
                'data' => $processedData,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
                'recycle_info' => [
                    'retention_days' => 180,
                    'warning_days' => 150
                ]
            ]);

        } catch (\Exception $e) {
            w_log_error("RecycleBin API Error: " . $e->getMessage());
            return $this->error('回收站数据获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 恢复回收站记录
     */
    public function postRestoreRecord()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);

        try {
            if (empty($model)) {
                return $this->error('模型类不能为空');
            }

            if (empty($id) && empty($ids)) {
                return $this->error('记录ID不能为空');
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            // 处理批量恢复
            if (!empty($ids) && is_array($ids)) {
                return $this->batchRestoreRecords($model, $ids);
            }

            // 单个恢复
            return $this->singleRestoreRecord($model, $id);

        } catch (\Exception $e) {
            w_log_error("Restore Record Error: " . $e->getMessage());
            return $this->error('记录恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * 永久删除回收站记录
     */
    public function postPermanentlyDelete()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);
        $confirm = $this->request->getParam('confirm', false);

        try {
            if (!$confirm) {
                return $this->error('请确认要永久删除这些记录，此操作不可撤销');
            }

            if (empty($model)) {
                return $this->error('模型类不能为空');
            }

            if (empty($id) && empty($ids)) {
                return $this->error('记录ID不能为空');
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            // 处理批量永久删除
            if (!empty($ids) && is_array($ids)) {
                return $this->batchPermanentlyDelete($model, $ids);
            }

            // 单个永久删除
            return $this->singlePermanentlyDelete($model, $id);

        } catch (\Exception $e) {
            w_log_error("Permanently Delete Error: " . $e->getMessage());
            return $this->error(__('永久删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 清空回收站
     */
    public function postEmptyRecycleBin()
    {
        $model = $this->request->getParam('model');
        $confirm = $this->request->getParam('confirm', false);
        $olderThan = $this->request->getParam('older_than', 180); // 默认180天

        try {
            if (!$confirm) {
                return $this->error('请确认要清空回收站，此操作不可撤销');
            }

            if (empty($model)) {
                return $this->error('模型类不能为空');
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', $model));
            }

            $modelInstance = w_obj($model);

            // 检查是否支持软删除
            if (!method_exists($modelInstance, 'cleanupExpiredSoftDeleted')) {
                return $this->error('该模型不支持软删除清理功能');
            }

            $deletedCount = $modelInstance->cleanupExpiredSoftDeleted($olderThan);

            return $this->success('回收站清空成功', [
                'deleted_count' => $deletedCount,
                'older_than_days' => $olderThan
            ]);

        } catch (\Exception $e) {
            w_log_error("Empty RecycleBin Error: " . $e->getMessage());
            return $this->error('清空回收站失败: ' . $e->getMessage());
        }
    }

    /**
     * 单个记录恢复
     */
    private function singleRestoreRecord(string $model, $id)
    {
        try {
            $modelInstance = w_obj($model);
            
            // 检查是否支持软删除
            if (!method_exists($modelInstance, 'withTrashed') || !method_exists($modelInstance, 'restore')) {
                return $this->error('该模型不支持软删除恢复功能');
            }

            // 查找软删除的记录
            $record = $modelInstance->withTrashed()->load($id);
            
            if (!$record->getId()) {
                return $this->error(__('记录不存在'));
            }

            // 检查记录是否已被软删除
            if (!method_exists($record, 'isTrashed') || !$record->isTrashed()) {
                return $this->error('该记录未被删除，无需恢复');
            }

            // 恢复记录
            $result = $record->restore();

            if ($result) {
                return $this->success('记录恢复成功', [
                    'id' => $id,
                    'restored_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                return $this->error('记录恢复失败');
            }

        } catch (\Exception $e) {
            return $this->error('恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量记录恢复
     */
    private function batchRestoreRecords(string $model, array $ids)
    {
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $result = $this->singleRestoreRecord($model, $id);
                
                if ($result['code'] === 200) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $errors[] = "ID {$id}: " . $result['msg'];
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = "ID {$id}: " . $e->getMessage();
            }
        }

        $message = "批量恢复完成，成功: {$successCount}，失败: {$failedCount}";
        
        return $this->success($message, [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'restored_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 单个记录永久删除
     */
    private function singlePermanentlyDelete(string $model, $id)
    {
        try {
            $modelInstance = w_obj($model);
            
            // 检查是否支持强制删除
            if (!method_exists($modelInstance, 'withTrashed') || !method_exists($modelInstance, 'forceDelete')) {
                return $this->error('该模型不支持永久删除功能');
            }

            // 查找软删除的记录
            $record = $modelInstance->withTrashed()->load($id);
            
            if (!$record->getId()) {
                return $this->error(__('记录不存在'));
            }

            // 永久删除记录
            $result = $record->forceDelete();

            if ($result) {
                return $this->success(__('记录已永久删除'), [
                    'id' => $id,
                    'deleted_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                return $this->error(__('永久删除失败'));
            }

        } catch (\Exception $e) {
            return $this->error(__('永久删除失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 批量永久删除
     */
    private function batchPermanentlyDelete(string $model, array $ids)
    {
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $result = $this->singlePermanentlyDelete($model, $id);
                
                if ($result['code'] === 200) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $errors[] = "ID {$id}: " . $result['msg'];
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = "ID {$id}: " . $e->getMessage();
            }
        }

        $message = "批量永久删除完成，成功: {$successCount}，失败: {$failedCount}";
        
        return $this->success($message, [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'deleted_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function normalizeBooleanFlag(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $normalized ?? $default;
    }

    private function loadRecordById(string $model, int|string $id): ?object
    {
        if (!class_exists($model)) {
            return null;
        }

        $record = w_obj($model);
        if (method_exists($record, 'reset')) {
            $record->reset();
        }
        if (method_exists($record, 'load')) {
            $record->load($id);
        }

        return method_exists($record, 'getId') && $record->getId() ? $record : null;
    }
 }
