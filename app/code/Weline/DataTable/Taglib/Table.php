<?php

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
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
            'isolate' => false,
            'dependencies' => false,
            'transaction' => false,
            'allow-frontend' => false,
            'api-url' => false,
            'field-api-url' => false,
            'api-provider' => false,
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
            if (!FrontendAccess::isAllowed($attributes)) {
                return FrontendAccess::deniedComment('d-table');
            }
            // 检查是否为后端请求
            /** @var \Weline\Framework\Http\Request $request */
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $isUnitTest = (\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__');
            if (false) {
                // 前端请求直接返回空（开发环境返回注释说明）
                if (defined('DEV') && DEV) {
                    return '<!-- DataTable 标签只能在后端使用，当前为前端请求 -->';
                }
                return '';
            }
            
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
                w_log_warning("DataTable Warning: 多模型配置但未指定JOIN条件，可能导致查询错误");
            }

            // 处理基本属性
            $id = $attributes['id'] ?? 'datatable-' . uniqid();
            $class = $attributes['class'] ?? 'table table-striped table-bordered weline-datatable';
            $style = $attributes['style'] ?? '';

            // 处理隔离标志（scope隔离）
            $isolate = filter_var($attributes['isolate'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // 如果设置了隔离标志，使用 scope 作为实例标识符
            if ($isolate) {
                // 使用 scope 作为 ID，确保同一 scope 只有一个实例
                $id = 'datatable-scope-' . $scope;
            }

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
            $allowFrontend = filter_var($attributes['allow-frontend'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $dependencies = $attributes['dependencies'] ?? '';
            $transaction = filter_var($attributes['transaction'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $apiUrl = $attributes['api-url'] ?? 'datatable/rest/v1/data-table';
            $fieldApiUrl = $attributes['field-api-url'] ?? 'datatable/rest/v1/form/fields';
            $apiProvider = $attributes['api-provider'] ?? ($allowFrontend ? 'datatable' : '');

            // 处理表单属性
            $form = $attributes['form'] ?? '';
            $formMode = $attributes['form-mode'] ?? 'modal';
            $formTitle = $attributes['form-title'] ?? '';

            // 存储表格上下文，供子标签继承使用
            $tableContext = [
                'id' => $id, // 存储table的ID，供d-form使用
                'model' => $model,
                'scope' => $scope,
                'join' => $join,
                'model_config' => $modelConfig,
                'join_config' => $joinConfig,
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
                'allow-frontend' => $allowFrontend,
                'dependencies' => $dependencies,
                'transaction' => $transaction,
                'api-url' => $apiUrl,
                'field-api-url' => $fieldApiUrl,
                'api-provider' => $apiProvider,
                'isolate' => $isolate,
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
            $isolateJs = $isolate ? 'true' : 'false';
            $transactionJs = $transaction ? 'true' : 'false';
            $workerApiJs = ($allowFrontend && $apiProvider) ? 'true' : 'false';

            // 修复模型名称的转义问题
            $modelJs = str_replace('\\', '\\\\', $model);
            $apiUrlJs = addslashes($apiUrl);
            $fieldApiUrlJs = addslashes($fieldApiUrl);
            $apiProviderJs = addslashes((string)$apiProvider);
            $dependenciesJs = addslashes($dependencies);
            // HTML 属性转义
            $modelHtml = htmlspecialchars($model ?? '', ENT_QUOTES, 'UTF-8');
            $scopeHtml = htmlspecialchars($scope ?? '', ENT_QUOTES, 'UTF-8');

            // 生成多模型和JOIN配置的JSON
            $modelConfigJson = json_encode($modelConfig, JSON_UNESCAPED_UNICODE);
            $joinConfigJson = json_encode($joinConfig, JSON_UNESCAPED_UNICODE);

            // 检查内容中是否包含 d-form 标签（检测嵌套的表单）
            $hasNestedForm = false;
            $nestedFormId = '';
            if (!empty($content)) {
                // 检查内容中是否包含 d-form 标签
                $hasNestedForm = (strpos($content, 'd-form') !== false || 
                                 strpos($content, 'w:d-form') !== false ||
                                 preg_match('/<[^>]*d-form[^>]*>/i', $content));
                
                // 尝试从内容中提取 d-form 的 id 属性
                if ($hasNestedForm) {
                    if (preg_match('/<[^>]*d-form[^>]*id=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
                        $nestedFormId = $matches[1];
                    } elseif (preg_match('/<[^>]*d-form[^>]*>/i', $content)) {
                        // 如果没有找到 id，使用默认的 formId
                        $nestedFormId = 'form-' . $id;
                    }
                }
            }
            
            // 如果设置了 form 属性或检测到嵌套的 d-form，则启用表单功能
            /**@var Template $tmp */
            $tmp = w_obj(Template::class);
            /**@var \Weline\Framework\View\Taglib $taglib */
            $taglib = ObjectManager::getInstance(\Weline\Framework\View\Taglib::class);
            $content = $taglib->tagReplace($tmp, $content);

            $enableForm = !empty($form) || $hasNestedForm;
            
            // 生成表单HTML（如果启用）
            $formHtml = '';
            $formJs = 'null';
            $formId = '';
            if ($enableForm) {
                // 如果检测到嵌套的 d-form，使用嵌套表单的 ID
                if ($hasNestedForm && empty($form)) {
                    $formId = $nestedFormId ?: 'form-' . $id;
                    // 不生成表单HTML，因为 d-form 标签会自己生成
                    $formHtml = '';
                    $formJs = "'{$formId}'";
                } else {
                    $formId = 'form-' . $id;
                    $formHtml = self::generateFormHtml($formId, $model, $scope, $formMode, $formTitle, $apiUrl, $fieldApiUrl, (string)$apiProvider);
                    $formJs = "'{$formId}'";
                }
            } else {
                // 即使没有表单，也自动创建一个默认表单，以便新增按钮可以使用
                $formId = 'form-' . $id;
                $formHtml = self::generateFormHtml($formId, $model, $scope, $formMode, $formTitle, $apiUrl, $fieldApiUrl, (string)$apiProvider);
                $formJs = "'{$formId}'";
                $enableForm = true; // 标记为已启用表单
            }

            // 生成新增按钮HTML（始终显示，因为我们已经确保有表单）
            $addButtonHtml = '';
            if ($enableForm && !empty($formId)) {
                $addButtonText = __('新增');
                $addButtonTitle = __('新增记录');
                $addButtonHtml = <<<BUTTON
                    <button type="button" class="w-toolbar-btn primary" onclick="if (typeof DataTableFormManager !== 'undefined') { DataTableFormManager.openModal('{$formId}', 'add'); } else { console.error('DataTableFormManager 未加载'); }" title="{$addButtonTitle}">
                        <i class="fas fa-plus"></i>
                        {$addButtonText}
                    </button>
BUTTON;
            }

            // 生成自动生成标识
            $autoGeneratedBadge = $hasManualConfig ? '' : '<span class="badge bg-info ms-2">' . __('自动生成') . '</span>';

            $jsUrl = $tmp->fetchTagSource('statics','Weline_DataTable::js/datatable-manager.js');
            
            // 读取并内联 CSS 文件
            $cssFile = __DIR__ . '/../view/statics/css/datatable.css';
            $inlineCss = file_exists($cssFile) ? file_get_contents($cssFile) : '';
            
            $dtableName = __('数据表格');
            $dtableTitle = __('数据表格');
            $dtableFieldConfig = __('字段设置');
            $dtableRefresh = __('刷新');
            $dtableTotal = __('总记录');
            $dtableDisplay = __('显示');
            // 生成弹窗滚动条美化样式（内联CSS）
            $modalScrollbarStyle = <<<CSS
<style id="w-datatable-modal-scrollbar-style">
/* 字段配置弹窗布局样式 */
#w-field-config-modal-{$id} .w-modal-dialog {
    display: flex;
    flex-direction: column;
    max-height: 90vh;
    background: #ffffff;
    border-radius: 8px;
    overflow: hidden;
}

#w-field-config-modal-{$id} .w-modal-header {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

#w-field-config-modal-{$id} .w-modal-header .w-modal-title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

#w-field-config-modal-{$id} .w-modal-header .w-btn-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: #6b7280;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    margin-left: auto;
}

#w-field-config-modal-{$id} .w-modal-header .w-btn-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #374151;
}

#w-field-config-modal-{$id} .w-modal-content {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

#w-field-config-modal-{$id} .w-modal-body {
    flex: 1;
    overflow: hidden;
    padding: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

#w-field-config-modal-{$id} .w-config-tabs {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

#w-field-config-modal-{$id} .w-nav.w-nav-tabs {
    flex-shrink: 0;
    margin: 0;
    padding: 16px 20px 0 20px;
    background: #ffffff;
    border-bottom: 2px solid #e5e7eb;
}

#w-field-config-modal-{$id} .w-tab-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 20px;
    min-height: 0;
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-nav.w-nav-tabs,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-nav.w-nav-tabs {
    background: #1f2937;
    border-bottom-color: #4b5563;
}

#w-field-config-modal-{$id} .w-modal-footer {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

/* 暗色模式支持 */
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-modal-dialog,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-modal-dialog {
    background: #1f2937;
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-modal-header,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-modal-header,
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-modal-footer,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-modal-footer {
    background: #374151;
    border-color: #4b5563;
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-modal-header .w-modal-title,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-modal-header .w-modal-title {
    color: #f9fafb;
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-modal-header .w-btn-close,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-modal-header .w-btn-close {
    color: #9ca3af;
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-modal-header .w-btn-close:hover,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-modal-header .w-btn-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #f9fafb;
}

/* 弹窗滚动条美化 - 亮色模式 */
#w-field-config-modal-{$id} .w-tab-content {
    scrollbar-width: thin;
    scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
}

#w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

#w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-track {
    background: transparent;
    border-radius: 3px;
}

#w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, rgba(156, 163, 175, 0.4) 0%, rgba(156, 163, 175, 0.6) 100%);
    border-radius: 3px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    transition: all 0.2s ease;
}

#w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, rgba(107, 114, 128, 0.6) 0%, rgba(107, 114, 128, 0.8) 100%);
    border-color: rgba(255, 255, 255, 0.2);
}

#w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb:active {
    background: linear-gradient(180deg, rgba(75, 85, 99, 0.7) 0%, rgba(75, 85, 99, 0.9) 100%);
}

/* 弹窗滚动条美化 - 暗色模式 */
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, rgba(107, 114, 128, 0.4) 0%, rgba(107, 114, 128, 0.6) 100%);
    border-color: rgba(0, 0, 0, 0.2);
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb:hover,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-tab-content::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, rgba(156, 163, 175, 0.6) 0%, rgba(156, 163, 175, 0.8) 100%);
    border-color: rgba(0, 0, 0, 0.3);
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-tab-content,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-tab-content {
    scrollbar-color: rgba(107, 114, 128, 0.5) transparent;
}

