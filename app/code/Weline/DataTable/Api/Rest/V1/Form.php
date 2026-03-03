<?php
/**
 * DataTable 表单API控制器
 * 处理表单字段获取、表单提交等API请求
 */

namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\DataTable\Exception\DataTableException;
use Weline\DataTable\Helper\ErrorHandler;

class Form extends BackendRestController
{
    /**
     * 获取表单字段信息
     * 路由: datatable/rest/v1/form/fields
     * 方法: POST 或 GET
     */
    public function getFields()
    {
        return $this->postFields();
    }

    /**
     * 获取表单字段信息
     * 路由: datatable/rest/v1/form/fields
     * 方法: POST
     */
    public function postFields()
    {
        try {
            // 获取请求参数
            $bodyParams = $this->request->getBodyParams();
            $model = $bodyParams['model'] ?? $this->request->getParam('model');
            $scope = $bodyParams['scope'] ?? $this->request->getParam('scope');
            $formId = $bodyParams['form_id'] ?? $this->request->getParam('form_id');
            $excludeFields = $bodyParams['exclude_fields'] ?? $this->request->getParam('exclude_fields', []);
            $includeFields = $bodyParams['include_fields'] ?? $this->request->getParam('include_fields', []);
            $manualFields = $bodyParams['manual_fields'] ?? $this->request->getParam('manual_fields', []);

            // 验证必需参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model],
                ['model']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);

            // 实例化模型
            $modelInstance = w_obj($model);

            // 获取模型字段信息
            $modelFields = [];
            $columns = [];

            // 使用 columns() 方法获取字段信息
            try {
                $columns = $modelInstance->columns();
                $modelFields = [];
                if (is_array($columns) && !empty($columns)) {
                    foreach ($columns as $column) {
                        if (is_array($column)) {
                            $fieldName = $column['Field'] ?? $column['field'] ?? '';
                            if (!empty($fieldName)) {
                                $modelFields[] = $fieldName;
                            }
                        } elseif (is_string($column)) {
                            $modelFields[] = $column;
                        }
                    }
                }
                
                // 如果仍然没有字段，尝试使用反射获取常量字段
                if (empty($modelFields)) {
                    $reflection = new \ReflectionClass($modelInstance);
                    $constants = $reflection->getConstants();
                    foreach ($constants as $key => $value) {
                        if (str_starts_with($key, 'fields_') && !empty($value) && is_string($value)) {
                            $modelFields[] = $value;
                        }
                    }
                }
            } catch (\Exception $e) {
                w_log_error("DataTable Form API: 获取字段信息失败: " . $e->getMessage());
                w_log_error("DataTable Form API: 异常堆栈: " . $e->getTraceAsString());
            }

            // 过滤空字段名
            $modelFields = array_filter($modelFields, function($field) {
                return !empty($field) && is_string($field);
            });

            // 获取主键
            $primaryKey = 'id';
            if (method_exists($modelInstance, 'getPrimaryKey')) {
                $primaryKey = $modelInstance->getPrimaryKey() ?? 'id';
            }

            // 构建字段信息数组
            $fields = [];
            foreach ($modelFields as $fieldName) {
                // 排除指定字段
                if (!empty($excludeFields) && in_array($fieldName, $excludeFields)) {
                    continue;
                }

                // 如果指定了包含字段，只返回包含的字段
                if (!empty($includeFields) && !in_array($fieldName, $includeFields)) {
                    continue;
                }

                // 排除手动设置的字段
                if (!empty($manualFields) && in_array($fieldName, $manualFields)) {
                    continue;
                }

                // 获取字段类型
                $fieldType = $this->getFieldType($fieldName, $columns);
                
                // 获取列信息（用于 maxlength、step 等属性）
                $columnInfo = $this->getColumnInfo($fieldName, $columns);
                
                // 构建字段信息
                $fieldInfo = [
                    'name' => $fieldName,
                    'label' => $this->getFieldLabel($fieldName),
                    'type' => $fieldType,
                    'required' => $fieldName === $primaryKey || ($columnInfo['nullable'] === false && $columnInfo['default'] === null),
                    'readonly' => $fieldName === $primaryKey,
                    'disabled' => false,
                    'placeholder' => __('请输入%{1}', [$this->getFieldLabel($fieldName)]),
                    'value' => $columnInfo['default'] ?? '',
                    'options' => $this->getFieldOptions($fieldName, $fieldType),
                    'validation' => $this->getFieldValidation($fieldName, $fieldType),
                    'help' => $columnInfo['comment'] ?? '',
                    'maxlength' => $columnInfo['maxlength'] ?? 0,
                    'min' => $columnInfo['min'] ?? null,
                    'max' => $columnInfo['max'] ?? null,
                    'step' => $this->getFieldStep($fieldType, $columnInfo),
                ];

                $fields[] = $fieldInfo;
            }

            // 如果没有获取到任何字段，返回提示信息
            if (empty($fields)) {
                return $this->error(__('未找到任何字段'), [
                    'fields' => [],
                    'model' => $model,
                    'scope' => $scope,
                    'form_id' => $formId,
                    'message' => __('模型 %{1} 没有可用的字段，或者所有字段都被排除了', [$model])
                ], 200);
            }

            return $this->success(__('获取字段成功'), [
                'fields' => $fields,
                'model' => $model,
                'scope' => $scope,
                'form_id' => $formId,
                'total' => count($fields)
            ]);

        } catch (DataTableException $e) {
            $e->log('Form::postFields');
            $errorResponse = ErrorHandler::handleException($e, 'Form::postFields');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Exception $e) {
            $errorResponse = ErrorHandler::handleException($e, 'Form::postFields');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 获取字段类型
     * 优先根据数据库列类型判断，然后根据字段名推断
     */
    private function getFieldType(string $fieldName, array $columns = []): string
    {
        // 首先尝试从数据库列信息推断类型（更准确）
        $dbType = $this->getFieldTypeFromColumn($fieldName, $columns);
        if ($dbType !== null) {
            return $dbType;
        }

        // 根据字段名推断类型
        $fieldNameLower = strtolower($fieldName);
        
        // 邮箱字段
        if (strpos($fieldNameLower, 'email') !== false || strpos($fieldNameLower, 'mail') !== false) {
            return 'email';
        }
        // 密码字段
        if (strpos($fieldNameLower, 'password') !== false || strpos($fieldNameLower, 'pwd') !== false) {
            return 'password';
        }
        // 电话字段
        if (strpos($fieldNameLower, 'phone') !== false || strpos($fieldNameLower, 'tel') !== false || strpos($fieldNameLower, 'mobile') !== false) {
            return 'tel';
        }
        // URL字段
        if (strpos($fieldNameLower, 'url') !== false || strpos($fieldNameLower, 'link') !== false || strpos($fieldNameLower, 'website') !== false) {
            return 'url';
        }
        // 日期时间字段
        if (strpos($fieldNameLower, 'datetime') !== false || 
            (strpos($fieldNameLower, 'date') !== false && strpos($fieldNameLower, 'time') !== false)) {
            return 'datetime';
        }
        if (strpos($fieldNameLower, 'date') !== false || strpos($fieldNameLower, 'created_at') !== false || 
            strpos($fieldNameLower, 'updated_at') !== false || strpos($fieldNameLower, 'deleted_at') !== false) {
            return 'date';
        }
        // 时间字段
        if (strpos($fieldNameLower, 'time') !== false || strpos($fieldNameLower, 'hours') !== false) {
            return 'time';
        }
        // 图片字段
        if (strpos($fieldNameLower, 'image') !== false || strpos($fieldNameLower, 'photo') !== false || 
            strpos($fieldNameLower, 'avatar') !== false || strpos($fieldNameLower, 'logo') !== false ||
            strpos($fieldNameLower, 'thumbnail') !== false || strpos($fieldNameLower, 'icon') !== false) {
            return 'image';
        }
        // 文件字段
        if (strpos($fieldNameLower, 'file') !== false || strpos($fieldNameLower, 'attachment') !== false || 
            strpos($fieldNameLower, 'document') !== false) {
            return 'file';
        }
        // 状态/类型字段（下拉选择）
        if (strpos($fieldNameLower, 'status') !== false || $fieldNameLower === 'type' || 
            strpos($fieldNameLower, 'state') !== false || strpos($fieldNameLower, 'category') !== false ||
            strpos($fieldNameLower, 'is_') !== false || strpos($fieldNameLower, 'has_') !== false) {
            return 'select';
        }
        // 长文本字段
        if (strpos($fieldNameLower, 'content') !== false || strpos($fieldNameLower, 'description') !== false || 
            strpos($fieldNameLower, 'detail') !== false || strpos($fieldNameLower, 'remark') !== false ||
            strpos($fieldNameLower, 'note') !== false || strpos($fieldNameLower, 'comment') !== false ||
            strpos($fieldNameLower, 'meta_') !== false) {
            return 'textarea';
        }
        // 数字字段
        if (strpos($fieldNameLower, 'price') !== false || strpos($fieldNameLower, 'amount') !== false || 
            strpos($fieldNameLower, 'money') !== false || strpos($fieldNameLower, 'cost') !== false ||
            strpos($fieldNameLower, 'total') !== false || strpos($fieldNameLower, 'quantity') !== false ||
            strpos($fieldNameLower, 'qty') !== false || strpos($fieldNameLower, 'count') !== false ||
            strpos($fieldNameLower, 'number') !== false || strpos($fieldNameLower, 'num') !== false ||
            strpos($fieldNameLower, 'age') !== false || strpos($fieldNameLower, 'weight') !== false ||
            strpos($fieldNameLower, 'width') !== false || strpos($fieldNameLower, 'height') !== false ||
            strpos($fieldNameLower, 'length') !== false || strpos($fieldNameLower, 'size') !== false ||
            strpos($fieldNameLower, 'sort') !== false || strpos($fieldNameLower, 'order') !== false ||
            strpos($fieldNameLower, 'latitude') !== false || strpos($fieldNameLower, 'longitude') !== false ||
            strpos($fieldNameLower, 'lat') !== false || strpos($fieldNameLower, 'lng') !== false ||
            strpos($fieldNameLower, 'percent') !== false || strpos($fieldNameLower, 'rate') !== false ||
            strpos($fieldNameLower, 'score') !== false || strpos($fieldNameLower, 'level') !== false) {
            return 'number';
        }
        // 外键ID字段（数字）
        if (preg_match('/_id$/', $fieldNameLower) && $fieldNameLower !== 'id') {
            return 'number';
        }

        // 默认返回文本类型
        return 'text';
    }

    /**
     * 从数据库列信息获取字段类型
     */
    private function getFieldTypeFromColumn(string $fieldName, array $columns): ?string
    {
        if (empty($columns)) {
            return null;
        }

        foreach ($columns as $column) {
            $colName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? $column['COLUMN_NAME'] ?? '') : '';
            if ($colName !== $fieldName) {
                continue;
            }

            $type = is_array($column) ? ($column['Type'] ?? $column['type'] ?? $column['DATA_TYPE'] ?? '') : '';
            $typeLower = strtolower($type);
            
            // 整数类型
            if (preg_match('/^(tiny|small|medium|big)?int/i', $typeLower)) {
                return 'number';
            }
            // 浮点数类型
            if (preg_match('/^(decimal|float|double|numeric|real)/i', $typeLower)) {
                return 'number';
            }
            // 日期时间类型
            if (strpos($typeLower, 'datetime') !== false || strpos($typeLower, 'timestamp') !== false) {
                return 'datetime';
            }
            if (strpos($typeLower, 'date') !== false) {
                return 'date';
            }
            if (strpos($typeLower, 'time') !== false) {
                return 'time';
            }
            // 长文本类型
            if (preg_match('/^(text|mediumtext|longtext|clob)/i', $typeLower)) {
                return 'textarea';
            }
            // BLOB类型（文件）
            if (preg_match('/^(blob|mediumblob|longblob|binary|varbinary)/i', $typeLower)) {
                return 'file';
            }
            // BOOLEAN类型
            if (preg_match('/^(bool|boolean|bit)/i', $typeLower)) {
                return 'select';
            }
            // ENUM类型
            if (strpos($typeLower, 'enum') !== false) {
                return 'select';
            }

            // VARCHAR/CHAR 默认返回 null，让字段名推断来处理
            break;
        }

        return null;
    }

