<?php
/**
 * DataTable 数据验证管理器
 * 提供增强的数据验证功能
 */

namespace Weline\DataTable\Helper;

use Weline\DataTable\Exception\DataTableException;

class ValidationManager
{
    /**
     * 内置验证规则
     */
    const RULE_REQUIRED = 'required';
    const RULE_EMAIL = 'email';
    const RULE_URL = 'url';
    const RULE_NUMERIC = 'numeric';
    const RULE_INTEGER = 'integer';
    const RULE_MIN = 'min';
    const RULE_MAX = 'max';
    const RULE_MIN_LENGTH = 'min_length';
    const RULE_MAX_LENGTH = 'max_length';
    const RULE_PATTERN = 'pattern';
    const RULE_UNIQUE = 'unique';
    const RULE_CUSTOM = 'custom';

    /**
     * 验证数据
     *
     * @param array $data 数据
     * @param array $rules 验证规则
     * @param object $modelInstance 模型实例（可选，用于唯一性验证）
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $data, array $rules, $modelInstance = null): array
    {
        $errors = [];
        $valid = true;

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldErrors = self::validateField($field, $value, $fieldRules, $modelInstance, $data);
            
            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
                $valid = false;
            }
        }

        return [
            'valid' => $valid,
            'errors' => $errors
        ];
    }

    /**
     * 验证单个字段
     *
     * @param string $field 字段名
     * @param mixed $value 字段值
     * @param array $rules 验证规则
     * @param object|null $modelInstance 模型实例
     * @param array $allData 所有数据（用于跨字段验证）
     * @return array 错误列表
     */
    public static function validateField(
        string $field,
        $value,
        array $rules,
        $modelInstance = null,
        array $allData = []
    ): array {
        $errors = [];

        foreach ($rules as $rule => $ruleValue) {
            $error = self::applyRule($field, $value, $rule, $ruleValue, $modelInstance, $allData);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * 应用验证规则
     *
     * @param string $field 字段名
     * @param mixed $value 字段值
     * @param string $rule 规则名称
     * @param mixed $ruleValue 规则值
     * @param object|null $modelInstance 模型实例
     * @param array $allData 所有数据
     * @return string|null 错误消息，null表示验证通过
     */
    private static function applyRule(
        string $field,
        $value,
        string $rule,
        $ruleValue,
        $modelInstance = null,
        array $allData = []
    ): ?string {
        switch ($rule) {
            case self::RULE_REQUIRED:
                if (empty($value) && $value !== '0' && $value !== 0) {
                    return "字段 {$field} 不能为空";
                }
                break;

            case self::RULE_EMAIL:
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "字段 {$field} 必须是有效的邮箱地址";
                }
                break;

            case self::RULE_URL:
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                    return "字段 {$field} 必须是有效的URL";
                }
                break;

            case self::RULE_NUMERIC:
                if (!empty($value) && !is_numeric($value)) {
                    return "字段 {$field} 必须是数字";
                }
                break;

            case self::RULE_INTEGER:
                if (!empty($value) && (!is_numeric($value) || (int)$value != $value)) {
                    return "字段 {$field} 必须是整数";
                }
                break;

            case self::RULE_MIN:
                if (!empty($value) && is_numeric($value) && $value < $ruleValue) {
                    return "字段 {$field} 不能小于 {$ruleValue}";
                }
                break;

            case self::RULE_MAX:
                if (!empty($value) && is_numeric($value) && $value > $ruleValue) {
                    return "字段 {$field} 不能大于 {$ruleValue}";
                }
                break;

            case self::RULE_MIN_LENGTH:
                if (!empty($value) && strlen($value) < $ruleValue) {
                    return "字段 {$field} 长度不能少于 {$ruleValue} 个字符";
                }
                break;

            case self::RULE_MAX_LENGTH:
                if (!empty($value) && strlen($value) > $ruleValue) {
                    return "字段 {$field} 长度不能超过 {$ruleValue} 个字符";
                }
                break;

            case self::RULE_PATTERN:
                if (!empty($value) && !preg_match($ruleValue, $value)) {
                    return "字段 {$field} 格式不正确";
                }
                break;

            case self::RULE_UNIQUE:
                if (!empty($value) && $modelInstance) {
                    if (self::checkUnique($modelInstance, $field, $value, $allData)) {
                        return "字段 {$field} 的值已存在";
                    }
                }
                break;

            case self::RULE_CUSTOM:
                if (is_callable($ruleValue)) {
                    $result = call_user_func($ruleValue, $field, $value, $allData);
                    if ($result !== true && !empty($result)) {
                        return is_string($result) ? $result : "字段 {$field} 验证失败";
                    }
                }
                break;
        }

        return null;
    }

