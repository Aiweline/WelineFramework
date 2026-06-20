<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * 搜索下拉选择组件
 * 
 * 支持远程 API 搜索和本地静态数据，带防抖和键盘导航功能
 */
class SearchSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:search-select';
    }

    public static function tag(): bool
    {
        return false; // 自闭合标签
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
            'id' => true,           // 组件唯一ID（必填）
            'name' => true,         // 表单字段名（必填）
            'url' => false,         // 远程搜索API地址
            'options' => false,     // 静态选项 "value:label,value2:label2"
            'value' => false,       // 当前选中值
            'value-field' => false, // 值字段名，默认 'value'
            'label-field' => false, // 显示字段名，默认 'label'
            'placeholder' => false, // 占位文本
            'debounce' => false,    // 防抖延迟毫秒数，默认 300
            'min-chars' => false,   // 最少输入字符数，默认 0
            'class' => false,       // 额外CSS类
            'style' => false,       // 内联样式
            'disabled' => false,    // 是否禁用
            'required' => false,    // 是否必填
            'clearable' => false,   // 是否可清空
            'multiple' => false,    // 是否多选
            'limit' => false,       // 显示数量限制
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'search-select-' . uniqid();
            $name = $attributes['name'] ?? '';
            $url = $attributes['url'] ?? '';
            $options = $attributes['options'] ?? '';
            $value = $attributes['value'] ?? '';
            $valueField = $attributes['value-field'] ?? 'value';
            $labelField = $attributes['label-field'] ?? 'label';
            $placeholder = $attributes['placeholder'] ?? __('请选择或搜索...');
            $debounce = (int)($attributes['debounce'] ?? 300);
            $minChars = (int)($attributes['min-chars'] ?? 0);
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $disabled = isset($attributes['disabled']) && ($attributes['disabled'] === 'true' || $attributes['disabled'] === '1');
            $required = isset($attributes['required']) && ($attributes['required'] === 'true' || $attributes['required'] === '1');
            $clearable = isset($attributes['clearable']) && ($attributes['clearable'] === 'true' || $attributes['clearable'] === '1');
            $multiple = isset($attributes['multiple']) && ($attributes['multiple'] === 'true' || $attributes['multiple'] === '1');
            $limit = (int)($attributes['limit'] ?? 50);

            // 处理远程URL
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

            // 静态选项在运行时由 $Taglib__options 解析（支持 PHP 变量和字面量）

            // 解析属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);

            $disabledAttr = $disabled ? 'disabled' : '';
            $requiredAttr = $required ? 'required' : '';
            $multipleAttr = $multiple ? 'multiple' : '';

            // 翻译文本
            $t_no_results = addslashes(__('没有找到匹配的结果'));
            $t_loading = addslashes(__('搜索中...'));
            $t_load_error = addslashes(__('加载失败'));
            $t_type_to_search = addslashes(__('输入关键词搜索'));
            $t_clear = addslashes(__('清空'));

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="w-search-select ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="search-select">';
            
            // 隐藏的表单字段
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value ?? \'\') ?>" ' . $requiredAttr . '>';
            
            // 触发按钮/输入框
            $html[] = '  <div class="w-search-select-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            $html[] = '    <input type="text" class="w-search-select-input" id="<?= htmlspecialchars($Taglib__id) ?>_input" placeholder="<?= htmlspecialchars($Taglib__placeholder) ?>" autocomplete="off" ' . $disabledAttr . '>';
            $html[] = '    <span class="w-search-select-display" id="<?= htmlspecialchars($Taglib__id) ?>_display"></span>';
            if ($clearable) {
                $html[] = '    <span class="w-search-select-clear" id="<?= htmlspecialchars($Taglib__id) ?>_clear" title="' . $t_clear . '">&times;</span>';
            }
            $html[] = '    <span class="w-search-select-arrow">&#9662;</span>';
            $html[] = '  </div>';
            
            // 下拉面板
            $html[] = '  <div class="w-search-select-dropdown" id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" style="display:none;">';
            $html[] = '    <div class="w-search-select-loading" id="<?= htmlspecialchars($Taglib__id) ?>_loading" style="display:none;">' . $t_loading . '</div>';
            $html[] = '    <div class="w-search-select-list" id="<?= htmlspecialchars($Taglib__id) ?>_list"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-search-select { position: relative; display: inline-block; width: 100%; font-family: inherit; }';
            $html[] = '.w-search-select-trigger { display: flex; align-items: center; border: 1px solid #ced4da; border-radius: 4px; background: #fff; cursor: pointer; min-height: 38px; padding: 0 30px 0 10px; position: relative; }';
            $html[] = '.w-search-select-trigger:hover { border-color: #80bdff; }';
            $html[] = '.w-search-select-trigger:focus-within { border-color: #80bdff; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }';
            $html[] = '.w-search-select-input { border: none; outline: none; flex: 1; background: transparent; font-size: inherit; padding: 6px 0; width: 100%; }';
            $html[] = '.w-search-select-display { display: none; position: absolute; left: 10px; right: 30px; pointer-events: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #495057; }';
            $html[] = '.w-search-select.has-value .w-search-select-display { display: block; }';
            $html[] = '.w-search-select.has-value .w-search-select-input::placeholder { color: transparent; }';
            $html[] = '.w-search-select-input:focus + .w-search-select-display { display: none; }';
            $html[] = '.w-search-select-arrow { position: absolute; right: 10px; color: #6c757d; font-size: 10px; transition: transform 0.2s; }';
            $html[] = '.w-search-select.open .w-search-select-arrow { transform: rotate(180deg); }';
            $html[] = '.w-search-select-clear { position: absolute; right: 25px; color: #6c757d; cursor: pointer; display: none; font-size: 16px; line-height: 1; }';
            $html[] = '.w-search-select-clear:hover { color: #dc3545; }';
            $html[] = '.w-search-select.has-value .w-search-select-clear { display: block; }';
            $html[] = '.w-search-select-dropdown { position: absolute; left: 0; right: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1050; max-height: 300px; overflow-y: auto; }';
            $html[] = '.w-search-select-loading { padding: 10px; text-align: center; color: #6c757d; }';
            $html[] = '.w-search-select-list { }';
            $html[] = '.w-search-select-item { padding: 8px 12px; cursor: pointer; transition: background 0.15s; }';
            $html[] = '.w-search-select-item:hover { background: #f8f9fa; }';
            $html[] = '.w-search-select-item.active { background: #007bff; color: #fff; }';
            $html[] = '.w-search-select-item.selected { background: #e9ecef; }';
            $html[] = '.w-search-select-empty { padding: 10px; text-align: center; color: #6c757d; }';
            $html[] = '.w-search-select[data-disabled="true"] .w-search-select-trigger { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '.w-search-select[data-disabled="true"] .w-search-select-input { cursor: not-allowed; }';
            $html[] = '</style>';

            // 运行时解析 options 属性为 JSON 数组
            $html[] = '<?php $__ssOpts = []; if (isset($Taglib__options) && $Taglib__options !== \'\') { foreach (explode(\',\', $Taglib__options) as $__ssPair) { $__ssParts = explode(\':\', trim($__ssPair), 2); if (count($__ssParts) === 2) { $__ssOpts[] = [\'value\' => trim($__ssParts[0]), \'label\' => trim($__ssParts[1])]; } } } ?>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const apiUrl = ' . json_encode($apiUrl) . ';';
            $html[] = 'const staticOptions = <?= json_encode($__ssOpts) ?>;';
            $html[] = 'const valueField = ' . json_encode($valueField) . ';';
            $html[] = 'const labelField = ' . json_encode($labelField) . ';';
            $html[] = 'const debounceTime = ' . $debounce . ';';
            $html[] = 'const minChars = ' . $minChars . ';';
            $html[] = 'const limit = ' . $limit . ';';
            $html[] = 'const clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'const multiple = ' . ($multiple ? 'true' : 'false') . ';';
            $html[] = 'const disabled = ' . ($disabled ? 'true' : 'false') . ';';

            $html[] = <<<JS
const container = document.getElementById(id + '_container');
const input = document.getElementById(id + '_input');
const display = document.getElementById(id + '_display');
const hidden = document.getElementById(id + '_value');
const dropdown = document.getElementById(id + '_dropdown');
const list = document.getElementById(id + '_list');
const loading = document.getElementById(id + '_loading');
const clearBtn = document.getElementById(id + '_clear');

if (disabled) {
    container.setAttribute('data-disabled', 'true');
}

let cache = null;
let activeIndex = -1;
let isOpen = false;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text ?? '')));
    return div.innerHTML;
}