    /**
     * 获取字段标签
     */
    private function getFieldLabel(string $fieldName): string
    {
        // 将字段名转换为标签（驼峰转中文）
        $label = str_replace('_', ' ', $fieldName);
        $label = ucwords($label);
        
        // 常见字段名映射
        $mappings = [
            'id' => 'ID',
            'name' => '名称',
            'title' => '标题',
            'email' => '邮箱',
            'phone' => '电话',
            'status' => '状态',
            'create_time' => '创建时间',
            'update_time' => '更新时间',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
            'deleted_at' => '删除时间',
            'password' => '密码',
            'description' => '描述',
            'content' => '内容',
            'image' => '图片',
            'avatar' => '头像',
            'price' => '价格',
            'amount' => '金额',
            'quantity' => '数量',
            'sort' => '排序',
            'order' => '排序'
        ];

        $fieldNameLower = strtolower($fieldName);
        if (isset($mappings[$fieldNameLower])) {
            return $mappings[$fieldNameLower];
        }

        return $label;
    }

    /**
     * 获取字段选项（用于select等类型）
     */
    private function getFieldOptions(string $fieldName, string $fieldType): array
    {
        if ($fieldType !== 'select' && $fieldType !== 'radio' && $fieldType !== 'checkbox') {
            return [];
        }

        $fieldNameLower = strtolower($fieldName);
        
        // 状态字段的默认选项
        if (strpos($fieldNameLower, 'status') !== false) {
            return [
                ['value' => '1', 'label' => __('启用')],
                ['value' => '0', 'label' => __('禁用')]
            ];
        }

        // 类型字段的默认选项
        if (strpos($fieldNameLower, 'type') !== false) {
            return [
                ['value' => '1', 'label' => __('类型1')],
                ['value' => '2', 'label' => __('类型2')]
            ];
        }

        return [];
    }

