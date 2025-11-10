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
use Weline\Framework\View\Template;

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
            'form-mode' => false,
            'form-title' => false,
            'show-trigger-button' => false,
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
            // 检查是否为后端请求
            /** @var \Weline\Framework\Http\Request $request */
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            if (!$request->isBackend() && !$request->isApiBackend()) {
                // 前端请求直接返回空（开发环境返回注释说明）
                if (defined('DEV') && DEV) {
                    return '<!-- DataTable 表单标签只能在后端使用，当前为前端请求 -->';
                }
                return '';
            }
            
            // 获取基础属性
            $model = $attributes['model'] ?? '';
            $scope = $attributes['scope'] ?? 'form';
            $id = $attributes['id'] ?? 'w-form-' . uniqid();
            $action = $attributes['action'] ?? '';
            $method = $attributes['method'] ?? 'POST';
            $mode = $attributes['mode'] ?? 'add';
            $recordId = $attributes['record_id'] ?? '';
            $title = $attributes['title'] ?? '';
            $buttonText = $attributes['button-text'] ?? __('添加');
            $buttonClass = $attributes['button-class'] ?? 'w-btn w-btn-primary';
            $buttonIcon = $attributes['button-icon'] ?? 'fas fa-plus';
            $class = $attributes['class'] ?? 'w-form';
            $layout = $attributes['layout'] ?? 'vertical';
            $autoFields = $attributes['auto_fields'] ?? true;
            $excludeFields = $attributes['exclude_fields'] ?? '';
            $includeFields = $attributes['include_fields'] ?? '';
            $for = $attributes['for'] ?? '';

            // 获取新属性：form-mode, form-title, show-trigger-button
            $formMode = $attributes['form-mode'] ?? 'modal'; // 默认modal模式
            $formTitle = $attributes['form-title'] ?? '';
            $showTriggerButton = isset($attributes['show-trigger-button']) 
                ? filter_var($attributes['show-trigger-button'], FILTER_VALIDATE_BOOLEAN) 
                : null; // null表示未设置，需要根据上下文决定

            // 检测d-form是否在d-table内部
            $tableContext = self::getTableContext();
            // 判断是否在table内部：如果tableContext存在且包含model字段，说明在table内
            $isInsideTable = ($tableContext !== null && !empty($tableContext['model']));

            // 如果d-form在d-table内部且model属性不存在，从table上下文继承model
            if ($isInsideTable && empty($model)) {
                // 从table上下文继承model
                $model = $tableContext['model'] ?? '';
            }
            
            // 如果scope未指定，尝试从表格上下文获取
            if (empty($scope) && $tableContext) {
                $scope = $tableContext['scope'] ?? 'form';
            }

            // 如果d-form在d-table内部且id属性未指定，使用table的ID生成表单ID
            // 这样Table.php中的新增按钮就能正确找到表单了
            if ($isInsideTable && empty($attributes['id']) && !empty($tableContext['id'])) {
                $id = 'form-' . $tableContext['id'];
            }

            // 验证必需属性：model必须存在
            // 1. 优先从标签属性获取
            // 2. 如果属性中没有，尝试从table上下文获取
            // 3. 如果都没有，直接返回错误，不渲染标签
            if (empty($model)) {
                $errorMsg = 'd-form 标签错误：必须指定 model 属性，或者确保在 d-table 标签内使用。';
                $errorMsg .= ' 示例：<w:d-form model="WeShop\\Store\\Model\\Store"> 或 <w:d-table model="..."><w:d-form></w:d-form></w:d-table>';
                
                // 开发环境返回详细错误信息，生产环境返回空（不渲染）
                if (defined('DEV') && DEV) {
                    return '<!-- ' . htmlspecialchars($errorMsg) . ' -->';
                }
                return ''; // 生产环境直接返回空，不渲染标签
            }

            // 处理form-title优先级：form-title > title > 根据mode自动生成
            if (!empty($formTitle)) {
                $title = $formTitle;
            } elseif (empty($title)) {
                $title = $mode === 'add' ? __('新增记录') : __('编辑记录');
            }

            // 处理show-trigger-button逻辑
            // 如果未设置，根据上下文决定：
            // - 独立使用时（不在d-table内）：mode=add时默认显示按钮，因为需要手动触发表单
            // - 嵌套使用时（在d-table内）：默认不显示按钮，因为：
            //   1. 表格会自动为每行数据添加"编辑"按钮（通过 DataTableFormManager.addEditButtons）
            //   2. 表格工具栏通常有"添加"按钮来触发新增表单
            //   3. 避免UI上出现重复的按钮，保持界面简洁
            //   如果需要显示，可以显式设置 show-trigger-button="true"
            if ($showTriggerButton === null) {
                if ($isInsideTable) {
                    // 嵌套使用时默认不显示按钮，由表格统一管理按钮
                    $showTriggerButton = false;
                } else {
                    // 独立使用时，mode=add时显示按钮，用于触发表单
                    $showTriggerButton = ($mode === 'add');
                }
            }

            // 设置表单上下文，供内部字段继承使用
            $formContext = [
                'type' => 'd-form',
                'scope' => $scope,
                'model' => $model,
                'attributes' => $attributes,
                'form-mode' => $formMode,
                'is-inside-table' => $isInsideTable
            ];
            TableContext::pushChildTag('d-form', $scope, $formContext);

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
                $includeFieldsArray, $for, $buttonText, $buttonClass, $buttonIcon,
                $formMode, $showTriggerButton, $isInsideTable
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
        $buttonText = null,
        $buttonClass = 'w-btn w-btn-primary',
        $buttonIcon = 'fas fa-plus',
        $formMode = 'modal',
        $showTriggerButton = true,
        $isInsideTable = false
    )
    {
        $layoutClass = $layout === 'horizontal' ? 'w-form-horizontal' : 'w-form-vertical';
        $modeClass = $mode === 'edit' ? 'w-form-edit' : 'w-form-add';
        $cancelText = __('取消');
        $saveText = __('保存');
        $loadingText = __('正在加载字段...');
        
        // 如果buttonText为null，使用默认值
        if ($buttonText === null) {
            $buttonText = __('添加');
        }

        // 确保所有变量都是字符串
        $recordIdStr = is_array($recordId) ? implode(',', $recordId) : (string)$recordId;
        $modeStr = is_array($mode) ? implode(',', $mode) : (string)$mode;
        $scopeStr = is_array($scope) ? implode(',', $scope) : (string)$scope;
        $modelStr = is_array($model) ? implode(',', $model) : (string)$model;
        $formMode = (string)$formMode;
        
        $formHtml = '';

        // 根据form-mode生成不同的HTML结构
        if ($formMode === 'inline') {
            // Inline模式：直接显示表单，不包含模态框
            $formHtml .= '<div class="w-form-inline-container" id="w-form-container-' . $id . '">';
            $formHtml .= '<div class="w-form-header">';
            $formHtml .= '<h3 class="w-form-title">';
            $formHtml .= '<i class="fas fa-edit"></i> ';
            $formHtml .= $title;
            $formHtml .= '</h3>';
            $formHtml .= '</div>';
            
            $formHtml .= '<form class="' . $class . ' ' . $layoutClass . ' ' . $modeClass . '" id="' . $id . '" action="' . $action . '" method="' . $method . '" data-model="' . $modelStr . '" data-scope="' . $scopeStr . '" data-mode="' . $modeStr . '" data-record-id="' . $recordIdStr . '" data-form-mode="inline">';
            $formHtml .= '<div class="w-form-body">';
            $formHtml .= '<div class="w-form-fields" id="w-form-fields-' . $id . '">';
            $formHtml .= '<!-- 手动设置的字段 -->';
            $contentStr = (string)($tag_data[1] ?? '');
            $processedContent = self::processMultiTableGroups($contentStr, $modelStr);
            $formHtml .= $processedContent;
            $formHtml .= '<!-- 自动生成的字段将在这里插入 -->';
            $formHtml .= '<div class="w-auto-fields" id="w-auto-fields-' . $id . '">';
            $formHtml .= '<div class="w-loading-fields">';
            $formHtml .= '<i class="fas fa-spinner fa-spin"></i> ';
            $formHtml .= $loadingText;
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            
            $formHtml .= '<div class="w-form-footer">';
            $formHtml .= '<div class="w-form-actions">';
            $formHtml .= '<button type="button" class="w-btn w-btn-secondary" onclick="DataTableFormManager.resetForm(\'' . $id . '\')">';
            $formHtml .= '<i class="fas fa-redo"></i> ';
            $formHtml .= __('重置');
            $formHtml .= '</button>';
            $formHtml .= '<button type="button" class="w-btn w-btn-primary" onclick="DataTableFormManager.submitForm(\'' . $id . '\')">';
            $formHtml .= '<i class="fas fa-save"></i> ';
            $formHtml .= $saveText;
            $formHtml .= '</button>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</form>';
            $formHtml .= '</div>';
        } else {
            // Modal模式：生成模态框HTML（默认）
            $formHtml .= '<div class="w-form-modal" id="w-form-modal-' . $id . '">';
            $formHtml .= '<div class="w-form-modal-overlay" onclick="DataTableFormManager.closeModal(\'' . $id . '\')"></div>';
            $formHtml .= '<div class="w-form-modal-container">';
            
            $formHtml .= '<div class="w-form-container" id="w-form-container-' . $id . '">';
            $formHtml .= '<div class="w-form-header">';
            $formHtml .= '<h3 class="w-form-title">';
            $formHtml .= '<i class="fas fa-edit"></i> ';
            $formHtml .= $title;
            $formHtml .= '</h3>';
            $formHtml .= '<button type="button" class="w-form-close" onclick="DataTableFormManager.closeModal(\'' . $id . '\')">';
            $formHtml .= '<i class="fas fa-times"></i>';
            $formHtml .= '</button>';
            $formHtml .= '</div>';
            
            $formHtml .= '<form class="' . $class . ' ' . $layoutClass . ' ' . $modeClass . '" id="' . $id . '" action="' . $action . '" method="' . $method . '" data-model="' . $modelStr . '" data-scope="' . $scopeStr . '" data-mode="' . $modeStr . '" data-record-id="' . $recordIdStr . '" data-form-mode="modal">';
            $formHtml .= '<div class="w-form-body">';
            $formHtml .= '<div class="w-form-fields" id="w-form-fields-' . $id . '">';
            $formHtml .= '<!-- 手动设置的字段 -->';
            $contentStr = (string)($tag_data[1] ?? '');
            $processedContent = self::processMultiTableGroups($contentStr, $modelStr);
            $formHtml .= $processedContent;
            $formHtml .= '<!-- 自动生成的字段将在这里插入 -->';
            $formHtml .= '<div class="w-auto-fields" id="w-auto-fields-' . $id . '">';
            $formHtml .= '<div class="w-loading-fields">';
            $formHtml .= '<i class="fas fa-spinner fa-spin"></i> ';
            $formHtml .= $loadingText;
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            
            $formHtml .= '<div class="w-form-footer">';
            $formHtml .= '<div class="w-form-actions">';
            $formHtml .= '<button type="button" class="w-btn w-btn-secondary" onclick="DataTableFormManager.closeModal(\'' . $id . '\')">';
            $formHtml .= '<i class="fas fa-times"></i> ';
            $formHtml .= $cancelText;
            $formHtml .= '</button>';
            $formHtml .= '<button type="button" class="w-btn w-btn-primary" onclick="DataTableFormManager.submitForm(\'' . $id . '\')">';
            $formHtml .= '<i class="fas fa-save"></i> ';
            $formHtml .= $saveText;
            $formHtml .= '</button>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</form>';
            $formHtml .= '</div>';
            
            $formHtml .= '</div>'; // w-form-modal-container
            $formHtml .= '</div>'; // w-form-modal
        }

        // 生成触发按钮（根据showTriggerButton和mode决定）
        if ($showTriggerButton && $mode === 'add') {
            $formHtml .= '<button type="button" class="' . $buttonClass . ' w-form-trigger" onclick="DataTableFormManager.openModal(\'' . $id . '\', \'add\')">';
            $formHtml .= '<i class="' . $buttonIcon . '"></i> ';
            $formHtml .= $buttonText;
            $formHtml .= '</button>';
        }

        // 内联CSS样式到HTML中（不依赖外部CSS文件）
        $formHtml .= '<style id="w-form-styles-' . $id . '">' . self::getFormStyles() . '</style>';
        
        // 尝试加载 JS 文件，浏览器会自动去重
        /**@var Template $tmp */
        $tmp = w_obj(Template::class);
        $jsUrl = $tmp->fetchTagSource('statics', 'Weline_DataTable::js/datatable-form-manager.js');
        $formHtml .= '<script>
(function() {
    var scriptId = "datatable-form-manager-js";
    if (!document.getElementById(scriptId)) {
        var script = document.createElement("script");
        script.id = scriptId;
        script.src = "' . $jsUrl . '";
        script.async = true;
        script.onload = function() {
            // JS 加载完成后，等待 DataTableFormManager 可用
            var checkInterval = setInterval(function() {
                if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                    clearInterval(checkInterval);
                    console.log("DataTableFormManager 已加载，初始化表单: ' . $id . '");
                    var initForm = function() {
                        DataTableFormManager.initForm("' . $id . '", {
                            model: "' . $modelStr . '",
                            scope: "' . $scopeStr . '",
                            mode: "' . $modeStr . '",
                            recordId: "' . $recordIdStr . '",
                            autoFields: ' . ($autoFields ? 'true' : 'false') . ',
                            excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',
                            includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE) . '
                        });
                    };
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", initForm);
                    } else {
                        initForm();
                    }
                }
            }, 50);
            setTimeout(function() { clearInterval(checkInterval); }, 5000);
        };
        document.head.appendChild(script);
    } else {
        // 如果脚本已存在，直接尝试初始化
        var initForm = function() {
            if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                console.log("DataTableFormManager 已加载，初始化表单: ' . $id . '");
                DataTableFormManager.initForm("' . $id . '", {
                    model: "' . $modelStr . '",
                    scope: "' . $scopeStr . '",
                    mode: "' . $modeStr . '",
                    recordId: "' . $recordIdStr . '",
                    autoFields: ' . ($autoFields ? 'true' : 'false') . ',
                    excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',
                    includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE) . '
                });
            } else {
                console.warn("DataTableFormManager 未加载，等待加载...");
                var checkInterval = setInterval(function() {
                    if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                        clearInterval(checkInterval);
                        DataTableFormManager.initForm("' . $id . '", {
                            model: "' . $modelStr . '",
                            scope: "' . $scopeStr . '",
                            mode: "' . $modeStr . '",
                            recordId: "' . $recordIdStr . '",
                            autoFields: ' . ($autoFields ? 'true' : 'false') . ',
                            excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',
                            includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE) . '
                        });
                    }
                }, 50);
                setTimeout(function() { clearInterval(checkInterval); }, 5000);
            }
        };
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initForm);
        } else {
            initForm();
        }
    }
})();
</script>';

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

    /**
     * 获取表单样式（内联到组件内部，不依赖外部CSS文件）
     *
     * @return string CSS样式内容
     */
    private static function getFormStyles(): string
    {
        return <<<'CSS'
/* DataTable 表单样式 - 内联到组件内部 */
/* 模态框样式 */
.w-form-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}
.w-form-modal.show {
    display: flex;
}
.w-form-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}
.w-form-modal-container {
    position: relative;
    width: 100%;
    max-width: 800px;
    max-height: 90vh;
    overflow: hidden;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
}
@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
/* 表单容器 */
.w-form-container {
    background: #ffffff;
    border-radius: 12px;
    overflow: hidden;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}