function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// 防抖函数
const debounce = (fn, delay) => {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(null, args), delay);
    };
};

// 渲染选项列表
function renderOptions(items) {
    if (!items || !items.length) {
        list.innerHTML = '<div class="w-search-select-empty">{$t_no_results}</div>';
        return;
    }
    
    const currentValue = hidden.value;
    list.innerHTML = items.slice(0, limit).map((item, idx) => {
        const val = String(item[valueField] || item.value || '');
        const lbl = String(item[labelField] || item.label || item.name || val);
        const selectedClass = val == currentValue ? 'selected' : '';
        return '<div class="w-search-select-item ' + selectedClass + '" data-value="' + escapeAttr(val) + '" data-label="' + escapeAttr(lbl) + '" data-index="' + idx + '">' + escapeHtml(lbl) + '</div>';
    }).join('');
    
    // 绑定点击事件
    list.querySelectorAll('.w-search-select-item').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            selectItem(this.dataset.value, this.dataset.label);
        });
    });
    
    activeIndex = -1;
}

// 选择项目
function selectItem(value, label) {
    hidden.value = value;
    display.textContent = label;
    input.value = '';
    closeDropdown();
    
    if (value) {
        container.classList.add('has-value');
    } else {
        container.classList.remove('has-value');
    }
    
    // 触发change事件
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
    
    // 更新选中状态
    list.querySelectorAll('.w-search-select-item').forEach(el => {
        el.classList.toggle('selected', el.dataset.value == value);
    });
}

