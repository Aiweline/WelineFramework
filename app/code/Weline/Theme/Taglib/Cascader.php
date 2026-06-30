<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * 级联选择器组件
 * 
 * 支持多级联动选择，适用于省市区、分类等场景
 */
class Cascader implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:cascader';
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
            'value' => false,           // 当前选中值，逗号分隔或JSON数组
            'value-field' => false,     // 值字段名，默认 'value'
            'label-field' => false,     // 显示字段名，默认 'label'
            'children-field' => false,  // 子节点字段名，默认 'children'
            'placeholder' => false,     // 占位文本
            'separator' => false,       // 显示分隔符，默认 ' / '
            'lazy' => false,            // 是否懒加载子级
            'lazy-url' => false,        // 懒加载API地址
            'multiple' => false,        // 是否多选
            'check-strictly' => false,  // 是否严格选择（父子不关联）
            'clearable' => false,       // 是否可清空
            'searchable' => false,      // 是否可搜索
            'class' => false,           // 额外CSS类
            'style' => false,           // 内联样式
            'disabled' => false,        // 是否禁用
            'required' => false,        // 是否必填
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'cascader-' . uniqid();
            $name = $attributes['name'] ?? '';
            $url = $attributes['url'] ?? '';
            $optionsJson = $attributes['options'] ?? '';
            $value = $attributes['value'] ?? '';
            $valueField = $attributes['value-field'] ?? 'value';
            $labelField = $attributes['label-field'] ?? 'label';
            $childrenField = $attributes['children-field'] ?? 'children';
            $placeholder = $attributes['placeholder'] ?? __('请选择');
            $separator = $attributes['separator'] ?? ' / ';
            $lazy = isset($attributes['lazy']) && ($attributes['lazy'] === 'true' || $attributes['lazy'] === '1');
            $lazyUrl = $attributes['lazy-url'] ?? '';
            $multiple = isset($attributes['multiple']) && ($attributes['multiple'] === 'true' || $attributes['multiple'] === '1');
            $checkStrictly = isset($attributes['check-strictly']) && ($attributes['check-strictly'] === 'true' || $attributes['check-strictly'] === '1');
            $clearable = isset($attributes['clearable']) && ($attributes['clearable'] === 'true' || $attributes['clearable'] === '1');
            $searchable = isset($attributes['searchable']) && ($attributes['searchable'] === 'true' || $attributes['searchable'] === '1');
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

            $lazyApiUrl = '';
            if ($lazyUrl) {
                if (str_starts_with($lazyUrl, 'http://') || str_starts_with($lazyUrl, 'https://') || str_starts_with($lazyUrl, '/')) {
                    $lazyApiUrl = $lazyUrl;
                } else {
                    /** @var Url $urlBuilder */
                    $urlBuilder = w_obj(Url::class);
                    $lazyApiUrl = $urlBuilder->getBackendUrl($lazyUrl);
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
            $code = \Weline\Taglib\Taglib::attributes($attributes);

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
            $html[] = '<div class="w-cascader ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="cascader">';
            
            // 隐藏字段
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="" ' . $requiredAttr . '>';
            
            // 触发器
            $html[] = '  <div class="w-cascader-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            if ($searchable) {
                $html[] = '    <input type="text" class="w-cascader-search" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off" ' . $disabledAttr . '>';
            }
            $html[] = '    <span class="w-cascader-display" id="<?= htmlspecialchars($Taglib__id) ?>_display">' . htmlspecialchars($placeholder) . '</span>';
            if ($clearable) {
                $html[] = '    <span class="w-cascader-clear" id="<?= htmlspecialchars($Taglib__id) ?>_clear" title="' . $t_clear . '">&times;</span>';
            }
            $html[] = '    <span class="w-cascader-arrow">&#9662;</span>';
            $html[] = '  </div>';
            
            // 下拉面板
            $html[] = '  <div class="w-cascader-dropdown" id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" style="display:none;">';
            $html[] = '    <div class="w-cascader-menus" id="<?= htmlspecialchars($Taglib__id) ?>_menus"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-cascader { position: relative; display: inline-block; width: 100%; font-family: inherit; }';
            $html[] = '.w-cascader-trigger { display: flex; align-items: center; border: 1px solid #ced4da; border-radius: 4px; background: #fff; cursor: pointer; min-height: 38px; padding: 0 30px 0 10px; position: relative; }';
            $html[] = '.w-cascader-trigger:hover { border-color: #80bdff; }';
            $html[] = '.w-cascader-search { border: none; outline: none; flex: 1; background: transparent; font-size: inherit; padding: 6px 0; width: 100%; }';
            $html[] = '.w-cascader-display { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #495057; padding: 6px 0; }';
            $html[] = '.w-cascader-display.placeholder { color: #6c757d; }';
            $html[] = '.w-cascader-arrow { position: absolute; right: 10px; color: #6c757d; font-size: 10px; transition: transform 0.2s; }';
            $html[] = '.w-cascader.open .w-cascader-arrow { transform: rotate(180deg); }';
            $html[] = '.w-cascader-clear { position: absolute; right: 25px; color: #6c757d; cursor: pointer; display: none; font-size: 16px; line-height: 1; }';
            $html[] = '.w-cascader-clear:hover { color: #dc3545; }';
            $html[] = '.w-cascader.has-value .w-cascader-clear { display: block; }';
            $html[] = '.w-cascader-dropdown { position: absolute; left: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1050; }';
            $html[] = '.w-cascader-menus { display: flex; }';
            $html[] = '.w-cascader-menu { min-width: 150px; max-height: 250px; overflow-y: auto; border-right: 1px solid #e9ecef; }';
            $html[] = '.w-cascader-menu:last-child { border-right: none; }';
            $html[] = '.w-cascader-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; cursor: pointer; transition: background 0.15s; }';
            $html[] = '.w-cascader-item:hover { background: #f8f9fa; }';
            $html[] = '.w-cascader-item.active { background: #e9ecef; }';
            $html[] = '.w-cascader-item.selected { color: #007bff; font-weight: 500; }';
            $html[] = '.w-cascader-item-arrow { color: #6c757d; font-size: 10px; margin-left: 8px; }';
            $html[] = '.w-cascader-loading { padding: 20px; text-align: center; color: #6c757d; }';
            $html[] = '.w-cascader-empty { padding: 20px; text-align: center; color: #6c757d; }';
            $html[] = '.w-cascader[data-disabled="true"] .w-cascader-trigger { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const fieldName = <?= json_encode($Taglib__name) ?>;';
            $html[] = 'const apiUrl = ' . json_encode($apiUrl) . ';';
            $html[] = 'const lazyApiUrl = ' . json_encode($lazyApiUrl) . ';';
            $html[] = 'const staticOptions = ' . json_encode($staticOptions) . ';';
            $html[] = 'const initialValue = ' . json_encode($initialValue) . ';';
            $html[] = 'const valueField = ' . json_encode($valueField) . ';';
            $html[] = 'const labelField = ' . json_encode($labelField) . ';';
            $html[] = 'const childrenField = ' . json_encode($childrenField) . ';';
            $html[] = 'const separator = ' . json_encode($separator) . ';';
            $html[] = 'const placeholder = ' . json_encode($placeholder) . ';';
            $html[] = 'const lazy = ' . ($lazy ? 'true' : 'false') . ';';
            $html[] = 'const multiple = ' . ($multiple ? 'true' : 'false') . ';';
            $html[] = 'const checkStrictly = ' . ($checkStrictly ? 'true' : 'false') . ';';
            $html[] = 'const clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'const searchable = ' . ($searchable ? 'true' : 'false') . ';';
            $html[] = 'const disabled = ' . ($disabled ? 'true' : 'false') . ';';

            $html[] = <<<JS
const container = document.getElementById(id + '_container');
const trigger = document.getElementById(id + '_trigger');
const display = document.getElementById(id + '_display');
const hidden = document.getElementById(id + '_value');
const dropdown = document.getElementById(id + '_dropdown');
const menusContainer = document.getElementById(id + '_menus');
const clearBtn = document.getElementById(id + '_clear');
const searchInput = document.getElementById(id + '_search');

if (disabled) {
    container.setAttribute('data-disabled', 'true');
}

let options = staticOptions || [];
let selectedPath = []; // 选中的路径 [{value, label, level}]
let isOpen = false;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text ?? '')));
    return div.innerHTML;
}

