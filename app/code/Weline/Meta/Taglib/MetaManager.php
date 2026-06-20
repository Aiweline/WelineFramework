<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib as TaglibView;
use Weline\Meta\Model\Meta as MetaModel;
use Weline\Taglib\TaglibInterface;

class MetaManager implements TaglibInterface
{
    static public function name(): string
    {
        return 'w:meta-manager';
    }

    static function tag(): bool
    {
        return true; // 支持成对标签，但内容可以为空
    }

    static function attr(): array
    {
        return [
            'namespace' => false,      // 命名空间（可选，支持PHP变量）
            'area' => false,           // 区域（可选，支持PHP变量）
            'scope' => false,          // 作用域（可选，默认default，支持PHP变量）
            'locale' => false,          // 语言（可选，支持PHP变量）
            'identity-id' => false,     // 实体ID（可选，支持PHP变量）
            'type' => false,            // 类型（可选，用于过滤meta_type，支持PHP变量）
            'category' => false,        // 分类（可选，用于过滤category，支持PHP变量）
            'show-filters' => false,    // 是否显示筛选条件（可选，默认true）
            'show-tree' => false,       // 是否显示文件树（可选，默认true）
            'default-namespace' => false, // 默认命名空间（可选，支持PHP变量）
            'on-save' => false,         // 保存成功后触发的自定义事件名称（可选，事件会携带保存的数据）
            'max-depth' => false,       // 最大显示层级（可选，默认不限制，支持PHP变量）
            'min-depth' => false,       // 最小显示层级/跳过层级（可选，默认1，支持PHP变量）
            'dir-config-callback' => false, // 目录配置回调JSON（可选，用于处理目录配置弹窗）
            'layout-active-callback' => false, // 布局激活回调URL（可选，用于获取当前选中的布局配置）
        ];
    }

    static function tag_start(): bool
    {
        return false;
    }

    static function tag_end(): bool
    {
        return false;
    }

    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 处理成对标签和带属性的自闭合标签
            // tag_key 可能的值：'tag'（成对标签）, 'tag-self-close-with-attrs'（带属性自闭合）, 'tag-self-close'（不带属性自闭合）
            if ($tag_key !== 'tag' && $tag_key !== 'tag-self-close-with-attrs' && $tag_key !== 'tag-self-close') {
                return '';
            }

            // 获取Taglib实例用于解析PHP变量
            /** @var TaglibView $Taglib */
            $Taglib = ObjectManager::getInstance(TaglibView::class);
            
            // 解析所有属性值（支持PHP变量）
            $parsedAttrs = [];
            foreach ($attributes as $key => $value) {
                if ($value === null || $value === '') {
                    $parsedAttrs[$key] = '';
                    continue;
                }
                
                $valueStr = (string)$value;
                // 检测是否包含PHP变量
                if (strpos($valueStr, '$') !== false || strpos($valueStr, '{{') !== false) {
                    // 使用varParser解析变量
                    $parsedValue = $Taglib->varParser($valueStr);
                    // 生成PHP代码片段，嵌入到HTML中
                    $parsedAttrs[$key] = '<?=(' . $parsedValue . '??\'\')?>';
                } else {
                    // 纯字符串，直接使用
                    $parsedAttrs[$key] = htmlspecialchars($valueStr, ENT_QUOTES, 'UTF-8');
                }
            }

            $namespace = $parsedAttrs['namespace'] ?? '';
            $area = $parsedAttrs['area'] ?? '';
            $scope = $parsedAttrs['scope'] ?? 'default';
            $locale = $parsedAttrs['locale'] ?? '';
            $identityId = $parsedAttrs['identity-id'] ?? '';
            $type = $parsedAttrs['type'] ?? '';
            $category = $parsedAttrs['category'] ?? '';
            $showFilters = self::parseBoolean($parsedAttrs['show-filters'] ?? 'true');
            $showTree = self::parseBoolean($parsedAttrs['show-tree'] ?? 'true');
            $defaultNamespace = $parsedAttrs['default-namespace'] ?? '';
            $onSaveEvent = $attributes['on-save'] ?? ''; // 保存成功后触发的事件名称（原始值，不解析PHP变量）
            $maxDepth = $parsedAttrs['max-depth'] ?? ''; // 最大显示层级
            $minDepth = $parsedAttrs['min-depth'] ?? ''; // 最小显示层级（跳过前N-1级）
            $dirConfigCallback = $attributes['dir-config-callback'] ?? ''; // 目录配置回调JSON（原始值，不解析PHP变量）
            $layoutActiveCallback = $parsedAttrs['layout-active-callback'] ?? ''; // 布局激活回调URL（支持PHP变量）

            // 生成唯一ID（基于属性值的原始字符串，因为PHP变量在运行时解析）
            // 包含 type 和 category 以确保不同 Tab 有不同的 ID
            // 使用下划线而不是连字符，因为 uniqueId 会用于 JavaScript 函数名
            static $instanceCounter = 0;
            $instanceCounter++;
            $uniqueId = 'mm_' . substr(md5(serialize([
                $attributes['namespace'] ?? '',
                $attributes['area'] ?? '',
                $attributes['scope'] ?? 'default',
                $attributes['locale'] ?? '',
                $attributes['identity-id'] ?? '',
                $attributes['type'] ?? '',
                $attributes['category'] ?? '',
                $instanceCounter, // 确保每个实例都有唯一ID
            ])), 0, 8);

            // 获取命名空间列表（如果show-filters为true）
            $namespaceList = [];
            if ($showFilters) {
                /** @var MetaModel $metaModel */
                $metaModel = ObjectManager::getInstance(MetaModel::class);
                $namespaces = $metaModel->reset()
                    ->fields(MetaModel::schema_fields_NAMESPACE)
                    ->where(MetaModel::schema_fields_NAMESPACE, null, 'IS NOT NULL')
                    ->where(MetaModel::schema_fields_NAMESPACE, '', '!=')
                    ->group(MetaModel::schema_fields_NAMESPACE)
                    ->order(MetaModel::schema_fields_NAMESPACE, 'ASC')
                    ->select()
                    ->fetch();
                
                foreach ($namespaces->getItems() as $item) {
                    $ns = (string)$item->getData(MetaModel::schema_fields_NAMESPACE);
                    if ($ns) {
                        $namespaceList[] = $ns;
                    }
                }
            }

            // 生成8个API端点URL
            /** @var \Weline\Framework\Http\Request $request */
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $treeUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/tree', []);
            $metaUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/fileMeta', []);
            $saveUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/save', []);
            $translationUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/paramTranslation', []);
            $translationSaveUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/paramTranslationSave', []);
            $localesUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/getLocales', []);
            $metaNameDescTranslationUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/metaNameDescriptionTranslation', []);
            $metaNameDescTranslationSaveUrl = $request->getUrlBuilder()->getBackendUrl('meta/backend/config/file/metaNameDescriptionTranslationSave', []);

            // 转义URL用于JavaScript
            $treeUrlJs = htmlspecialchars($treeUrl, ENT_QUOTES, 'UTF-8');
            $metaUrlJs = htmlspecialchars($metaUrl, ENT_QUOTES, 'UTF-8');
            $saveUrlJs = htmlspecialchars($saveUrl, ENT_QUOTES, 'UTF-8');
            $translationUrlJs = htmlspecialchars($translationUrl, ENT_QUOTES, 'UTF-8');
            $translationSaveUrlJs = htmlspecialchars($translationSaveUrl, ENT_QUOTES, 'UTF-8');
            $localesUrlJs = htmlspecialchars($localesUrl, ENT_QUOTES, 'UTF-8');
            $metaNameDescTranslationUrlJs = htmlspecialchars($metaNameDescTranslationUrl, ENT_QUOTES, 'UTF-8');
            $metaNameDescTranslationSaveUrlJs = htmlspecialchars($metaNameDescTranslationSaveUrl, ENT_QUOTES, 'UTF-8');

            // 生成目录配置保存URL（如果提供了回调）
            $dirConfigSaveUrl = '';
            if ($dirConfigCallback) {
                $dirConfigSaveUrl = $request->getUrlBuilder()->getBackendUrl('theme/backend/config/layout/saveDirConfig', []);
            }
            $dirConfigSaveUrlJs = htmlspecialchars($dirConfigSaveUrl, ENT_QUOTES, 'UTF-8');