/* 弹窗内容区域滚动条美化 */
#w-field-config-modal-{$id} .w-available-fields,
#w-field-config-modal-{$id} .w-display-fields,
#w-field-config-modal-{$id} .w-filter-fields {
    scrollbar-width: thin;
    scrollbar-color: rgba(156, 163, 175, 0.4) transparent;
}

#w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar,
#w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar,
#w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar {
    width: 5px;
    height: 5px;
}

#w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-track,
#w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-track,
#w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-track {
    background: transparent;
    border-radius: 2.5px;
}

#w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-thumb,
#w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-thumb,
#w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, rgba(156, 163, 175, 0.3) 0%, rgba(156, 163, 175, 0.5) 100%);
    border-radius: 2.5px;
    transition: all 0.2s ease;
}

#w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-thumb:hover,
#w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-thumb:hover,
#w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, rgba(107, 114, 128, 0.5) 0%, rgba(107, 114, 128, 0.7) 100%);
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-thumb,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-thumb,
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-thumb,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-thumb,
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-thumb,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, rgba(107, 114, 128, 0.3) 0%, rgba(107, 114, 128, 0.5) 100%);
}

body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-thumb:hover,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-available-fields::-webkit-scrollbar-thumb:hover,
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-thumb:hover,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-display-fields::-webkit-scrollbar-thumb:hover,
body[data-sidebar="dark"] #w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-thumb:hover,
body[data-topbar="dark"] #w-field-config-modal-{$id} .w-filter-fields::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, rgba(156, 163, 175, 0.5) 0%, rgba(156, 163, 175, 0.7) 100%);
}
</style>
CSS;

            $result = <<<HTML
