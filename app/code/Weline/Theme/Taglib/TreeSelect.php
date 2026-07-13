<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * 树形选择器组件
 * 
 * 树形结构数据选择，支持单选/多选和搜索
 */
class TreeSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:tree-select';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,               // 组件唯一ID（必填）
            'name' => true,             // 表单字段名（必填）
            'url' => false,             // 数据源API地址
            'options' => false,         // 静态选项JSON
            'value' => false,           // 当前选中值
            'value-field' => false,     // 值字段名，默认 'value'
            'label-field' => false,     // 显示字段名，默认 'label'
            'children-field' => false,  // 子节点字段名，默认 'children'
            'placeholder' => false,     // 占位文本
            'multiple' => false,        // 是否多选
            'checkable' => false,       // 是否显示复选框
            'check-strictly' => false,  // 父子节点是否不关联
            'default-expand-all' => false, // 是否默认展开所有
            'searchable' => false,      // 是否可搜索
            'clearable' => false,       // 是否可清空
            'class' => false,           // 额外CSS类
            'style' => false,           // 内联样式
            'disabled' => false,        // 是否禁用
            'required' => false,        // 是否必填
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'tree-select-' . uniqid();
            $name = $attributes['name'] ?? '';
            $url = $attributes['url'] ?? '';
            $optionsJson = $attributes['options'] ?? '';
            $value = $attributes['value'] ?? '';
            $valueField = $attributes['value-field'] ?? 'value';
            $labelField = $attributes['label-field'] ?? 'label';
            $childrenField = $attributes['children-field'] ?? 'children';
            $placeholder = $attributes['placeholder'] ?? __('请选择');
            $multiple = isset($attributes['multiple']) && ($attributes['multiple'] === 'true' || $attributes['multiple'] === '1');
            $checkable = isset($attributes['checkable']) && ($attributes['checkable'] === 'true' || $attributes['checkable'] === '1');
            $checkStrictly = isset($attributes['check-strictly']) && ($attributes['check-strictly'] === 'true' || $attributes['check-strictly'] === '1');
            $defaultExpandAll = isset($attributes['default-expand-all']) && ($attributes['default-expand-all'] === 'true' || $attributes['default-expand-all'] === '1');
            $searchable = isset($attributes['searchable']) && ($attributes['searchable'] === 'true' || $attributes['searchable'] === '1');
            $clearable = isset($attributes['clearable']) && ($attributes['clearable'] === 'true' || $attributes['clearable'] === '1');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $disabled = isset($attributes['disabled']) && ($attributes['disabled'] === 'true' || $attributes['disabled'] === '1');
            $required = isset($attributes['required']) && ($attributes['required'] === 'true' || $attributes['required'] === '1');

            // 处理URL
            $apiUrl = '';
            if ($url) {
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '/')) {
                    $apiUrl = $url;
                } else {
                    /** @var Url $urlBuilder */
                    $urlBuilder = w_obj(Url::class);
                    $apiUrl = $urlBuilder->getBackendUrl($url);
                }
            }

            // 解析静态选项
            $staticOptions = [];
            if ($optionsJson) {
                $staticOptions = json_decode($optionsJson, true) ?? [];
            }

            // 解析初始值
            $initialValue = [];
            if ($value) {
                if (str_starts_with($value, '[')) {
                    $initialValue = json_decode($value, true) ?? [];
                } else {
                    $initialValue = array_map('trim', explode(',', $value));
                }
            }

            // 解析属性
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);

            // 翻译文本
            $t_no_data = addslashes(__('暂无数据'));
            $t_loading = addslashes(__('加载中...'));
            $t_clear = addslashes(__('清空'));
            $t_search = addslashes(__('搜索...'));

            $disabledAttr = $disabled ? 'disabled' : '';
            $requiredAttr = $required ? 'required' : '';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="w-tree-select ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="tree-select">';
            
            // 隐藏字段
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="" ' . $requiredAttr . '>';
            
            // 触发器
            $html[] = '  <div class="w-tree-select-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            if ($searchable) {
                $html[] = '    <input type="text" class="w-tree-select-search" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off" ' . $disabledAttr . '>';
            }
            $html[] = '    <div class="w-tree-select-tags" id="<?= htmlspecialchars($Taglib__id) ?>_tags"></div>';
            $html[] = '    <span class="w-tree-select-display" id="<?= htmlspecialchars($Taglib__id) ?>_display">' . htmlspecialchars($placeholder) . '</span>';
            if ($clearable) {
                $html[] = '    <span class="w-tree-select-clear" id="<?= htmlspecialchars($Taglib__id) ?>_clear" title="' . $t_clear . '">&times;</span>';
            }
            $html[] = '    <span class="w-tree-select-arrow">&#9662;</span>';
            $html[] = '  </div>';
            
            // 下拉面板
            $html[] = '  <div class="w-tree-select-dropdown" id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" style="display:none;">';
            $html[] = '    <div class="w-tree-select-tree" id="<?= htmlspecialchars($Taglib__id) ?>_tree"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-tree-select { position: relative; display: inline-block; width: 100%; font-family: inherit; }';
            $html[] = '.w-tree-select-trigger { display: flex; flex-wrap: wrap; align-items: center; border: 1px solid #ced4da; border-radius: 4px; background: #fff; cursor: pointer; min-height: 38px; padding: 4px 30px 4px 10px; position: relative; gap: 4px; }';
            $html[] = '.w-tree-select-trigger:hover { border-color: #80bdff; }';
            $html[] = '.w-tree-select-search { border: none; outline: none; flex: 1; min-width: 80px; background: transparent; font-size: inherit; padding: 2px 0; }';
            $html[] = '.w-tree-select-tags { display: flex; flex-wrap: wrap; gap: 4px; }';
            $html[] = '.w-tree-select-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px; background: #e9ecef; border-radius: 3px; font-size: 12px; }';
            $html[] = '.w-tree-select-tag-remove { cursor: pointer; color: #6c757d; font-size: 14px; line-height: 1; }';
            $html[] = '.w-tree-select-tag-remove:hover { color: #dc3545; }';
            $html[] = '.w-tree-select-display { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #6c757d; padding: 2px 0; }';
            $html[] = '.w-tree-select.has-value .w-tree-select-display { color: #495057; }';
            $html[] = '.w-tree-select-arrow { position: absolute; right: 10px; color: #6c757d; font-size: 10px; transition: transform 0.2s; }';
            $html[] = '.w-tree-select.open .w-tree-select-arrow { transform: rotate(180deg); }';
            $html[] = '.w-tree-select-clear { position: absolute; right: 25px; color: #6c757d; cursor: pointer; display: none; font-size: 16px; line-height: 1; }';
            $html[] = '.w-tree-select-clear:hover { color: #dc3545; }';
            $html[] = '.w-tree-select.has-value .w-tree-select-clear { display: block; }';
            $html[] = '.w-tree-select-dropdown { position: absolute; left: 0; right: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1050; max-height: 300px; overflow-y: auto; }';
            $html[] = '.w-tree-select-tree { padding: 8px; }';
            $html[] = '.w-tree-select-node { }';
            $html[] = '.w-tree-select-node-content { display: flex; align-items: center; padding: 6px 8px; cursor: pointer; border-radius: 3px; gap: 6px; }';
            $html[] = '.w-tree-select-node-content:hover { background: #f8f9fa; }';
            $html[] = '.w-tree-select-node.selected > .w-tree-select-node-content { background: #e9ecef; }';
            $html[] = '.w-tree-select-node-expand { width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #6c757d; cursor: pointer; transition: transform 0.2s; }';
            $html[] = '.w-tree-select-node.expanded > .w-tree-select-node-content .w-tree-select-node-expand { transform: rotate(90deg); }';
            $html[] = '.w-tree-select-node-checkbox { width: 16px; height: 16px; }';
            $html[] = '.w-tree-select-node-label { flex: 1; }';
            $html[] = '.w-tree-select-node-children { padding-left: 20px; display: none; }';
            $html[] = '.w-tree-select-node.expanded > .w-tree-select-node-children { display: block; }';
            $html[] = '.w-tree-select-loading { padding: 20px; text-align: center; color: #6c757d; }';
            $html[] = '.w-tree-select-empty { padding: 20px; text-align: center; color: #6c757d; }';
            $html[] = '.w-tree-select[data-disabled="true"] .w-tree-select-trigger { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '.w-tree-select-node.hidden { display: none; }';
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const fieldName = <?= json_encode($Taglib__name) ?>;';
            $html[] = 'const apiUrl = ' . json_encode($apiUrl) . ';';
            $html[] = 'const staticOptions = ' . json_encode($staticOptions) . ';';
            $html[] = 'const initialValue = ' . json_encode($initialValue) . ';';
            $html[] = 'const valueField = ' . json_encode($valueField) . ';';
            $html[] = 'const labelField = ' . json_encode($labelField) . ';';
            $html[] = 'const childrenField = ' . json_encode($childrenField) . ';';
            $html[] = 'const placeholder = ' . json_encode($placeholder) . ';';
            $html[] = 'const multiple = ' . ($multiple ? 'true' : 'false') . ';';
            $html[] = 'const checkable = ' . ($checkable ? 'true' : 'false') . ';';
            $html[] = 'const checkStrictly = ' . ($checkStrictly ? 'true' : 'false') . ';';
            $html[] = 'const defaultExpandAll = ' . ($defaultExpandAll ? 'true' : 'false') . ';';
            $html[] = 'const searchable = ' . ($searchable ? 'true' : 'false') . ';';
            $html[] = 'const clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'const disabled = ' . ($disabled ? 'true' : 'false') . ';';

            $html[] = <<<JS
