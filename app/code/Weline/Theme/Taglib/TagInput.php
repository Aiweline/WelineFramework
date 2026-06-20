<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * 多选标签输入组件
 * 
 * 支持手动输入和远程建议，适用于标签、关键词等多值输入场景
 */
class TagInput implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:tag-input';
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
            'name' => true,             // 表单字段名（必填），会自动添加[]
            'value' => false,           // 初始值，逗号分隔或JSON数组
            'placeholder' => false,     // 占位文本
            'suggestions-url' => false, // 建议列表API地址
            'suggestions' => false,     // 静态建议列表，逗号分隔
            'max' => false,             // 最大标签数量
            'min-length' => false,      // 单个标签最小长度
            'max-length' => false,      // 单个标签最大长度
            'allow-duplicates' => false,// 是否允许重复标签
            'separator' => false,       // 分隔符，默认逗号和回车
            'class' => false,           // 额外CSS类
            'style' => false,           // 内联样式
            'disabled' => false,        // 是否禁用
            'required' => false,        // 是否必填
            'debounce' => false,        // 建议搜索防抖时间
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'tag-input-' . uniqid();
            $name = $attributes['name'] ?? 'tags';
            $value = $attributes['value'] ?? '';
            $placeholder = $attributes['placeholder'] ?? __('输入后按回车添加');
            $suggestionsUrl = $attributes['suggestions-url'] ?? '';
            $suggestions = $attributes['suggestions'] ?? '';
            $max = (int)($attributes['max'] ?? 0);
            $minLength = (int)($attributes['min-length'] ?? 1);
            $maxLength = (int)($attributes['max-length'] ?? 50);
            $allowDuplicates = isset($attributes['allow-duplicates']) && ($attributes['allow-duplicates'] === 'true' || $attributes['allow-duplicates'] === '1');
            $separator = $attributes['separator'] ?? ',';
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $disabled = isset($attributes['disabled']) && ($attributes['disabled'] === 'true' || $attributes['disabled'] === '1');
            $required = isset($attributes['required']) && ($attributes['required'] === 'true' || $attributes['required'] === '1');
            $debounce = (int)($attributes['debounce'] ?? 300);

            // 处理建议URL
            $apiUrl = '';
            if ($suggestionsUrl) {
                if (str_starts_with($suggestionsUrl, 'http://') || str_starts_with($suggestionsUrl, 'https://') || str_starts_with($suggestionsUrl, '/')) {
                    $apiUrl = $suggestionsUrl;
                } else {
                    /** @var Url $urlBuilder */
                    $urlBuilder = w_obj(Url::class);
                    $apiUrl = $urlBuilder->getBackendUrl($suggestionsUrl);
                }
            }

            // 解析静态建议
            $staticSuggestions = [];
            if ($suggestions) {
                $staticSuggestions = array_map('trim', explode(',', $suggestions));
            }

            // 解析初始值
            $initialTags = [];
            if ($value) {
                if (str_starts_with($value, '[')) {
                    $initialTags = json_decode($value, true) ?? [];
                } else {
                    $initialTags = array_map('trim', explode(',', $value));
                }
            }

            // 解析属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);

            // 翻译文本
            $t_remove = addslashes(__('移除'));
            $t_max_reached = addslashes(__('已达到最大标签数量'));
            $t_duplicate = addslashes(__('标签已存在'));
            $t_too_short = addslashes(__('标签太短'));
            $t_too_long = addslashes(__('标签太长'));

            $disabledAttr = $disabled ? 'disabled' : '';
            $requiredAttr = $required ? 'required' : '';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="w-tag-input ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="tag-input">';
            
            // 标签容器和输入框
            $html[] = '  <div class="w-tag-input-wrapper">';
            $html[] = '    <div class="w-tag-input-tags" id="<?= htmlspecialchars($Taglib__id) ?>_tags"></div>';
            $html[] = '    <input type="text" class="w-tag-input-field" id="<?= htmlspecialchars($Taglib__id) ?>_input" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off" ' . $disabledAttr . '>';
            $html[] = '  </div>';
            
            // 建议下拉
            $html[] = '  <div class="w-tag-input-suggestions" id="<?= htmlspecialchars($Taglib__id) ?>_suggestions" style="display:none;"></div>';
            
            // 隐藏字段容器
            $html[] = '  <div class="w-tag-input-hidden" id="<?= htmlspecialchars($Taglib__id) ?>_hidden"></div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-tag-input { position: relative; width: 100%; font-family: inherit; }';
            $html[] = '.w-tag-input-wrapper { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; background: #fff; min-height: 38px; cursor: text; }';
            $html[] = '.w-tag-input-wrapper:focus-within { border-color: #80bdff; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }';
            $html[] = '.w-tag-input-tags { display: flex; flex-wrap: wrap; gap: 4px; }';
            $html[] = '.w-tag-input-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #e9ecef; border-radius: 3px; font-size: 13px; max-width: 200px; }';
            $html[] = '.w-tag-input-tag-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }';
            $html[] = '.w-tag-input-tag-remove { cursor: pointer; color: #6c757d; font-size: 14px; line-height: 1; margin-left: 2px; }';
            $html[] = '.w-tag-input-tag-remove:hover { color: #dc3545; }';
            $html[] = '.w-tag-input-field { border: none; outline: none; flex: 1; min-width: 100px; background: transparent; font-size: inherit; padding: 2px 0; }';
            $html[] = '.w-tag-input-suggestions { position: absolute; left: 0; right: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1050; max-height: 200px; overflow-y: auto; }';
            $html[] = '.w-tag-input-suggestion { padding: 8px 12px; cursor: pointer; transition: background 0.15s; }';
            $html[] = '.w-tag-input-suggestion:hover, .w-tag-input-suggestion.active { background: #f8f9fa; }';
            $html[] = '.w-tag-input[data-disabled="true"] .w-tag-input-wrapper { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '.w-tag-input[data-disabled="true"] .w-tag-input-field { cursor: not-allowed; }';
            $html[] = '.w-tag-input[data-disabled="true"] .w-tag-input-tag-remove { display: none; }';
            $html[] = '.w-tag-input-error { color: #dc3545; font-size: 12px; margin-top: 4px; }';
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const fieldName = <?= json_encode($Taglib__name) ?>;';
            $html[] = 'const apiUrl = ' . json_encode($apiUrl) . ';';
            $html[] = 'const staticSuggestions = ' . json_encode($staticSuggestions) . ';';
            $html[] = 'const initialTags = ' . json_encode($initialTags) . ';';
            $html[] = 'const maxTags = ' . $max . ';';
            $html[] = 'const minLength = ' . $minLength . ';';
            $html[] = 'const maxLength = ' . $maxLength . ';';
            $html[] = 'const allowDuplicates = ' . ($allowDuplicates ? 'true' : 'false') . ';';
            $html[] = 'const separator = ' . json_encode($separator) . ';';
            $html[] = 'const debounceTime = ' . $debounce . ';';
            $html[] = 'const disabled = ' . ($disabled ? 'true' : 'false') . ';';

            $html[] = <<<JS