    /**
     * 获取字段验证规则
     */
    private function getFieldValidation(string $fieldName, string $fieldType): array
    {
        $validation = [];

        // 根据字段类型添加验证规则
        if ($fieldType === 'email') {
            $validation['pattern'] = '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$';
        } elseif ($fieldType === 'url') {
            $validation['pattern'] = '^https?://.+';
        } elseif ($fieldType === 'number') {
            $validation['type'] = 'number';
        } elseif ($fieldType === 'tel') {
            $validation['pattern'] = '^[\\d\\-\\+\\(\\)\\s]+$';
        }

        return $validation;
    }

    /**
     * 获取列详细信息
     */
    private function getColumnInfo(string $fieldName, array $columns): array
    {
        $info = [
            'maxlength' => 0,
            'nullable' => true,
            'default' => null,
            'comment' => '',
            'precision' => null,
            'scale' => null,
            'min' => null,
            'max' => null,
        ];

        if (empty($columns)) {
            return $info;
        }

        foreach ($columns as $column) {
            $colName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? $column['COLUMN_NAME'] ?? '') : '';
            if ($colName !== $fieldName) {
                continue;
            }

            $type = is_array($column) ? ($column['Type'] ?? $column['type'] ?? $column['DATA_TYPE'] ?? '') : '';
            $typeLower = strtolower($type);
            
            // 解析 VARCHAR(255) 或 DECIMAL(10,2) 格式
            if (preg_match('/varchar\((\d+)\)/i', $type, $matches)) {
                $info['maxlength'] = (int)$matches[1];
            } elseif (preg_match('/char\((\d+)\)/i', $type, $matches)) {
                $info['maxlength'] = (int)$matches[1];
            } elseif (preg_match('/decimal\((\d+),(\d+)\)/i', $type, $matches)) {
                $info['precision'] = (int)$matches[1];
                $info['scale'] = (int)$matches[2];
            }
            
            // 获取是否允许 NULL
            $nullable = $column['Null'] ?? $column['null'] ?? $column['IS_NULLABLE'] ?? 'YES';
            $info['nullable'] = strtoupper($nullable) === 'YES' || $nullable === true;
            
            // 获取默认值
            $info['default'] = $column['Default'] ?? $column['default'] ?? $column['COLUMN_DEFAULT'] ?? null;
            
            // 获取注释
            $info['comment'] = $column['Comment'] ?? $column['comment'] ?? $column['COLUMN_COMMENT'] ?? '';
            
            break;
        }