const container = document.getElementById(id + '_container');
const trigger = document.getElementById(id + '_trigger');
const display = document.getElementById(id + '_display');
const tagsContainer = document.getElementById(id + '_tags');
const hidden = document.getElementById(id + '_value');
const dropdown = document.getElementById(id + '_dropdown');
const treeContainer = document.getElementById(id + '_tree');
const clearBtn = document.getElementById(id + '_clear');
const searchInput = document.getElementById(id + '_search');

if (disabled) {
    container.setAttribute('data-disabled', 'true');
}

let options = staticOptions || [];
let selectedValues = multiple ? [] : null;
let isOpen = false;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text ?? '')));
    return div.innerHTML;
}

function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function findNodeByValue(value) {
    const target = String(value ?? '');
    return Array.from(treeContainer.querySelectorAll('.w-tree-select-node')).find(node => node.dataset.value === target) || null;
}

// 加载数据
function loadOptions() {
    if (options.length) {
        renderTree();
        return Promise.resolve();
    }
    
    if (!apiUrl) {
        treeContainer.innerHTML = '<div class="w-tree-select-empty">{$t_no_data}</div>';
        return Promise.resolve();
    }
    
    treeContainer.innerHTML = '<div class="w-tree-select-loading">{$t_loading}</div>';
    
    return fetch(apiUrl)
        .then(r => r.json())
        .then(res => {
            options = res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
            renderTree();
        })
        .catch(() => {
            treeContainer.innerHTML = '<div class="w-tree-select-empty">{$t_no_data}</div>';
        });
}