// 加载数据
function loadOptions() {
    if (options.length) {
        renderMenus();
        return Promise.resolve();
    }
    
    if (!apiUrl) {
        renderMenus();
        return Promise.resolve();
    }
    
    menusContainer.innerHTML = '<div class="w-cascader-loading">{$t_loading}</div>';
    
    return fetch(apiUrl)
        .then(r => r.json())
        .then(res => {
            options = res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
            renderMenus();
        })
        .catch(() => {
            menusContainer.innerHTML = '<div class="w-cascader-empty">{$t_no_data}</div>';
        });
}

// 懒加载子级
function loadChildren(parentValue, callback) {
    if (!lazyApiUrl) {
        callback([]);
        return;
    }
    
    fetch(lazyApiUrl + (lazyApiUrl.includes('?') ? '&' : '?') + 'parent=' + encodeURIComponent(parentValue))
        .then(r => r.json())
        .then(res => {
            const children = res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
            callback(children);
        })
        .catch(() => callback([]));
}

// 渲染菜单
function renderMenus() {
    menusContainer.innerHTML = '';
    
    // 渲染各级菜单
    let currentOptions = options;
    for (let level = 0; level <= selectedPath.length; level++) {
        if (!currentOptions || !currentOptions.length) break;
        
        const selectedAtLevel = selectedPath[level];
        const menu = renderMenu(currentOptions, level, selectedAtLevel ? selectedAtLevel.value : null);
        menusContainer.appendChild(menu);
        
        // 获取下一级选项
        if (selectedAtLevel) {
            const selectedItem = currentOptions.find(o => (o[valueField] || o.value) == selectedAtLevel.value);
            currentOptions = selectedItem ? (selectedItem[childrenField] || selectedItem.children || []) : [];
        } else {
            break;
        }
    }
    
    if (!menusContainer.children.length) {
        menusContainer.innerHTML = '<div class="w-cascader-empty">{$t_no_data}</div>';
    }
}