    /**
     * 检查唯一性
     *
     * @param object $modelInstance 模型实例
     * @param string $field 字段名
     * @param mixed $value 值
     * @param array $allData 所有数据（用于排除当前记录）
     * @return bool true表示已存在
     */
    private static function checkUnique($modelInstance, string $field, $value, array $allData): bool
    {
        try {
            $query = $modelInstance->where($field, '=', $value);
            
            // 如果是更新操作，排除当前记录
            if (isset($allData['id']) && !empty($allData['id'])) {
                $primaryKey = method_exists($modelInstance, 'getPrimaryKey') 
                    ? $modelInstance->getPrimaryKey() 
                    : 'id';
                $query->where($primaryKey, '!=', $allData['id']);
            }
            
            $count = $query->count();
            return $count > 0;
        } catch (\Exception $e) {
            w_log_error("Unique check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 跨字段验证
     *
     * @param array $data 数据
     * @param array $crossFieldRules 跨字段验证规则
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateCrossField(array $data, array $crossFieldRules): array
    {
        $errors = [];
        $valid = true;

        foreach ($crossFieldRules as $ruleName => $rule) {
            if (!isset($rule['fields']) || !isset($rule['validator'])) {
                continue;
            }

            $fields = $rule['fields'];
            $validator = $rule['validator'];
            $message = $rule['message'] ?? '跨字段验证失败';

            if (is_callable($validator)) {
                $fieldValues = [];
                foreach ($fields as $field) {
                    $fieldValues[$field] = $data[$field] ?? null;
                }

                $result = call_user_func($validator, $fieldValues, $data);
                if ($result !== true) {
                    $errors[$ruleName] = is_string($result) ? $result : $message;
                    $valid = false;
                }
            }
        }

        return [
            'valid' => $valid,
            'errors' => $errors
        ];
    }

    /**
     * 异步验证（用于前端）
     * 注意：这个方法需要在API中调用
     *
     * @param object $modelInstance 模型实例
     * @param string $field 字段名
     * @param mixed $value 值
     * @param string $rule 验证规则
     * @param mixed $ruleValue 规则值
     * @return array ['valid' => bool, 'message' => string]
     */
    public static function asyncValidate(
        $modelInstance,
        string $field,
        $value,
        string $rule,
        $ruleValue = null
    ): array {
        $rules = [$field => [$rule => $ruleValue]];
        $data = [$field => $value];
        
        $result = self::validate($data, $rules, $modelInstance);
        
        if ($result['valid']) {
            return ['valid' => true, 'message' => ''];
        } else {
            $errors = $result['errors'][$field] ?? [];
            return [
                'valid' => false,
                'message' => implode(', ', $errors)
            ];
        }
    }

    /**
     * 生成验证规则（从模型字段信息）
     *
     * @param object $modelInstance 模型实例
     * @return array
     */
    public static function generateRules($modelInstance): array
    {
        $rules = [];

        try {
            $columns = $modelInstance->columns();

            foreach ($columns as $column) {
                $fieldName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? '') : $column;
                if (empty($fieldName)) {
                    continue;
                }

                $fieldRules = [];

                // 必填验证
                if (is_array($column) && isset($column['Null']) && $column['Null'] === 'NO') {
                    $fieldRules[self::RULE_REQUIRED] = true;
                }

                // 类型验证
                if (is_array($column) && isset($column['Type'])) {
                    $type = strtolower($column['Type']);
                    
                    if (strpos($type, 'int') !== false) {
                        $fieldRules[self::RULE_INTEGER] = true;
                    } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) {
                        $fieldRules[self::RULE_NUMERIC] = true;
                    } elseif (strpos($type, 'varchar') !== false || strpos($type, 'char') !== false) {
                        // 提取长度限制
                        if (preg_match('/\((\d+)\)/', $type, $matches)) {
                            $maxLength = (int)$matches[1];
                            $fieldRules[self::RULE_MAX_LENGTH] = $maxLength;
                        }
                    }
                }

                // 唯一性验证
                if (is_array($column) && isset($column['Key'])) {
                    if ($column['Key'] === 'PRI' || $column['Key'] === 'UNI') {
                        $fieldRules[self::RULE_UNIQUE] = true;
                    }
                }

                if (!empty($fieldRules)) {
                    $rules[$fieldName] = $fieldRules;
                }
            }
        } catch (\Exception $e) {
            w_log_error("Generate rules error: " . $e->getMessage());
        }

        return $rules;
    }
}