// 渲染树
function renderTree() {
    treeContainer.innerHTML = renderNodes(options, 0);
    bindNodeEvents();
    
    // 设置初始选中
    if (initialValue.length) {
        initialValue.forEach(val => {
            if (multiple) {
                if (!selectedValues.includes(val)) {
                    selectedValues.push(val);
                }
            } else {
                selectedValues = val;
            }
        });
        updateUI();
    }
}

// 递归渲染节点
function renderNodes(nodes, level) {
    if (!nodes || !nodes.length) return '';
    
    let html = '';
    nodes.forEach(node => {
        const val = String(node[valueField] || node.value || '');
        const lbl = String(node[labelField] || node.label || node.name || val);
        const children = node[childrenField] || node.children || [];
        const hasChildren = children.length > 0;
        
        const isSelected = multiple 
            ? (Array.isArray(selectedValues) && selectedValues.includes(val))
            : selectedValues === val;
        
        const expandedClass = defaultExpandAll ? 'expanded' : '';
        const selectedClass = isSelected ? 'selected' : '';
        
        html += '<div class="w-tree-select-node ' + expandedClass + ' ' + selectedClass + '" data-value="' + escapeAttr(val) + '" data-label="' + escapeAttr(lbl) + '">';
        html += '<div class="w-tree-select-node-content">';
        
        // 展开图标
        if (hasChildren) {
            html += '<span class="w-tree-select-node-expand">&#9656;</span>';
        } else {
            html += '<span class="w-tree-select-node-expand"></span>';
        }
        
        // 复选框
        if (checkable && multiple) {
            const checked = isSelected ? 'checked' : '';
            html += '<input type="checkbox" class="w-tree-select-node-checkbox" ' + checked + '>';
        }
        
        // 标签
        html += '<span class="w-tree-select-node-label">' + escapeHtml(lbl) + '</span>';
        html += '</div>';
        
        // 子节点
        if (hasChildren) {
            html += '<div class="w-tree-select-node-children">';
            html += renderNodes(children, level + 1);
            html += '</div>';
        }
        
        html += '</div>';
    });
    
    return html;
}

