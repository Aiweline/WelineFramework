<?php
/**
 * DataTable 表单API控制器
 * 处理表单字段获取、表单提交等API请求
 */

namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;

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
            if (empty($model)) {
                return $this->error(__('缺少必需参数: model。请传递 model 参数指定表单对应的模型类名，例如：WeShop\\Store\\Model\\Store'), '', 400);
            }

            // 验证模型类是否存在
            if (!class_exists($model)) {
                return $this->error(__('模型类不存在: %{1}', [$model]));
            }

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
                error_log("DataTable Form API: 获取字段信息失败: " . $e->getMessage());
                error_log("DataTable Form API: 异常堆栈: " . $e->getTraceAsString());
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
                
                // 构建字段信息
                $fieldInfo = [
                    'name' => $fieldName,
                    'label' => $this->getFieldLabel($fieldName),
                    'type' => $fieldType,
                    'required' => $fieldName === $primaryKey,
                    'readonly' => $fieldName === $primaryKey,
                    'placeholder' => __('请输入%{1}', [$this->getFieldLabel($fieldName)]),
                    'value' => '',
                    'options' => $this->getFieldOptions($fieldName, $fieldType),
                    'validation' => $this->getFieldValidation($fieldName, $fieldType),
                    'help' => ''
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

        } catch (\Exception $e) {
            error_log("DataTable Form API Error: " . $e->getMessage());
            return $this->error(__('获取字段失败: %{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取字段类型
     */
    private function getFieldType(string $fieldName, array $columns = []): string
    {
        // 根据字段名推断类型
        $fieldNameLower = strtolower($fieldName);
        
        if (strpos($fieldNameLower, 'email') !== false) {
            return 'email';
        }
        if (strpos($fieldNameLower, 'password') !== false) {
            return 'password';
        }
        if (strpos($fieldNameLower, 'phone') !== false || strpos($fieldNameLower, 'tel') !== false) {
            return 'tel';
        }
        if (strpos($fieldNameLower, 'url') !== false || strpos($fieldNameLower, 'link') !== false) {
            return 'url';
        }
        if (strpos($fieldNameLower, 'date') !== false) {
            if (strpos($fieldNameLower, 'time') !== false && strpos($fieldNameLower, 'date') !== false) {
                return 'datetime';
            }
            return 'date';
        }
        if (strpos($fieldNameLower, 'time') !== false) {
            return 'time';
        }
        if (strpos($fieldNameLower, 'image') !== false || strpos($fieldNameLower, 'photo') !== false || strpos($fieldNameLower, 'avatar') !== false) {
            return 'image';
        }
        if (strpos($fieldNameLower, 'file') !== false || strpos($fieldNameLower, 'attachment') !== false) {
            return 'file';
        }
        if (strpos($fieldNameLower, 'status') !== false || strpos($fieldNameLower, 'type') !== false || strpos($fieldNameLower, 'state') !== false) {
            return 'select';
        }
        if (strpos($fieldNameLower, 'content') !== false || strpos($fieldNameLower, 'description') !== false || strpos($fieldNameLower, 'detail') !== false) {
            return 'textarea';
        }
        if (strpos($fieldNameLower, 'price') !== false || strpos($fieldNameLower, 'amount') !== false || strpos($fieldNameLower, 'money') !== false) {
            return 'number';
        }
        if (strpos($fieldNameLower, 'id') !== false && $fieldNameLower !== 'id') {
            return 'number';
        }

        // 从数据库列信息推断类型
        if (!empty($columns)) {
            foreach ($columns as $column) {
                $colName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? '') : '';
                if ($colName === $fieldName) {
                    $type = is_array($column) ? ($column['Type'] ?? $column['type'] ?? '') : '';
                    $typeLower = strtolower($type);
                    
                    if (strpos($typeLower, 'int') !== false) {
                        return 'number';
                    }
                    if (strpos($typeLower, 'decimal') !== false || strpos($typeLower, 'float') !== false || strpos($typeLower, 'double') !== false) {
                        return 'number';
                    }
                    if (strpos($typeLower, 'date') !== false) {
                        if (strpos($typeLower, 'time') !== false) {
                            return 'datetime';
                        }
                        return 'date';
                    }
                    if (strpos($typeLower, 'time') !== false) {
                        return 'time';
                    }
                    if (strpos($typeLower, 'text') !== false) {
                        return 'textarea';
                    }
                    break;
                }
            }
        }

        // 默认返回文本类型
        return 'text';
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
        }

        return $validation;
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
            error_log("DataTable Form API Error: " . $e->getMessage());
            return $this->error(__('获取记录失败: %{1}', [$e->getMessage()]));
        }
    }
}