        return $info;
    }

    /**
     * 获取字段步进值（用于 number 类型）
     */
    private function getFieldStep(string $fieldType, array $columnInfo): ?string
    {
        if ($fieldType !== 'number') {
            return null;
        }

        // 如果有小数位数，设置相应的步进值
        if (isset($columnInfo['scale']) && $columnInfo['scale'] > 0) {
            return '0.' . str_repeat('0', $columnInfo['scale'] - 1) . '1';
        }

        // 整数类型默认步进值为 1
        return '1';
    }

    /**
     * 获取表单记录数据（编辑模式）
     * 路由: datatable/rest/v1/form/record
     * 方法: POST
     */
    public function postRecord()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $model = $bodyParams['model'] ?? $this->request->getParam('model');
            $recordId = $bodyParams['record_id'] ?? $this->request->getParam('record_id');

            if (empty($model) || empty($recordId)) {
                return $this->error(__('缺少必需参数: model 或 record_id'));
            }

            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', [$model]));
            }

            $modelInstance = w_obj($model);
            $modelInstance->load($recordId);

            if (!$modelInstance->getId()) {
                return $this->error(__('记录不存在'));
            }

            return $this->success(__('获取记录成功'), [
                'record' => $modelInstance->getData()
            ]);

        } catch (\Exception $e) {
            w_log_error("DataTable Form API Error: " . $e->getMessage());
            return $this->error(__('获取记录失败: %{1}', [$e->getMessage()]));
        }
    }
}