// 绑定节点事件
function bindNodeEvents() {
    // 展开/折叠
    treeContainer.querySelectorAll('.w-tree-select-node-expand').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            const node = this.closest('.w-tree-select-node');
            node.classList.toggle('expanded');
        });
    });
    
    // 选择节点
    treeContainer.querySelectorAll('.w-tree-select-node-content').forEach(el => {
        el.addEventListener('click', function(e) {
            if (e.target.classList.contains('w-tree-select-node-checkbox') || 
                e.target.classList.contains('w-tree-select-node-expand')) {
                return;
            }
            
            const node = this.closest('.w-tree-select-node');
            const val = node.dataset.value;
            const lbl = node.dataset.label;
            
            if (multiple) {
                toggleSelect(val, lbl, node);
            } else {
                selectSingle(val, lbl, node);
            }
        });
    });
    
    // 复选框
    if (checkable && multiple) {
        treeContainer.querySelectorAll('.w-tree-select-node-checkbox').forEach(el => {
            el.addEventListener('change', function(e) {
                e.stopPropagation();
                const node = this.closest('.w-tree-select-node');
                const val = node.dataset.value;
                const lbl = node.dataset.label;
                
                if (this.checked) {
                    if (!selectedValues.includes(val)) {
                        selectedValues.push(val);
                    }
                    node.classList.add('selected');
                } else {
                    const idx = selectedValues.indexOf(val);
                    if (idx > -1) {
                        selectedValues.splice(idx, 1);
                    }
                    node.classList.remove('selected');
                }
                
                // 处理父子联动
                if (!checkStrictly) {
                    handleCheckRelation(node, this.checked);
                }
                
                updateUI();
            });
        });
    }
}

// 切换多选
function toggleSelect(val, lbl, node) {
    const idx = selectedValues.indexOf(val);
    if (idx > -1) {
        selectedValues.splice(idx, 1);
        node.classList.remove('selected');
        if (checkable) {
            const checkbox = node.querySelector('.w-tree-select-node-checkbox');
            if (checkbox) checkbox.checked = false;
        }
    } else {
        selectedValues.push(val);
        node.classList.add('selected');
        if (checkable) {
            const checkbox = node.querySelector('.w-tree-select-node-checkbox');
            if (checkbox) checkbox.checked = true;
        }
    }
    updateUI();
}

// 单选
function selectSingle(val, lbl, node) {
    treeContainer.querySelectorAll('.w-tree-select-node').forEach(n => n.classList.remove('selected'));
    node.classList.add('selected');
    selectedValues = val;
    updateUI();
    closeDropdown();
}

// 处理父子联动
function handleCheckRelation(node, checked) {
    // 选中/取消子节点
    const childCheckboxes = node.querySelectorAll('.w-tree-select-node-children .w-tree-select-node-checkbox');
    childCheckboxes.forEach(cb => {
        cb.checked = checked;
        const childNode = cb.closest('.w-tree-select-node');
        const val = childNode.dataset.value;
        
        if (checked) {
            if (!selectedValues.includes(val)) {
                selectedValues.push(val);
            }
            childNode.classList.add('selected');
        } else {
            const idx = selectedValues.indexOf(val);
            if (idx > -1) {
                selectedValues.splice(idx, 1);
            }
            childNode.classList.remove('selected');
        }
    });
}

// 更新UI
function updateUI() {
    if (multiple) {
        // 多选模式
        if (selectedValues.length) {
            container.classList.add('has-value');
            display.style.display = 'none';
            
            // 渲染标签
            const labels = [];
            selectedValues.forEach(val => {
                const node = findNodeByValue(val);
                if (node) labels.push(node.dataset.label);
            });
            
            tagsContainer.innerHTML = labels.map((lbl, idx) => {
                return '<span class="w-tree-select-tag">' + escapeHtml(lbl) +
                    '<span class="w-tree-select-tag-remove" data-index="' + idx + '">&times;</span></span>';
            }).join('');
            
            // 绑定删除事件
            tagsContainer.querySelectorAll('.w-tree-select-tag-remove').forEach(el => {
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const idx = parseInt(this.dataset.index);
                    const val = selectedValues[idx];
                    selectedValues.splice(idx, 1);
                    
                    const node = findNodeByValue(val);
                    if (node) {
                        node.classList.remove('selected');
                        const cb = node.querySelector('.w-tree-select-node-checkbox');
                        if (cb) cb.checked = false;
                    }
                    
                    updateUI();
                });
            });
            
            hidden.value = selectedValues.join(',');
        } else {
            container.classList.remove('has-value');
            display.style.display = '';
            display.textContent = placeholder;
            tagsContainer.innerHTML = '';
            hidden.value = '';
        }
    } else {
        // 单选模式
        if (selectedValues) {
            container.classList.add('has-value');
            const node = findNodeByValue(selectedValues);
            display.textContent = node ? node.dataset.label : selectedValues;
            hidden.value = selectedValues;
        } else {
            container.classList.remove('has-value');
            display.textContent = placeholder;
            hidden.value = '';
        }
    }
    
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
}