/* 表单头部 */
.w-form-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 24px 28px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-shrink: 0;
    min-height: 64px;
}
.w-form-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: #6b7280;
    cursor: pointer;
    padding: 10px;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    margin-left: auto;
}
.w-form-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #374151;
}
.w-form-title {
    margin: 0;
    font-size: 1.375rem;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 12px;
    line-height: 1.5;
}
.w-form-title i {
    color: #3b82f6;
    font-size: 1.125rem;
    display: inline-flex;
    align-items: center;
}
.w-form-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
/* 表单主体 */
.w-form-body {
    padding: 32px 28px;
    flex: 1;
    overflow-y: auto;
    min-height: 200px;
}
.w-form-fields {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
/* 表单字段 */
.w-form-field {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 4px;
}
.w-field-label {
    font-size: 0.9375rem;
    font-weight: 500;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
    line-height: 1.5;
}
.w-required-mark {
    color: #ef4444;
    font-weight: 600;
}
.w-field-control {
    position: relative;
}
.w-form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9375rem;
    color: #374151;
    background: #ffffff;
    transition: all 0.2s ease;
    box-sizing: border-box;
    line-height: 1.5;
    min-height: 44px;
}
.w-form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.w-form-control:hover {
    border-color: #9ca3af;
}
.w-form-control:disabled {
    background: #f9fafb;
    color: #9ca3af;
    cursor: not-allowed;
}
.w-form-control:readonly {
    background: #f9fafb;
}
/* 文本域 */
.w-form-control[type="textarea"],
textarea.w-form-control {
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
    padding: 12px 16px;
    line-height: 1.6;
}
/* 下拉选择 */
select.w-form-control {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 8px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 32px;
}
/* 复选框组 */
.w-checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.w-checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 0.875rem;
    color: #374151;
}
.w-checkbox-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #3b82f6;
    cursor: pointer;
}
.w-checkbox-label {
    cursor: pointer;
}
/* 单选框组 */
.w-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.w-radio-item {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 0.875rem;
    color: #374151;
}
.w-radio-item input[type="radio"] {
    width: 16px;
    height: 16px;
    accent-color: #3b82f6;
    cursor: pointer;
}
.w-radio-label {
    cursor: pointer;
}
/* 字段帮助文本 */
.w-field-help {
    font-size: 0.8125rem;
    color: #6b7280;
    margin-top: 6px;
    line-height: 1.5;
}
/* 字段验证 */
.w-field-validation {
    font-size: 0.8125rem;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.5;
}
.w-field-validation.w-field-error {
    color: #ef4444;
}
.w-field-validation i {
    font-size: 0.875rem;
}
/* 字段错误状态 */
.w-form-field.w-field-error .w-form-control {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}
.w-form-field.w-field-error .w-field-label {
    color: #ef4444;
}
/* 表单底部 */
.w-form-footer {
    background: #f9fafb;
    padding: 24px 28px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    flex-shrink: 0;
    min-height: 72px;
    align-items: center;
}
/* 按钮样式 */
.w-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 24px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 0.9375rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    background: none;
    outline: none;
    box-sizing: border-box;
    min-height: 44px;
    line-height: 1.5;
    white-space: nowrap;
    user-select: none;
}
.w-btn i {
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    width: 1.2em;
    justify-content: center;
}
/* 表单触发按钮 */
.w-form-trigger {
    margin: 16px 0;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    font-weight: 600;
    letter-spacing: 0.025em;
    min-height: 44px;
    padding: 12px 24px;
}
.w-form-trigger:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    transform: translateY(-1px);
}
.w-form-trigger:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}
.w-form-trigger i {
    font-size: 1rem;
    margin-right: 6px;
    width: 1.2em;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.w-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.w-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
}
.w-btn-primary:hover:not(:disabled) {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-color: #1d4ed8;
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.4);
    transform: translateY(-1px);
}
.w-btn-primary:active:not(:disabled) {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(59, 130, 246, 0.3);
}
.w-btn-secondary {
    background: #ffffff;
    color: #374151;
    border: 1px solid #d1d5db;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.w-btn-secondary:hover:not(:disabled) {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #1f2937;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}
.w-btn-secondary:active:not(:disabled) {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    background: #f3f4f6;
}
/* 表单消息 */
.w-form-message {
    padding: 12px 16px;
    border-radius: 6px;
    margin: 16px 24px 0 24px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.w-form-message-success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.w-form-message-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.w-form-message i {
    font-size: 1rem;
}
/* 加载字段提示 */
.w-loading-fields {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 32px 24px;
    color: #6b7280;
    font-size: 0.9375rem;
    margin: 16px 0;
}
.w-loading-fields i {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
/* 水平布局 */
.w-form-horizontal .w-form-field {
    flex-direction: row;
    align-items: center;
    gap: 16px;
}
.w-form-horizontal .w-field-label {
    min-width: 120px;
    text-align: right;
    margin-bottom: 0;
}
.w-form-horizontal .w-field-control {
    flex: 1;
}
/* 响应式设计 */
@media (max-width: 768px) {
    .w-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    .w-form-actions {
        width: 100%;
        justify-content: flex-end;
    }
    .w-form-body {
        padding: 16px;
    }
    .w-form-footer {
        padding: 16px;
    }
    .w-form-horizontal .w-form-field {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .w-form-horizontal .w-field-label {
        min-width: auto;
        text-align: left;
    }
}
/* 深色模式支持 - 基于媒体查询 */
@media (prefers-color-scheme: dark) {
    .w-form-container {
        background: #1f2937;
        border-color: #4b5563;
    }
    .w-form-header {
        background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
        border-bottom-color: #4b5563;
    }
    .w-form-title {
        color: #f9fafb;
    }
    .w-form-control {
        background: #374151;
        border-color: #4b5563;
        color: #f9fafb;
    }
    .w-form-control:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }
    .w-form-control:hover {
        border-color: #6b7280;
    }
    .w-form-control:disabled {
        background: #374151;
        color: #6b7280;
    }
    .w-form-control:readonly {
        background: #374151;
    }
    .w-field-label {
        color: #d1d5db;
    }
    .w-checkbox-item,
    .w-radio-item {
        color: #d1d5db;
    }
    .w-field-help {
        color: #9ca3af;
    }
    .w-form-footer {
        background: #374151;
        border-top-color: #4b5563;
    }
    .w-btn-secondary {
        background: #374151;
        color: #d1d5db;
        border-color: #4b5563;
    }
    .w-btn-secondary:hover:not(:disabled) {
        background: #4b5563;
        border-color: #6b7280;
    }
    .w-loading-fields {
        color: #9ca3af;
    }
}
/* 深色主题支持 - 基于body属性 */
body[data-sidebar="dark"] .w-form-container,
body[data-topbar="dark"] .w-form-container,
body[data-sidebar="dark"] .w-form-inline-container,
body[data-topbar="dark"] .w-form-inline-container {
    background: #1f2937;
    border-color: #4b5563;
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-header,
body[data-topbar="dark"] .w-form-header {
    background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
    border-bottom-color: #4b5563;
}
body[data-sidebar="dark"] .w-form-title,
body[data-topbar="dark"] .w-form-title {
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-title i,
body[data-topbar="dark"] .w-form-title i {
    color: #60a5fa;
}
body[data-sidebar="dark"] .w-form-close,
body[data-topbar="dark"] .w-form-close {
    color: #9ca3af;
}
body[data-sidebar="dark"] .w-form-close:hover,
body[data-topbar="dark"] .w-form-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-body,
body[data-topbar="dark"] .w-form-body {
    background: #1f2937;
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-control,
body[data-topbar="dark"] .w-form-control,
body[data-sidebar="dark"] input.w-form-control,
body[data-sidebar="dark"] textarea.w-form-control,
body[data-sidebar="dark"] select.w-form-control,
body[data-topbar="dark"] input.w-form-control,
body[data-topbar="dark"] textarea.w-form-control,
body[data-topbar="dark"] select.w-form-control {
    background: #374151;
    border-color: #4b5563;
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-control:focus,
body[data-topbar="dark"] .w-form-control:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
}
body[data-sidebar="dark"] .w-form-control:hover,
body[data-topbar="dark"] .w-form-control:hover {
    border-color: #6b7280;
}
body[data-sidebar="dark"] .w-form-control:disabled,
body[data-topbar="dark"] .w-form-control:disabled {
    background: #374151;
    color: #6b7280;
}
body[data-sidebar="dark"] .w-form-control:readonly,
body[data-topbar="dark"] .w-form-control:readonly {
    background: #374151;
}
body[data-sidebar="dark"] .w-field-label,
body[data-topbar="dark"] .w-field-label {
    color: #d1d5db;
}
body[data-sidebar="dark"] .w-checkbox-item,
body[data-sidebar="dark"] .w-radio-item,
body[data-topbar="dark"] .w-checkbox-item,
body[data-topbar="dark"] .w-radio-item {
    color: #d1d5db;
}
body[data-sidebar="dark"] .w-field-help,
body[data-topbar="dark"] .w-field-help {
    color: #9ca3af;
}
body[data-sidebar="dark"] .w-form-footer,
body[data-topbar="dark"] .w-form-footer {
    background: #374151;
    border-top-color: #4b5563;
}
body[data-sidebar="dark"] .w-btn-secondary,
body[data-topbar="dark"] .w-btn-secondary {
    background: #374151;
    color: #d1d5db;
    border-color: #4b5563;
}
body[data-sidebar="dark"] .w-btn-secondary:hover:not(:disabled),
body[data-topbar="dark"] .w-btn-secondary:hover:not(:disabled) {
    background: #4b5563;
    border-color: #6b7280;
}
body[data-sidebar="dark"] .w-loading-fields,
body[data-topbar="dark"] .w-loading-fields {
    color: #9ca3af;
}
body[data-sidebar="dark"] .w-form-message-success,
body[data-topbar="dark"] .w-form-message-success {
    background: #064e3b;
    color: #6ee7b7;
    border-color: #059669;
}
body[data-sidebar="dark"] .w-form-message-error,
body[data-topbar="dark"] .w-form-message-error {
    background: #7f1d1d;
    color: #fca5a5;
    border-color: #dc2626;
}
/* 动画效果 */
.w-form-container {
    animation: fadeInUp 0.3s ease;
}
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* 表单字段动画 */
.w-form-field {
    animation: slideInLeft 0.3s ease;
}
@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}
/* 消息动画 */
.w-form-message {
    animation: slideInDown 0.3s ease;
}
@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* Inline 表单容器样式 */
.w-form-inline-container {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    margin: 20px 0;
}
.w-form-inline-container .w-form-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 24px 28px;
    border-bottom: 1px solid #e5e7eb;
    min-height: 64px;
}
.w-form-inline-container .w-form-body {
    padding: 32px 28px;
    min-height: 200px;
}
.w-form-inline-container .w-form-footer {
    background: #f9fafb;
    padding: 24px 28px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    min-height: 72px;
    align-items: center;
}
/* 按钮组增强 */
.w-form-actions {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 4px;
}
.w-form-actions .w-btn {
    flex-shrink: 0;
}
/* 改进加载状态样式 */
.w-auto-fields .w-loading-fields {
    background: #f9fafb;
    border-radius: 8px;
    border: 1px dashed #d1d5db;
    margin: 8px 0;
}
.w-loading-fields i {
    font-size: 1.125rem;
}
/* 改进图标显示 */
.w-btn i.fas,
.w-btn i.far,
.w-btn i.fab {
    font-family: "Font Awesome 5 Free", "Font Awesome 5 Brands";
    font-weight: 900;
    display: inline-block;
    width: 1em;
    text-align: center;
}
/* 按钮焦点状态 */
.w-btn:focus-visible {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}
/* 暗色主题下的按钮改进 */
body[data-sidebar="dark"] .w-form-trigger,
body[data-topbar="dark"] .w-form-trigger {
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}
body[data-sidebar="dark"] .w-form-trigger:hover,
body[data-topbar="dark"] .w-form-trigger:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
}
body[data-sidebar="dark"] .w-form-inline-container,
body[data-topbar="dark"] .w-form-inline-container {
    background: #1f2937;
    border-color: #4b5563;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
body[data-sidebar="dark"] .w-btn-primary,
body[data-topbar="dark"] .w-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.4);
}
body[data-sidebar="dark"] .w-btn-primary:hover:not(:disabled),
body[data-topbar="dark"] .w-btn-primary:hover:not(:disabled) {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.5);
}
body[data-sidebar="dark"] .w-loading-fields,
body[data-topbar="dark"] .w-loading-fields {
    background: #374151;
    border-color: #4b5563;
}
CSS;
    }
}