const container = document.getElementById(id + '_container');
const input = document.getElementById(id + '_input');
const tagsContainer = document.getElementById(id + '_tags');
const suggestionsContainer = document.getElementById(id + '_suggestions');
const hiddenContainer = document.getElementById(id + '_hidden');

if (disabled) {
    container.setAttribute('data-disabled', 'true');
}

let tags = [];
let activeIndex = -1;

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

// 添加标签
function addTag(text) {
    text = (text || '').trim();
    
    if (!text) return false;
    
    // 验证长度
    if (text.length < minLength) {
        showError('{$t_too_short}');
        return false;
    }
    if (text.length > maxLength) {
        showError('{$t_too_long}');
        return false;
    }
    
    // 检查最大数量
    if (maxTags > 0 && tags.length >= maxTags) {
        showError('{$t_max_reached}');
        return false;
    }
    
    // 检查重复
    if (!allowDuplicates && tags.includes(text)) {
        showError('{$t_duplicate}');
        return false;
    }
    
    tags.push(text);
    renderTags();
    updateHiddenFields();
    return true;
}

// 移除标签
function removeTag(index) {
    if (disabled) return;
    tags.splice(index, 1);
    renderTags();
    updateHiddenFields();
}

// 渲染标签
function renderTags() {
    tagsContainer.innerHTML = tags.map((tag, idx) => {
        return '<span class="w-tag-input-tag">' +
            '<span class="w-tag-input-tag-text" title="' + escapeAttr(tag) + '">' + escapeHtml(tag) + '</span>' +
            '<span class="w-tag-input-tag-remove" data-index="' + idx + '" title="{$t_remove}">&times;</span>' +
            '</span>';
    }).join('');
    
    // 绑定删除事件
    tagsContainer.querySelectorAll('.w-tag-input-tag-remove').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            removeTag(parseInt(this.dataset.index));
        });
    });
}