// 搜索过滤
function filterTree(keyword) {
    keyword = (keyword || '').toLowerCase();
    
    treeContainer.querySelectorAll('.w-tree-select-node').forEach(node => {
        const lbl = (node.dataset.label || '').toLowerCase();
        const match = lbl.includes(keyword);
        
        if (keyword) {
            node.classList.toggle('hidden', !match);
            if (match) {
                // 展开父节点
                let parent = node.parentElement.closest('.w-tree-select-node');
                while (parent) {
                    parent.classList.add('expanded');
                    parent.classList.remove('hidden');
                    parent = parent.parentElement.closest('.w-tree-select-node');
                }
            }
        } else {
            node.classList.remove('hidden');
        }
    });
}

// 打开下拉
function openDropdown() {
    if (disabled) return;
    dropdown.style.display = 'block';
    container.classList.add('open');
    isOpen = true;
    loadOptions();
}

// 关闭下拉
function closeDropdown() {
    dropdown.style.display = 'none';
    container.classList.remove('open');
    isOpen = false;
}

// 清空选择
function clearSelection() {
    if (multiple) {
        selectedValues = [];
    } else {
        selectedValues = null;
    }
    treeContainer.querySelectorAll('.w-tree-select-node').forEach(n => {
        n.classList.remove('selected');
        const cb = n.querySelector('.w-tree-select-node-checkbox');
        if (cb) cb.checked = false;
    });
    updateUI();
}

// 事件绑定
trigger.addEventListener('click', function(e) {
    if (disabled) return;
    if (e.target.classList.contains('w-tree-select-tag-remove')) return;
    e.stopPropagation();
    if (isOpen) {
        closeDropdown();
    } else {
        openDropdown();
    }
});

if (searchInput) {
    searchInput.addEventListener('input', function() {
        filterTree(this.value);
    });
    searchInput.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

if (clearBtn) {
    clearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        clearSelection();
    });
}

document.addEventListener('click', function(e) {
    if (!container.contains(e.target)) {
        closeDropdown();
    }
});
JS;
            $html[] = '})();</script>';

            return implode("\n", $html);
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return <<<DOC
<h3><code>&lt;w:theme:tree-select&gt;</code> 树形选择器组件</h3>

<p><strong>功能</strong>：树形结构数据选择，支持单选/多选、复选框和搜索</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>name</code>：表单字段名（必填）</li>
    <li><code>url</code>：数据源API地址</li>
    <li><code>options</code>：静态选项JSON字符串</li>
    <li><code>value</code>：当前选中值，逗号分隔或JSON数组</li>
    <li><code>value-field</code>：值字段名，默认 'value'</li>
    <li><code>label-field</code>：显示字段名，默认 'label'</li>
    <li><code>children-field</code>：子节点字段名，默认 'children'</li>
    <li><code>placeholder</code>：占位文本</li>
    <li><code>multiple</code>：是否多选</li>
    <li><code>checkable</code>：是否显示复选框（多选时生效）</li>
    <li><code>check-strictly</code>：父子节点是否不关联</li>
    <li><code>default-expand-all</code>：是否默认展开所有节点</li>
    <li><code>searchable</code>：是否可搜索</li>
    <li><code>clearable</code>：是否可清空</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 单选 --&gt;
&lt;w:theme:tree-select 
    id="category"
    name="category_id"
    url="/api/categories/tree"
    placeholder="请选择分类"
    clearable="true"
/&gt;

&lt;!-- 多选带复选框 --&gt;
&lt;w:theme:tree-select 
    id="permissions"
    name="permission_ids"
    url="/api/permissions/tree"
    multiple="true"
    checkable="true"
    default-expand-all="true"
/&gt;

&lt;!-- 静态数据 --&gt;
&lt;w:theme:tree-select 
    id="dept"
    name="dept_id"
    options='[{"value":"1","label":"总公司","children":[{"value":"1-1","label":"技术部"},{"value":"1-2","label":"市场部"}]}]'
    searchable="true"
/&gt;
</pre>

<h4>数据格式</h4>
<pre>
[
    {
        "value": "1",
        "label": "父节点",
        "children": [
            {"value": "1-1", "label": "子节点1"},
            {"value": "1-2", "label": "子节点2"}
        ]
    }
]
</pre>
DOC;
    }
}