// 渲染单个菜单
function renderMenu(items, level, selectedValue) {
    const menu = document.createElement('div');
    menu.className = 'w-cascader-menu';
    menu.dataset.level = level;
    
    items.forEach(item => {
        const val = String(item[valueField] || item.value || '');
        const lbl = String(item[labelField] || item.label || item.name || val);
        const hasChildren = lazy || (item[childrenField] && item[childrenField].length) || (item.children && item.children.length);
        
        const el = document.createElement('div');
        el.className = 'w-cascader-item';
        if (val == selectedValue) el.classList.add('active');
        
        // 检查是否在最终选中路径中
        const inPath = selectedPath.some(p => p.value == val);
        if (inPath) el.classList.add('selected');
        
        el.dataset.value = val;
        el.dataset.label = lbl;
        el.dataset.level = level;
        el.dataset.hasChildren = hasChildren ? '1' : '0';
        
        el.innerHTML = '<span class="w-cascader-item-text">' + escapeHtml(lbl) + '</span>' +
            (hasChildren ? '<span class="w-cascader-item-arrow">&#9656;</span>' : '');
        
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            selectItem(this);
        });
        
        menu.appendChild(el);
    });
    
    return menu;
}

// 选择项目
function selectItem(el) {
    const level = parseInt(el.dataset.level);
    const value = el.dataset.value;
    const label = el.dataset.label;
    const hasChildren = el.dataset.hasChildren === '1';
    
    // 更新选中路径
    selectedPath = selectedPath.slice(0, level);
    selectedPath.push({ value, label, level });
    
    // 更新UI
    const menu = el.parentElement;
    menu.querySelectorAll('.w-cascader-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    
    // 移除后续菜单
    const menus = menusContainer.querySelectorAll('.w-cascader-menu');
    for (let i = level + 1; i < menus.length; i++) {
        menus[i].remove();
    }
    
    if (hasChildren) {
        if (lazy && lazyApiUrl) {
            // 懒加载
            const loadingMenu = document.createElement('div');
            loadingMenu.className = 'w-cascader-menu';
            loadingMenu.innerHTML = '<div class="w-cascader-loading">{$t_loading}</div>';
            menusContainer.appendChild(loadingMenu);
            
            loadChildren(value, function(children) {
                loadingMenu.remove();
                if (children.length) {
                    const childMenu = renderMenu(children, level + 1, null);
                    menusContainer.appendChild(childMenu);
                    
                    // 更新选项数据
                    updateOptionsChildren(value, children);
                }
            });
        } else {
            // 直接渲染子菜单
            const currentItem = findItemByPath(options, selectedPath);
            if (currentItem) {
                const children = currentItem[childrenField] || currentItem.children || [];
                if (children.length) {
                    const childMenu = renderMenu(children, level + 1, null);
                    menusContainer.appendChild(childMenu);
                }
            }
        }
    } else {
        // 没有子级，选择完成
        if (!checkStrictly || !hasChildren) {
            confirmSelection();
        }
    }
    
    // 严格模式下可以选择任意层级
    if (checkStrictly) {
        updateDisplay();
        updateValue();
    }
}

// 更新选项数据中的子级
function updateOptionsChildren(parentValue, children) {
    function update(items) {
        for (let item of items) {
            if ((item[valueField] || item.value) == parentValue) {
                item[childrenField] = children;
                item.children = children;
                return true;
            }
            if (item[childrenField] || item.children) {
                if (update(item[childrenField] || item.children)) return true;
            }
        }
        return false;
    }
    update(options);
}

// 根据路径查找项目
function findItemByPath(items, path) {
    let current = items;
    let result = null;
    
    for (let p of path) {
        result = current.find(o => (o[valueField] || o.value) == p.value);
        if (!result) break;
        current = result[childrenField] || result.children || [];
    }
    
    return result;
}

// 确认选择
function confirmSelection() {
    updateDisplay();
    updateValue();
    closeDropdown();
}

// 更新显示
function updateDisplay() {
    if (selectedPath.length) {
        display.textContent = selectedPath.map(p => p.label).join(separator);
        display.classList.remove('placeholder');
        container.classList.add('has-value');
    } else {
        display.textContent = placeholder;
        display.classList.add('placeholder');
        container.classList.remove('has-value');
    }
}

// 更新隐藏字段值
function updateValue() {
    const values = selectedPath.map(p => p.value);
    hidden.value = values.join(',');
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
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
    selectedPath = [];
    updateDisplay();
    updateValue();
    renderMenus();
}

// 事件绑定
trigger.addEventListener('click', function(e) {
    if (disabled) return;
    e.stopPropagation();
    if (isOpen) {
        closeDropdown();
    } else {
        openDropdown();
    }
});

if (clearBtn) {
    clearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        clearSelection();
    });
}

