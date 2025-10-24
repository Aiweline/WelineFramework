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
            'join' => false,
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
            'width' => false,
            'form' => false,
            'form-mode' => false,
            'form-title' => false
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
            
            // 验证必需的属性
            $model = $attributes['model'] ?? '';
            $scope = $attributes['scope'] ?? '';

            if (empty($model)) {
                throw new Exception(__('d-table标签必须指定model属性！'));
            }
            if (empty($scope)) {
                throw new Exception(__('d-table标签必须指定scope属性！'));
            }

            $join = $attributes['join'] ?? '';

            // 修复model属性的转义问题
            if (strpos($model, '\\\\') !== false) {
                $model = str_replace('\\\\', '\\', $model);
            }

            // 解析多模型配置
            $modelConfig = self::parseModelConfig($model);

            // 解析JOIN配置
            $joinConfig = self::parseJoinConfig($join);

            // 验证多模型配置的有效性
            if (!empty($modelConfig['models']) && count($modelConfig['models']) > 1 && empty($join)) {
                error_log("DataTable Warning: 多模型配置但未指定JOIN条件，可能导致查询错误");
            }

            // 处理基本属性
            $id = $attributes['id'] ?? 'datatable-' . uniqid();
            $class = $attributes['class'] ?? 'table table-striped table-bordered weline-datatable';
            $style = $attributes['style'] ?? '';

            // 处理功能属性
            $editable = filter_var($attributes['editable'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $inlineEdit = filter_var($attributes['inline-edit'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $modalEdit = filter_var($attributes['modal-edit'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $searchable = filter_var($attributes['searchable'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $sortable = filter_var($attributes['sortable'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 处理显示属性
            $pageSize = max(1, intval($attributes['page-size'] ?? 20));
            $showPagination = filter_var($attributes['show-pagination'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $showToolbar = filter_var($attributes['show-toolbar'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $showConfig = filter_var($attributes['show-config'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $height = $attributes['height'] ?? '';
            $width = $attributes['width'] ?? '';

            // 处理表单属性
            $form = $attributes['form'] ?? '';
            $formMode = $attributes['form-mode'] ?? 'modal';
            $formTitle = $attributes['form-title'] ?? '';

            // 存储表格上下文，供子标签继承使用
            $tableContext = [
                'model' => $model,
                'scope' => $scope,
                'join' => $join,
                'model_config' => $modelConfig,
                'join_config' => $joinConfig,
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
                'width' => $width,
                'form' => $form,
                'form-mode' => $formMode,
                'form-title' => $formTitle
            ];
            
            // 使用TableContext助手类设置表格上下文
            TableContext::setTableContext($scope, $tableContext);

            $content = $tag_data[2] ?? '';
            
            // 检查是否有手动配置的内容
            $hasManualConfig = !empty(trim($content));
            
            // 如果没有手动配置，则自动生成默认的表格结构
            if (!$hasManualConfig) {
                $content = self::generateDefaultTableStructure($model, $scope, $modelConfig, $joinConfig);
            } else {
                // 如果有手动配置，确保必要的标签存在
                $content = self::ensureRequiredTags($content);
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

            // 生成多模型和JOIN配置的JSON
            $modelConfigJson = json_encode($modelConfig, JSON_UNESCAPED_UNICODE);
            $joinConfigJson = json_encode($joinConfig, JSON_UNESCAPED_UNICODE);

            // 生成表单HTML（如果启用）
            $formHtml = '';
            $formJs = 'null';
            if ($form) {
                $formId = 'form-' . $id;
                $formHtml = self::generateFormHtml($formId, $model, $scope, $formMode, $formTitle);
                $formJs = "'{$formId}'";
            }

            // 生成自动生成标识
            $autoGeneratedBadge = $hasManualConfig ? '' : '<span class="badge bg-info ms-2">' . __('自动生成') . '</span>';

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
{$formHtml}
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 初始化数据表格
    window.DataTableManager.initTable('#{$id}', {
        model: '{$modelJs}',
        scope: '{$scope}',
        join: '{$join}',
        modelConfig: {$modelConfigJson},
        joinConfig: {$joinConfigJson},
        editable: {$editableJs},
        inlineEdit: {$inlineEditJs},
        modalEdit: {$modalEditJs},
        searchable: {$searchableJs},
        sortable: {$sortableJs},
        pageSize: {$pageSize},
        showPagination: {$showPaginationJs},
        showToolbar: {$showToolbarJs},
        showConfig: {$showConfigJs},
        autoGenerated: {$autoGeneratedJs},
        formId: {$formJs}
    });
    
    // 如果启用了表单，添加编辑按钮
    if ({$formJs}) {
        DataTableFormManager.addEditButtons('{$id}', {$formJs});
    }
});
</script>

<div id="w-datatable-{$id}" class="w-datatable" data-model="{$model}" data-scope="{$scope}" style="{$styleStr}">
    <div class="w-datatable-container">
        <!-- 工具栏 -->
        <div class="w-datatable-toolbar">
            <div class="w-toolbar-left">
                <div class="w-toolbar-title">
                    <i class="fas fa-table"></i>
                    <span>{$dtableTitle}</span>
                </div>
                <div class="w-toolbar-actions">
                    <button type="button" class="w-toolbar-btn primary" data-w-action="field-config" data-table="{$id}" onclick="DataTableManager.openFieldConfig('{$id}')" title="{$dtableFieldConfig}">
                        <i class="fas fa-cog"></i>
                        <?= __('字段配置') ?>
                    </button>
                    <button type="button" class="w-toolbar-btn success" onclick="DataTableManager.refreshData('{$id}')" title="<?= __('刷新数据') ?>">
                        <i class="fas fa-sync-alt"></i>
                        <?= __('刷新') ?>
                    </button>
                    <button type="button" class="w-toolbar-btn warning" data-w-action="important-view" onclick="DataTableManager.toggleImportantView('{$id}')" title="<?= __('只显示重要数据') ?>">
                        <i class="fas fa-star"></i>
                        <?= __('只显示重要数据') ?>
                    </button>
                </div>
            </div>
            <div class="w-toolbar-right">
                <div class="w-toolbar-actions">
                                            <div class="w-reset-group">
                            <div class="w-dropdown">
                                <button type="button" class="w-dropdown-toggle" data-w-toggle="dropdown" title="<?= __('重置配置') ?>">
                                    <i class="fas fa-undo"></i>
                                    <?= __('重置') ?>
                                </button>
                            <ul class="w-dropdown-menu">
                                <li>
                                    <button type="button" class="w-dropdown-item" onclick="DataTableManager.clearHeaderConfig('{$id}')" title="<?= __('重置表头字段配置') ?>">
                                        <i class="fas fa-columns"></i>
                                        <?= __('重置表头') ?>
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="w-dropdown-item" onclick="DataTableManager.clearFilterConfig('{$id}')" title="<?= __('重置筛选字段配置') ?>">
                                        <i class="fas fa-filter"></i>
                                        <?= __('重置筛选') ?>
                                    </button>
                                </li>
                                <li><hr class="w-dropdown-divider"></li>
                                <li>
                                    <button type="button" class="w-dropdown-item" onclick="DataTableManager.clearAllConfig('{$id}')" title="<?= __('重置全部配置') ?>">
                                        <i class="fas fa-trash"></i>
                                        <?= __('全部重置') ?>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="w-toolbar-btn" data-w-action="theme-config" title="<?= __('主题配置') ?>">
                        <i class="fas fa-palette"></i>
                        <?= __('主题') ?>
                    </button>
                    <div class="w-dropdown">
                        <button type="button" class="w-toolbar-btn dropdown-toggle" data-w-toggle="dropdown" title="<?= __('导出数据') ?>">
                            <i class="fas fa-download"></i>
                            <?= __('导出') ?>
                        </button>
                        <ul class="w-dropdown-menu">
                            <li>
                                <button type="button" class="w-dropdown-item" onclick="DataTableManager.exportData('{$id}', 'excel')" title="<?= __('导出为Excel') ?>">
                                    <i class="fas fa-file-excel"></i>
                                    <?= __('导出Excel') ?>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="w-dropdown-item" onclick="DataTableManager.exportData('{$id}', 'csv')" title="<?= __('导出为CSV') ?>">
                                    <i class="fas fa-file-csv"></i>
                                    <?= __('导出CSV') ?>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="w-dropdown-item" onclick="DataTableManager.exportData('{$id}', 'json')" title="<?= __('导出为JSON') ?>">
                                    <i class="fas fa-file-code"></i>
                                    <?= __('导出JSON') ?>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
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
                    <i class="fas fa-cog"></i> <?= __('字段配置') ?>
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
                <button type="button" class="w-btn w-btn-secondary" onclick="DataTableManager.closeFieldConfig('{$id}')"><?= __('取消') ?></button>
                <button type="button" class="w-btn w-btn-primary" onclick="DataTableManager.saveFieldConfig('{$id}')"><?= __('保存配置') ?></button>
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

【多模型和JOIN查询用法】：
<w:d-table 
    model="Weline\Admin\Model\Admin as main, Weline\Store\Model\Store as store" 
    join="left main.store_id = store.store_id"
    scope="admin-store-join">
</w:d-table>

【复杂JOIN查询】：
<w:d-table 
    model="Weline\Store\Model\Store as store, Weline\Admin\Model\Admin as admin, Weline\SystemConfig\Model\Config as config" 
    join="left store.store_id = admin.store_id, right admin.username = config.scope"
    scope="complex-join-query">
</w:d-table>

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

【多模型手动配置】：
<w:d-table 
    model="Weline\Admin\Model\Admin as admin, Weline\Store\Model\Store as store" 
    join="left admin.store_id = store.store_id"
    scope="admin-store-manual">
    
    <w:t-filter>
        <w:field belong="t-filter" name="admin.username" type="text" placeholder="搜索用户名"></w:field>
        <w:field belong="t-filter" name="store.name" type="text" placeholder="搜索店铺名"></w:field>
    </w:t-filter>
    
    <w:t-header>
        <w:field belong="t-header" name="admin.admin_id" sortable="true" width="80">管理员ID</w:field>
        <w:field belong="t-header" name="admin.username" sortable="true" width="150">用户名</w:field>
        <w:field belong="t-header" name="store.name" sortable="true" width="200">店铺名</w:field>
    </w:t-header>
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
model: 必须，指定数据模型类名，支持多模型格式："class1 as alias1, class2 as alias2"
scope: 必须，指定数据作用域，用于存储用户配置
join: 可选，JOIN查询配置，格式："left table1.field = table2.field, right table2.field = table3.field"
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

【JOIN类型支持】：
- left: LEFT JOIN
- right: RIGHT JOIN
- inner: INNER JOIN (默认)
- outer: OUTER JOIN

【自动生成特性】：
1. 自动从模型中获取字段信息（支持getFields()和getTableFields()方法）
2. 智能过滤敏感字段（password、token、secret、hash等）
3. 自动设置合适的列宽和过滤器类型
4. 限制显示字段数量（最多8个），避免表格过宽
5. 自动生成过滤器（前3个主要字段）
6. 支持状态字段的选项配置（启用/禁用）
7. 多模型查询时自动为字段添加别名前缀

【手动配置优势】：
1. 可以精确控制显示的字段和顺序
2. 可以自定义字段标签和样式
3. 可以配置复杂的过滤器选项
4. 可以设置字段的排序、编辑、搜索等属性
5. 可以覆盖自动生成的默认配置
6. 支持多模型字段的精确控制

【多模型注意事项】：
1. 第一个模型会自动作为主模型（FROM表）
2. JOIN条件中的表名应该使用别名
3. 字段名在手动配置中需要包含别名前缀（如：admin.username）
4. 自动生成的字段会包含别名前缀以避免冲突
5. 确保JOIN条件中的字段存在且类型匹配

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
     * 确保手动配置的内容包含必要的标签
     * @param string $content 手动配置的内容
     * @return string 补全后的内容
     */
    private static function ensureRequiredTags(string $content): string
    {
        // 确保包含必要的子标签
        if (!str_contains($content, 't-filter')) {
            $content = '<w:t-filter></w:t-filter>' . $content;
        }
        if (!str_contains($content, 't-header')) {
            $content = '<w:t-header></w:t-header>' . $content;
        }
        if (!str_contains($content, 't-body')) {
            $content = $content . '<w:t-body></w:t-body>';
        }
        if (!str_contains($content, 't-footer')) {
            $content = $content . '<w:t-footer></w:t-footer>';
        }

        return $content;
    }

    /**
     * 自动生成默认的表格结构
     * @param string $model 模型类名
     * @param string $scope 作用域
     * @param array $modelConfig 模型配置
     * @param array $joinConfig JOIN配置
     * @return string 生成的HTML内容
     */
    private static function generateDefaultTableStructure(string $model, string $scope, array $modelConfig = [], array $joinConfig = []): string
    {
        $fields = [];
        $modelInstance = null;

        // 如果是多模型配置，处理多模型字段
        if (!empty($modelConfig['models'])) {
            $fields = self::getMultiModelFields($modelConfig, $joinConfig);
        } else {
            // 单模型处理
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
        try {
            if (method_exists($modelInstance, 'getFields')) {
                $fields = $modelInstance->getFields();
                if (!empty($fields)) {
                    if (is_array($fields) && isset($fields['data']) && is_array($fields['data'])) {
                        $fields = $fields['data'];
                    }
                    $fields = array_filter($fields, function($field) {
                        $name = is_array($field) ? $field['name'] : $field;
                        return !in_array(strtolower($name), ['password','token','secret','hash','salt','key','auth']);
                    });
                    $fields = array_slice($fields, 0, 8);
                    return $fields;
                }
            }
        } catch (\Throwable $e) {
            error_log("DataTable: getFields()异常: " . $e->getMessage());
        }
        // 方法2: 尝试调用getTableFields方法
        try {
            if (method_exists($modelInstance, 'getTableFields')) {
                $tableFields = $modelInstance->getTableFields();
                if (!empty($tableFields)) {
                    if (is_array($tableFields) && isset($tableFields['data']) && is_array($tableFields['data'])) {
                        return $tableFields['data'];
                    }
                    if (is_array($tableFields) && array_keys($tableFields) !== range(0, count($tableFields) - 1)) {
                        $fields = array_keys($tableFields);
                    } else {
                        $fields = $tableFields;
                    }
                    return $fields;
                }
            }
        } catch (\Throwable $e) {
            error_log("DataTable: getTableFields()异常: " . $e->getMessage());
        }
        // 方法3: 尝试调用getSchema方法
        try {
            if (method_exists($modelInstance, 'getSchema')) {
                $schema = $modelInstance->getSchema();
                if (!empty($schema) && is_array($schema)) {
                    if (isset($schema['data']) && is_array($schema['data'])) {
                        return $schema['data'];
                    }
                    $fields = array_keys($schema);
                    return $fields;
                }
            }
        } catch (\Throwable $e) {
            error_log("DataTable: getSchema()异常: " . $e->getMessage());
        }
        // 方法4: 尝试调用getFieldConfig方法
        try {
            if (method_exists($modelInstance, 'getFieldConfig')) {
                $fieldConfig = $modelInstance->getFieldConfig();
                if (!empty($fieldConfig) && is_array($fieldConfig)) {
                    if (isset($fieldConfig['data']) && is_array($fieldConfig['data'])) {
                        return $fieldConfig['data'];
                    }
                    return $fieldConfig;
                }
            }
        } catch (\Throwable $e) {
            error_log("DataTable: getFieldConfig()异常: " . $e->getMessage());
        }
        // 方法5: 反射获取属性
        try {
            $reflection = new \ReflectionClass($modelInstance);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
            $fields = array_map(function($property) {
                return $property->getName();
            }, $properties);
            $fields = array_filter($fields, function($field) {
                return !in_array($field, ['_data', '_resource', '_connection', '_table']);
            });
            if (!empty($fields)) {
                return array_values($fields);
            }
        } catch (\Throwable $e) {
            error_log("DataTable: 反射获取属性异常: " . $e->getMessage());
        }
        // 方法6: 表名推断字段
        try {
            if (method_exists($modelInstance, 'getTable')) {
                $tableName = $modelInstance->getTable();
                if ($tableName) {
                    $fields = self::getFieldsByTableName($tableName);
                    if (!empty($fields)) {
                        return $fields;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("DataTable: getTable()异常: " . $e->getMessage());
        }
        // 所有方法都失败，返回默认字段
        $fields = self::getDefaultFields($modelClass);
        $fields = array_filter($fields, function($field) {
            $name = is_array($field) ? $field['name'] : $field;
            return !in_array(strtolower($name), ['password','token','secret','hash','salt','key','auth']);
        });
        $fields = array_slice($fields, 0, 8);
        return $fields;
    }
    
    /**
     * 获取多模型字段信息
     * @param array $modelConfig 模型配置
     * @param array $joinConfig JOIN配置
     * @return array 字段数组
     */
    private static function getMultiModelFields(array $modelConfig, array $joinConfig = []): array
    {
        $fields = [];
        
        foreach ($modelConfig['models'] as $alias => $modelClass) {
            try {
                $modelInstance = w_obj($modelClass);
                $modelFields = self::getModelFields($modelInstance, $modelClass);
                
                // 为每个字段添加别名前缀
                foreach ($modelFields as $field) {
                    $fieldName = is_array($field) ? $field['name'] : $field;
                    $fieldLabel = is_array($field) ? ($field['label'] ?? $fieldName) : $fieldName;
                    
                    $newField = [
                        'name' => "{$alias}.{$fieldName}",
                        'label' => "{$alias}_{$fieldLabel}",
                        'alias' => $alias,
                        'original_field' => $fieldName
                    ];
                    
                    // 保留原有的字段属性
                    if (is_array($field)) {
                        $newField = array_merge($field, $newField);
                    }
                    
                    $fields[] = $newField;
                }
            } catch (\Exception $e) {
                error_log("DataTable: 无法获取模型 {$modelClass} 的字段: " . $e->getMessage());
                continue;
            }
        }
        
        return $fields;
    }

    /**
     * 检查Table标签自动生成表格结构的主流程
     * 1. 只传model和scope时，能否自动获取字段并渲染表头、过滤器、表体、表尾
     * 2. 多模型和JOIN场景下，字段前缀、别名、字段数量、敏感字段过滤是否正确
     * 3. 代码健壮性：模型不存在、字段获取失败时有无降级处理
     *
     * 修复点：
     * - getModelFields/getMultiModelFields/getDefaultFields等方法的健壮性
     * - 过滤敏感字段、字段数量限制
     * - 自动补全t-header、t-filter、t-body、t-footer
     * - 代码注释和结构优化
     */
    
    // 获取默认字段
    private static function getDefaultFields(string $modelClass): array
    {
        // 优先支持常见模型的字段映射，便于降级和自动生成
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
        $className = basename(str_replace('\\', '/', $modelClass));
        if (isset($modelFieldMap[$className])) {
            return $modelFieldMap[$className];
        }
        // 默认降级字段
        return ['id', 'name', 'status', 'created_at', 'updated_at'];
    }
    // 获取字段宽度
    private static function getFieldWidth(string $fieldName): string
    {
        // 常用字段宽度映射，便于自动生成表格美观
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
    // 根据表名推断字段
    private static function getFieldsByTableName(string $tableName): array
    {
        // 支持常见表名的字段降级推断
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
        foreach ($tableFieldMap as $pattern => $fields) {
            if (stripos($tableName, $pattern) !== false) {
                return $fields;
            }
        }
        return [];
    }
    // 获取过滤器类型
    private static function getFilterType(string $fieldName): string
    {
        // 字段名到过滤器类型的映射
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
    // 解析多模型配置
    private static function parseModelConfig(string $modelConfig): array
    {
        $result = [
            'models' => [],
            'main_model' => '',
            'aliases' => []
        ];
        if (empty($modelConfig)) return $result;
        $modelParts = array_map('trim', explode(',', $modelConfig));
        foreach ($modelParts as $part) {
            if (strpos($part, ' as ') !== false) {
                list($modelClass, $alias) = array_map('trim', explode(' as ', $part, 2));
                $result['models'][$alias] = $modelClass;
                $result['aliases'][$modelClass] = $alias;
                if (empty($result['main_model'])) $result['main_model'] = $modelClass;
            } else {
                $modelClass = trim($part);
                $alias = basename(str_replace('\\', '/', $modelClass));
                $result['models'][$alias] = $modelClass;
                $result['aliases'][$modelClass] = $alias;
                if (empty($result['main_model'])) $result['main_model'] = $modelClass;
            }
        }
        return $result;
    }
    // 解析JOIN配置
    private static function parseJoinConfig(string $joinConfig): array
    {
        $result = [ 'joins' => [] ];
        if (empty($joinConfig)) return $result;
        $joinParts = array_map('trim', explode(',', $joinConfig));
        foreach ($joinParts as $part) {
            $join = [ 'type' => 'INNER', 'table' => '', 'condition' => '' ];
            if (preg_match('/^(left|right|inner|outer)\s+(.+?)\s+on\s+(.+)$/i', $part, $matches)) {
                $join['type'] = strtoupper($matches[1]);
                $join['table'] = trim($matches[2]);
                $join['condition'] = trim($matches[3]);
            } elseif (preg_match('/^(.+?)\s+on\s+(.+)$/i', $part, $matches)) {
                $join['table'] = trim($matches[1]);
                $join['condition'] = trim($matches[2]);
            }
            if (!empty($join['table']) && !empty($join['condition'])) {
                $result['joins'][] = $join;
            }
        }
        return $result;
    }
    // 生成表单HTML（简化版）
    private static function generateFormHtml(string $formId, string $model, string $scope, string $mode, string $title): string
    {
        $title = $title ?: ($mode === 'add' ? __('新增记录') : __('编辑记录'));
        $cancelText = __('取消');
        $saveText = __('保存');
        $addText = __('添加');
        $formHtml = '<div class="w-form-modal" id="w-form-modal-' . $formId . '">';
        $formHtml .= '<div class="w-form-modal-overlay" onclick="DataTableFormManager.closeModal(\'' . $formId . '\')"></div>';
        $formHtml .= '<div class="w-form-modal-container">';
        $formHtml .= '<div class="w-form-container" id="w-form-container-' . $formId . '">';
        $formHtml .= '<div class="w-form-header">';
        $formHtml .= '<h3 class="w-form-title"><i class="fas fa-edit"></i>' . $title . '</h3>';
        $formHtml .= '<button type="button" class="w-form-close" onclick="DataTableFormManager.closeModal(\'' . $formId . '\')"><i class="fas fa-times"></i></button>';
        $formHtml .= '</div>';
        $formHtml .= '<form class="w-form w-form-vertical" id="' . $formId . '" action="/datatable/api/form" method="POST" data-model="' . $model . '" data-scope="' . $scope . '" data-mode="' . $mode . '" data-record-id="">';
        $formHtml .= '<div class="w-form-body"><div class="w-form-fields" id="w-form-fields-' . $formId . '"><div class="w-auto-fields" id="w-auto-fields-' . $formId . '"><div class="w-loading-fields"><i class="fas fa-spinner fa-spin"></i>' . __('正在加载字段...') . '</div></div></div></div>';
        $formHtml .= '<div class="w-form-footer"><div class="w-form-actions">';
        $formHtml .= '<button type="button" class="w-btn w-btn-secondary" onclick="DataTableFormManager.closeModal(\'' . $formId . '\')"><i class="fas fa-times"></i>' . $cancelText . '</button>';
        $formHtml .= '<button type="button" class="w-btn w-btn-primary" onclick="DataTableFormManager.submitForm(\'' . $formId . '\')"><i class="fas fa-save"></i>' . $saveText . '</button>';
        $formHtml .= '</div></div></form></div></div></div>';
        if ($mode === 'add') {
            $formHtml .= '<button type="button" class="w-btn w-btn-primary w-form-trigger" onclick="DataTableFormManager.openModal(\'' . $formId . '\', \'add\')"><i class="fas fa-plus"></i>' . $addText . '</button>';
        }
        $formHtml .= '<script src="@static(Weline_DataTable::js/datatable-form-manager.js)"></script>';
        $formHtml .= '<script>document.addEventListener("DOMContentLoaded", function() {if (typeof DataTableFormManager !== "undefined") {DataTableFormManager.initForm("' . $formId . '", {model: "' . $model . '",scope: "' . $scope . '",mode: "' . $mode . '",recordId: "",autoFields: true,excludeFields: [],includeFields: []});} else {console.error("DataTableFormManager 未加载");}});</script>';
        return $formHtml;
    }
}