<script>
// 尝试加载 datatable-manager.js，浏览器会自动去重
(function() {
    var scriptId = 'datatable-manager-js';
    if (!document.getElementById(scriptId)) {
        var script = document.createElement('script');
        script.id = scriptId;
        script.src = '{$jsUrl}';
        // 使用 defer 确保脚本按顺序执行且在 DOM 解析后执行
        script.defer = true;
        document.head.appendChild(script);
    }
})();
</script>
<style>
{$inlineCss}
</style>
{$modalScrollbarStyle}
{$formHtml}
<script>
(function() {
    var tableId = '{$id}';
    var dtableFieldConfig = '{$dtableFieldConfig}';
    var resolveApiUrl = function(route) {
        if (!route) {
            return '';
        }
        if (/^https?:\/\//i.test(route) || route.charAt(0) === '/') {
            return route;
        }
        if (typeof window.api === "function") {
            return window.api(route);
        }
        if (window.site && window.site.api_host) {
            var apiHost = window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/';
            return apiHost + route.replace(/^\/+/, '');
        }
        return '/' + route.replace(/^\/+/, '');
    };
    
    // 启用字段配置按钮
    var enableFieldConfigBtn = function() {
        var container = document.getElementById('w-datatable-' + tableId);
        if (container) {
            var btn = container.querySelector('.w-btn-field-config');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-cog"></i> <span><?= __('字段配置') ?></span>';
                btn.onclick = function() {
                    if (window.DataTableManager && typeof DataTableManager.openFieldConfig === 'function') {
                        DataTableManager.openFieldConfig(tableId);
                    }
                };
            }
        }
    };
    
    var initTable = function() {
        // 等待 DataTableManager 加载完成
        var checkManager = setInterval(function() {
            if (typeof window.DataTableManager !== "undefined" && window.DataTableManager.initTable) {
                clearInterval(checkManager);
                
                // 启用字段配置按钮
                enableFieldConfigBtn();
                
                // 初始化数据表格
                window.DataTableManager.initTable('#' + tableId, {
                    model: '{$modelJs}',
                    scope: '{$scope}',
                    join: '{$join}',
                    modelConfig: {$modelConfigJson},
                    joinConfig: {$joinConfigJson},
                    apiUrl: {$workerApiJs} ? '' : resolveApiUrl('{$apiUrlJs}'),
                    fieldApiUrl: {$workerApiJs} ? '' : resolveApiUrl('{$fieldApiUrlJs}'),
                    workerApi: {$workerApiJs},
                    apiProvider: '{$apiProviderJs}',
                    operations: {
                        data: 'data',
                        fields: 'fields',
                        saveConfig: 'saveConfig',
                        clearConfig: 'clearConfig',
                        create: 'create',
                        update: 'update',
                        saveData: 'saveData',
                        deleteData: 'deleteData',
                        exportData: 'exportData'
                    },
                    dependencies: '{$dependenciesJs}',
                    transaction: {$transactionJs},
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
                    formId: {$formJs},
                    isolate: {$isolateJs}
                });
                
                // 如果启用了表单，添加编辑按钮
                if ({$formJs}) {
                    // 等待 DataTableFormManager 加载完成
                    var checkFormManager = setInterval(function() {
                        if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                            clearInterval(checkFormManager);
                            DataTableFormManager.addEditButtons(tableId, {$formJs});
                        }
                    }, 50);
                    setTimeout(function() { clearInterval(checkFormManager); }, 5000);
                }
            }
        }, 50);
        setTimeout(function() { 
            clearInterval(checkManager);
            if (typeof window.DataTableManager === "undefined") {
                console.error("DataTableManager 未加载，请检查 JS 文件是否正确引入");
                // 更新按钮状态为错误
                var container = document.getElementById('w-datatable-' + tableId);
                if (container) {
                    var btn = container.querySelector('.w-btn-field-config');
                    if (btn) {
                        btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span><?= __('加载失败') ?></span>';
                        btn.classList.add('error');
                    }
                }
            }
        }, 10000);
    };
    
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initTable);
    } else {
        initTable();
    }
})();
</script>