// 搜索函数
function doSearch(keyword) {
    keyword = (keyword || '').trim();
    
    if (apiUrl) {
        // 远程搜索
        if (keyword.length < minChars && minChars > 0) {
            list.innerHTML = '<div class="w-search-select-empty">{$t_type_to_search}</div>';
            return;
        }
        
        loading.style.display = 'block';
        list.style.display = 'none';
        
        const searchUrl = apiUrl + (apiUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(keyword) + '&limit=' + limit;
        
        fetch(searchUrl)
            .then(r => r.json())
            .then(res => {
                loading.style.display = 'none';
                list.style.display = 'block';
                const data = res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
                cache = data;
                renderOptions(data);
            })
            .catch(() => {
                loading.style.display = 'none';
                list.style.display = 'block';
                list.innerHTML = '<div class="w-search-select-empty">{$t_load_error}</div>';
            });
    } else if (staticOptions.length) {
        // 本地搜索
        const filtered = staticOptions.filter(opt => {
            const lbl = (opt[labelField] || opt.label || '').toLowerCase();
            const val = (opt[valueField] || opt.value || '').toLowerCase();
            const kw = keyword.toLowerCase();
            return lbl.includes(kw) || val.includes(kw);
        });
        renderOptions(filtered);
    }
}

const debouncedSearch = debounce(doSearch, debounceTime);

// 打开下拉
function openDropdown() {
    if (disabled) return;
    dropdown.style.display = 'block';
    container.classList.add('open');
    isOpen = true;
    
    // 首次打开加载数据
    if (!cache) {
        if (staticOptions.length) {
            cache = staticOptions;
            renderOptions(staticOptions);
        } else if (apiUrl) {
            doSearch('');
        }
    } else {
        renderOptions(cache);
    }
}

// 关闭下拉
function closeDropdown() {
    dropdown.style.display = 'none';
    container.classList.remove('open');
    isOpen = false;
    activeIndex = -1;
}

// 键盘导航
function handleKeydown(e) {
    if (!isOpen) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            openDropdown();
            e.preventDefault();
        }
        return;
    }
    
    const items = list.querySelectorAll('.w-search-select-item');
    
    switch(e.key) {
        case 'ArrowDown':
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            updateActiveItem(items);
            break;
        case 'ArrowUp':
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            updateActiveItem(items);
            break;
        case 'Enter':
            e.preventDefault();
            if (activeIndex >= 0 && items[activeIndex]) {
                const item = items[activeIndex];
                selectItem(item.dataset.value, item.dataset.label);
            }
            break;
        case 'Escape':
            closeDropdown();
            break;
    }
}

