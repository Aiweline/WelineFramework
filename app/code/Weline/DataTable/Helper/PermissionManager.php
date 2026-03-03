<?php
/**
 * DataTable 权限管理器
 * 提供细粒度的权限控制功能
 */

namespace Weline\DataTable\Helper;

use Weline\DataTable\Exception\DataTableException;

class PermissionManager
{
    /**
     * 权限类型常量
     */
    const PERMISSION_VIEW = 'view';
    const PERMISSION_CREATE = 'create';
    const PERMISSION_UPDATE = 'update';
    const PERMISSION_DELETE = 'delete';
    const PERMISSION_EXPORT = 'export';
    const PERMISSION_IMPORT = 'import';

    /**
     * 权限级别常量
     */
    const LEVEL_FIELD = 'field';
    const LEVEL_OPERATION = 'operation';
    const LEVEL_DATA = 'data';

    /**
     * 检查字段显示权限
     *
     * @param string $model 模型类名
     * @param string $field 字段名
     * @param mixed $user 用户对象（可选）
     * @return bool
     */
    public static function canViewField(string $model, string $field, $user = null): bool
    {
        // 默认允许查看所有字段
        // 可以通过配置或权限系统进行控制
        $permissions = self::getFieldPermissions($model, $user);
        
        if (isset($permissions[$field])) {
            return $permissions[$field]['view'] ?? true;
        }

        return true;
    }

    /**
     * 检查字段编辑权限
     *
     * @param string $model 模型类名
     * @param string $field 字段名
     * @param mixed $user 用户对象（可选）
     * @return bool
     */
    public static function canEditField(string $model, string $field, $user = null): bool
    {
        $permissions = self::getFieldPermissions($model, $user);
        
        if (isset($permissions[$field])) {
            return $permissions[$field]['edit'] ?? true;
        }

        return true;
    }

    /**
     * 检查操作权限
     *
     * @param string $model 模型类名
     * @param string $action 操作类型（create, update, delete, export, import）
     * @param mixed $user 用户对象（可选）
     * @return bool
     */
    public static function canPerformAction(string $model, string $action, $user = null): bool
    {
        // 默认允许所有操作
        // 可以通过配置或权限系统进行控制
        $permissions = self::getActionPermissions($model, $user);
        
        return $permissions[$action] ?? true;
    }

    /**
     * 检查数据范围权限
     *
     * @param string $model 模型类名
     * @param mixed $recordId 记录ID
     * @param mixed $user 用户对象（可选）
     * @return bool
     */
    public static function canAccessRecord(string $model, $recordId, $user = null): bool
    {
        // 默认允许访问所有记录
        // 可以通过配置实现数据范围权限（如只能查看自己的数据）
        $dataScope = self::getDataScope($model, $user);
        
        if (empty($dataScope)) {
            return true; // 没有限制，允许访问
        }

        // 检查记录是否在允许的范围内
        try {
            $modelInstance = w_obj($model);
            $record = $modelInstance->find($recordId);
            
            if (!$record) {
                return false;
            }

            // 检查数据范围条件
            foreach ($dataScope as $field => $value) {
                if ($record->getData($field) != $value) {
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            w_log_error("Permission check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 过滤可显示的字段
     *
     * @param string $model 模型类名
     * @param array $fields 字段列表
     * @param mixed $user 用户对象（可选）
     * @return array
     */
    public static function filterViewableFields(string $model, array $fields, $user = null): array
    {
        return array_filter($fields, function($field) use ($model, $user) {
            return self::canViewField($model, $field, $user);
        });
    }

    /**
     * 过滤可编辑的字段
     *
     * @param string $model 模型类名
     * @param array $fields 字段列表
     * @param mixed $user 用户对象（可选）
     * @return array
     */
    public static function filterEditableFields(string $model, array $fields, $user = null): array
    {
        return array_filter($fields, function($field) use ($model, $user) {
            return self::canEditField($model, $field, $user);
        });
    }

    /**
     * 获取字段权限配置
     *
     * @param string $model 模型类名
     * @param mixed $user 用户对象（可选）
     * @return array
     */
    private static function getFieldPermissions(string $model, $user = null): array
    {
        // 这里可以从缓存、配置或权限系统获取权限配置
        // 目前返回空数组，表示使用默认权限（全部允许）
        
        // 示例：从缓存获取
        $cacheKey = "datatable_field_permissions_{$model}";
        // $permissions = Cache::get($cacheKey);
        
        return [];
    }

    /**
     * 获取操作权限配置
     *
     * @param string $model 模型类名
     * @param mixed $user 用户对象（可选）
     * @return array
     */
    private static function getActionPermissions(string $model, $user = null): array
    {
        // 这里可以从缓存、配置或权限系统获取权限配置
        // 目前返回空数组，表示使用默认权限（全部允许）
        
        // 示例：从缓存获取
        $cacheKey = "datatable_action_permissions_{$model}";
        // $permissions = Cache::get($cacheKey);
        
        return [];
    }

    /**
     * 获取数据范围配置
     *
     * @param string $model 模型类名
     * @param mixed $user 用户对象（可选）
     * @return array
     */
    private static function getDataScope(string $model, $user = null): array
    {
        // 这里可以从缓存、配置或权限系统获取数据范围配置
        // 返回空数组表示没有限制
        
        // 示例：如果用户只能查看自己的数据
        // if ($user && method_exists($user, 'getId')) {
        //     return ['user_id' => $user->getId()];
        // }
        
        return [];
    }

    /**
     * 验证权限并抛出异常
     *
     * @param string $model 模型类名
     * @param string $action 操作类型
     * @param mixed $user 用户对象（可选）
     * @throws DataTableException
     * @return void
     */
    public static function requirePermission(string $model, string $action, $user = null): void
    {
        if (!self::canPerformAction($model, $action, $user)) {
            throw DataTableException::permissionDenied("执行操作 {$action} 需要权限");
        }
    }

    /**
     * 验证字段权限并抛出异常
     *
     * @param string $model 模型类名
     * @param string $field 字段名
     * @param string $permission 权限类型（view, edit）
     * @param mixed $user 用户对象（可选）
     * @throws DataTableException
     * @return void
     */
    public static function requireFieldPermission(string $model, string $field, string $permission, $user = null): void
    {
        $allowed = false;
        
        if ($permission === self::PERMISSION_VIEW) {
            $allowed = self::canViewField($model, $field, $user);
        } elseif ($permission === self::PERMISSION_UPDATE) {
            $allowed = self::canEditField($model, $field, $user);
        }

        if (!$allowed) {
            throw DataTableException::permissionDenied("字段 {$field} 的 {$permission} 权限不足");
        }
    }
}