            // 构建HTML结构
            return self::renderHTML($uniqueId, [
                'namespace' => $namespace,
                'area' => $area,
                'scope' => $scope,
                'locale' => $locale,
                'identity_id' => $identityId,
                'type' => $type,
                'category' => $category,
                'default_namespace' => $defaultNamespace,
                'namespace_list' => $namespaceList,
                'show_filters' => $showFilters,
                'show_tree' => $showTree,
                'max_depth' => $maxDepth, // 最大显示层级
                'min_depth' => $minDepth, // 最小显示层级
                'tree_url' => $treeUrlJs,
                'meta_url' => $metaUrlJs,
                'save_url' => $saveUrlJs,
                'translation_url' => $translationUrlJs,
                'translation_save_url' => $translationSaveUrlJs,
                'locales_url' => $localesUrlJs,
                'meta_name_desc_translation_url' => $metaNameDescTranslationUrlJs,
                'meta_name_desc_translation_save_url' => $metaNameDescTranslationSaveUrlJs,
                'on_save_event' => $onSaveEvent, // 保存成功后触发的事件名称
                'dir_config_callback' => $dirConfigCallback, // 目录配置回调JSON
                'dir_config_save_url' => $dirConfigSaveUrlJs, // 目录配置保存URL
                'layout_active_callback' => $layoutActiveCallback ? htmlspecialchars($layoutActiveCallback, ENT_QUOTES, 'UTF-8') : '', // 布局激活回调URL
            ]);
        };
    }

    /**
     * 解析布尔值
     */
    private static function parseBoolean($value): bool
    {
        if (empty($value)) {
            return true;
        }
        $valueStr = (string)$value;
        // 如果是PHP代码片段，返回true（运行时解析）
        if (strpos($valueStr, '<?=') !== false) {
            return true;
        }
        $valueStr = strtolower(trim($valueStr));
        return in_array($valueStr, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * 渲染HTML结构
     */
    private static function renderHTML(string $uniqueId, array $data): string
    {
        $namespace = $data['namespace'];
        $area = $data['area'];
        $scope = $data['scope'];
        $locale = $data['locale'];
        $identityId = $data['identity_id'];
        $type = $data['type'] ?? '';
        $category = $data['category'] ?? '';
        $defaultNamespace = $data['default_namespace'];
        $namespaceList = $data['namespace_list'];
        $showFilters = $data['show_filters'];
        $showTree = $data['show_tree'];
        
        // 构建命名空间选项HTML
        // 优先使用 default-namespace，如果没有则使用 namespace
        $selectedNamespace = $defaultNamespace ?: $namespace;
        
        // 确保传入的固定 namespace 值在列表中（如果不是PHP变量）
        $fixedNamespace = '';
        if ($selectedNamespace && strpos($selectedNamespace, '<?=') === false) {
            $fixedNamespace = $selectedNamespace;
            if (!in_array($fixedNamespace, $namespaceList)) {
                array_unshift($namespaceList, $fixedNamespace);
            }
        }
        
        $namespaceOptions = '';
        foreach ($namespaceList as $ns) {
            $nsEscaped = htmlspecialchars($ns, ENT_QUOTES, 'UTF-8');
            // 对于PHP变量，需要特殊处理selected属性
            if (strpos($selectedNamespace, '<?=') !== false) {
                $selected = '<?=(' . substr($selectedNamespace, 4, -3) . '===\'' . $nsEscaped . '\'?\'selected\':\'\')?>';
            } else {
                $selected = ($selectedNamespace === $ns) ? 'selected' : '';
            }
            $namespaceOptions .= "<option value=\"{$nsEscaped}\" {$selected}>{$nsEscaped}</option>";
        }

        // 构建区域选项
        $areaFrontendSelected = '';
        $areaBackendSelected = '';
        if (strpos($area, '<?=') !== false) {
            $areaVar = substr($area, 4, -3);
            $areaFrontendSelected = '<?=(' . $areaVar . '===\'frontend\'?\'selected\':\'\')?>';
            $areaBackendSelected = '<?=(' . $areaVar . '===\'backend\'?\'selected\':\'\')?>';
        } else {
            $areaFrontendSelected = ($area === 'frontend') ? 'selected' : '';
            $areaBackendSelected = ($area === 'backend') ? 'selected' : '';
        }

        // 构建类型、分类和命名空间的 readonly/disabled 属性
        $typeReadonly = '';
        $categoryReadonly = '';
        $namespaceDisabled = '';
        if (strpos($type, '<?=') !== false) {
            $typeReadonly = '<?=(' . substr($type, 4, -3) . '?\'readonly\':\'\')?>';
        } else {
            $typeReadonly = $type ? 'readonly' : '';
        }
        if (strpos($category, '<?=') !== false) {
            $categoryReadonly = '<?=(' . substr($category, 4, -3) . '?\'readonly\':\'\')?>';
        } else {
            $categoryReadonly = $category ? 'readonly' : '';
        }
        // 如果 namespace 是固定值，则禁用下拉框并使用隐藏字段传值
        $namespaceHiddenInput = '';
        $namespaceSelectName = 'namespace';
        if (strpos($selectedNamespace, '<?=') !== false) {
            $namespaceDisabled = '';
        } else {
            if ($selectedNamespace) {
                $namespaceDisabled = 'disabled';
                $namespaceSelectName = '_namespace_display'; // 禁用的 select 不会提交，改名避免冲突
                $namespaceHiddenInput = '<input type="hidden" name="namespace" value="' . htmlspecialchars($selectedNamespace, ENT_QUOTES, 'UTF-8') . '">';
            } else {
                $namespaceDisabled = '';
            }
        }

        // 筛选条件HTML（流式布局，实时过滤，默认收起）
        $filtersHtml = '';
        if ($showFilters) {
            $filtersHtml = <<<HTML
<div class="mb-3">
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center" style="cursor: pointer;" data-meta-filter-toggle="{$uniqueId}-filter-body">
            <h6 class="mb-0"><i class="mdi mdi-filter-outline me-1"></i>筛选条件</h6>
            <div class="d-flex align-items-center gap-2">
                <small class="text-muted" id="{$uniqueId}-loading-status"></small>
                <i class="mdi mdi-chevron-down {$uniqueId}_filter_toggle_icon" style="transition: transform 0.3s;"></i>
            </div>
        </div>
        <div class="card-body py-2 collapse" id="{$uniqueId}-filter-body">
            <div id="{$uniqueId}-filter-form" class="{$uniqueId}-meta-filter-form">
                <div class="{$uniqueId}_filter_flow">
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">命名空间</label>
                        <select class="form-select form-select-sm {$uniqueId}_auto_filter" name="{$namespaceSelectName}" id="{$uniqueId}-namespace-select" {$namespaceDisabled}>
                            <option value="">请选择</option>
                            {$namespaceOptions}
                        </select>
                        {$namespaceHiddenInput}
                    </div>
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">区域</label>
                        <select class="form-select form-select-sm {$uniqueId}_auto_filter" name="area" id="{$uniqueId}-area-select">
                            <option value="">全部</option>
                            <option value="frontend" {$areaFrontendSelected}>frontend</option>
                            <option value="backend" {$areaBackendSelected}>backend</option>
                        </select>
                    </div>
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">Scope</label>
                        <input type="text" class="form-control form-control-sm {$uniqueId}_auto_filter" name="scope" id="{$uniqueId}-scope-input" value="{$scope}" placeholder="default">
                    </div>
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">语言</label>
                        <input type="text" class="form-control form-control-sm {$uniqueId}_auto_filter" name="locale" id="{$uniqueId}-locale-input" value="{$locale}" placeholder="zh_Hans_CN">
                    </div>
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">类型</label>
                        <input type="text" class="form-control form-control-sm {$uniqueId}_auto_filter" name="type" id="{$uniqueId}-type-input" value="{$type}" placeholder="layout..." {$typeReadonly}>
                    </div>
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">分类</label>
                        <input type="text" class="form-control form-control-sm {$uniqueId}_auto_filter" name="category" id="{$uniqueId}-category-input" value="{$category}" placeholder="account..." {$categoryReadonly}>
                    </div>
                    <div class="{$uniqueId}_filter_item">
                        <label class="form-label small mb-1">实体ID</label>
                        <input type="text" class="form-control form-control-sm {$uniqueId}_auto_filter" name="identity_id" id="{$uniqueId}-identity-input" value="{$identityId}" placeholder="主题ID...">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
        }

        // 文件树HTML（自适应宽度）
        $treeHtml = '';
        if ($showTree) {
            $treeHtml = <<<HTML
<div class="{$uniqueId}_tree_panel" id="{$uniqueId}_tree_panel">
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <h6 class="mb-0"><i class="mdi mdi-file-tree me-1"></i>元数据文件树</h6>
            <small class="text-muted" id="{$uniqueId}-tree-count"></small>
        </div>
        <div class="card-body p-2" style="max-height: calc(100vh - 320px); overflow-y: auto;">
            <div id="{$uniqueId}-file-tree" class="{$uniqueId}-meta-tree">
                <div class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                    <p class="mt-2 mb-0 small">正在加载...</p>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
        }

        // 编辑器HTML（滑动面板）
        $editorHtml = <<<HTML
<div class="{$uniqueId}_editor_panel {$uniqueId}_editor_collapsed" id="{$uniqueId}_editor_panel">
    <div class="{$uniqueId}_editor_toggle" id="{$uniqueId}_editor_toggle" title="展开配置详情">
        <i class="mdi mdi-chevron-left"></i>
        <span class="{$uniqueId}_toggle_text">详情</span>
    </div>
    <div class="{$uniqueId}_editor_content" id="{$uniqueId}_editor_content">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <h6 class="mb-0"><i class="mdi mdi-cog me-1"></i>元数据配置</h6>
                <button type="button" class="btn btn-sm btn-outline-secondary {$uniqueId}_collapse_btn" id="{$uniqueId}_collapse_btn" title="收起">
                    <i class="mdi mdi-chevron-right"></i>
                </button>
            </div>
            <div class="card-body p-3" id="{$uniqueId}-file-editor" style="max-height: calc(100vh - 320px); overflow-y: auto;">
                <div class="text-center text-muted py-4">
                    <i class="mdi mdi-gesture-tap" style="font-size: 2.5rem; opacity: 0.5;"></i>
                    <p class="mt-3 mb-0">点击左侧文件节点查看配置</p>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;

        // Modal HTML
        $modalHtml = self::renderModals($uniqueId);

        // CSS样式
        $cssHtml = self::renderCSS($uniqueId);

        // JavaScript代码
        $jsHtml = self::renderJavaScript($uniqueId, $data);

        return <<<HTML
<div class="{$uniqueId}_meta_manager_container" id="{$uniqueId}_container">
    {$cssHtml}
    {$filtersHtml}
    <div class="{$uniqueId}_main_layout" id="{$uniqueId}_main_layout">
        {$treeHtml}
        {$editorHtml}
    </div>
    {$modalHtml}
</div>
{$jsHtml}
HTML;
    }

    /**
     * 渲染右侧翻译抽屉
     */
    private static function renderModals(string $uniqueId): string
    {
        return <<<HTML
<!-- 翻译抽屉（从右向左推出） -->
<div class="{$uniqueId}_translation_drawer" id="{$uniqueId}-translation-drawer">
    <div class="{$uniqueId}_translation_header">
        <div class="d-flex align-items-center">
            <h6 class="mb-0 flex-grow-1" id="{$uniqueId}-translation-title">翻译</h6>
            <button type="button" class="btn btn-sm btn-outline-light {$uniqueId}_translation_close" id="{$uniqueId}-translation-close" title="收起">
                <i class="mdi mdi-chevron-right"></i>
            </button>
        </div>
    </div>
    <div class="{$uniqueId}_translation_body" id="{$uniqueId}-translation-body">
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-3 text-muted mb-0">正在加载翻译...</p>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * 渲染CSS样式
     */
    private static function renderCSS(string $uniqueId): string
    {
        return <<<CSS
<style>
/* 筛选条件流式布局 */
.{$uniqueId}_filter_flow {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.{$uniqueId}_filter_item {
    flex: 0 0 auto;
    min-width: 120px;
    max-width: 160px;
}
.{$uniqueId}_filter_btn {
    min-width: 80px;
    max-width: 100px;
}

/* 主布局 - Flex容器 */
.{$uniqueId}_main_layout {
    display: flex;
    gap: 0;
    position: relative;
    min-height: 400px;
}

/* 树面板 - 自适应宽度 */
.{$uniqueId}_tree_panel {
    flex: 1;
    min-width: 0;
    transition: flex 0.3s ease;
}

/* 编辑器面板 - 滑动效果 */
.{$uniqueId}_editor_panel {
    position: relative;
    display: flex;
    transition: width 0.3s ease, flex 0.3s ease;
}

/* 编辑器收起状态 */
.{$uniqueId}_editor_collapsed {
    width: 40px;
    flex: 0 0 40px;
}
.{$uniqueId}_editor_collapsed .{$uniqueId}_editor_content {
    opacity: 0;
    pointer-events: none;
    width: 0;
    overflow: hidden;
}
.{$uniqueId}_editor_collapsed .{$uniqueId}_editor_toggle {
    opacity: 1;
    pointer-events: auto;
}

/* 编辑器展开状态 */
.{$uniqueId}_editor_expanded {
    width: 55%;
    flex: 0 0 55%;
}
.{$uniqueId}_editor_expanded .{$uniqueId}_editor_content {
    opacity: 1;
    pointer-events: auto;
    width: 100%;
}
.{$uniqueId}_editor_expanded .{$uniqueId}_editor_toggle {
    opacity: 0;
    pointer-events: none;
    width: 0;
}

/* 展开按钮（竖条） - 跟随主题主色 */
.{$uniqueId}_editor_toggle {
    width: 40px;
    background: var(--bs-primary, #0d6efd);
    border-radius: 8px 0 0 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #fff;
    transition: all 0.3s ease;
    box-shadow: -2px 0 8px rgba(0,0,0,0.1);
}
.{$uniqueId}_editor_toggle:hover {
    background: color-mix(in srgb, var(--bs-primary, #0d6efd) 85%, #000 15%);
    width: 45px;
}
.{$uniqueId}_editor_toggle i {
    font-size: 20px;
    margin-bottom: 5px;
}
.{$uniqueId}_toggle_text {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    font-size: 12px;
    letter-spacing: 2px;
}

/* 编辑器内容区 */
.{$uniqueId}_editor_content {
    flex: 1;
    transition: opacity 0.3s ease, width 0.3s ease;
    overflow: hidden;
}

/* 翻译抽屉 */
.{$uniqueId}_translation_drawer {
    position: fixed;
    top: 0;
    right: 0;
    height: 100vh;
    width: 0;
    max-width: 520px;
    background: var(--mm-bg, var(--bs-body-bg, #fff));
    color: var(--mm-text, var(--bs-body-color, #212529));
    box-shadow: -6px 0 16px rgba(0,0,0,0.15);
    transition: width 0.3s ease, transform 0.3s ease;
    transform: translateX(100%);
    z-index: 1055;
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--mm-border, var(--bs-border-color, rgba(0,0,0,0.08)));
}
.{$uniqueId}_translation_drawer.open {
    width: min(48vw, 520px);
    transform: translateX(0);
}
.{$uniqueId}_translation_header {
    padding: 12px 16px;
    background: var(--mm-primary, var(--bs-primary, #0d6efd));
    color: #fff;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.{$uniqueId}_translation_body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
    background: var(--mm-bg, var(--bs-body-bg, #fff));
}
/* 抽屉内表单元素适配主题 */
.{$uniqueId}_translation_drawer .form-control,
.{$uniqueId}_translation_drawer .list-group-item {
    background: var(--mm-bg, var(--bs-body-bg, #fff));
    color: var(--mm-text, var(--bs-body-color, #212529));
    border-color: var(--mm-border, var(--bs-border-color, #dee2e6));
}
.{$uniqueId}_translation_drawer .alert-info {
    background: var(--mm-info-bg, var(--bs-info-bg-subtle, #cfe2ff));
    color: var(--mm-info-text, var(--bs-info-text-emphasis, #084298));
    border-color: var(--mm-info-border, var(--bs-info-border-subtle, #9ec5fe));
}
.{$uniqueId}_translation_body::-webkit-scrollbar {
    width: 6px;
}
.{$uniqueId}_translation_body::-webkit-scrollbar-thumb {
    background: var(--mm-scroll-thumb, var(--bs-secondary-bg, rgba(0,0,0,0.2)));
    border-radius: 3px;
}
.{$uniqueId}_translation_body {
    scrollbar-width: thin;
    scrollbar-color: var(--mm-scroll-thumb, var(--bs-secondary-bg, rgba(0,0,0,0.2))) var(--mm-scroll-track, transparent);
}

/* 树样式 */
.{$uniqueId}-meta-tree ul {
    list-style: none;
    padding-left: 1rem;
    margin-bottom: 0;
}
.{$uniqueId}-meta-tree li {
    margin: 0.2rem 0;
}
.{$uniqueId}-meta-tree .{$uniqueId}-tree-label {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.4rem;
    border-radius: 4px;
    font-size: 13px;
}
.{$uniqueId}-meta-tree .{$uniqueId}-tree-label:hover {
    background: rgba(13,110,253,0.08);
}
.{$uniqueId}-meta-tree .{$uniqueId}-tree-label.active {
    background: linear-gradient(135deg, rgba(102,126,234,0.15) 0%, rgba(118,75,162,0.15) 100%);
    color: #667eea;
    font-weight: 600;
}
.{$uniqueId}-meta-tree .{$uniqueId}-tree-node-children {
    margin-left: 1rem;
}
.{$uniqueId}-meta-tree .{$uniqueId}-collapsed {
    display: none;
}

/* 参数列表样式 */
.{$uniqueId}-meta-param-list .list-group-item {
    border-left: none;
    border-right: none;
}
.{$uniqueId}-meta-param-list .list-group-item:first-child {
    border-top: none;
}
.{$uniqueId}-meta-param-list .list-group-item:last-child {
    border-bottom: none;
}

/* 滚动条样式 */
#{$uniqueId}_container .card-body::-webkit-scrollbar,
#{$uniqueId}-file-tree::-webkit-scrollbar,
#{$uniqueId}-file-editor::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
#{$uniqueId}_container .card-body::-webkit-scrollbar-thumb,
#{$uniqueId}-file-tree::-webkit-scrollbar-thumb,
#{$uniqueId}-file-editor::-webkit-scrollbar-thumb {
    background-color: rgba(0, 0, 0, 0.2);
    border-radius: 3px;
}
#{$uniqueId}_container .card-body::-webkit-scrollbar-thumb:hover,
#{$uniqueId}-file-tree::-webkit-scrollbar-thumb:hover,
#{$uniqueId}-file-editor::-webkit-scrollbar-thumb:hover {
    background-color: rgba(0, 0, 0, 0.3);
}
#{$uniqueId}_container .card-body::-webkit-scrollbar-track,
#{$uniqueId}-file-tree::-webkit-scrollbar-track,
#{$uniqueId}-file-editor::-webkit-scrollbar-track {
    background-color: rgba(0, 0, 0, 0.05);
}
#{$uniqueId}_container .card-body,
#{$uniqueId}-file-tree,
#{$uniqueId}-file-editor {
    scrollbar-width: thin;
    scrollbar-color: rgba(0, 0, 0, 0.2) rgba(0, 0, 0, 0.05);
}

/* 目录配置按钮样式 */
.{$uniqueId}-dir-config-btn {
    opacity: 0.5;
    transition: opacity 0.2s ease;
}
.{$uniqueId}-tree-label:hover .{$uniqueId}-dir-config-btn {
    opacity: 1;
}
.{$uniqueId}-dir-config-btn:hover {
    opacity: 1;
    transform: scale(1.1);
}
</style>
CSS;
    }

    /**
     * 渲染JavaScript代码
     */
    private static function renderJavaScript(string $uniqueId, array $data): string
    {
        $treeUrl = $data['tree_url'];
        $metaUrl = $data['meta_url'];
        $saveUrl = $data['save_url'];
        $translationUrl = $data['translation_url'];
        $translationSaveUrl = $data['translation_save_url'];
        $localesUrl = $data['locales_url'];
        $metaNameDescTranslationUrl = $data['meta_name_desc_translation_url'];
        $metaNameDescTranslationSaveUrl = $data['meta_name_desc_translation_save_url'];
        $onSaveEvent = $data['on_save_event'] ?? '';
        $onSaveEventJs = $onSaveEvent ? "'{$onSaveEvent}'" : 'null';
        $maxDepth = $data['max_depth'] ?? '';
        $maxDepthJs = $maxDepth !== '' ? (int)$maxDepth : 'null';
        $minDepth = $data['min_depth'] ?? '';
        $minDepthJs = $minDepth !== '' ? (int)$minDepth : '1';
        $dirConfigCallback = $data['dir_config_callback'] ?? '';
        // 如果已经是JSON字符串，直接使用；否则编码为JSON
        if ($dirConfigCallback) {
            // 尝试解析，如果已经是JSON字符串则直接使用
            $decoded = json_decode($dirConfigCallback, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // 是有效的JSON，重新编码确保格式正确
                $dirConfigCallbackJs = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                // 不是JSON，可能是普通字符串，需要编码
                $dirConfigCallbackJs = json_encode($dirConfigCallback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } else {
            $dirConfigCallbackJs = 'null';
        }
        $dirConfigSaveUrl = $data['dir_config_save_url'] ?? '';
        $layoutActiveCallback = $data['layout_active_callback'] ?? '';
        $layoutActiveCallbackJs = $layoutActiveCallback ? "'" . addslashes($layoutActiveCallback) . "'" : 'null';

        return <<<JS
<script>
(function() {
    const uniqueId = '{$uniqueId}';
    const app = document.getElementById(uniqueId + '_container');
    if (!app) {
        console.error('[MetaManager] 容器未找到! ID:', uniqueId + '_container');
        return;
    }

    const endpoints = {
        tree: '{$treeUrl}',
        file: '{$metaUrl}',
        save: '{$saveUrl}',
        translation: '{$translationUrl}',
        translationSave: '{$translationSaveUrl}',
        locales: '{$localesUrl}',
        metaNameDescTranslation: '{$metaNameDescTranslationUrl}',
        metaNameDescTranslationSave: '{$metaNameDescTranslationSaveUrl}',
    };

    // 保存成功后触发的自定义事件名称
    const onSaveEvent = {$onSaveEventJs};
    
    // 最大显示层级（null 表示不限制）
    const maxDepth = {$maxDepthJs};
    
    // 最小显示层级（默认1，从第1级开始显示；设置为3则从第3级开始显示，跳过前2级）
    const minDepth = {$minDepthJs};
    
    // 目录配置回调JSON
    const dirConfigCallback = {$dirConfigCallbackJs};

    // 布局激活回调URL（用于获取当前选中的布局配置）
    const layoutActiveCallback = {$layoutActiveCallbackJs};
    
    // 当前激活的布局配置（路径 => 布局文件名）
    let activeLayouts = {};

    // 目录配置保存URL
    const dirConfigSaveUrl = '{$dirConfigSaveUrl}';
    
    // Toast 提示函数（替代 alert）
    function {$uniqueId}_showToast(message, type = 'success') {
        // 检查是否存在全局 toast 函数
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
            return;
        }
        
        // 创建 toast 容器（如果不存在）
        let toastContainer = document.getElementById('meta-toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'meta-toast-container';
            toastContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
            document.body.appendChild(toastContainer);
        }
        
        // 创建 toast 元素
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#17a2b8';
        const textColor = type === 'warning' ? '#212529' : '#fff';
        const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : type === 'warning' ? '⚠' : 'ℹ';
        
        toast.style.cssText = 'background: ' + bgColor + '; color: ' + textColor + '; padding: 12px 20px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; font-size: 14px; opacity: 0; transform: translateX(100%); transition: all 0.3s ease; pointer-events: auto;';
        toast.innerHTML = '<span style="font-size: 16px;">' + icon + '</span><span>' + message + '</span>';
        
        toastContainer.appendChild(toast);
        
        // 动画显示
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });
        
        // 3秒后自动消失
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    const filtersForm = document.getElementById(uniqueId + '-filter-form');
    const treeContainer = document.getElementById(uniqueId + '-file-tree');
    const treeCount = document.getElementById(uniqueId + '-tree-count');
    const loadingStatus = document.getElementById(uniqueId + '-loading-status');
    const editorContainer = document.getElementById(uniqueId + '-file-editor');
    
    // 防抖定时器
    let filterDebounceTimer = null;
    const translationDrawer = document.getElementById(uniqueId + '-translation-drawer');
    const translationDrawerBody = document.getElementById(uniqueId + '-translation-body');
    const translationDrawerTitle = document.getElementById(uniqueId + '-translation-title');
    const translationDrawerClose = document.getElementById(uniqueId + '-translation-close');

    // 将父页面/当前页面的主题色写入局部 CSS 变量，兼容无 Bootstrap CSS 变量的场景
    (function initLocalThemeVars() {
        const style = getComputedStyle(document.body);
        const getVar = (name, fallback) => style.getPropertyValue(name)?.trim() || fallback;
        const isDark = (() => {
            const body = document.body;
            const html = document.documentElement;
            const hasDarkAttr = (el, attr) => (el && (el.getAttribute(attr) === 'dark'));
            const hasDarkClass = (el) => el && (el.classList.contains('dark') || el.classList.contains('dark-mode') || el.classList.contains('theme-dark'));
            return (
                hasDarkAttr(body, 'data-theme-mode') ||
                hasDarkAttr(body, 'data-layout-mode') ||
                hasDarkAttr(body, 'data-sidebar') ||
                hasDarkAttr(body, 'data-topbar') ||
                hasDarkAttr(html, 'data-theme') ||
                hasDarkAttr(html, 'data-bs-theme') ||
                hasDarkClass(body) || hasDarkClass(html)
            );
        })();

        // 明暗双套预设，避免外层缺省变量时变白
        const palette = isDark ? {
            bg: '#0f1115',
            text: '#e9ecef',
            border: 'rgba(255,255,255,0.08)',
            primary: '#4dabf7',
            infoBg: '#13304b',
            infoText: '#9bd0ff',
            infoBorder: '#265c8c',
            scrollThumb: 'rgba(255,255,255,0.3)',
            scrollTrack: 'rgba(255,255,255,0.08)'
        } : {
            bg: '#fff',
            text: '#212529',
            border: 'rgba(0,0,0,0.08)',
            primary: '#0d6efd',
            infoBg: '#cfe2ff',
            infoText: '#084298',
            infoBorder: '#9ec5fe',
            scrollThumb: 'rgba(0,0,0,0.2)',
            scrollTrack: 'rgba(0,0,0,0.05)'
        };

        // 优先外部变量，其次预设
        const bg = getVar('--bs-body-bg', palette.bg);
        const text = getVar('--bs-body-color', palette.text);
        const border = getVar('--bs-border-color', palette.border);
        const primary = getVar('--bs-primary', palette.primary);
        const infoBg = getVar('--bs-info-bg-subtle', palette.infoBg);
        const infoText = getVar('--bs-info-text-emphasis', palette.infoText);
        const infoBorder = getVar('--bs-info-border-subtle', palette.infoBorder);
        const scrollThumb = getVar('--bs-secondary-bg', palette.scrollThumb);
        const scrollTrack = palette.scrollTrack;
        // 设置变量到容器和翻译抽屉上（fixed定位元素可能不继承父元素CSS变量）
        const setVarsOnElement = (el) => {
            if (el && el.style) {
                el.style.setProperty('--mm-bg', bg);
                el.style.setProperty('--mm-text', text);
                el.style.setProperty('--mm-border', border);
                el.style.setProperty('--mm-primary', primary);
                el.style.setProperty('--mm-info-bg', infoBg);
                el.style.setProperty('--mm-info-text', infoText);
                el.style.setProperty('--mm-info-border', infoBorder);
                el.style.setProperty('--mm-scroll-thumb', scrollThumb);
                el.style.setProperty('--mm-scroll-track', scrollTrack);
            }
        };
        setVarsOnElement(app);
        setVarsOnElement(translationDrawer);
    })();

    // 原生弹窗类 - 兼容亮色/暗色主题
    class NativeModal {
        constructor(element) {
            this.element = element;
            this.isVisible = false;
            this.backdrop = null;
            this.init();
        }

        // 检测当前主题
        getCurrentTheme() {
            // 方法1: 检测 data-theme 属性
            const dataTheme = document.documentElement.getAttribute('data-theme');
            if (dataTheme) {
                return dataTheme === 'dark' ? 'dark' : 'light';
            }
            
            // 方法2: 检测 body 或 html 的 class
            if (document.documentElement.classList.contains('dark') || 
                document.body.classList.contains('dark')) {
                return 'dark';
            }
            
            // 方法3: 检测 prefers-color-scheme
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                return 'dark';
            }
            
            return 'light';
        }

        // 更新主题样式
        updateThemeStyles() {
            const theme = this.getCurrentTheme();
            const isDark = theme === 'dark';
            
            // 更新背景遮罩颜色
            if (this.backdrop) {
                if (isDark) {
                    this.backdrop.style.backgroundColor = 'rgba(0, 0, 0, 0.7)';
                } else {
                    this.backdrop.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                }
            }
        }

        init() {
            // 创建背景遮罩
            this.backdrop = document.createElement('div');
            this.backdrop.className = 'modal-backdrop fade';
            this.updateThemeStyles();
            this.backdrop.style.cssText += 'position: fixed; top: 0; left: 0; z-index: 1040; width: 100vw; height: 100vh;';
            document.body.appendChild(this.backdrop);

            // 绑定关闭按钮事件
            const closeButtons = this.element.querySelectorAll('[data-bs-dismiss="modal"]');
            closeButtons.forEach(btn => {
                btn.addEventListener('click', () => this.hide());
            });

            // 点击背景关闭
            this.backdrop.addEventListener('click', () => this.hide());

            // ESC键关闭
            this.escapeHandler = (e) => {
                if (e.key === 'Escape' && this.isVisible) {
                    this.hide();
                }
            };

            // 监听主题变化
            if (window.matchMedia) {
                this.themeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
                this.themeChangeHandler = () => {
                    this.updateThemeStyles();
                };
                this.themeMediaQuery.addEventListener('change', this.themeChangeHandler);
            }

            // 监听 data-theme 属性变化
            this.themeObserver = new MutationObserver(() => {
                this.updateThemeStyles();
            });
            this.themeObserver.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-theme', 'class']
            });
        }

        show() {
            if (this.isVisible) return;
            
            this.isVisible = true;
            this.element.style.display = 'block';
            this.element.classList.remove('fade');
            this.element.classList.add('show');
            this.element.setAttribute('aria-hidden', 'false');
            this.element.setAttribute('aria-modal', 'true');
            
            // 设置弹窗样式
            const currentStyle = this.element.getAttribute('style') || '';
            if (!currentStyle.includes('position')) {
                this.element.style.position = 'fixed';
            }
            if (!currentStyle.includes('top')) {
                this.element.style.top = '0';
            }
            if (!currentStyle.includes('left')) {
                this.element.style.left = '0';
            }
            if (!currentStyle.includes('z-index')) {
                this.element.style.zIndex = '1050';
            }
            if (!currentStyle.includes('width')) {
                this.element.style.width = '100%';
            }
            if (!currentStyle.includes('height')) {
                this.element.style.height = '100%';
            }
            this.element.style.overflowX = 'hidden';
            this.element.style.overflowY = 'auto';
            this.element.style.outline = '0';
            
            // 显示背景遮罩
            this.backdrop.classList.add('show');
            this.backdrop.style.display = 'block';
            
            // 防止body滚动
            document.body.style.overflow = 'hidden';
            document.body.style.paddingRight = '0px';
            
            // 绑定ESC键
            document.addEventListener('keydown', this.escapeHandler);
            
            // 触发显示事件
            this.element.dispatchEvent(new Event('shown', { bubbles: true }));
        }

        hide() {
            if (!this.isVisible) return;
            
            this.isVisible = false;
            this.element.classList.remove('show');
            this.element.classList.add('fade');
            this.element.setAttribute('aria-hidden', 'true');
            this.element.setAttribute('aria-modal', 'false');
            
            // 隐藏背景遮罩
            this.backdrop.classList.remove('show');
            
            // 延迟隐藏，等待动画完成
            setTimeout(() => {
                this.element.style.display = 'none';
                this.backdrop.style.display = 'none';
                
                // 恢复body滚动
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }, 150);
            
            // 移除ESC键绑定
            document.removeEventListener('keydown', this.escapeHandler);
            
            // 触发隐藏事件
            this.element.dispatchEvent(new Event('hidden', { bubbles: true }));
        }

        dispose() {
            if (this.backdrop && this.backdrop.parentNode) {
                this.backdrop.parentNode.removeChild(this.backdrop);
            }
            document.removeEventListener('keydown', this.escapeHandler);
            
            // 清理主题监听
            if (this.themeMediaQuery && this.themeChangeHandler) {
                this.themeMediaQuery.removeEventListener('change', this.themeChangeHandler);
            }
            if (this.themeObserver) {
                this.themeObserver.disconnect();
            }
        }
    }
    const translationState = {
        metaIdentify: null,
        param: null,
        scope: 'default',
        locale: null,
        area: null,
    };

    // 抽屉控制
    function {$uniqueId}_setTranslationTitle(text) {
        if (translationDrawerTitle) translationDrawerTitle.textContent = text || '翻译';
    }
    function {$uniqueId}_setTranslationLoading() {
        if (translationDrawerBody) {
            translationDrawerBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted mb-0">正在加载翻译...</p></div>';
        }
        if (translationDrawer) translationDrawer.classList.add('open');
    }
    function {$uniqueId}_openTranslationDrawer() {
        if (translationDrawer) translationDrawer.classList.add('open');
    }
    function {$uniqueId}_closeTranslationDrawer() {
        if (translationDrawer) translationDrawer.classList.remove('open');
        if (translationDrawerBody) translationDrawerBody.innerHTML = '';
    }
    let lastFilters = {};

    function {$uniqueId}_getFilters() {
        // 获取输入元素的值
        const getVal = (id) => {
            const el = document.getElementById(uniqueId + '-' + id);
            return el ? el.value.trim() : '';
        };
        
        // 获取命名空间：多种方式尝试
        let namespace = '';
        
        // 方式1：查找隐藏字段
        const hiddenNs = document.querySelector('#' + uniqueId + '-filter-form input[name="namespace"]');
        if (hiddenNs && hiddenNs.value) {
            namespace = hiddenNs.value.trim();
        }
        
        // 方式2：下拉框
        if (!namespace) {
            const nsSelect = document.getElementById(uniqueId + '-namespace-select');
            if (nsSelect && nsSelect.value) {
                namespace = nsSelect.value.trim();
            }
        }
        
        // 方式3：直接从容器中查找所有隐藏字段
        if (!namespace) {
            const container = document.getElementById(uniqueId + '_container');
            if (container) {
                const allHidden = container.querySelectorAll('input[name="namespace"]');
                allHidden.forEach(inp => {
                    if (inp.value) namespace = inp.value.trim();
                });
            }
        }
        
        const filters = {
            namespace: namespace,
            area: getVal('area-select'),
            scope: getVal('scope-input') || 'default',
            locale: getVal('locale-input'),
            type: getVal('type-input'),
            category: getVal('category-input'),
            identity_id: getVal('identity-input'),
        };
        
        return filters;
    }

    // 加载当前激活的布局配置
    function {$uniqueId}_loadActiveLayouts() {
        if (!layoutActiveCallback || layoutActiveCallback === 'null' || layoutActiveCallback === null) {
            return Promise.resolve({});
        }
        
        const filters = {$uniqueId}_getFilters();
        const params = new URLSearchParams({
            identity_id: filters.identity_id || '',
            area: filters.area || '',
            scope: filters.scope || 'default'
        });
        
        return fetch(layoutActiveCallback + '?' + params.toString())
            .then(response => {
                // 检查响应状态
                if (!response.ok) {
                    console.error('[MetaManager] HTTP错误:', response.status, response.statusText);
                    return response.text().then(text => {
                        console.error('[MetaManager] 响应内容:', text);
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log('[MetaManager] 收到激活布局配置响应:', data);
                // 支持两种返回格式：{code: 200, data: {...}} 或 {status: 'success', data: {...}}
                if (data && ((data.code === 200 || data.status === 'success') && data.data && data.data.active_layouts)) {
                    activeLayouts = data.data.active_layouts || {};
                    console.log('[MetaManager] 加载激活布局配置:', activeLayouts);
                    console.log('[MetaManager] 激活布局配置详情:', JSON.stringify(activeLayouts, null, 2));
                    return activeLayouts;
                } else {
                    console.warn('[MetaManager] 激活布局配置数据格式不正确:', data);
                }
                return {};
            })
            .catch(error => {
                console.error('[MetaManager] 加载激活布局配置失败:', error);
                console.error('[MetaManager] 错误详情:', error.message, error.stack);
                return {};
            });
    }

    function {$uniqueId}_loadTree() {
        const filters = {$uniqueId}_getFilters();

        if (!filters.namespace || filters.namespace.trim() === '') {
            console.warn('[MetaManager] 命名空间为空，无法加载');
            if (treeContainer) {
                treeContainer.innerHTML = '<div class="text-center text-muted py-4"><i class="mdi mdi-alert-circle-outline" style="font-size: 2rem;"></i><p class="mt-2 mb-0 small">请先选择命名空间</p></div>';
            }
            if (loadingStatus) loadingStatus.textContent = '';
            return;
        }
        
        lastFilters = filters;
        if (loadingStatus) loadingStatus.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>加载中...';
        if (treeContainer) {
            treeContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><p class="text-muted mt-2 mb-0 small">正在加载...</p></div>';
        }
        if (treeCount) treeCount.textContent = '';

        // 先加载激活的布局配置，然后加载树
        const loadTreeData = () => {
            const params = new URLSearchParams(filters);
            const fetchUrl = endpoints.tree + '?' + params.toString();
            fetch(fetchUrl, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(json => {
                    if (json.code !== 200) {
                        throw new Error(json.msg || json.message || '加载失败');
                    }
                    const tree = json.data.tree || [];
                    
                    // 计算文件总数（叶子节点数）
                    function countFiles(nodes) {
                        let count = 0;
                        nodes.forEach(node => {
                            if (node.type === 'file') {
                                count++;
                            }
                            if (node.children && node.children.length) {
                                count += countFiles(node.children);
                            }
                        });
                        return count;
                    }
                    const fileCount = countFiles(tree);
                    
                    if (treeCount) {
                        treeCount.textContent = fileCount ? '共 ' + fileCount + ' 个文件' : '';
                    }
                    if (loadingStatus) loadingStatus.innerHTML = '<span class="text-success"><i class="mdi mdi-check"></i> 已加载</span>';
                    {$uniqueId}_renderTree(tree);
                    if (editorContainer) {
                        editorContainer.innerHTML = '<div class="text-center text-muted py-4"><i class="mdi mdi-gesture-tap" style="font-size: 2.5rem; opacity: 0.5;"></i><p class="mt-3 mb-0">点击左侧文件节点查看配置</p></div>';
                    }
                    // 2秒后清除状态
                    setTimeout(() => { if (loadingStatus) loadingStatus.textContent = ''; }, 2000);
                })
                .catch(error => {
                    console.error('[MetaManager] 加载文件树失败:', error);
                    console.error('[MetaManager] 错误详情:', error.message, error.stack);
                    if (loadingStatus) loadingStatus.innerHTML = '<span class="text-danger"><i class="mdi mdi-alert"></i> 失败</span>';
                    if (treeContainer) {
                        treeContainer.innerHTML = '<div class="alert alert-danger mb-0 small">加载失败: ' + error.message + '</div>';
                    }
                });
        };
        
        // 如果有布局激活回调，先加载激活布局配置
        if (layoutActiveCallback && layoutActiveCallback !== 'null') {
            console.log('[MetaManager] 开始加载激活布局配置...');
            {$uniqueId}_loadActiveLayouts().then(() => {
                console.log('[MetaManager] 激活布局配置加载完成，开始加载树:', activeLayouts);
                loadTreeData();
            }).catch(error => {
                console.error('[MetaManager] 加载激活布局配置失败，继续加载树:', error);
                loadTreeData();
            });
        } else {
            // 没有布局激活回调，直接加载树
            loadTreeData();
        }
    }

    function {$uniqueId}_renderTree(nodes) {
        if (!treeContainer) return;
        if (!nodes.length) {
            treeContainer.innerHTML = '<div class="text-muted text-center py-4">未找到符合条件的元数据文件</div>';
            return;
        }
        treeContainer.innerHTML = '';
        const ul = document.createElement('ul');
        
        // 如果 minDepth > 1，需要展开树以找到第 minDepth 层的节点
        if (minDepth > 1) {
            const flattenedNodes = {$uniqueId}_flattenToDepth(nodes, 1, minDepth);
            flattenedNodes.forEach(node => {
                ul.appendChild({$uniqueId}_createTreeNode(node, minDepth, true));
            });
        } else {
            nodes.forEach(node => {
                ul.appendChild({$uniqueId}_createTreeNode(node, 1, false));
            });
        }
        
        treeContainer.appendChild(ul);
    }

    // 展开树到指定层级，返回该层级的所有节点
    function {$uniqueId}_flattenToDepth(nodes, currentDepth, targetDepth) {
        if (currentDepth >= targetDepth) {
            return nodes;
        }
        
        let result = [];
        nodes.forEach(node => {
            if (node.children && node.children.length) {
                result = result.concat({$uniqueId}_flattenToDepth(node.children, currentDepth + 1, targetDepth));
            }
        });
        return result;
    }

    function {$uniqueId}_createTreeNode(node, currentDepth, isFromFlatten) {
        const li = document.createElement('li');
        const label = document.createElement('div');
        label.className = uniqueId + '-tree-label';
        label.dataset.nodeType = node.type || 'dir';
        label.style.display = 'flex';
        label.style.alignItems = 'center';
        label.style.justifyContent = 'space-between';
        label.style.gap = '8px';
        
        const labelContent = document.createElement('span');
        labelContent.style.flex = '1';
        labelContent.style.display = 'flex';
        labelContent.style.alignItems = 'center';
        labelContent.style.gap = '6px';
        labelContent.textContent = node.title || node.meta_identify || '…';

        if (node.type === 'file') {
            label.dataset.metaIdentify = node.meta_identify;
            label.dataset.nodeArea = node.area || '';
            const icon = document.createElement('i');
            icon.className = 'mdi mdi-file-document-outline text-primary';
            labelContent.prepend(icon);
            
            // 检查是否是当前激活的布局文件（仅对 layout 类型）
            if (layoutActiveCallback && layoutActiveCallback !== 'null') {
                // 从 meta_identify 中提取路径和文件名
                // meta_identify 格式可能是: theme.frontend.layouts.homepage.minimal
                // 需要提取: 路径 = homepage, 文件名 = minimal
                let category = node.category || '';
                let fileName = '';
                
                // 调试：输出节点信息
                console.log('[MetaManager] 检查节点:', {
                    type: node.type,
                    meta_identify: node.meta_identify,
                    category: node.category,
                    title: node.title,
                    activeLayouts: activeLayouts
                });
                
                if (node.meta_identify) {
                    // 解析 meta_identify: theme.frontend.layouts.homepage.minimal
                    const parts = node.meta_identify.split('.');
                    // 查找 'layouts' 的位置
                    const layoutsIndex = parts.indexOf('layouts');
                    if (layoutsIndex >= 0 && layoutsIndex + 2 < parts.length) {
                        // 路径是 layouts 后面的第一个部分（homepage）
                        category = parts[layoutsIndex + 1] || node.category || '';
                        // 文件名是 layouts 后面的第二个部分（minimal）
                        fileName = parts[layoutsIndex + 2] || '';
                        console.log('[MetaManager] 从 meta_identify 解析:', {
                            layoutsIndex: layoutsIndex,
                            parts: parts,
                            category: category,
                            fileName: fileName
                        });
                    }
                }
                
                // 如果从 meta_identify 解析失败，尝试使用 category 和 title
                if (!category && node.category) {
                    category = node.category;
                }
                if (!fileName) {
                    // 从 title 中提取文件名（去掉 .phtml 扩展名）
                    fileName = (node.title || '').replace(/\.phtml$/, '').replace(/\.php$/, '');
                }
                
                // 调试日志
                console.log('[MetaManager] 最终匹配信息:', {
                    category: category,
                    fileName: fileName,
                    activeLayouts: activeLayouts,
                    match: category && fileName ? activeLayouts[category] === fileName : false,
                    activeLayoutForCategory: category ? activeLayouts[category] : undefined
                });
                
                // 检查 activeLayouts 中是否有该路径的配置，且文件名匹配
                if (category && fileName && activeLayouts[category] === fileName) {
                    console.log('[MetaManager] ✓ 匹配成功，添加星号标记');
                    const starIcon = document.createElement('i');
                    starIcon.className = 'mdi mdi-star text-warning';
                    starIcon.style.fontSize = '14px';
                    starIcon.style.marginLeft = '4px';
                    starIcon.title = '当前激活的布局';
                    labelContent.appendChild(starIcon);
                } else {
                    console.log('[MetaManager] ✗ 未匹配:', {
                        hasCategory: !!category,
                        hasFileName: !!fileName,
                        categoryMatch: category ? (activeLayouts[category] || '未找到') : '无category',
                        expectedFileName: category ? activeLayouts[category] : undefined,
                        actualFileName: fileName
                    });
                }
            }
            
            label.appendChild(labelContent);
        } else {
            // 目录节点（包括所有层级的目录）
            label.dataset.dirPath = node.meta_identify || node.title || '';
            label.dataset.dirArea = node.area || '';
            const icon = document.createElement('i');
            icon.className = 'mdi mdi-folder-outline text-warning';
            labelContent.prepend(icon);
            
            // 为所有目录节点添加设置图标（如果提供了回调配置）
            if (dirConfigCallback) {
                const configBtn = document.createElement('button');
                configBtn.type = 'button';
                configBtn.className = uniqueId + '-dir-config-btn btn btn-sm btn-link p-0';
                configBtn.style.cssText = 'color: var(--bs-primary, #0d6efd); padding: 2px 4px; line-height: 1; opacity: 0.7; transition: opacity 0.2s;';
                configBtn.title = '配置';
                configBtn.innerHTML = '<i class="mdi mdi-cog-outline" style="font-size: 16px;"></i>';
                configBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    {$uniqueId}_openDirConfig(node);
                });
                label.appendChild(labelContent);
                label.appendChild(configBtn);
            } else {
                label.appendChild(labelContent);
            }
        }

        li.appendChild(label);

        // 检查层级限制：如果设置了 maxDepth 且当前深度已达到或超过限制，则不显示子节点
        if (node.children && node.children.length) {
            const shouldShowChildren = maxDepth === null || currentDepth < maxDepth;
            
            if (shouldShowChildren) {
                const childrenContainer = document.createElement('div');
                childrenContainer.className = uniqueId + '-tree-node-children';
                const childUl = document.createElement('ul');
                node.children.forEach(child => {
                    childUl.appendChild({$uniqueId}_createTreeNode(child, currentDepth + 1, false));
                });
                childrenContainer.appendChild(childUl);
                li.appendChild(childrenContainer);
            }
        }

        return li;
    }

    function {$uniqueId}_loadMetaFile(metaIdentify, area) {
        if (!editorContainer) return;
        editorContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2 mb-0">正在加载元数据...</p></div>';

        const params = new URLSearchParams({
            meta_identify: metaIdentify,
            scope: lastFilters.scope || 'default',
            locale: lastFilters.locale || '',
            area: area || lastFilters.area || '',
            identity_id: lastFilters.identity_id || '',
        });

        fetch(endpoints.file + '?' + params.toString(), { credentials: 'same-origin' })
            .then(response => response.json())
            .then(json => {
                if (json.code !== 200) {
                    throw new Error(json.msg || json.message || '加载失败');
                }
                {$uniqueId}_renderMetaEditor(json.data, params);
            })
            .catch(error => {
                console.error(error);
                if (editorContainer) {
                    editorContainer.innerHTML = '<div class="alert alert-danger mb-0">加载失败: ' + error.message + '</div>';
                }
            });
    }

    function {$uniqueId}_renderMetaEditor(data, params) {
        if (!editorContainer) return;
        const meta = data.meta || {};
        const paramsList = data.params || [];

        let html = '';
        html += '<div class="mb-3">';
        html += '<div class="d-flex justify-content-between align-items-center">';
        html += '<div class="flex-grow-1">';
        html += '<div class="d-flex justify-content-between align-items-center mb-1">';
        html += '<h5 class="mb-0">' + (meta.name || meta.meta_identify || 'meta') + '</h5>';
        // 始终提供名称翻译入口（使用 Meta 翻译表）
        if (meta.meta_identify) {
            html += '<button type="button" class="btn btn-sm btn-outline-secondary ' + uniqueId + '-meta-name-desc-translate-btn" data-meta-identify="' + (meta.meta_identify || '') + '" data-field="name" data-scope="' + (params.get('scope') || 'default') + '" title="翻译名称"><i class="mdi mdi-translate"></i></button>';
        }
        html += '</div>';
        if (meta.description) {
            html += '<div class="d-flex justify-content-between align-items-center mb-1">';
            html += '<div class="text-muted small">' + meta.description + '</div>';
            // 始终提供描述翻译入口
            if (meta.meta_identify) {
                html += '<button type="button" class="btn btn-sm btn-outline-secondary ' + uniqueId + '-meta-name-desc-translate-btn" data-meta-identify="' + (meta.meta_identify || '') + '" data-field="description" data-scope="' + (params.get('scope') || 'default') + '" title="翻译描述"><i class="mdi mdi-translate"></i></button>';
            }
            html += '</div>';
        }
        html += '<div class="text-muted small">' + (meta.file_path || meta.file_full_path || '') + '</div>';
        html += '</div>';
        html += '<span class="badge bg-light text-dark align-self-center" style="height: fit-content;">' + (meta.meta_type || 'meta') + '</span>';
        html += '</div>';
        html += '</div>';

        html += '<form id="' + uniqueId + '-meta-file-form" data-meta-identify="' + (meta.meta_identify || '') + '" data-area="' + (meta.area || params.get('area') || '') + '">';
        html += '<input type="hidden" name="meta_identify" value="' + (meta.meta_identify || '') + '">';
        html += '<input type="hidden" name="scope" value="' + (params.get('scope') || 'default') + '">';
        html += '<input type="hidden" name="area" value="' + (meta.area || params.get('area') || '') + '">';
        html += '<input type="hidden" name="identity_id" value="' + (params.get('identity_id') || '') + '">';

        if (!paramsList.length) {
            html += '<div class="alert alert-info mb-0">该文件没有可配置的参数。</div>';
        } else {
            html += '<div class="list-group ' + uniqueId + '-meta-param-list mb-3">';
            paramsList.forEach(function (item) {
                const value = item.value ?? '';
                // 判断参数是否已保存（有配置值则认为已保存）
                const isSaved = item.is_saved === true || (value !== '' && value !== null && value !== undefined);
                html += '<div class="list-group-item">';
                html += '<div class="d-flex justify-content-between align-items-center mb-1">';
                html += '<div><strong>' + (item.label || item.name) + '</strong> <code class="text-muted ms-1" style="font-size: 11px;">' + item.name + '</code>';
                // 参数翻译按钮：只有保存后才能翻译
                if (meta.meta_identify) {
                    if (isSaved) {
                        html += '<button type="button" class="btn btn-sm btn-outline-secondary ' + uniqueId + '-param-translate-btn ms-1" data-meta-identify="' + (meta.meta_identify || '') + '" data-param="' + item.name + '" data-area="' + (meta.area || params.get('area') || 'frontend') + '" title="翻译参数"><i class="mdi mdi-translate"></i></button>';
                    } else {
                        html += '<button type="button" class="btn btn-sm btn-outline-secondary ms-1" disabled title="请先保存配置后再翻译" style="opacity: 0.5; cursor: not-allowed;"><i class="mdi mdi-translate"></i></button>';
                    }
                }
                html += '</div>';
                html += '</div>';
                if (item.description) {
                    html += '<div class="text-muted small mb-2">' + item.description + '</div>';
                }

                if ((item.input === 'textarea') || (typeof value === 'string' && value.length > 80)) {
                    html += '<textarea class="form-control form-control-sm" rows="3" name="params[' + item.name + ']">' + (value || '') + '</textarea>';
                } else if ((item.input === 'select' || item.options && Object.keys(item.options).length)) {
                    html += '<select class="form-select form-select-sm" name="params[' + item.name + ']">';
                    const options = item.options || {};
                    Object.keys(options).forEach(function (optValue) {
                        const selected = String(optValue) === String(value) ? 'selected' : '';
                        html += '<option value="' + optValue + '" ' + selected + '>' + options[optValue] + '</option>';
                    });
                    html += '</select>';
                } else {
                    html += '<input type="text" class="form-control form-control-sm" name="params[' + item.name + ']" value="' + (value || '') + '">';
                }

                html += '</div>';
            });
            html += '</div>';
            html += '<div class="text-end">';
            html += '<button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save me-1"></i>保存配置</button>';
            html += '</div>';
        }

        html += '</form>';

        editorContainer.innerHTML = html;
        const form = document.getElementById(uniqueId + '-meta-file-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                {$uniqueId}_saveMetaConfig(form);
            });
        }
    }

    function {$uniqueId}_saveMetaConfig(form) {
        const formData = new FormData(form);
        const formArea = form.getAttribute('data-area') || (lastFilters.area || '');
        if (treeContainer) {
            treeContainer.querySelectorAll('.' + uniqueId + '-tree-label').forEach(el => el.classList.remove('saving'));
        }
        const activeNode = treeContainer ? treeContainer.querySelector('.' + uniqueId + '-tree-label.active') : null;
        if (activeNode) {
            activeNode.classList.add('saving');
        }
        
        // 获取 metaIdentify（从表单或当前活动节点）
        const metaIdentify = formData.get('meta_identify') || (activeNode ? activeNode.dataset.metaIdentify : '') || '';

        fetch(endpoints.save, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
            .then(response => response.json())
            .then(json => {
                if (json.code !== 200) {
                    throw new Error(json.msg || json.message || '保存失败');
                }
                {$uniqueId}_showToast(json.msg || json.message || '配置保存成功', 'success');
                {$uniqueId}_notifyParentConfigSaved(formArea);
                
                // 触发自定义保存事件（如果配置了 on-save 属性）
                if (onSaveEvent) {
                    const saveEventDetail = {
                        uniqueId: uniqueId,
                        metaIdentify: metaIdentify || json.data?.meta_identify || '',
                        area: formArea,
                        scope: formData.get('scope') || 'default',
                        locale: formData.get('locale') || '',
                        namespace: formData.get('namespace') || '',
                        type: formData.get('type') || '',
                        category: formData.get('category') || '',
                        responseData: json.data || {},
                        message: json.msg || json.message || '配置保存成功'
                    };
                    // 在 document 上派发自定义事件
                    document.dispatchEvent(new CustomEvent(onSaveEvent, { 
                        detail: saveEventDetail,
                        bubbles: true 
                    }));
                }
            })
            .catch(error => {
                console.error(error);
                {$uniqueId}_showToast('保存失败: ' + error.message, 'error');
            })
            .finally(() => {
                if (activeNode) {
                    activeNode.classList.remove('saving');
                }
            });
    }

    function {$uniqueId}_openParamTranslation(payload) {
        translationState.metaIdentify = payload.metaIdentify;
        translationState.param = payload.param;
        translationState.scope = lastFilters.scope || 'default';
        translationState.locale = lastFilters.locale || '';
        translationState.area = payload.area || lastFilters.area || '';
        {$uniqueId}_setTranslationTitle('参数翻译 - ' + (payload.param || ''));
        {$uniqueId}_setTranslationLoading();
        {$uniqueId}_loadParamTranslation();
    }

    function {$uniqueId}_loadParamTranslation() {
        const params = new URLSearchParams({
            meta_identify: translationState.metaIdentify,
            param: translationState.param,
            scope: translationState.scope,
            locale: translationState.locale,
            area: translationState.area,
        });

        {$uniqueId}_setTranslationLoading();

        fetch(endpoints.translation + '?' + params.toString(), { credentials: 'same-origin' })
            .then(response => response.json())
            .then(json => {
                if (json.code !== 200) {
                    throw new Error(json.msg || json.message || '加载失败');
                }

                const data = json.data || {};
                let translations = data.translations || [];

                // 渲染翻译表单的函数
                const renderParamTranslationForm = (translations) => {
                    let html = '';
                    html += '<form id="' + uniqueId + '-metaParamTranslationForm">';
                    html += '<input type="hidden" name="meta_identify" value="' + (data.meta_identify || translationState.metaIdentify) + '">';
                    html += '<input type="hidden" name="param" value="' + (data.param || translationState.param) + '">';
                    html += '<input type="hidden" name="scope" value="' + (data.scope || translationState.scope) + '">';

                    html += '<div class="mb-3">';
                    html += '<label class="form-label"><strong>默认值 (' + (data.label || translationState.param) + ')</strong></label>';
                    html += '<div class="form-control-plaintext bg-light p-2 rounded">' + (data.default_value || '') + '</div>';
                    html += '<div class="form-text">如果某语言没有翻译，将使用此默认值</div>';
                    if (data.description) {
                        html += '<div class="text-muted small mt-1">' + data.description + '</div>';
                    }
                    html += '</div>';

                    html += '<div class="mb-3">';
                    html += '<label class="form-label"><strong>多语言翻译</strong></label>';
                    if (translations.length === 0) {
                        html += '<div class="alert alert-info mb-0">没有可用的语言（请先在 I18n 模块中安装语言）</div>';
                    } else {
                        html += '<div class="list-group">';
                        translations.forEach(function(trans) {
                            html += '<div class="list-group-item">';
                            html += '<div class="row align-items-center">';
                            html += '<div class="col-md-3">';
                            html += '<label class="form-label mb-0"><strong>' + (trans.name || trans.code) + '</strong></label>';
                            html += '<div class="text-muted small">' + trans.code + '</div>';
                            html += '</div>';
                            html += '<div class="col-md-9">';
                            html += '<input type="text" class="form-control" name="translations[' + trans.code + ']" value="' + (trans.value || '') + '" placeholder="留空则使用默认值">';
                            html += '</div>';
                            html += '</div>';
                            html += '</div>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';

                    html += '<div class="d-flex justify-content-end gap-2 mt-3">';
                    html += '<button type="button" class="btn btn-outline-secondary btn-sm" id="' + uniqueId + '-translation-close-btn">收起</button>';
                    html += '<button type="button" class="btn btn-primary btn-sm" id="' + uniqueId + '-translation-save-btn">保存翻译</button>';
                    html += '</div>';
                    html += '</form>';
                    if (translationDrawerBody) {
                        translationDrawerBody.innerHTML = html;
                    }
                    {$uniqueId}_openTranslationDrawer();
                };

                // 如果 translations 为空，请求 locales API 获取可用语言
                if (translations.length === 0) {
                    fetch(endpoints.locales, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(localesJson => {
                            // API 返回格式是 {code: 200, data: {locales: [...]}}
                            const localesList = localesJson.data?.locales || localesJson.data || [];
                            if (localesJson.code === 200 && localesList.length) {
                                translations = localesList.map(loc => ({
                                    code: loc.code,
                                    name: loc.name || loc.code,
                                    value: ''
                                }));
                            }
                            renderParamTranslationForm(translations);
                        })
                        .catch(() => {
                            renderParamTranslationForm(translations);
                        });
                } else {
                    renderParamTranslationForm(translations);
                }
            })
            .catch(error => {
                console.error(error);
                if (translationDrawerBody) {
                    translationDrawerBody.innerHTML = '<div class="alert alert-danger mb-0">加载失败: ' + error.message + '</div>';
                }
            });
    }

    function {$uniqueId}_saveParamTranslation() {
        const form = document.getElementById(uniqueId + '-metaParamTranslationForm');
        if (!form) {
            return;
        }

        const formData = new FormData(form);
        fetch(endpoints.translationSave, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
            .then(response => response.json())
            .then(json => {
                if (json.code !== 200) {
                    throw new Error(json.msg || json.message || '保存失败');
                }
                {$uniqueId}_closeTranslationDrawer();
                {$uniqueId}_showToast(json.msg || json.message || '翻译已保存', 'success');
                
                // 重新加载当前文件以更新显示
                const activeNode = treeContainer ? treeContainer.querySelector('.' + uniqueId + '-tree-label.active') : null;
                let metaIdentify = '';
                let area = '';
                if (activeNode) {
                    metaIdentify = activeNode.dataset.metaIdentify;
                    area = activeNode.dataset.nodeArea || lastFilters.area || 'frontend';
                    {$uniqueId}_loadMetaFile(metaIdentify, area);
                }
                
                // 触发自定义保存事件（如果配置了 on-save 属性）
                if (onSaveEvent) {
                    const saveEventDetail = {
                        uniqueId: uniqueId,
                        metaIdentify: metaIdentify || translationState.metaIdentify || '',
                        namespace: lastFilters.namespace || '',
                        area: area || lastFilters.area || 'frontend',
                        type: lastFilters.type || '',
                        category: lastFilters.category || '',
                        scope: translationState.scope || 'default',
                        data: json.data || {},
                        message: json.msg || json.message || '翻译已保存',
                        translationType: 'param',
                        param: translationState.param || ''
                    };
                    document.dispatchEvent(new CustomEvent(onSaveEvent, { 
                        detail: saveEventDetail,
                        bubbles: true 
                    }));
                }
            })
            .catch(error => {
                console.error(error);
                {$uniqueId}_showToast('保存失败: ' + error.message, 'error');
            });
    }

    function {$uniqueId}_sprintf(format) {
        const args = Array.prototype.slice.call(arguments, 1);
        return format.replace(/%s/g, () => args.shift());
    }

    function {$uniqueId}_notifyParentConfigSaved(area) {
        if (window.parent && window.parent !== window) {
            try {
                window.parent.postMessage({
                    type: 'meta-config:saved',
                    area: area || ''
                }, '*');
            } catch (err) {
                console.warn('postMessage failed', err);
            }
        }
    }

    // Meta name/description 翻译相关
    const metaNameDescTranslationState = {
        metaIdentify: null,
        field: null,
        scope: 'default',
    };

    function {$uniqueId}_openMetaNameDescriptionTranslation(payload) {
        metaNameDescTranslationState.metaIdentify = payload.metaIdentify;
        metaNameDescTranslationState.field = payload.field;
        metaNameDescTranslationState.scope = payload.scope || 'default';
        {$uniqueId}_setTranslationTitle('Meta 翻译 - ' + (payload.field === 'name' ? '名称' : '描述'));
        {$uniqueId}_setTranslationLoading();
        {$uniqueId}_loadMetaNameDescriptionTranslation();
    }

    function {$uniqueId}_loadMetaNameDescriptionTranslation() {
        const params = new URLSearchParams({
            meta_identify: metaNameDescTranslationState.metaIdentify,
            field: metaNameDescTranslationState.field,
            scope: metaNameDescTranslationState.scope,
        });

        {$uniqueId}_setTranslationLoading();

        fetch(endpoints.metaNameDescTranslation + '?' + params.toString(), { credentials: 'same-origin' })
            .then(response => response.json())
            .then(json => {
                if (json.code !== 200) {
                    throw new Error(json.msg || json.message || '加载失败');
                }

                const data = json.data || {};
                let translations = data.translations || [];
                const fieldLabel = data.field === 'name' ? '名称' : '描述';

                // 渲染翻译表单的函数
                const renderNameDescTranslationForm = (translations) => {
                    let html = '';
                    html += '<form id="' + uniqueId + '-metaNameDescriptionTranslationForm">';
                    html += '<input type="hidden" name="meta_identify" value="' + (data.meta_identify || '') + '">';
                    html += '<input type="hidden" name="field" value="' + (data.field || '') + '">';
                    html += '<input type="hidden" name="scope" value="' + (data.scope || 'default') + '">';

                    html += '<div class="mb-3">';
                    html += '<label class="form-label"><strong>默认值 (' + fieldLabel + ')</strong></label>';
                    html += '<div class="form-control-plaintext bg-light p-2 rounded">' + (data.default_value || '') + '</div>';
                    html += '<div class="form-text">如果某语言没有翻译，将使用此默认值</div>';
                    html += '</div>';

                    html += '<div class="mb-3">';
                    html += '<label class="form-label"><strong>多语言翻译</strong></label>';
                    if (translations.length === 0) {
                        html += '<div class="alert alert-info mb-0">没有可用的语言（请先在 I18n 模块中安装语言）</div>';
                    } else {
                        html += '<div class="list-group">';
                        translations.forEach(function(trans) {
                            html += '<div class="list-group-item">';
                            html += '<div class="row align-items-center">';
                            html += '<div class="col-md-3">';
                            html += '<label class="form-label mb-0"><strong>' + (trans.name || trans.code) + '</strong></label>';
                            html += '<div class="text-muted small">' + trans.code + '</div>';
                            html += '</div>';
                            html += '<div class="col-md-9">';
                            html += '<input type="text" class="form-control" name="translations[' + trans.code + ']" value="' + (trans.value || '') + '" placeholder="留空则使用默认值">';
                            html += '</div>';
                            html += '</div>';
                            html += '</div>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';

                    html += '<div class="d-flex justify-content-end gap-2 mt-3">';
                    html += '<button type="button" class="btn btn-outline-secondary btn-sm" id="' + uniqueId + '-translation-close-btn">收起</button>';
                    html += '<button type="button" class="btn btn-primary btn-sm" id="' + uniqueId + '-translation-name-desc-save-btn">保存翻译</button>';
                    html += '</div>';
                    html += '</form>';
                    if (translationDrawerBody) {
                        translationDrawerBody.innerHTML = html;
                    }
                    {$uniqueId}_openTranslationDrawer();
                };

                // 如果 translations 为空，请求 locales API 获取可用语言
                if (translations.length === 0) {
                    fetch(endpoints.locales, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(localesJson => {
                            // API 返回格式是 {code: 200, data: {locales: [...]}}
                            const localesList = localesJson.data?.locales || localesJson.data || [];
                            if (localesJson.code === 200 && localesList.length) {
                                translations = localesList.map(loc => ({
                                    code: loc.code,
                                    name: loc.name || loc.code,
                                    value: ''
                                }));
                            }
                            renderNameDescTranslationForm(translations);
                        })
                        .catch(() => {
                            renderNameDescTranslationForm(translations);
                        });
                } else {
                    renderNameDescTranslationForm(translations);
                }
            })
            .catch(error => {
                console.error(error);
                if (translationDrawerBody) {
                    translationDrawerBody.innerHTML = '<div class="alert alert-danger mb-0">加载失败: ' + error.message + '</div>';
                }
            });
    }

    function {$uniqueId}_saveMetaNameDescriptionTranslation() {
        const form = document.getElementById(uniqueId + '-metaNameDescriptionTranslationForm');
        if (!form) {
            return;
        }

        const formData = new FormData(form);
        fetch(endpoints.metaNameDescTranslationSave, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
            .then(response => response.json())
            .then(json => {
                if (json.code !== 200) {
                    throw new Error(json.msg || json.message || '保存失败');
                }
                {$uniqueId}_closeTranslationDrawer();
                {$uniqueId}_showToast(json.msg || json.message || '翻译已保存', 'success');
                
                const activeNode = treeContainer ? treeContainer.querySelector('.' + uniqueId + '-tree-label.active') : null;
                let metaIdentify = '';
                let area = '';
                if (activeNode) {
                    metaIdentify = activeNode.dataset.metaIdentify;
                    area = activeNode.dataset.nodeArea || lastFilters.area || 'frontend';
                    {$uniqueId}_loadMetaFile(metaIdentify, area);
                }
                
                // 触发自定义保存事件（如果配置了 on-save 属性）
                if (onSaveEvent) {
                    const saveEventDetail = {
                        uniqueId: uniqueId,
                        metaIdentify: metaIdentify || metaNameDescTranslationState.metaIdentify || '',
                        namespace: lastFilters.namespace || '',
                        area: area || lastFilters.area || 'frontend',
                        type: lastFilters.type || '',
                        category: lastFilters.category || '',
                        scope: metaNameDescTranslationState.scope || 'default',
                        data: json.data || {},
                        message: json.msg || json.message || '翻译已保存',
                        translationType: 'meta',
                        field: metaNameDescTranslationState.field || ''
                    };
                    document.dispatchEvent(new CustomEvent(onSaveEvent, { 
                        detail: saveEventDetail,
                        bubbles: true 
                    }));
                }
            })
            .catch(error => {
                console.error(error);
                {$uniqueId}_showToast('保存失败: ' + error.message, 'error');
            });
    }

    // 翻译抽屉交互
    if (translationDrawerClose) {
        translationDrawerClose.addEventListener('click', {$uniqueId}_closeTranslationDrawer);
    }
    if (translationDrawerBody) {
        translationDrawerBody.addEventListener('click', function(e) {
            if (e.target.id === uniqueId + '-translation-close-btn') {
                {$uniqueId}_closeTranslationDrawer();
                return;
            }
            if (e.target.id === uniqueId + '-translation-save-btn') {
                {$uniqueId}_saveParamTranslation();
                return;
            }
            if (e.target.id === uniqueId + '-translation-name-desc-save-btn') {
                {$uniqueId}_saveMetaNameDescriptionTranslation();
                return;
            }
        });
    }

    // 编辑器面板展开/收起逻辑
    const editorPanel = document.getElementById(uniqueId + '_editor_panel');
    const editorToggle = document.getElementById(uniqueId + '_editor_toggle');
    const collapseBtn = document.getElementById(uniqueId + '_collapse_btn');
    
    function {$uniqueId}_expandEditor() {
        if (editorPanel) {
            editorPanel.classList.remove(uniqueId + '_editor_collapsed');
            editorPanel.classList.add(uniqueId + '_editor_expanded');
        }
    }
    
    function {$uniqueId}_collapseEditor() {
        if (editorPanel) {
            editorPanel.classList.remove(uniqueId + '_editor_expanded');
            editorPanel.classList.add(uniqueId + '_editor_collapsed');
        }
    }
    
    // 点击展开按钮
    if (editorToggle) {
        editorToggle.addEventListener('click', function() {
            {$uniqueId}_expandEditor();
        });
    }
    
    // 点击收起按钮
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            {$uniqueId}_collapseEditor();
        });
    }

    // 实时过滤 - 监听所有筛选输入的变化
    function {$uniqueId}_onFilterChange() {
        // 防抖处理
        if (filterDebounceTimer) {
            clearTimeout(filterDebounceTimer);
        }
        filterDebounceTimer = setTimeout(function() {
            {$uniqueId}_loadTree();
        }, 300);
    }
    
    // 绑定所有自动过滤输入的事件
    const autoFilterInputs = document.querySelectorAll('.' + uniqueId + '_auto_filter');
    autoFilterInputs.forEach(function(input) {
        if (input.tagName === 'SELECT') {
            input.addEventListener('change', {$uniqueId}_onFilterChange);
        } else {
            input.addEventListener('input', {$uniqueId}_onFilterChange);
            input.addEventListener('change', {$uniqueId}_onFilterChange);
        }
    });

    const filterToggle = document.querySelector('[data-meta-filter-toggle="' + uniqueId + '-filter-body"]');
    if (filterToggle) {
        filterToggle.addEventListener('click', function() {
            const filterIcon = filterToggle.querySelector('.' + uniqueId + '_filter_toggle_icon');
            const filterBody = document.getElementById(uniqueId + '-filter-body');

            if (filterIcon) {
                filterIcon.classList.toggle('mdi-chevron-down');
                filterIcon.classList.toggle('mdi-chevron-up');
            }
            if (filterBody) {
                filterBody.classList.toggle('collapse');
            }
        });
    }

    // 打开目录配置弹窗
    function {$uniqueId}_openDirConfig(node) {
        if (!dirConfigCallback || dirConfigCallback === 'null' || dirConfigCallback === null) {
            {$uniqueId}_showToast('未配置目录配置回调', 'warning');
            return;
        }
        
        try {
            // 解析回调配置
            let callback = dirConfigCallback;
            
            // 如果dirConfigCallback是字符串，尝试解析为JSON
            if (typeof dirConfigCallback === 'string') {
                // 移除可能的HTML实体编码
                let jsonStr = dirConfigCallback;
                // 如果包含HTML实体，先解码
                if (jsonStr.includes('&quot;') || jsonStr.includes('&#039;')) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = jsonStr;
                    jsonStr = tempDiv.textContent || tempDiv.innerText || jsonStr;
                }
                
                try {
                    callback = JSON.parse(jsonStr);
                } catch (e) {
                    console.error('[MetaManager] 无法解析dirConfigCallback JSON:', e);
                    console.error('[MetaManager] JSON字符串:', jsonStr);
                    {$uniqueId}_showToast('回调配置格式不正确: ' + e.message, 'error');
                    return;
                }
            }
            
            if (!callback || typeof callback !== 'object') {
                {$uniqueId}_showToast('回调配置格式不正确', 'error');
                return;
            }
            
            // 准备目录信息
            // type 优先从节点的 meta_type 获取，其次从筛选条件获取，最后默认为 layout
            const nodeType = node.meta_type || lastFilters.type || 'layout';
            const dirInfo = {
                path: node.meta_identify || node.title || '',
                area: node.area || lastFilters.area || 'frontend',
                scope: lastFilters.scope || 'default',
                identity_id: lastFilters.identity_id || '',
                type: nodeType, // 传递类型参数（layout, partial, colors, component）
                namespace: lastFilters.namespace || '',
            };
            console.log('[MetaManager] 目录配置信息:', dirInfo);
            
            // 调用回调函数处理配置弹窗
            if (typeof callback === 'function') {
                callback(dirInfo, {$uniqueId}_saveDirConfig);
            } else if (callback && typeof callback === 'object') {
                // 如果是对象，可能包含url、method等配置
                if (callback.url) {
                    // 使用URL加载配置表单
                    {$uniqueId}_loadDirConfigForm(callback.url, dirInfo);
                } else if (callback.form) {
                    // 使用提供的表单HTML
                    {$uniqueId}_showDirConfigModal(callback.form, dirInfo);
                } else {
                    {$uniqueId}_showToast('回调配置格式不正确', 'error');
                }
            } else {
                {$uniqueId}_showToast('回调配置格式不正确', 'error');
            }
        } catch (error) {
            console.error('[MetaManager] 打开目录配置失败:', error);
            {$uniqueId}_showToast('打开配置失败: ' + error.message, 'error');
        }
    }
    
    // 加载目录配置表单
    function {$uniqueId}_loadDirConfigForm(url, dirInfo) {
        const params = new URLSearchParams(dirInfo);
        fetch(url + '?' + params.toString(), { credentials: 'same-origin' })
            .then(response => response.text())
            .then(html => {
                {$uniqueId}_showDirConfigModal(html, dirInfo);
            })
            .catch(error => {
                console.error('[MetaManager] 加载配置表单失败:', error);
                {$uniqueId}_showToast('加载配置表单失败: ' + error.message, 'error');
            });
    }
    
    // 显示目录配置弹窗
    function {$uniqueId}_showDirConfigModal(formHtml, dirInfo) {
        // 创建模态框
        const modalId = uniqueId + '-dir-config-modal';
        let modal = document.getElementById(modalId);
        const saveBtnId = modalId + '-save';
        
        // 检查 modal 是否存在，以及结构是否完整
        let modalBody = modal ? document.getElementById(modalId + '-body') : null;
        
        if (!modal || !modalBody) {
            // 如果 modal 不存在或结构不完整，重新创建
            if (modal) {
                // 如果 modal 存在但结构不完整，先移除旧的
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.dispose();
                }
                modal.remove();
            }
            
            // 创建新的 modal
            modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            
            // 创建 modal 结构
            const modalDialog = document.createElement('div');
            modalDialog.className = 'modal-dialog modal-lg';
            
            const modalContent = document.createElement('div');
            modalContent.className = 'modal-content';
            
            // 创建 modal-header
            const modalHeader = document.createElement('div');
            modalHeader.className = 'modal-header';
            modalHeader.innerHTML = `
                <h5 class="modal-title">目录配置</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            `;
            
            // 创建 modal-body
            modalBody = document.createElement('div');
            modalBody.className = 'modal-body';
            modalBody.id = modalId + '-body';
            
            // 创建 modal-footer
            const modalFooter = document.createElement('div');
            modalFooter.className = 'modal-footer';
            modalFooter.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="` + saveBtnId + `">保存</button>
            `;
            
            // 组装 modal 结构
            modalContent.appendChild(modalHeader);
            modalContent.appendChild(modalBody);
            modalContent.appendChild(modalFooter);
            modalDialog.appendChild(modalContent);
            modal.appendChild(modalDialog);
            
            // 添加到 DOM
            document.body.appendChild(modal);
        } else {
            // 如果 modal 已存在，确保 modalBody 和保存按钮都存在
            modalBody = document.getElementById(modalId + '-body');
            if (!modalBody) {
                // 如果仍然找不到，尝试从 modal 内部查找
                modalBody = modal.querySelector('.modal-body');
            }
            
            // 检查保存按钮是否存在
            const existingSaveBtn = document.getElementById(saveBtnId);
            if (!existingSaveBtn) {
                // 如果保存按钮不存在，尝试从 modal 内部查找
                const foundSaveBtn = modal.querySelector('#' + saveBtnId);
                if (!foundSaveBtn) {
                    // 如果仍然找不到，说明结构不完整，重新创建
                    console.warn('[MetaManager] Modal 结构不完整（缺少保存按钮），重新创建');
                    // 清理旧的 modal
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) {
                        bsModal.dispose();
                    }
                    modal.remove();
                    modal = null;
                    modalBody = null;
                    
                    // 重新创建 modal（进入 if 分支）
                    // 创建新的 modal
                    modal = document.createElement('div');
                    modal.id = modalId;
                    modal.className = 'modal fade';
                    modal.setAttribute('tabindex', '-1');
                    
                    // 创建 modal 结构
                    const modalDialog = document.createElement('div');
                    modalDialog.className = 'modal-dialog modal-lg';
                    
                    const modalContent = document.createElement('div');
                    modalContent.className = 'modal-content';
                    
                    // 创建 modal-header
                    const modalHeader = document.createElement('div');
                    modalHeader.className = 'modal-header';
                    modalHeader.innerHTML = `
                        <h5 class="modal-title">目录配置</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    `;
                    
                    // 创建 modal-body
                    modalBody = document.createElement('div');
                    modalBody.className = 'modal-body';
                    modalBody.id = modalId + '-body';
                    
                    // 创建 modal-footer
                    const modalFooter = document.createElement('div');
                    modalFooter.className = 'modal-footer';
                    modalFooter.innerHTML = `
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="` + saveBtnId + `">保存</button>
                    `;
                    
                    // 组装 modal 结构
                    modalContent.appendChild(modalHeader);
                    modalContent.appendChild(modalBody);
                    modalContent.appendChild(modalFooter);
                    modalDialog.appendChild(modalContent);
                    modal.appendChild(modalDialog);
                    
                    // 添加到 DOM
                    document.body.appendChild(modal);
                }
            }
        }
        
        // 填充表单内容
        if (!modalBody) {
            console.error('[MetaManager] 无法创建或找到 modal-body 元素', {
                modalId: modalId,
                modal: modal,
                modalExists: !!document.getElementById(modalId)
            });
            {$uniqueId}_showToast('无法创建配置弹窗', 'error');
            return;
        }
        
        modalBody.innerHTML = formHtml;
        
        // 执行模板中的脚本（innerHTML 不会自动执行 script 标签）
        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
        
        // 在表单中添加隐藏字段保存目录信息
        const form = modalBody.querySelector('form');
        if (form) {
            Object.keys(dirInfo).forEach(key => {
                if (!form.querySelector('[name="' + key + '"]')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = dirInfo[key] || '';
                    form.appendChild(input);
                }
            });
            
            // 注意：卡片选择逻辑现在由 dir-config-form.phtml 模板中的脚本处理
            // 这里不再需要单独处理，因为模板已经包含了通用的事件委托逻辑
            // 这样可以支持 layout, partial, component, color 等多种类型
        }
        
        // 重新获取保存按钮（保存按钮在 modal-footer 中，不会被 modalBody.innerHTML 覆盖）
        // 需要从 modal 中查找，确保找到正确的按钮
        let saveBtn = modal.querySelector('#' + saveBtnId);
        if (!saveBtn) {
            // 如果通过 ID 找不到，尝试从 document 中查找
            saveBtn = document.getElementById(saveBtnId);
        }
        
        if (!saveBtn) {
            // 如果仍然找不到，尝试通过类名查找 btn-primary 按钮（保存按钮通常是主要的按钮）
            const modalFooter = modal.querySelector('.modal-footer');
            if (modalFooter) {
                const primaryBtn = modalFooter.querySelector('.btn-primary');
                if (primaryBtn) {
                    // 找到按钮但没有 ID，给它设置正确的 ID
                    primaryBtn.id = saveBtnId;
                    saveBtn = primaryBtn;
                }
            }
        }
        
        if (!saveBtn) {
            // 如果仍然找不到，说明 modal 结构有问题
            console.error('[MetaManager] 保存按钮未找到:', saveBtnId, {
                modalId: modalId,
                modal: modal,
                modalExists: !!document.getElementById(modalId),
                modalFooter: modal.querySelector('.modal-footer'),
                allButtons: modal.querySelectorAll('button'),
                primaryButtons: modal.querySelectorAll('.btn-primary')
            });
            {$uniqueId}_showToast('保存按钮未找到，请刷新页面重试', 'error');
            return;
        }
        
        // 克隆节点以移除所有事件监听器
        const newSaveBtn = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
        
        // 绑定新的保存按钮事件
        newSaveBtn.addEventListener('click', function() {
            const form = modalBody.querySelector('form');
            if (form) {
                const formData = new FormData(form);
                // 正确解析 FormData，处理嵌套的 config[layout] 格式
                const configData = {};
                for (const [key, value] of formData.entries()) {
                    if (key.startsWith('config[') && key.endsWith(']')) {
                        // 处理 config[key] 格式，例如：config[layout]
                        const configKey = key.slice(7, -1); // 提取 key (从 "config[" 到 "]")
                        if (!configData.config) {
                            configData.config = {};
                        }
                        configData.config[configKey] = value;
                    } else if (key !== 'config') {
                        // 其他字段直接添加（排除 config 本身）
                        configData[key] = value;
                    }
                }
                console.log('[MetaManager] 保存配置数据:', configData);
                {$uniqueId}_saveDirConfig(configData, dirInfo);
            } else {
                {$uniqueId}_showToast('未找到配置表单', 'error');
            }
        });
        
        // 显示模态框
        let bsModal = bootstrap.Modal.getInstance(modal);
        if (!bsModal) {
            bsModal = new bootstrap.Modal(modal);
        }
        bsModal.show();
    }
    
    // 保存目录配置
    function {$uniqueId}_saveDirConfig(configData, dirInfo) {
        if (!dirConfigSaveUrl) {
            {$uniqueId}_showToast('未配置保存URL', 'error');
            return;
        }
        
        const formData = new FormData();
        Object.keys(dirInfo).forEach(key => {
            formData.append(key, dirInfo[key]);
        });
        
        // 从表单中获取 meta_id 和 meta_identify（如果存在）
        const form = document.getElementById('dir-config-form');
        if (form) {
            const metaIdInput = form.querySelector('[name="meta_id"]');
            const metaIdentifyInput = form.querySelector('[name="meta_identify"]');
            if (metaIdInput && metaIdInput.value) {
                formData.append('meta_id', metaIdInput.value);
            }
            if (metaIdentifyInput && metaIdentifyInput.value) {
                formData.append('meta_identify', metaIdentifyInput.value);
            }
        }
        
        // 处理 config 对象（嵌套结构）
        if (configData.config && typeof configData.config === 'object') {
            Object.keys(configData.config).forEach(key => {
                const value = configData.config[key];
                if (value !== null && value !== undefined && value !== '') {
                    formData.append('config[' + key + ']', value);
                }
            });
        } else {
            // 兼容旧格式（扁平结构）
            Object.keys(configData).forEach(key => {
                if (key !== 'config' && configData[key] !== null && configData[key] !== undefined && configData[key] !== '') {
                    formData.append('config[' + key + ']', configData[key]);
                }
            });
        }
        
        // 调试：输出 FormData 内容
        console.log('[MetaManager] 保存 FormData:', Array.from(formData.entries()));
        
        console.log('[MetaManager] 发送保存请求:', {
            url: dirConfigSaveUrl,
            formData: Array.from(formData.entries())
        });
        
        fetch(dirConfigSaveUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(json => {
            if (json.code === 200 || json.status === 'success') {
                {$uniqueId}_showToast('配置保存成功', 'success');
                // 关闭模态框
                const modalId = uniqueId + '-dir-config-modal';
                
                // 重新加载激活布局配置并刷新树（如果有布局激活回调）
                if (layoutActiveCallback && layoutActiveCallback !== 'null') {
                    {$uniqueId}_loadActiveLayouts().then(() => {
                        // 重新渲染树以更新标记
                        {$uniqueId}_loadTree();
                    });
                } else {
                    // 如果没有布局激活回调，直接重新加载树
                    {$uniqueId}_loadTree();
                }
                const modal = document.getElementById(modalId);
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
                // 触发保存事件
                if (onSaveEvent) {
                    document.dispatchEvent(new CustomEvent(onSaveEvent, {
                        detail: { type: 'dir-config', data: configData, dirInfo: dirInfo }
                    }));
                }
            } else {
                {$uniqueId}_showToast(json.msg || json.message || '保存失败', 'error');
            }
        })
        .catch(error => {
            console.error('[MetaManager] 保存目录配置失败:', error);
            {$uniqueId}_showToast('保存失败: ' + error.message, 'error');
        });
    }

    if (treeContainer) {
        treeContainer.addEventListener('click', function (e) {
            // 如果点击的是配置按钮，不处理
            if (e.target.closest('.' + uniqueId + '-dir-config-btn')) {
                return;
            }
            
            const label = e.target.closest('.' + uniqueId + '-tree-label');
            if (!label) return;

            const nodeType = label.dataset.nodeType;
            if (nodeType === 'dir') {
                const children = label.parentElement.querySelector(':scope > .' + uniqueId + '-tree-node-children');
                if (children) {
                    children.classList.toggle(uniqueId + '-collapsed');
                }
                return;
            }

            const metaIdentify = label.dataset.metaIdentify;
            const area = label.dataset.nodeArea || lastFilters.area || 'frontend';
            treeContainer.querySelectorAll('.' + uniqueId + '-tree-label').forEach(el => el.classList.remove('active'));
            label.classList.add('active');
            
            // 点击叶子节点时展开编辑器
            {$uniqueId}_expandEditor();
            
            {$uniqueId}_loadMetaFile(metaIdentify, area);
        });
    }

        if (editorContainer) {
        editorContainer.addEventListener('click', function(e) {
            const translateBtn = e.target.closest('.' + uniqueId + '-param-translate-btn');
            if (translateBtn) {
                {$uniqueId}_openParamTranslation({
                    metaIdentify: translateBtn.dataset.metaIdentify,
                    param: translateBtn.dataset.param,
                    area: translateBtn.dataset.area,
                });
                return;
            }
            
            const metaNameDescTranslateBtn = e.target.closest('.' + uniqueId + '-meta-name-desc-translate-btn');
            if (metaNameDescTranslateBtn) {
                {$uniqueId}_openMetaNameDescriptionTranslation({
                    metaIdentify: metaNameDescTranslateBtn.dataset.metaIdentify,
                    field: metaNameDescTranslateBtn.dataset.field,
                    scope: metaNameDescTranslateBtn.dataset.scope,
                });
            }
        });
    }

    // 异步初始化加载
    function {$uniqueId}_initLoad() {
        // 获取筛选条件
        const filters = {$uniqueId}_getFilters();
        
        // 如果命名空间有值，自动加载文件树
        if (filters.namespace && filters.namespace.trim() !== '') {
            {$uniqueId}_loadTree();
        } else {
            if (treeContainer) {
                treeContainer.innerHTML = '<div class="text-center text-muted py-4"><i class="mdi mdi-information-outline" style="font-size: 2rem; opacity: 0.5;"></i><p class="mt-2 mb-0 small">请选择命名空间</p></div>';
            }
        }
    }
    
    // 页面加载完成后自动初始化
    if (document.readyState === 'complete') {
        setTimeout({$uniqueId}_initLoad, 100);
    } else {
        window.addEventListener('load', function() {
            setTimeout({$uniqueId}_initLoad, 100);
        });
    }
})();
</script>
JS;
    }

    static function tag_self_close(): bool
    {
        return true; // 支持自闭合
    }

    static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    static function parent(): ?string
    {
        return null;
    }

    static function document(): string
    {
        return 'MetaManager标签，用于在模板中快速集成Meta配置管理界面。' .
               '属性说明：' .
               'namespace: 命名空间（可选，支持$variable、$object.property、$array.key）' .
               'area: 区域（可选，frontend/backend，支持变量）' .
               'scope: 作用域（可选，默认default，支持变量）' .
               'locale: 语言（可选，支持变量）' .
               'identity-id: 实体ID（可选，支持变量）' .
               'show-filters: 是否显示筛选条件（可选，默认true）' .
               'show-tree: 是否显示文件树（可选，默认true）' .
               'default-namespace: 默认命名空间（可选，支持变量）' .
               '使用示例：' .
               '<w:meta-manager namespace="theme" area="frontend" />' .
               '<w:meta-manager namespace="$theme.namespace" area="$config.area" identity-id="$theme.id" />';
    }
}