<div id="w-datatable-{$id}" class="w-datatable" data-model="{$modelHtml}" data-scope="{$scopeHtml}" style="{$styleStr}">
    <div class="w-datatable-container">
        <!-- 工具栏 -->
        <div class="w-datatable-toolbar">
            <div class="w-toolbar-left">
                <div class="w-toolbar-title">
                    <i class="fas fa-table"></i>
                    <span>{$dtableTitle}</span>
                </div>
                <div class="w-toolbar-actions">
                    {$addButtonHtml}
                    <button type="button" class="w-toolbar-btn primary w-btn-field-config" data-w-action="field-config" data-table="{$id}" title="{$dtableFieldConfig}" disabled>
                        <i class="fas fa-spinner fa-spin"></i>
                        <span><?= __('加载中') ?>...</span>
                    </button>
                    <button type="button" class="w-toolbar-btn success" onclick="window.DataTableManager &amp;&amp; DataTableManager.refreshData('{$id}')" title="<?= __('刷新数据') ?>">
                        <i class="fas fa-sync-alt"></i>
                        <?= __('刷新') ?>
                    </button>
                    <button type="button" class="w-toolbar-btn warning" data-w-action="important-view" onclick="window.DataTableManager &amp;&amp; DataTableManager.toggleImportantView('{$id}')" title="<?= __('只显示重要数据') ?>">
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
                                    <button type="button" class="w-dropdown-item" onclick="window.DataTableManager &amp;&amp; DataTableManager.clearHeaderConfig('{$id}')" title="<?= __('重置表头字段配置') ?>">
                                        <i class="fas fa-columns"></i>
                                        <?= __('重置表头') ?>
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="w-dropdown-item" onclick="window.DataTableManager &amp;&amp; DataTableManager.clearFilterConfig('{$id}')" title="<?= __('重置筛选字段配置') ?>">
                                        <i class="fas fa-filter"></i>
                                        <?= __('重置筛选') ?>
                                    </button>
                                </li>
                                <li><hr class="w-dropdown-divider"></li>
                                <li>
                                    <button type="button" class="w-dropdown-item" onclick="window.DataTableManager &amp;&amp; DataTableManager.clearAllConfig('{$id}')" title="<?= __('重置全部配置') ?>">
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
                                <button type="button" class="w-dropdown-item" onclick="window.DataTableManager &amp;&amp; DataTableManager.exportData('{$id}', 'excel')" title="<?= __('导出为Excel') ?>">
                                    <i class="fas fa-file-excel"></i>
                                    <?= __('导出Excel') ?>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="w-dropdown-item" onclick="window.DataTableManager &amp;&amp; DataTableManager.exportData('{$id}', 'csv')" title="<?= __('导出为CSV') ?>">
                                    <i class="fas fa-file-csv"></i>
                                    <?= __('导出CSV') ?>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="w-dropdown-item" onclick="window.DataTableManager &amp;&amp; DataTableManager.exportData('{$id}', 'json')" title="<?= __('导出为JSON') ?>">
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
            <thead class="datatable-header" data-model="{$modelHtml}" data-scope="{$scopeHtml}"></thead>
            <tbody class="datatable-body" data-model="{$modelHtml}" data-scope="{$scopeHtml}"></tbody>
            <tfoot class="datatable-footer" data-model="{$modelHtml}" data-scope="{$scopeHtml}">
                <tr>
                    <td colspan="100%">
                        <div class="datatable-footer-content">
                            <div class="datatable-summary" style="display: block;">
                                <span class="datatable-summary-text"></span>
                            </div>
                            <div class="datatable-footer-center"></div>
                            <div class="datatable-pagination" style="display: none;"></div>
                        </div>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- 字段设置modal结构，直接输出HTML到body -->