// 更新隐藏字段
function updateHiddenFields() {
    const name = fieldName.endsWith('[]') ? fieldName : fieldName + '[]';
    hiddenContainer.innerHTML = tags.map(tag => {
        return '<input type="hidden" name="' + escapeAttr(name) + '" value="' + escapeAttr(tag) + '">';
    }).join('');
    
    // 触发change事件
    container.dispatchEvent(new CustomEvent('change', { 
        detail: { tags: tags.slice() },
        bubbles: true 
    }));
}

// 显示错误
function showError(msg) {
    let errorEl = container.querySelector('.w-tag-input-error');
    if (!errorEl) {
        errorEl = document.createElement('div');
        errorEl.className = 'w-tag-input-error';
        container.appendChild(errorEl);
    }
    errorEl.textContent = msg;
    setTimeout(() => errorEl.remove(), 3000);
}

// 显示建议
function showSuggestions(items) {
    if (!items || !items.length) {
        suggestionsContainer.style.display = 'none';
        return;
    }
    
    suggestionsContainer.innerHTML = items.slice(0, 10).map((item, idx) => {
        const text = String(typeof item === 'string' ? item : (item.label || item.name || item.value || ''));
        return '<div class="w-tag-input-suggestion" data-value="' + escapeAttr(text) + '" data-index="' + idx + '">' + escapeHtml(text) + '</div>';
    }).join('');
    
    suggestionsContainer.style.display = 'block';
    activeIndex = -1;
    
    // 绑定点击事件
    suggestionsContainer.querySelectorAll('.w-tag-input-suggestion').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            if (addTag(this.dataset.value)) {
                input.value = '';
                input.focus();
            }
            suggestionsContainer.style.display = 'none';
        });
    });
}

// 搜索建议
function searchSuggestions(keyword) {
    keyword = (keyword || '').trim().toLowerCase();
    
    if (apiUrl) {
        fetch(apiUrl + (apiUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(keyword))
            .then(r => r.json())
            .then(res => {
                const data = res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
                showSuggestions(data);
            })
            .catch(() => {
                // 回退到静态建议
                filterStaticSuggestions(keyword);
            });
    } else {
        filterStaticSuggestions(keyword);
    }
}

function filterStaticSuggestions(keyword) {
    if (!staticSuggestions.length) {
        suggestionsContainer.style.display = 'none';
        return;
    }
    
    const filtered = staticSuggestions.filter(s => 
        s.toLowerCase().includes(keyword) && !tags.includes(s)
    );
    showSuggestions(filtered);
}

const debouncedSearch = debounce(searchSuggestions, debounceTime);

// 键盘事件
input.addEventListener('keydown', function(e) {
    const suggestions = suggestionsContainer.querySelectorAll('.w-tag-input-suggestion');
    
    switch(e.key) {
        case 'Enter':
            e.preventDefault();
            if (activeIndex >= 0 && suggestions[activeIndex]) {
                if (addTag(suggestions[activeIndex].dataset.value)) {
                    input.value = '';
                }
                suggestionsContainer.style.display = 'none';
            } else if (input.value.trim()) {
                if (addTag(input.value)) {
                    input.value = '';
                }
            }
            break;
            
        case 'Backspace':
            if (!input.value && tags.length) {
                removeTag(tags.length - 1);
            }
            break;
            
        case 'ArrowDown':
            if (suggestionsContainer.style.display !== 'none') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
                updateActiveSuggestion(suggestions);
            }
            break;
            
        case 'ArrowUp':
            if (suggestionsContainer.style.display !== 'none') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                updateActiveSuggestion(suggestions);
            }
            break;
            
        case 'Escape':
            suggestionsContainer.style.display = 'none';
            break;
            
        default:
            // 检查分隔符
            if (separator.includes(e.key) && input.value.trim()) {
                e.preventDefault();
                if (addTag(input.value)) {
                    input.value = '';
                }
            }
    }
});

