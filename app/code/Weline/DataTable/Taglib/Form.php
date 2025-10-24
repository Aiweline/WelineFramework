<?php
/**
 * DataTable 表单标签
 * 支持开发者手动设置字段，未设置的字段由JS动态生成
 * 支持修改和新增记录
 * 支持上下文继承，内部字段可以使用belong属性
 */

namespace Weline\DataTable\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\DataTable\Helper\TableContext;

class Form implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'd-form';
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
            'model' => false,
            'scope' => false,
            'id' => false,
            'action' => false,
            'method' => false,
            'mode' => false,
            'record_id' => false,
            'title' => false,
            'class' => false,
            'layout' => false,
            'auto_fields' => false,
            'exclude_fields' => false,
            'include_fields' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 获取属性
            $model = $attributes['model'] ?? '';
            $scope = $attributes['scope'] ?? 'form';
            $id = $attributes['id'] ?? 'w-form-' . uniqid();
            $action = $attributes['action'] ?? '';
            $method = $attributes['method'] ?? 'POST';
            $mode = $attributes['mode'] ?? 'add';
            $recordId = $attributes['record_id'] ?? '';
            $title = $attributes['title'] ?? '';
            $buttonText = $attributes['button-text'] ?? '添加';
            $buttonClass = $attributes['button-class'] ?? 'w-btn w-btn-primary';
            $buttonIcon = $attributes['button-icon'] ?? 'fas fa-plus';
            $class = $attributes['class'] ?? 'w-form';
            $layout = $attributes['layout'] ?? 'vertical';
            $autoFields = $attributes['auto_fields'] ?? true;
            $excludeFields = $attributes['exclude_fields'] ?? '';
            $includeFields = $attributes['include_fields'] ?? '';
            $for = $attributes['for'] ?? '';


            // 如果模型或作用域未指定，尝试从表格上下文获取
            if (empty($model) || empty($scope)) {
                $tableContext = self::getTableContext();
                if ($tableContext) {
                    if (empty($model)) {
                        $model = $tableContext['model'] ?? '';
                    }
                    if (empty($scope)) {
                        $scope = $tableContext['scope'] ?? 'form';
                    }
                }
            }

            // 验证必需属性
            if (empty($model)) {
                throw new \Exception('d-form 标签必须指定 model 属性，或者确保在 d-table 标签内使用');
            }

            // 设置表单上下文，供内部字段继承使用
            $formContext = [
                'type' => 'd-form',
                'scope' => $scope,
                'model' => $model,
                'attributes' => $attributes
            ];
            TableContext::pushChildTag('d-form', $scope, $formContext);

            // 生成表单标题
            if (empty($title)) {
                $title = $mode === 'add' ? __('新增记录') : __('编辑记录');
            }

            // 生成API URL
            if (empty($action)) {
                $action = '/datatable/api/form';
            }

            // 解析排除和包含字段
            $excludeFieldsArray = !empty($excludeFields) ? array_map('trim', explode(',', $excludeFields)) : [];
            $includeFieldsArray = !empty($includeFields) ? array_map('trim', explode(',', $includeFields)) : [];

            // 获取内容
            $content = $tag_data[2] ?? '';

            // 生成表单HTML
            $formHtml = self::generateFormHtml(
                $id, $model, $scope, $action, $method,
                $mode, $recordId, $title, $class, $layout,
                $content, $autoFields, $excludeFieldsArray,
                $includeFieldsArray, $for, $buttonText, $buttonClass, $buttonIcon
            );

            // 弹出表单上下文
            TableContext::popTag();

            return $formHtml;
        };
    }

    /**
     * 获取表格上下文
     * @return array|null
     */
    private static function getTableContext(): ?array
    {
        // 尝试从TableContext助手类获取当前表格上下文
        if (class_exists('Weline\DataTable\Helper\TableContext')) {
            // 首先尝试获取当前活跃的表格上下文
            $currentContext = TableContext::getCurrentTableContext();
            if ($currentContext) {
                return $currentContext;
            }

            // 如果没有当前上下文，获取所有表格上下文中的最后一个
            $contexts = TableContext::getAllTableContexts();
            if (!empty($contexts)) {
                return end($contexts);
            }
        }
        
        return null;
    }

    /**
     * 生成表单HTML
     */
    private static function generateFormHtml(
        $id,
        $model,
        $scope,
        $action,
        $method,
        $mode,
        $recordId,
        $title,
        $class,
        $layout,
        $tag_data,
        $autoFields,
        $excludeFields,
        $includeFields,
        $for,
        $buttonText = '添加',
        $buttonClass = 'w-btn w-btn-primary',
        $buttonIcon = 'fas fa-plus'
    )
    {
        $layoutClass = $layout === 'horizontal' ? 'w-form-horizontal' : 'w-form-vertical';
        $modeClass = $mode === 'edit' ? 'w-form-edit' : 'w-form-add';
        $cancelText = __('取消');
        $saveText = __('保存');
        $loadingText = __('正在加载字段...');

        // 生成模态框HTML
        $formHtml = '<div class="w-form-modal" id="w-form-modal-' . $id . '">';
        $formHtml .= '<div class="w-form-modal-overlay" onclick="DataTableFormManager.closeModal(\'' . $id . '\')"></div>';
        $formHtml .= '<div class="w-form-modal-container">';
        
        $formHtml .= '<div class="w-form-container" id="w-form-container-' . $id . '">';
        $formHtml .= '<div class="w-form-header">';
        $formHtml .= '<h3 class="w-form-title">';
        $formHtml .= '<i class="fas fa-edit"></i>';
        $formHtml .= $title;
        $formHtml .= '</h3>';
        $formHtml .= '<button type="button" class="w-form-close" onclick="DataTableFormManager.closeModal(\'' . $id . '\')">';
        $formHtml .= '<i class="fas fa-times"></i>';
        $formHtml .= '</button>';
        $formHtml .= '</div>';
        
        // 确保所有变量都是字符串
        $recordIdStr = is_array($recordId) ? implode(',', $recordId) : (string)$recordId;
        $modeStr = is_array($mode) ? implode(',', $mode) : (string)$mode;
        $scopeStr = is_array($scope) ? implode(',', $scope) : (string)$scope;
        $modelStr = is_array($model) ? implode(',', $model) : (string)$model;
        $formHtml .= '<form class="' . $class . ' ' . $layoutClass . ' ' . $modeClass . '" id="' . $id . '" action="' . $action . '" method="' . $method . '" data-model="' . $modelStr . '" data-scope="' . $scopeStr . '" data-mode="' . $modeStr . '" data-record-id="' . $recordIdStr . '">';
        $formHtml .= '<div class="w-form-body">';
        $formHtml .= '<div class="w-form-fields" id="w-form-fields-' . $id . '">';
        $formHtml .= '<!-- 手动设置的字段 -->';
        // 确保 content 是字符串
        $contentStr = (string)$tag_data[1]??'';

        // 处理多表分组
        $processedContent = self::processMultiTableGroups($contentStr, $modelStr);
        $formHtml .= $processedContent;
        $formHtml .= '<!-- 自动生成的字段将在这里插入 -->';
        $formHtml .= '<div class="w-auto-fields" id="w-auto-fields-' . $id . '">';
        $formHtml .= '<div class="w-loading-fields">';
        $formHtml .= '<i class="fas fa-spinner fa-spin"></i>';
        $formHtml .= $loadingText;
        $formHtml .= '</div>';
        $formHtml .= '</div>';
        $formHtml .= '</div>';
        $formHtml .= '</div>';
        
        $formHtml .= '<div class="w-form-footer">';
        $formHtml .= '<div class="w-form-actions">';
        $formHtml .= '<button type="button" class="w-btn w-btn-secondary" onclick="DataTableFormManager.closeModal(\'' . $id . '\')">';
        $formHtml .= '<i class="fas fa-times"></i>';
        $formHtml .= $cancelText;
        $formHtml .= '</button>';
        $formHtml .= '<button type="button" class="w-btn w-btn-primary" onclick="DataTableFormManager.submitForm(\'' . $id . '\')">';
        $formHtml .= '<i class="fas fa-save"></i>';
        $formHtml .= $saveText;
        $formHtml .= '</button>';
        $formHtml .= '</div>';
        $formHtml .= '</div>';
        $formHtml .= '</form>';
        $formHtml .= '</div>';
        
        $formHtml .= '</div>'; // w-form-modal-container
        $formHtml .= '</div>'; // w-form-modal

        // 生成触发按钮（仅在新增模式下）
        if ($mode === 'add') {
            $formHtml .= '<button type="button" class="' . $buttonClass . ' w-form-trigger" onclick="DataTableFormManager.openModal(\'' . $id . '\', \'add\')">';
            $formHtml .= '<i class="' . $buttonIcon . '"></i>';
            $formHtml .= $buttonText;
            $formHtml .= '</button>';
        }

        // 引入CSS和JS文件
        $formHtml .= '<link rel="stylesheet" href="' . \Weline\Framework\App\Env::getInstance()->getBaseUrl() . 'pub/static/Weline/default/Weline/DataTable/view/statics/css/datatable-enhancements.css">';
        $formHtml .= '<script src="' . \Weline\Framework\App\Env::getInstance()->getBaseUrl() . 'pub/static/Weline/default/Weline/DataTable/view/statics/js/datatable-form-manager.js"></script>';
        $formHtml .= '<script>';
        $formHtml .= 'console.log("d-form 脚本开始执行，表单ID: ' . $id . '");';
        $formHtml .= 'document.addEventListener("DOMContentLoaded", function() {';
        $formHtml .= 'console.log("DOM 加载完成，检查 DataTableFormManager");';
        $formHtml .= 'if (typeof DataTableFormManager !== "undefined") {';
        $formHtml .= 'console.log("DataTableFormManager 已加载，初始化表单: ' . $id . '");';
        $formHtml .= 'DataTableFormManager.initForm("' . $id . '", {';
        $formHtml .= 'model: "' . $modelStr . '",';
        $formHtml .= 'scope: "' . $scopeStr . '",';
        $formHtml .= 'mode: "' . $modeStr . '",';
        $formHtml .= 'recordId: "' . $recordIdStr . '",';
        $formHtml .= 'autoFields: ' . ($autoFields ? 'true' : 'false') . ',';
        $formHtml .= 'excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',';
        $formHtml .= 'includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE);
        $formHtml .= '});';
        $formHtml .= '} else {';
        $formHtml .= 'console.error("DataTableFormManager 未加载，请检查JS文件是否正确引入");';
        $formHtml .= '}';
        $formHtml .= '});';
        $formHtml .= '</script>';

        return $formHtml;
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
        return null; // Form标签是独立的，没有依赖
    }

    public static function document(): string
    {
        return <<<DOC
DataTable 表单组件使用说明

【基础用法 - 自动生成字段】：
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <!-- 可以手动设置特定字段 -->
    <w:field name="name" type="text" label="店铺名称" required="true"></w:field>
    <w:field name="description" type="textarea" label="店铺描述"></w:field>
</w:d-form>

【继承模式 - 从表格继承模型】：
<w:d-table model="WeShop\Store\Model\Store" scope="store-table" form="true">
    <!-- 表单会自动继承表格的模型和作用域 -->
    <w:d-form>
        <w:field name="name" type="text" label="店铺名称" required="true"></w:field>
        <w:field name="description" type="textarea" label="店铺描述"></w:field>
    </w:d-form>
</w:d-table>

【编辑模式】：
<w:d-form model="WeShop\Store\Model\Store" scope="store-edit" mode="edit" record_id="123">
    <w:field name="name" type="text" label="店铺名称" required="true"></w:field>
</w:d-form>

【字段belong属性支持】：
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <!-- 字段可以使用belong="d-form"指定属于表单 -->
    <w:field belong="d-form" name="name" type="text" label="店铺名称" required="true"></w:field>
    <w:field belong="d-form" name="description" type="textarea" label="店铺描述"></w:field>
    <w:field belong="d-form" name="status" type="select" label="状态" options="1:启用,0:禁用"></w:field>
</w:d-form>

【排除特定字段】：
<w:d-form model="WeShop\Store\Model\Store" exclude_fields="created_at,updated_at,deleted_at">
    <w:field name="name" type="text" label="店铺名称"></w:field>
</w:d-form>

【只包含特定字段】：
<w:d-form model="WeShop\Store\Model\Store" include_fields="name,description,status">
    <w:field name="name" type="text" label="店铺名称"></w:field>
</w:d-form>

【水平布局】：
<w:d-form model="WeShop\Store\Model\Store" layout="horizontal">
    <w:field name="name" type="text" label="店铺名称"></w:field>
</w:d-form>

【禁用自动字段生成】：
<w:d-form model="WeShop\Store\Model\Store" auto_fields="false">
    <!-- 只显示手动设置的字段 -->
    <w:field name="name" type="text" label="店铺名称"></w:field>
    <w:field name="status" type="select" label="状态" options="1:启用,0:禁用"></w:field>
</w:d-form>

字段标签 (w:field) 属性：
- name: 字段名（必需）
- belong: 所属上下文（d-form/t-header/t-filter）
- type: 字段类型（text, textarea, select, checkbox, radio, date, datetime, number, email, password等）
- label: 字段标签
- placeholder: 占位符
- required: 是否必填
- readonly: 是否只读
- disabled: 是否禁用
- value: 默认值
- options: 选项（用于select、radio、checkbox）
- validation: 验证规则
- help: 帮助文本
- class: CSS类名
- style: 内联样式
DOC;
    }

    /**
     * 处理多表分组
     *
     * @param string $content 表单内容
     * @param string $model 模型字符串
     * @return string 处理后的内容
     */
    private static function processMultiTableGroups(string $content, string $model): string
    {
        // 检查是否为多表模型
        if (strpos($model, ',') === false) {
            return $content; // 单表，直接返回原内容
        }

        // 解析模型配置
        $models = [];
        $modelParts = explode(',', $model);
        foreach ($modelParts as $part) {
            $part = trim($part);
            if (strpos($part, ' as ') !== false) {
                [$modelClass, $alias] = explode(' as ', $part, 2);
                $models[trim($alias)] = trim($modelClass);
            } else {
                // 如果没有别名，使用类名作为别名
                $modelClass = trim($part);
                $className = basename(str_replace('\\', '/', $modelClass));
                $models[$className] = $modelClass;
            }
        }

        // 如果没有fieldset标签，自动生成
        if (strpos($content, '<fieldset') === false) {
            $content = self::generateAutoFieldsets($models) . $content;
        }

        // 处理现有的fieldset标签，添加多表相关的CSS类和属性
        $content = preg_replace_callback(
            '/<fieldset\s+id="([^"]+)"([^>]*?)>(\s*<legend[^>]*>([^<]*)</legend>)?/i',
            function($matches) use ($models) {
                $fieldsetId = $matches[1];
                $attributes = $matches[2];
                $legend = $matches[3] ?? '';
                $legendText = $matches[4] ?? '';

                // 检查fieldset ID是否对应表别名
                if (isset($models[$fieldsetId])) {
                    $modelClass = $models[$fieldsetId];
                    $className = 'multi-table-group table-group-' . $fieldsetId . ' collapsible-group';

                    // 添加CSS类和数据属性
                    if (strpos($attributes, 'class=') !== false) {
                        $attributes = preg_replace('/class="([^"]*)"/', 'class="$1 ' . $className . '"', $attributes);
                    } else {
                        $attributes .= ' class="' . $className . '"';
                    }

                    $attributes .= ' data-table-alias="' . $fieldsetId . '"';
                    $attributes .= ' data-model-class="' . htmlspecialchars($modelClass) . '"';
                    $attributes .= ' data-collapsible="true"';

                    // 如果没有legend，自动生成
                    if (empty($legend)) {
                        $tableName = self::getTableFriendlyName($fieldsetId);
                        $legend = '<legend class="group-legend">';
                        $legend .= '<span class="legend-text">' . $tableName . '</span>';
                        $legend .= '<span class="collapse-toggle" onclick="DataTableFormManager.toggleFieldset(\'' . $fieldsetId . '\')">';
                        $legend .= '<i class="fas fa-chevron-up"></i>';
                        $legend .= '</span>';
                        $legend .= '</legend>';
                    } else {
                        // 为现有legend添加折叠按钮
                        $legend = preg_replace(
                            '/<legend([^>]*)>([^<]*)</i',
                            '<legend$1 class="group-legend">$2<span class="collapse-toggle" onclick="DataTableFormManager.toggleFieldset(\'' . $fieldsetId . '\')"><i class="fas fa-chevron-up"></i></span></',
                            $legend
                        );
                    }
                }

                return '<fieldset id="' . $fieldsetId . '"' . $attributes . '>' . $legend;
            },
            $content
        );

        // 为字段添加表别名前缀
        $content = self::processFieldsWithTablePrefix($content, $models);

        // 添加多表分组相关的CSS样式
        $content .= self::generateMultiTableGroupStyles();

        // 为多表分组添加JavaScript初始化
        $content .= '<script type="text/javascript">';
        $content .= 'document.addEventListener("DOMContentLoaded", function() {';
        $content .= '    if (typeof DataTableFormManager !== "undefined") {';
        $content .= '        DataTableFormManager.initMultiTableGroups();';
        $content .= '        DataTableFormManager.setModelConfig(' . json_encode($models) . ');';
        $content .= '    }';
        $content .= '});';
        $content .= '</script>';

        return $content;
    }

    /**
     * 自动生成fieldset分组
     *
     * @param array $models 模型配置
     * @return string 生成的fieldset HTML
     */
    private static function generateAutoFieldsets(array $models): string
    {
        $html = '';
        
        foreach ($models as $alias => $modelClass) {
            $tableName = self::getTableFriendlyName($alias);
            
            $html .= '<fieldset id="' . $alias . '" class="multi-table-group table-group-' . $alias . ' collapsible-group auto-generated"';
            $html .= ' data-table-alias="' . $alias . '"';
            $html .= ' data-model-class="' . htmlspecialchars($modelClass) . '"';
            $html .= ' data-collapsible="true">';
            $html .= '<legend class="group-legend">';
            $html .= '<span class="legend-text">' . $tableName . '</span>';
            $html .= '<span class="collapse-toggle" onclick="DataTableFormManager.toggleFieldset(\'' . $alias . '\')">';
            $html .= '<i class="fas fa-chevron-up"></i>';
            $html .= '</span>';
            $html .= '</legend>';
            $html .= '<div class="fieldset-content" id="fieldset-content-' . $alias . '">';
            $html .= '<!-- 自动生成的字段将在这里插入 -->';
            $html .= '</div>';
            $html .= '</fieldset>';
        }
        
        return $html;
    }

    /**
     * 处理字段的表别名前缀
     *
     * @param string $content 内容
     * @param array $models 模型配置
     * @return string 处理后的内容
     */
    private static function processFieldsWithTablePrefix(string $content, array $models): string
    {
        // 为field标签添加表别名前缀
        $content = preg_replace_callback(
            '/<w:field\s+([^>]*?)name="([^"]*?)"([^>]*?)>/i',
            function($matches) use ($models, $content) {
                $beforeName = $matches[1];
                $fieldName = $matches[2];
                $afterName = $matches[3];

                // 如果字段名已经包含表别名前缀，直接返回
                if (strpos($fieldName, '.') !== false) {
                    return $matches[0];
                }

                // 查找当前字段所在的fieldset
                $fieldsetPattern = '/<fieldset\s+id="([^"]+)"[^>]*>.*?' . preg_quote($matches[0], '/') . '/s';
                if (preg_match($fieldsetPattern, $content, $fieldsetMatches)) {
                    $fieldsetId = $fieldsetMatches[1];
                    
                    // 如果fieldset对应表别名，添加前缀
                    if (isset($models[$fieldsetId])) {
                        $prefixedName = $fieldsetId . '.' . $fieldName;
                        return '<w:field ' . $beforeName . 'name="' . $prefixedName . '"' . $afterName . '>';
                    }
                }

                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * 生成多表分组相关的CSS样式
     *
     * @return string CSS样式
     */
    private static function generateMultiTableGroupStyles(): string
    {
        return '<style type="text/css">
            .multi-table-group {
                margin-bottom: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
                overflow: hidden;
                transition: all 0.3s ease;
            }
            
            .multi-table-group.collapsed .fieldset-content {
                display: none;
            }
            
            .group-legend {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                margin: 0;
                padding: 12px 15px;
                font-weight: 600;
                font-size: 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                cursor: pointer;
                user-select: none;
            }
            
            .group-legend:hover {
                background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            }
            
            .legend-text {
                flex: 1;
            }
            
            .collapse-toggle {
                transition: transform 0.3s ease;
                padding: 5px;
                border-radius: 3px;
            }
            
            .collapse-toggle:hover {
                background: rgba(255, 255, 255, 0.1);
            }
            
            .multi-table-group.collapsed .collapse-toggle i {
                transform: rotate(180deg);
            }
            
            .fieldset-content {
                padding: 20px;
                background: #f9f9f9;
                transition: all 0.3s ease;
            }
            
            .auto-generated {
                border-style: dashed;
            }
            
            .table-group-indicator {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 8px;
            }
            
            /* 为不同表分组设置不同的颜色 */
            .table-group-0 .table-group-indicator { background: #667eea; }
            .table-group-1 .table-group-indicator { background: #764ba2; }
            .table-group-2 .table-group-indicator { background: #f093fb; }
            .table-group-3 .table-group-indicator { background: #f5576c; }
            .table-group-4 .table-group-indicator { background: #4facfe; }
        </style>';
    }

    /**
     * 获取表的友好名称
     *
     * @param string $tableAlias 表别名
     * @return string 友好名称
     */
    private static function getTableFriendlyName(string $tableAlias): string
    {
        // 转换别名为友好名称
        $friendlyNames = [
            'u' => '用户信息',
            'p' => '档案信息', 
            'a' => '地址信息',
            'o' => '订单信息',
            'user' => '用户信息',
            'profile' => '档案信息',
            'address' => '地址信息',
            'order' => '订单信息',
            'product' => '产品信息',
            'category' => '分类信息'
        ];

        if (isset($friendlyNames[strtolower($tableAlias)])) {
            return $friendlyNames[strtolower($tableAlias)];
        }

        // 如果没有预定义的友好名称，转换驼峰命名为可读格式
        return ucwords(str_replace(['_', '-'], ' ', $tableAlias));
    }
}