<div class="w-modal" id="w-field-config-modal-{$id}" tabindex="-1" aria-labelledby="w-field-config-modal-label-{$id}" aria-hidden="true" style="display:none;">
    <div class="w-modal-dialog w-modal-lg">
        <div class="w-modal-header">
            <h5 class="w-modal-title" id="w-field-config-modal-label-{$id}">
                <i class="fas fa-cog"></i> <?= __('字段配置') ?>
            </h5>
            <button type="button" class="w-btn-close" onclick="window.DataTableManager &amp;&amp; DataTableManager.closeFieldConfig('{$id}')" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="w-modal-content">
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
        </div>
        <div class="w-modal-footer">
            <button type="button" class="w-btn w-btn-secondary" onclick="window.DataTableManager &amp;&amp; DataTableManager.closeFieldConfig('{$id}')"><?= __('取消') ?></button>
            <button type="button" class="w-btn w-btn-primary" onclick="window.DataTableManager &amp;&amp; DataTableManager.saveFieldConfig('{$id}')"><?= __('保存配置') ?></button>
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
                w_log_error("DataTable: 无法实例化模型 {$model}: " . $e->getMessage());
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
        
        // 生成过滤器字段（只包含主要字段，并且验证字段是否存在）
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
            
            // 验证字段是否在模型中存在（避免使用默认字段时出错）
            try {
                if (!empty($modelInstance)) {
                    $modelFields = $modelInstance->getModelFields();
                    if (!in_array($fieldName, $modelFields)) {
                        // 字段不存在，跳过
                        continue;
                    }
                }
            } catch (\Exception $e) {
                // 验证失败，跳过该字段
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
        // 使用 columns() 方法获取字段
        try {
            $columns = $modelInstance->columns();
            if (!empty($columns) && is_array($columns)) {
                foreach ($columns as $column) {
                    if (is_array($column)) {
                        $fieldName = $column['Field'] ?? $column['field'] ?? '';
                    } elseif (is_string($column)) {
                        $fieldName = $column;
                    } else {
                        continue;
                    }
                    if (!empty($fieldName) && !in_array(strtolower($fieldName), ['password','token','secret','hash','salt','key','auth'])) {
                        $fields[] = $fieldName;
                    }
                }
                if (!empty($fields)) {
                    return array_slice($fields, 0, 8);
                }
            }
        } catch (\Throwable $e) {
            w_log_error("DataTable: columns()异常: " . $e->getMessage());
        }
        // 方法4: 尝试调用getTableFields方法
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
            w_log_error("DataTable: getTableFields()异常: " . $e->getMessage());
        }
        // 方法5: 尝试调用getSchema方法
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
            w_log_error("DataTable: getSchema()异常: " . $e->getMessage());
        }
        // 方法6: 尝试调用getFieldConfig方法
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
            w_log_error("DataTable: getFieldConfig()异常: " . $e->getMessage());
        }
        // 方法7: 反射获取属性
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
            w_log_error("DataTable: 反射获取属性异常: " . $e->getMessage());
        }
        // 方法8: 表名推断字段
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
            w_log_error("DataTable: getTable()异常: " . $e->getMessage());
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
                w_log_error("DataTable: 无法获取模型 {$modelClass} 的字段: " . $e->getMessage());
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
    private static function generateFormHtml(string $formId, string $model, string $scope, string $mode, string $title, string $apiUrl = 'datatable/rest/v1/data-table', string $fieldApiUrl = 'datatable/rest/v1/form/fields', string $apiProvider = ''): string
    {
        // JavaScript 字符串中需要转义反斜杠，否则 \S, \M 等会被解释为转义字符
        $modelJs = addslashes($model);
        $apiUrlJs = addslashes($apiUrl);
        $fieldApiUrlJs = addslashes($fieldApiUrl);
        $workerApiJs = $apiProvider !== '' ? 'true' : 'false';
        $apiProviderJs = addslashes($apiProvider);
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
        $formHtml .= '<form class="w-form w-form-vertical" id="' . $formId . '" action="' . htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') . '" method="POST" data-model="' . htmlspecialchars($model, ENT_QUOTES, 'UTF-8') . '" data-scope="' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '" data-mode="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '" data-record-id="">';
        $formHtml .= '<div class="w-form-body"><div class="w-form-fields" id="w-form-fields-' . $formId . '"><div class="w-auto-fields" id="w-auto-fields-' . $formId . '"><div class="w-loading-fields"><i class="fas fa-spinner fa-spin"></i>' . __('正在加载字段...') . '</div></div></div></div>';
        $formHtml .= '<div class="w-form-footer"><div class="w-form-actions">';
        $formHtml .= '<button type="button" class="w-btn w-btn-secondary" onclick="DataTableFormManager.closeModal(\'' . $formId . '\')"><i class="fas fa-times"></i>' . $cancelText . '</button>';
        $formHtml .= '<button type="button" class="w-btn w-btn-primary" onclick="DataTableFormManager.submitForm(\'' . $formId . '\')"><i class="fas fa-save"></i>' . $saveText . '</button>';
        $formHtml .= '</div></div></form></div></div></div>';
        if ($mode === 'add') {
            $formHtml .= '<button type="button" class="w-btn w-btn-primary w-form-trigger" onclick="DataTableFormManager.openModal(\'' . $formId . '\', \'add\')"><i class="fas fa-plus"></i>' . $addText . '</button>';
        }
        // 尝试加载 datatable-form-manager.js，浏览器会自动去重
        /**@var Template $tmp */
        $tmp = w_obj(Template::class);
        $formManagerJsUrl = $tmp->fetchTagSource('statics', 'Weline_DataTable::js/datatable-form-manager.js');
        $formHtml .= '<script>
(function() {
    var scriptId = "datatable-form-manager-js";
    if (!document.getElementById(scriptId)) {
        var script = document.createElement("script");
        script.id = scriptId;
        script.src = "' . $formManagerJsUrl . '";
        script.async = true;
        script.onload = function() {
            // JS 加载完成后，等待 DataTableFormManager 可用
            var checkInterval = setInterval(function() {
                if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                    clearInterval(checkInterval);
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", function() {
                            DataTableFormManager.initForm("' . $formId . '", {model: "' . $modelJs . '",scope: "' . $scope . '",mode: "' . $mode . '",recordId: "",autoFields: true,excludeFields: [],includeFields: [],apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $apiUrlJs . '"') . ',fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $fieldApiUrlJs . '"') . ',workerApi: ' . $workerApiJs . ',apiProvider: "' . $apiProviderJs . '",operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"}});
                        });
                    } else {
                        DataTableFormManager.initForm("' . $formId . '", {model: "' . $modelJs . '",scope: "' . $scope . '",mode: "' . $mode . '",recordId: "",autoFields: true,excludeFields: [],includeFields: [],apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $apiUrlJs . '"') . ',fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $fieldApiUrlJs . '"') . ',workerApi: ' . $workerApiJs . ',apiProvider: "' . $apiProviderJs . '",operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"}});
                    }
                }
            }, 50);
            setTimeout(function() { clearInterval(checkInterval); }, 5000);
        };
        document.head.appendChild(script);
    } else {
        // 如果脚本已存在，直接尝试初始化
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", function() {
                if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                    DataTableFormManager.initForm("' . $formId . '", {model: "' . $modelJs . '",scope: "' . $scope . '",mode: "' . $mode . '",recordId: "",autoFields: true,excludeFields: [],includeFields: [],apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $apiUrlJs . '"') . ',fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $fieldApiUrlJs . '"') . ',workerApi: ' . $workerApiJs . ',apiProvider: "' . $apiProviderJs . '",operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"}});
                }
            });
        } else {
            if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                DataTableFormManager.initForm("' . $formId . '", {model: "' . $modelJs . '",scope: "' . $scope . '",mode: "' . $mode . '",recordId: "",autoFields: true,excludeFields: [],includeFields: [],apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $apiUrlJs . '"') . ',fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . $fieldApiUrlJs . '"') . ',workerApi: ' . $workerApiJs . ',apiProvider: "' . $apiProviderJs . '",operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"}});
            }
        }
    }
})();
</script>';
        return $formHtml;
    }
}
