<?php

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\TableContext;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Taglib\TaglibInterface;

class Table implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'd-table';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [
            'model' => true,
            'scope' => true,
            'id' => false,
            'class' => false,
            'style' => false,
            'editable' => false,
            'inline-edit' => false,
            'modal-edit' => false,
            'searchable' => false,
            'sortable' => false,
            'page-size' => false,
            'show-pagination' => false,
            'show-toolbar' => false,
            'show-config' => false,
            'height' => false,
            'width' => false
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            
            $model = $attributes['model'] ?? '';
            $scope = $attributes['scope'] ?? '';
            
            // 修复model属性的转义问题
            if (strpos($model, '\\\\') !== false) {
                $model = str_replace('\\\\', '\\', $model);
            }
            
            $id = $attributes['id'] ?? 'datatable-' . uniqid();
            $class = $attributes['class'] ?? 'table table-striped table-bordered';
            $style = $attributes['style'] ?? '';
            $editable = filter_var($attributes['editable'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $inlineEdit = filter_var($attributes['inline-edit'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $modalEdit = filter_var($attributes['modal-edit'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $searchable = filter_var($attributes['searchable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $sortable = filter_var($attributes['sortable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $pageSize = intval($attributes['page-size'] ?? 20);
            $showPagination = filter_var($attributes['show-pagination'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $showToolbar = filter_var($attributes['show-toolbar'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $showConfig = filter_var($attributes['show-config'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $height = $attributes['height'] ?? '';
            $width = $attributes['width'] ?? '';

            if (empty($model)) {
                throw new Exception(__('Table标签必须指定model属性！'));
            }
            if (empty($scope)) {
                throw new Exception(__('Table标签必须指定scope属性！'));
            }

            // 存储表格上下文，供子标签继承使用
            $tableContext = [
                'model' => $model,
                'scope' => $scope,
                'id' => $id,
                'class' => $class,
                'style' => $style,
                'editable' => $editable,
                'inline-edit' => $inlineEdit,
                'modal-edit' => $modalEdit,
                'searchable' => $searchable,
                'sortable' => $sortable,
                'page-size' => $pageSize,
                'show-pagination' => $showPagination,
                'show-toolbar' => $showToolbar,
                'show-config' => $showConfig,
                'height' => $height,
                'width' => $width
            ];
            
            // 使用TableContext助手类设置表格上下文
            TableContext::setTableContext($scope, $tableContext);

            $content = $tag_data[2] ?? '';
            
            // 检查是否有手动配置的内容
            $hasManualConfig = !empty(trim($content));
            
            // 如果没有手动配置，则自动生成默认的表格结构
            if (!$hasManualConfig) {
                $content = self::generateDefaultTableStructure($model, $scope);
            } else {
                // 如果有手动配置，确保必要的标签存在
                if(!str_contains($content,'t-filter')){
                    $content = '<w:t-filter></w:t-filter>'.$content;
                }
                if(!str_contains($content,'t-header')){
                    $content = '<w:t-header></w:t-header>'.$content;
                }
                if(!str_contains($content,'t-body')){
                    $content = $content.'<w:t-body></w:t-body>';
                }
                if(!str_contains($content,'t-footer')){
                    $content = $content.'<w:t-footer></w:t-footer>';
                }
            }
            
            // 构建样式
            $styleStr = '';
            if ($style) $styleStr .= $style;
            if ($height) $styleStr .= "height: {$height};";
            if ($width) $styleStr .= "width: {$width};";

            // 转换为JavaScript布尔值
            $editableJs = $editable ? 'true' : 'false';
            $inlineEditJs = $inlineEdit ? 'true' : 'false';
            $modalEditJs = $modalEdit ? 'true' : 'false';
            $searchableJs = $searchable ? 'true' : 'false';
            $sortableJs = $sortable ? 'true' : 'false';
            $showPaginationJs = $showPagination ? 'true' : 'false';
            $showToolbarJs = $showToolbar ? 'true' : 'false';
            $showConfigJs = $showConfig ? 'true' : 'false';
            $autoGeneratedJs = $hasManualConfig ? 'false' : 'true';

            // 修复模型名称的转义问题
            $modelJs = str_replace('\\', '\\\\', $model);

            // 生成自动生成标识
            $autoGeneratedBadge = $hasManualConfig ? '' : '<span class="badge bg-info ms-2">自动生成</span>';

            /**@var Template $tmp */
            $tmp = w_obj(Template::class);
            $jsUrl = $tmp->fetchTagSource('statics','Weline_DataTable::js/datatable-manager.js');
            $cssUrl = $tmp->fetchTagSource('statics','Weline_DataTable::css/datatable.css');
            $dtableName = __('数据表格');
            $dtableTitle = __('数据表格');
            $dtableFieldConfig = __('字段设置');
            $dtableRefresh = __('刷新');
            $dtableTotal = __('总记录');
            $dtableDisplay = __('显示');
            $result = <<<HTML
<script src="{$jsUrl}"></script>
<link rel="stylesheet" href="{$cssUrl}">
<script>
$(function() {
    // 初始化数据表格
    window.DataTableManager.initTable('#{$id}', {
        model: '{$modelJs}',
        scope: '{$scope}',
        editable: {$editableJs},
        inlineEdit: {$inlineEditJs},
        modalEdit: {$modalEditJs},
        searchable: {$searchableJs},
        sortable: {$sortableJs},
        pageSize: {$pageSize},
        showPagination: {$showPaginationJs},
        showToolbar: {$showToolbarJs},
        showConfig: {$showConfigJs},
        autoGenerated: {$autoGeneratedJs}
    });
});
</script>

<div id="w-datatable-{$id}" class="w-datatable" data-model="{$model}" data-scope="{$scope}" style="{$styleStr}">
    <div class="w-datatable-container">
        <!-- 工具栏 -->
        <div class="w-datatable-toolbar">
            <div class="w-datatable-toolbar-left">
                <div class="w-toolbar-title-section">
                    <div class="w-title-icon">
                        <i class="fas fa-table"></i>
                    </div>
                    <div class="w-title-content">
                        <h5 class="w-datatable-title">
                            {$dtableName}
                            {$autoGeneratedBadge}
                        </h5>
                        <div class="w-title-subtitle">
                            <span class="w-model-info">
                                <i class="fas fa-database"></i>
                                <span class="w-model-name">{$model}</span>
                            </span>
                            <span class="w-scope-info">
                                <i class="fas fa-tag"></i>
                                <span class="w-scope-name">{$scope}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="w-datatable-toolbar-right">
                <div class="w-toolbar-actions">
                    <div class="w-action-group">
                        <button type="button" class="w-btn w-btn-outline-secondary w-btn-sm w-me-2" data-w-action="field-config" data-table="{$id}" onclick="DataTableManager.openFieldConfig('{$id}')" title="{$dtableFieldConfig}">
                            <i class="fas fa-cog"></i>
                            <span class="w-btn-text">{$dtableTitle}</span>
                        </button>
                        <button type="button" class="w-btn w-btn-outline-primary w-btn-sm" onclick="DataTableManager.refreshData('{$id}')" title="刷新数据">
                            <i class="fas fa-sync-alt"></i>
                            <span class="w-btn-text">{$dtableRefresh}</span>
                        </button>
                    </div>
                    <div class="w-toolbar-divider"></div>
                    <div class="w-quick-stats">
                        <div class="w-stat-item">
                            <i class="fas fa-list"></i>
                            <span class="w-stat-label">{$dtableTotal}</span>
                            <span class="w-stat-value" id="w-{$id}-total-count">-</span>
                        </div>
                        <div class="w-stat-item">
                            <i class="fas fa-eye"></i>
                            <span class="w-stat-label">{$dtableDisplay}</span>
                            <span class="w-stat-value" id="w-{$id}-display-count">-</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <table class="{$class}" id="{$id}">
            <!-- 表格内容 t-header t-filter t-body t-footer 都在content中-->
            {$content}
        </table>
    </div>
</div>

<!-- 字段设置modal结构，直接输出HTML到body -->
<div class="w-modal" id="w-field-config-modal-{$id}" tabindex="-1" aria-labelledby="w-field-config-modal-label-{$id}" aria-hidden="true" style="display:none;">
    <div class="w-modal-dialog w-modal-lg">
        <div class="w-modal-content">
            <div class="w-modal-header">
                <h5 class="w-modal-title" id="w-field-config-modal-label-{$id}">
                    <i class="fas fa-cog"></i> 字段配置
                </h5>
                <button type="button" class="w-btn-close" onclick="DataTableManager.closeFieldConfig('{$id}')" aria-label="Close"></button>
            </div>
            <div class="w-modal-body">
                <div class="w-config-tabs">
                    <ul class="w-nav w-nav-tabs" role="tablist">
                        <li class="w-nav-item" role="presentation">
                            <button class="w-nav-link active" data-w-toggle="tab" data-w-target="#w-columns-tab-{$id}" type="button" role="tab">
                                <i class="fas fa-columns"></i> <?=__("列设置")?>
                            </button>
                        </li>
                        <li class="w-nav-item" role="presentation">
                            <button class="w-nav-link" data-w-toggle="tab" data-w-target="#w-filter-tab-{$id}" type="button" role="tab">
                                <i class="fas fa-filter"></i> <?=__("筛选设置")?>
                            </button>
                        </li>
                    </ul>
                    <div class="w-tab-content">
                        <div class="w-tab-pane w-fade w-show active" id="w-columns-tab-{$id}" role="tabpanel">
                            <div class="w-row" style="display:flex;flex-direction:row;gap:24px;">
                                <div class="w-col-md-6" style="flex:1 1 0;min-width:0;">
                                    <h6><?=__("可用字段")?></h6>
                                    <div class="w-available-fields" id="w-available-fields-{$id}">
                                        <div class="w-text-center w-text-muted">
                                            <i class="fas fa-spinner fa-spin"></i> <?=__("加载中...")?>
                                        </div>
                                    </div>
                                </div>
                                <div class="w-col-md-6" style="flex:1 1 0;min-width:0;">
                                    <h6><?=__("显示字段")?></h6>
                                    <div class="w-display-fields" id="w-display-fields-{$id}">
                                        <div class="w-text-center w-text-muted">
                                            <i class="fas fa-spinner fa-spin"></i> <?=__("加载中...")?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="w-tab-pane w-fade" id="w-filter-tab-{$id}" role="tabpanel">
                            <div class="w-row" style="display:flex;flex-direction:row;gap:24px;">
                                <div class="w-col-md-6" style="flex:1 1 0;min-width:0;">
                                    <h6><?=__("可用字段")?></h6>
                                    <div class="w-available-fields" id="w-available-fields-filter-{$id}">
                                        <div class="w-text-center w-text-muted">
                                            <i class="fas fa-spinner fa-spin"></i> <?=__("加载中...")?>
                                        </div>
                                    </div>
                                </div>
                                <div class="w-col-md-6" style="flex:1 1 0;min-width:0;">
                                    <h6><?=__("筛选字段")?></h6>
                                    <div class="w-filter-fields" id="w-filter-fields-{$id}">
                                        <div class="w-text-center w-text-muted">
                                            <i class="fas fa-spinner fa-spin"></i> <?=__("加载中...")?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="w-modal-footer">
                <button type="button" class="w-btn w-btn-secondary" onclick="DataTableManager.closeFieldConfig('{$id}')">取消</button>
                <button type="button" class="w-btn w-btn-primary" onclick="DataTableManager.saveFieldConfig('{$id}')">保存配置</button>
            </div>
        </div>
    </div>
</div>
HTML;

            // 渲染结束后弹出表格上下文
            TableContext::popTag();
            
            return $result;
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // Table标签是DataTable的父标签，没有依赖
    }

    public static function document(): string
    {
        return <<<DOC
数据表格组件使用方式：

【最简单用法 - 只需要两个参数】：
<w:d-table model="WeShop\Store\Model\Store" scope="store-listing"></w:d-table>

这种方式会自动：
1. 从模型中获取字段信息
2. 自动生成表格列和过滤器
3. 智能识别字段类型和宽度
4. 过滤掉敏感字段（如password、token等）
5. 限制显示字段数量，避免表格过宽

【基础用法（手动配置子标签）】：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table" editable="true" searchable="true">
    <w:t-header>
        <w:field belong="t-header" name="id" sortable="true" width="80">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true" width="200">名称</w:field>
        <w:field belong="t-header" name="status" sortable="true" width="100">状态</w:field>
    </w:t-header>
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>
        <w:field belong="t-filter" name="status" type="select" options="1:启用,0:禁用"></w:field>
    </w:t-filter>
</w:d-table>

【高级用法（子标签可以覆盖继承的属性）】：
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header model="Weline\Demo\Model\Demo" scope="custom-header-scope">
        <w:field belong="t-header" name="id" sortable="true">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true">名称</w:field>
    </w:t-header>
    <w:t-filter model="Weline\Demo\Model\Demo" scope="custom-filter-scope">
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>
    </w:t-filter>
</w:d-table>

【属性说明】：
model: 必须，指定数据模型类名（会自动传递给子标签）
scope: 必须，指定数据作用域，用于存储用户配置
id: 可选，指定表格ID，默认自动生成
class: 可选，指定表格CSS类，默认"table table-striped table-bordered"
style: 可选，指定内联样式
editable: 可选，是否启用编辑功能，默认false
inline-edit: 可选，是否启用行内编辑，默认true
modal-edit: 可选，是否启用弹窗编辑，默认true
searchable: 可选，是否启用搜索功能，默认true
sortable: 可选，是否启用排序功能，默认true
page-size: 可选，每页显示数量，默认20
show-pagination: 可选，是否显示分页，默认true
show-toolbar: 可选，是否显示工具栏，默认true
show-config: 可选，是否显示配置按钮，默认true
height: 可选，指定表格高度
width: 可选，指定表格宽度

【自动生成特性】：
1. 自动从模型中获取字段信息（支持getFields()和getTableFields()方法）
2. 智能过滤敏感字段（password、token、secret、hash等）
3. 自动设置合适的列宽和过滤器类型
4. 限制显示字段数量（最多8个），避免表格过宽
5. 自动生成过滤器（前3个主要字段）
6. 支持状态字段的选项配置（启用/禁用）

【手动配置优势】：
1. 可以精确控制显示的字段和顺序
2. 可以自定义字段标签和样式
3. 可以配置复杂的过滤器选项
4. 可以设置字段的排序、编辑、搜索等属性
5. 可以覆盖自动生成的默认配置

【注意事项】：
1. 当不提供子标签内容时，会自动生成完整的表格结构
2. 自动生成的表格会显示"自动生成"标识
3. 用户可以通过字段设置按钮自定义显示列和筛选器
4. 配置会自动保存到指定的scope中
5. 子标签的scope会自动生成：t-header为"{table-scope}-header"，t-filter为"{table-scope}-filter"
6. 子标签无需重复指定model和scope，除非需要覆盖继承的值
DOC;
    }

    /**
     * 自动生成默认的表格结构
     * @param string $model 模型类名
     * @param string $scope 作用域
     * @return string 生成的HTML内容
     */
    private static function generateDefaultTableStructure(string $model, string $scope): string
    {
        $fields = [];
        $modelInstance = null;
        
        try {
            // 尝试实例化模型
            $modelInstance = w_obj($model);
            
            // 多种方式获取字段信息
            $fields = self::getModelFields($modelInstance, $model);
            
        } catch (\Exception $e) {
            // 记录错误日志，但不抛出异常，使用默认字段
            error_log("DataTable: 无法实例化模型 {$model}: " . $e->getMessage());
            $fields = self::getDefaultFields($model);
        }
        
        // 过滤掉一些不需要显示的字段
        $excludeFields = ['password', 'token', 'secret', 'hash', 'salt', 'key', 'auth'];
        $displayFields = array_filter($fields, function($field) use ($excludeFields) {
            $fieldName = is_array($field) ? $field['name'] : $field;
            return !in_array(strtolower($fieldName), $excludeFields);
        });
        
        // 限制显示的字段数量，避免表格过宽
        $displayFields = array_slice($displayFields, 0, 8);
        
        // 生成表头字段
        $headerFields = '';
        foreach ($displayFields as $field) {
            $fieldName = is_array($field) ? $field['name'] : $field;
            $fieldLabel = is_array($field) ? ($field['label'] ?? $fieldName) : $fieldName;
            $sortable = is_array($field) ? ($field['sortable'] ?? true) : true;
            $width = is_array($field) ? ($field['width'] ?? self::getFieldWidth($fieldName)) : self::getFieldWidth($fieldName);
            $visible = is_array($field) ? ($field['visible'] ?? true) : true;
            
            // 如果字段不可见，跳过
            if (!$visible) {
                continue;
            }
            
            $sortableAttr = $sortable ? 'sortable="true"' : '';
            
            $headerFields .= <<<HTML
                <w:field belong="t-header" name="{$fieldName}" {$sortableAttr} width="{$width}">{$fieldLabel}</w:field>
HTML;
        }
        
        // 生成过滤器字段（只包含主要字段）
        $filterFields = '';
        $mainFields = array_slice($displayFields, 0, 3);
        foreach ($mainFields as $field) {
            $fieldName = is_array($field) ? $field['name'] : $field;
            $fieldLabel = is_array($field) ? ($field['label'] ?? $fieldName) : $fieldName;
            $searchable = is_array($field) ? ($field['searchable'] ?? true) : true;
            $visible = is_array($field) ? ($field['visible'] ?? true) : true;
            
            // 如果字段不可见或不可搜索，跳过
            if (!$visible || !$searchable) {
                continue;
            }
            
            // 根据字段类型和名称确定过滤器类型
            $filterType = self::getFilterTypeFromField($field);
            $placeholder = "搜索{$fieldLabel}";
            
            $filterFields .= <<<HTML
                <w:field belong="t-filter" name="{$fieldName}" type="{$filterType}" placeholder="{$placeholder}"></w:field>
HTML;
        }
        
        return <<<HTML
<w:t-filter>
    {$filterFields}
</w:t-filter>
<w:t-header>
    {$headerFields}
</w:t-header>
<w:t-body></w:t-body>
<w:t-footer></w:t-footer>
HTML;
    }
    
    /**
     * 根据字段信息获取合适的过滤器类型
     * @param mixed $field 字段信息（可能是字符串或数组）
     * @return string 过滤器类型
     */
    private static function getFilterTypeFromField($field): string
    {
        if (!is_array($field)) {
            return self::getFilterType($field);
        }
        
        $fieldName = $field['name'] ?? '';
        $fieldType = $field['type'] ?? '';
        
        // 根据字段类型确定过滤器类型
        switch ($fieldType) {
            case 'number':
                return 'number';
            case 'date':
            case 'datetime':
                return 'date';
            case 'email':
                return 'email';
            case 'tel':
            case 'phone':
                return 'tel';
            case 'url':
            case 'website':
                return 'url';
            default:
                // 根据字段名进一步判断
                if (in_array($fieldName, ['status', 'type', 'state'])) {
                    return 'select';
                } elseif (in_array($fieldName, ['created_at', 'updated_at', 'create_time', 'update_time'])) {
                    return 'date';
                } elseif (in_array($fieldName, ['email'])) {
                    return 'email';
                } elseif (in_array($fieldName, ['phone', 'tel'])) {
                    return 'tel';
                } else {
                    return 'text';
                }
        }
    }
    
    /**
     * 获取模型字段信息
     * @param object $modelInstance 模型实例
     * @param string $modelClass 模型类名
     * @return array 字段数组
     */
    private static function getModelFields($modelInstance, string $modelClass): array
    {
        $fields = [];
        
        // 方法1: 尝试调用getFields方法
        if (method_exists($modelInstance, 'getFields')) {
            try {
                $fields = $modelInstance->getFields();
                if (!empty($fields)) {
                    // 检查是否是API返回的格式
                    if (is_array($fields) && isset($fields['data']) && is_array($fields['data'])) {
                        return $fields['data'];
                    }
                    return $fields;
                }
            } catch (\Exception $e) {
                error_log("DataTable: 调用getFields()失败: " . $e->getMessage());
            }
        }
        
        // 方法2: 尝试调用getTableFields方法
        if (method_exists($modelInstance, 'getTableFields')) {
            try {
                $tableFields = $modelInstance->getTableFields();
                if (!empty($tableFields)) {
                    // 检查是否是API返回的格式
                    if (is_array($tableFields) && isset($tableFields['data']) && is_array($tableFields['data'])) {
                        return $tableFields['data'];
                    }
                    
                    // 如果是关联数组，提取字段名
                    if (is_array($tableFields) && array_keys($tableFields) !== range(0, count($tableFields) - 1)) {
                        $fields = array_keys($tableFields);
                    } else {
                        $fields = $tableFields;
                    }
                    return $fields;
                }
            } catch (\Exception $e) {
                error_log("DataTable: 调用getTableFields()失败: " . $e->getMessage());
            }
        }
        
        // 方法3: 尝试调用getSchema方法
        if (method_exists($modelInstance, 'getSchema')) {
            try {
                $schema = $modelInstance->getSchema();
                if (!empty($schema) && is_array($schema)) {
                    // 检查是否是API返回的格式
                    if (isset($schema['data']) && is_array($schema['data'])) {
                        return $schema['data'];
                    }
                    $fields = array_keys($schema);
                    return $fields;
                }
            } catch (\Exception $e) {
                error_log("DataTable: 调用getSchema()失败: " . $e->getMessage());
            }
        }
        
        // 方法4: 尝试调用getFieldConfig方法（新增）
        if (method_exists($modelInstance, 'getFieldConfig')) {
            try {
                $fieldConfig = $modelInstance->getFieldConfig();
                if (!empty($fieldConfig) && is_array($fieldConfig)) {
                    // 检查是否是API返回的格式
                    if (isset($fieldConfig['data']) && is_array($fieldConfig['data'])) {
                        return $fieldConfig['data'];
                    }
                    return $fieldConfig;
                }
            } catch (\Exception $e) {
                error_log("DataTable: 调用getFieldConfig()失败: " . $e->getMessage());
            }
        }
        
        // 方法5: 尝试通过反射获取属性
        try {
            $reflection = new \ReflectionClass($modelInstance);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
            $fields = array_map(function($property) {
                return $property->getName();
            }, $properties);
            
            // 过滤掉一些系统属性
            $fields = array_filter($fields, function($field) {
                return !in_array($field, ['_data', '_resource', '_connection', '_table']);
            });
            
            if (!empty($fields)) {
                return array_values($fields);
            }
        } catch (\Exception $e) {
            error_log("DataTable: 反射获取属性失败: " . $e->getMessage());
        }
        
        // 方法6: 尝试从表名推断字段
        if (method_exists($modelInstance, 'getTable')) {
            try {
                $tableName = $modelInstance->getTable();
                if ($tableName) {
                    // 根据表名推断常见字段
                    $fields = self::getFieldsByTableName($tableName);
                    if (!empty($fields)) {
                        return $fields;
                    }
                }
            } catch (\Exception $e) {
                error_log("DataTable: 获取表名失败: " . $e->getMessage());
            }
        }
        
        // 如果所有方法都失败，返回默认字段
        return self::getDefaultFields($modelClass);
    }
    
    /**
     * 根据表名推断字段
     * @param string $tableName 表名
     * @return array 字段数组
     */
    private static function getFieldsByTableName(string $tableName): array
    {
        $tableFieldMap = [
            'store' => ['store_id', 'name', 'status', 'created_at', 'updated_at'],
            'product' => ['product_id', 'name', 'sku', 'price', 'status', 'created_at'],
            'user' => ['user_id', 'username', 'email', 'status', 'created_at'],
            'order' => ['order_id', 'order_number', 'customer_id', 'total', 'status', 'created_at'],
            'category' => ['category_id', 'name', 'parent_id', 'sort_order', 'status'],
            'customer' => ['customer_id', 'name', 'email', 'phone', 'status', 'created_at'],
            'admin' => ['admin_id', 'username', 'email', 'role', 'status', 'created_at'],
            'config' => ['config_id', 'key', 'value', 'group', 'created_at'],
            'log' => ['log_id', 'level', 'message', 'context', 'created_at'],
            'file' => ['file_id', 'name', 'path', 'size', 'type', 'created_at']
        ];
        
        // 尝试匹配表名
        foreach ($tableFieldMap as $pattern => $fields) {
            if (stripos($tableName, $pattern) !== false) {
                return $fields;
            }
        }
        
        return [];
    }
    
    /**
     * 获取默认字段
     * @param string $modelClass 模型类名
     * @return array 默认字段数组
     */
    private static function getDefaultFields(string $modelClass): array
    {
        // 根据模型类名推断可能的字段
        $modelFieldMap = [
            'Store' => ['store_id', 'name', 'status', 'created_at', 'updated_at'],
            'Product' => ['product_id', 'name', 'sku', 'price', 'status', 'created_at'],
            'User' => ['user_id', 'username', 'email', 'status', 'created_at'],
            'Order' => ['order_id', 'order_number', 'customer_id', 'total', 'status', 'created_at'],
            'Category' => ['category_id', 'name', 'parent_id', 'sort_order', 'status'],
            'Customer' => ['customer_id', 'name', 'email', 'phone', 'status', 'created_at'],
            'Admin' => ['admin_id', 'username', 'email', 'role', 'status', 'created_at'],
            'Config' => ['config_id', 'key', 'value', 'group', 'created_at'],
            'Log' => ['log_id', 'level', 'message', 'context', 'created_at'],
            'File' => ['file_id', 'name', 'path', 'size', 'type', 'created_at']
        ];
        
        // 从类名中提取模型名
        $className = basename(str_replace('\\', '/', $modelClass));
        
        if (isset($modelFieldMap[$className])) {
            return $modelFieldMap[$className];
        }
        
        // 通用默认字段
        return ['id', 'name', 'status', 'created_at', 'updated_at'];
    }
    
    /**
     * 根据字段名获取合适的列宽
     * @param string $fieldName 字段名
     * @return string 列宽
     */
    private static function getFieldWidth(string $fieldName): string
    {
        $widthMap = [
            'id' => '80',
            'status' => '100',
            'created_at' => '150',
            'updated_at' => '150',
            'name' => '200',
            'title' => '200',
            'email' => '180',
            'phone' => '120',
            'default' => '150'
        ];
        
        return $widthMap[$fieldName] ?? $widthMap['default'];
    }
    
    /**
     * 根据字段名获取合适的过滤器类型
     * @param string $fieldName 字段名
     * @return string 过滤器类型
     */
    private static function getFilterType(string $fieldName): string
    {
        $typeMap = [
            'status' => 'select',
            'type' => 'select',
            'created_at' => 'date',
            'updated_at' => 'date',
            'email' => 'email',
            'phone' => 'tel',
            'default' => 'text'
        ];
        
        return $typeMap[$fieldName] ?? $typeMap['default'];
    }
} 