function updateActiveItem(items) {
    items.forEach((el, idx) => {
        el.classList.toggle('active', idx === activeIndex);
        if (idx === activeIndex) {
            el.scrollIntoView({ block: 'nearest' });
        }
    });
}

// 事件绑定
input.addEventListener('focus', openDropdown);
input.addEventListener('input', function() {
    display.style.display = 'none';
    debouncedSearch(this.value);
});
input.addEventListener('keydown', handleKeydown);

if (clearBtn) {
    clearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        selectItem('', '');
        input.focus();
    });
}

// 点击外部关闭
document.addEventListener('click', function(e) {
    if (!container.contains(e.target)) {
        closeDropdown();
    }
});

// 初始化显示
if (hidden.value) {
    container.classList.add('has-value');
    // 尝试从静态选项找显示文本
    const found = staticOptions.find(o => (o[valueField] || o.value) == hidden.value);
    if (found) {
        display.textContent = found[labelField] || found.label || hidden.value;
    } else if (apiUrl) {
        // 远程获取显示文本
        fetch(apiUrl + (apiUrl.includes('?') ? '&' : '?') + 'id=' + encodeURIComponent(hidden.value))
            .then(r => r.json())
            .then(res => {
                const data = res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
                if (data.length) {
                    display.textContent = data[0][labelField] || data[0].label || data[0].name || hidden.value;
                }
            })
            .catch(() => {});
    }
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
<h3><code>&lt;w:theme:search-select&gt;</code> 搜索下拉选择组件</h3>

<p><strong>功能</strong>：可搜索的下拉选择器，支持远程 API 搜索和本地静态数据</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>name</code>：表单字段名（必填）</li>
    <li><code>url</code>：远程搜索API地址，返回格式 {success: true, data: [{value, label}]}</li>
    <li><code>options</code>：静态选项，格式 "value1:显示文本1,value2:显示文本2"</li>
    <li><code>value</code>：当前选中值</li>
    <li><code>value-field</code>：值字段名，默认 'value'</li>
    <li><code>label-field</code>：显示字段名，默认 'label'</li>
    <li><code>placeholder</code>：占位文本</li>
    <li><code>debounce</code>：防抖延迟毫秒数，默认 300</li>
    <li><code>min-chars</code>：最少输入字符数触发搜索，默认 0</li>
    <li><code>clearable</code>：是否可清空，默认 false</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
    <li><code>class</code>：额外CSS类</li>
    <li><code>style</code>：内联样式</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 远程搜索 --&gt;
&lt;w:theme:search-select 
    id="user-select"
    name="user_id"
    url="/api/users/search"
    value-field="id"
    label-field="name"
    placeholder="搜索用户..."
    clearable="true"
/&gt;

&lt;!-- 静态选项 --&gt;
&lt;w:theme:search-select 
    id="status-select"
    name="status"
    options="1:启用,0:禁用"
    value="1"
    placeholder="选择状态"
/&gt;
</pre>

<h4>API 返回格式</h4>
<pre>
{
    "success": true,
    "data": [
        {"value": "1", "label": "选项一"},
        {"value": "2", "label": "选项二"}
    ]
}
</pre>
DOC;
    }
}