function updateActiveSuggestion(suggestions) {
    suggestions.forEach((el, idx) => {
        el.classList.toggle('active', idx === activeIndex);
        if (idx === activeIndex) {
            el.scrollIntoView({ block: 'nearest' });
        }
    });
}

// 输入事件
input.addEventListener('input', function() {
    if (this.value.trim() && (apiUrl || staticSuggestions.length)) {
        debouncedSearch(this.value);
    } else {
        suggestionsContainer.style.display = 'none';
    }
});

// 聚焦显示建议
input.addEventListener('focus', function() {
    if (this.value.trim() && (apiUrl || staticSuggestions.length)) {
        debouncedSearch(this.value);
    } else if (staticSuggestions.length) {
        filterStaticSuggestions('');
    }
});

// 点击外部关闭建议
document.addEventListener('click', function(e) {
    if (!container.contains(e.target)) {
        suggestionsContainer.style.display = 'none';
    }
});

// 点击容器聚焦输入框
container.querySelector('.w-tag-input-wrapper').addEventListener('click', function() {
    if (!disabled) {
        input.focus();
    }
});

// 初始化标签
initialTags.forEach(tag => {
    if (tag && typeof tag === 'string') {
        tags.push(tag.trim());
    }
});
if (tags.length) {
    renderTags();
    updateHiddenFields();
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
<h3><code>&lt;w:theme:tag-input&gt;</code> 多选标签输入组件</h3>

<p><strong>功能</strong>：多值标签输入，支持手动输入和远程建议，适用于标签、关键词等场景</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>name</code>：表单字段名（必填），会自动添加[]</li>
    <li><code>value</code>：初始值，逗号分隔或JSON数组</li>
    <li><code>placeholder</code>：占位文本</li>
    <li><code>suggestions-url</code>：建议列表API地址</li>
    <li><code>suggestions</code>：静态建议列表，逗号分隔</li>
    <li><code>max</code>：最大标签数量，0表示不限制</li>
    <li><code>min-length</code>：单个标签最小长度，默认1</li>
    <li><code>max-length</code>：单个标签最大长度，默认50</li>
    <li><code>allow-duplicates</code>：是否允许重复标签</li>
    <li><code>separator</code>：分隔符，默认逗号</li>
    <li><code>debounce</code>：建议搜索防抖时间，默认300ms</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 基础用法 --&gt;
&lt;w:theme:tag-input 
    id="keywords"
    name="keywords"
    placeholder="输入后按回车添加"
    max="10"
/&gt;

&lt;!-- 带静态建议 --&gt;
&lt;w:theme:tag-input 
    id="tags"
    name="tags"
    suggestions="PHP,JavaScript,Python,Java,Go"
    value="PHP,JavaScript"
/&gt;

&lt;!-- 带远程建议 --&gt;
&lt;w:theme:tag-input 
    id="skills"
    name="skills"
    suggestions-url="/api/skills/suggest"
    max="5"
/&gt;
</pre>

<h4>API 返回格式</h4>
<pre>
{
    "success": true,
    "data": ["标签1", "标签2", "标签3"]
}
// 或
{
    "success": true,
    "data": [
        {"label": "标签1"},
        {"label": "标签2"}
    ]
}
</pre>

<h4>事件</h4>
<p>组件会在标签变化时触发 <code>change</code> 事件，可通过 <code>e.detail.tags</code> 获取当前标签数组</p>
DOC;
    }
}