// 点击外部关闭
document.addEventListener('click', function(e) {
    if (!container.contains(e.target)) {
        closeDropdown();
    }
});

// 初始化选中值
if (initialValue.length) {
    loadOptions().then(() => {
        // 根据初始值设置选中路径
        let current = options;
        for (let val of initialValue) {
            const item = current.find(o => (o[valueField] || o.value) == val);
            if (item) {
                selectedPath.push({
                    value: item[valueField] || item.value,
                    label: item[labelField] || item.label || item.name || val,
                    level: selectedPath.length
                });
                current = item[childrenField] || item.children || [];
            } else {
                break;
            }
        }
        updateDisplay();
    });
}
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
<h3><code>&lt;w:theme:cascader&gt;</code> 级联选择器组件</h3>

<p><strong>功能</strong>：多级联动选择，适用于省市区、分类等场景</p>

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
    <li><code>separator</code>：显示分隔符，默认 ' / '</li>
    <li><code>lazy</code>：是否懒加载子级</li>
    <li><code>lazy-url</code>：懒加载API地址（需要支持?parent=xxx参数）</li>
    <li><code>check-strictly</code>：是否可选择任意层级（父子不关联）</li>
    <li><code>clearable</code>：是否可清空</li>
    <li><code>searchable</code>：是否可搜索（预留）</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 远程数据 --&gt;
&lt;w:theme:cascader 
    id="region"
    name="region_id"
    url="/api/regions"
    placeholder="请选择地区"
    clearable="true"
/&gt;

&lt;!-- 懒加载 --&gt;
&lt;w:theme:cascader 
    id="category"
    name="category_id"
    url="/api/categories/top"
    lazy="true"
    lazy-url="/api/categories/children"
    placeholder="请选择分类"
/&gt;

&lt;!-- 静态数据 --&gt;
&lt;w:theme:cascader 
    id="level"
    name="level"
    options='[{"value":"1","label":"一级","children":[{"value":"1-1","label":"一级-1"}]}]'
    value="1,1-1"
/&gt;
</pre>

<h4>数据格式</h4>
<pre>
[
    {
        "value": "1",
        "label": "选项一",
        "children": [
            {"value": "1-1", "label": "选项一-1"},
            {"value": "1-2", "label": "选项一-2"}
        ]
    },
    {
        "value": "2",
        "label": "选项二",
        "children": [...]
    }
]
</pre>
DOC;
    }
}

