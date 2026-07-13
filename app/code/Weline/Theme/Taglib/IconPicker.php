<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 图标选择器组件
 * 
 * 支持 FontAwesome 和 Material Design Icons 图标库
 */
class IconPicker implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:icon-picker';
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
            'id' => true,           // 组件唯一ID（必填）
            'name' => true,         // 表单字段名（必填）
            'value' => false,       // 当前选中值
            'library' => false,     // 图标库：fontawesome, material, 默认 fontawesome
            'placeholder' => false, // 占位文本
            'clearable' => false,   // 是否可清空
            'class' => false,       // 额外CSS类
            'style' => false,       // 内联样式
            'disabled' => false,    // 是否禁用
            'required' => false,    // 是否必填
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'icon-picker-' . uniqid();
            $name = $attributes['name'] ?? 'icon';
            $value = $attributes['value'] ?? '';
            $library = $attributes['library'] ?? 'fontawesome';
            $placeholder = $attributes['placeholder'] ?? __('选择图标');
            $clearable = isset($attributes['clearable']) && ($attributes['clearable'] === 'true' || $attributes['clearable'] === '1');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $disabled = isset($attributes['disabled']) && ($attributes['disabled'] === 'true' || $attributes['disabled'] === '1');
            $required = isset($attributes['required']) && ($attributes['required'] === 'true' || $attributes['required'] === '1');

            // 解析属性
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);

            // 翻译文本
            $t_search = addslashes(__('搜索图标...'));
            $t_no_results = addslashes(__('没有找到匹配的图标'));
            $t_clear = addslashes(__('清空'));
            $t_all = addslashes(__('全部'));

            $disabledAttr = $disabled ? 'disabled' : '';
            $requiredAttr = $required ? 'required' : '';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="w-icon-picker ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="icon-picker">';
            
            // 隐藏字段
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value ?? \'\') ?>" ' . $requiredAttr . '>';
            
            // 触发器
            $html[] = '  <div class="w-icon-picker-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            $html[] = '    <span class="w-icon-picker-preview" id="<?= htmlspecialchars($Taglib__id) ?>_preview">';
            $html[] = '      <i id="<?= htmlspecialchars($Taglib__id) ?>_icon"></i>';
            $html[] = '    </span>';
            $html[] = '    <span class="w-icon-picker-text" id="<?= htmlspecialchars($Taglib__id) ?>_text">' . htmlspecialchars($placeholder) . '</span>';
            if ($clearable) {
                $html[] = '    <span class="w-icon-picker-clear" id="<?= htmlspecialchars($Taglib__id) ?>_clear" title="' . $t_clear . '">&times;</span>';
            }
            $html[] = '    <span class="w-icon-picker-arrow">&#9662;</span>';
            $html[] = '  </div>';
            
            // 下拉面板
            $html[] = '  <div class="w-icon-picker-dropdown" id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" style="display:none;">';
            $html[] = '    <div class="w-icon-picker-search">';
            $html[] = '      <input type="text" class="w-icon-picker-search-input" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . $t_search . '" autocomplete="off">';
            $html[] = '    </div>';
            $html[] = '    <div class="w-icon-picker-categories" id="<?= htmlspecialchars($Taglib__id) ?>_categories"></div>';
            $html[] = '    <div class="w-icon-picker-list" id="<?= htmlspecialchars($Taglib__id) ?>_list"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-icon-picker { position: relative; display: inline-block; width: 100%; font-family: inherit; }';
            $html[] = '.w-icon-picker-trigger { display: flex; align-items: center; border: 1px solid #ced4da; border-radius: 4px; background: #fff; cursor: pointer; min-height: 38px; padding: 0 30px 0 10px; position: relative; gap: 8px; }';
            $html[] = '.w-icon-picker-trigger:hover { border-color: #80bdff; }';
            $html[] = '.w-icon-picker-preview { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #495057; }';
            $html[] = '.w-icon-picker-text { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #6c757d; }';
            $html[] = '.w-icon-picker.has-value .w-icon-picker-text { color: #495057; }';
            $html[] = '.w-icon-picker-arrow { position: absolute; right: 10px; color: #6c757d; font-size: 10px; transition: transform 0.2s; }';
            $html[] = '.w-icon-picker.open .w-icon-picker-arrow { transform: rotate(180deg); }';
            $html[] = '.w-icon-picker-clear { position: absolute; right: 25px; color: #6c757d; cursor: pointer; display: none; font-size: 16px; line-height: 1; }';
            $html[] = '.w-icon-picker-clear:hover { color: #dc3545; }';
            $html[] = '.w-icon-picker.has-value .w-icon-picker-clear { display: block; }';
            $html[] = '.w-icon-picker-dropdown { position: absolute; left: 0; right: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1050; }';
            $html[] = '.w-icon-picker-search { padding: 8px; border-bottom: 1px solid #e9ecef; }';
            $html[] = '.w-icon-picker-search-input { width: 100%; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }';
            $html[] = '.w-icon-picker-search-input:focus { border-color: #80bdff; outline: none; }';
            $html[] = '.w-icon-picker-categories { display: flex; flex-wrap: wrap; gap: 4px; padding: 8px; border-bottom: 1px solid #e9ecef; }';
            $html[] = '.w-icon-picker-category { padding: 4px 10px; font-size: 12px; border: 1px solid #ced4da; border-radius: 3px; background: #fff; cursor: pointer; }';
            $html[] = '.w-icon-picker-category:hover { border-color: #007bff; color: #007bff; }';
            $html[] = '.w-icon-picker-category.active { background: #007bff; border-color: #007bff; color: #fff; }';
            $html[] = '.w-icon-picker-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(40px, 1fr)); gap: 4px; padding: 8px; max-height: 250px; overflow-y: auto; }';
            $html[] = '.w-icon-picker-item { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border: 1px solid transparent; border-radius: 4px; cursor: pointer; font-size: 18px; transition: all 0.15s; }';
            $html[] = '.w-icon-picker-item:hover { background: #f8f9fa; border-color: #ced4da; }';
            $html[] = '.w-icon-picker-item.selected { background: #007bff; color: #fff; border-color: #007bff; }';
            $html[] = '.w-icon-picker-empty { padding: 20px; text-align: center; color: #6c757d; }';
            $html[] = '.w-icon-picker[data-disabled="true"] .w-icon-picker-trigger { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '</style>';

            // JavaScript - 图标数据和逻辑
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const library = ' . json_encode($library) . ';';
            $html[] = 'const placeholder = ' . json_encode($placeholder) . ';';
            $html[] = 'const clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'const disabled = ' . ($disabled ? 'true' : 'false') . ';';

            // 常用 FontAwesome 图标列表
            $html[] = <<<'JS'
const fontawesomeIcons = {
    'common': ['fa-home', 'fa-user', 'fa-cog', 'fa-search', 'fa-plus', 'fa-minus', 'fa-check', 'fa-times', 'fa-edit', 'fa-trash', 'fa-save', 'fa-download', 'fa-upload', 'fa-refresh', 'fa-sync', 'fa-spinner', 'fa-star', 'fa-heart', 'fa-bell', 'fa-envelope', 'fa-comment', 'fa-comments', 'fa-share', 'fa-link', 'fa-external-link', 'fa-calendar', 'fa-clock', 'fa-map-marker', 'fa-phone', 'fa-mobile', 'fa-laptop', 'fa-desktop', 'fa-tablet', 'fa-camera', 'fa-image', 'fa-video', 'fa-music', 'fa-play', 'fa-pause', 'fa-stop'],
    'arrows': ['fa-arrow-up', 'fa-arrow-down', 'fa-arrow-left', 'fa-arrow-right', 'fa-chevron-up', 'fa-chevron-down', 'fa-chevron-left', 'fa-chevron-right', 'fa-angle-up', 'fa-angle-down', 'fa-angle-left', 'fa-angle-right', 'fa-caret-up', 'fa-caret-down', 'fa-caret-left', 'fa-caret-right', 'fa-arrows-alt', 'fa-expand', 'fa-compress', 'fa-sort', 'fa-sort-up', 'fa-sort-down'],
    'files': ['fa-file', 'fa-file-alt', 'fa-file-pdf', 'fa-file-word', 'fa-file-excel', 'fa-file-powerpoint', 'fa-file-image', 'fa-file-video', 'fa-file-audio', 'fa-file-code', 'fa-file-archive', 'fa-folder', 'fa-folder-open', 'fa-copy', 'fa-paste', 'fa-cut', 'fa-clipboard'],
    'business': ['fa-building', 'fa-briefcase', 'fa-chart-line', 'fa-chart-bar', 'fa-chart-pie', 'fa-dollar-sign', 'fa-euro-sign', 'fa-credit-card', 'fa-shopping-cart', 'fa-store', 'fa-box', 'fa-truck', 'fa-warehouse', 'fa-barcode', 'fa-qrcode', 'fa-receipt', 'fa-calculator', 'fa-percent'],
    'users': ['fa-user', 'fa-users', 'fa-user-plus', 'fa-user-minus', 'fa-user-check', 'fa-user-times', 'fa-user-edit', 'fa-user-cog', 'fa-user-shield', 'fa-user-tie', 'fa-user-graduate', 'fa-id-card', 'fa-address-book', 'fa-address-card'],
    'interface': ['fa-bars', 'fa-th', 'fa-th-large', 'fa-th-list', 'fa-list', 'fa-list-ul', 'fa-list-ol', 'fa-grip-horizontal', 'fa-grip-vertical', 'fa-ellipsis-h', 'fa-ellipsis-v', 'fa-filter', 'fa-sliders-h', 'fa-toggle-on', 'fa-toggle-off'],
    'status': ['fa-check-circle', 'fa-times-circle', 'fa-exclamation-circle', 'fa-info-circle', 'fa-question-circle', 'fa-ban', 'fa-lock', 'fa-unlock', 'fa-eye', 'fa-eye-slash', 'fa-thumbs-up', 'fa-thumbs-down', 'fa-flag', 'fa-bookmark'],
    'social': ['fa-facebook', 'fa-twitter', 'fa-instagram', 'fa-linkedin', 'fa-youtube', 'fa-github', 'fa-gitlab', 'fa-weixin', 'fa-weibo', 'fa-qq', 'fa-whatsapp', 'fa-telegram', 'fa-discord', 'fa-slack']
};

const materialIcons = {
    'common': ['mdi-home', 'mdi-account', 'mdi-cog', 'mdi-magnify', 'mdi-plus', 'mdi-minus', 'mdi-check', 'mdi-close', 'mdi-pencil', 'mdi-delete', 'mdi-content-save', 'mdi-download', 'mdi-upload', 'mdi-refresh', 'mdi-sync', 'mdi-loading', 'mdi-star', 'mdi-heart', 'mdi-bell', 'mdi-email', 'mdi-comment', 'mdi-share', 'mdi-link', 'mdi-calendar', 'mdi-clock', 'mdi-map-marker', 'mdi-phone', 'mdi-cellphone', 'mdi-laptop', 'mdi-monitor', 'mdi-tablet', 'mdi-camera', 'mdi-image', 'mdi-video', 'mdi-music', 'mdi-play', 'mdi-pause', 'mdi-stop'],
    'arrows': ['mdi-arrow-up', 'mdi-arrow-down', 'mdi-arrow-left', 'mdi-arrow-right', 'mdi-chevron-up', 'mdi-chevron-down', 'mdi-chevron-left', 'mdi-chevron-right', 'mdi-menu-up', 'mdi-menu-down', 'mdi-menu-left', 'mdi-menu-right', 'mdi-arrow-expand', 'mdi-arrow-collapse', 'mdi-sort', 'mdi-sort-ascending', 'mdi-sort-descending'],
    'files': ['mdi-file', 'mdi-file-document', 'mdi-file-pdf', 'mdi-file-word', 'mdi-file-excel', 'mdi-file-powerpoint', 'mdi-file-image', 'mdi-file-video', 'mdi-file-music', 'mdi-file-code', 'mdi-folder', 'mdi-folder-open', 'mdi-content-copy', 'mdi-content-paste', 'mdi-content-cut', 'mdi-clipboard'],
    'business': ['mdi-office-building', 'mdi-briefcase', 'mdi-chart-line', 'mdi-chart-bar', 'mdi-chart-pie', 'mdi-currency-usd', 'mdi-currency-eur', 'mdi-credit-card', 'mdi-cart', 'mdi-store', 'mdi-package', 'mdi-truck', 'mdi-warehouse', 'mdi-barcode', 'mdi-qrcode', 'mdi-receipt', 'mdi-calculator', 'mdi-percent']
};

const categoryLabels = {
    'common': '常用',
    'arrows': '箭头',
    'files': '文件',
    'business': '商务',
    'users': '用户',
    'interface': '界面',
    'status': '状态',
    'social': '社交'
};
JS;

            $html[] = <<<JS
const container = document.getElementById(id + '_container');
const trigger = document.getElementById(id + '_trigger');
const preview = document.getElementById(id + '_preview');
const iconEl = document.getElementById(id + '_icon');
const textEl = document.getElementById(id + '_text');
const hidden = document.getElementById(id + '_value');
const dropdown = document.getElementById(id + '_dropdown');
const searchInput = document.getElementById(id + '_search');
const categoriesContainer = document.getElementById(id + '_categories');
const listContainer = document.getElementById(id + '_list');
const clearBtn = document.getElementById(id + '_clear');

if (disabled) {
    container.setAttribute('data-disabled', 'true');
}

let icons = library === 'material' ? materialIcons : fontawesomeIcons;
let currentCategory = null;
let isOpen = false;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text ?? '')));
    return div.innerHTML;
}

function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function sanitizeIconName(iconName) {
    return String(iconName || '').replace(/[^\w-]/g, '');
}

function isKnownIcon(iconName) {
    return Object.keys(icons).some(cat => (icons[cat] || []).includes(iconName));
}

// 获取图标类前缀
function getIconClass(iconName) {
    iconName = sanitizeIconName(iconName);
    if (!iconName || !isKnownIcon(iconName)) {
        return '';
    }
    if (library === 'material') {
        return 'mdi ' + iconName;
    } else {
        // FontAwesome: fa-xxx -> fas fa-xxx 或 fab fa-xxx
        if (iconName.startsWith('fa-facebook') || iconName.startsWith('fa-twitter') || 
            iconName.startsWith('fa-instagram') || iconName.startsWith('fa-linkedin') ||
            iconName.startsWith('fa-youtube') || iconName.startsWith('fa-github') ||
            iconName.startsWith('fa-gitlab') || iconName.startsWith('fa-weixin') ||
            iconName.startsWith('fa-weibo') || iconName.startsWith('fa-qq') ||
            iconName.startsWith('fa-whatsapp') || iconName.startsWith('fa-telegram') ||
            iconName.startsWith('fa-discord') || iconName.startsWith('fa-slack')) {
            return 'fab ' + iconName;
        }
        return 'fas ' + iconName;
    }
}

// 渲染分类
function renderCategories() {
    let html = '<button type="button" class="w-icon-picker-category active" data-category="">{$t_all}</button>';
    for (let cat in icons) {
        const label = categoryLabels[cat] || cat;
        html += '<button type="button" class="w-icon-picker-category" data-category="' + escapeAttr(cat) + '">' + escapeHtml(label) + '</button>';
    }
    categoriesContainer.innerHTML = html;
    
    categoriesContainer.querySelectorAll('.w-icon-picker-category').forEach(btn => {
        btn.addEventListener('click', function() {
            categoriesContainer.querySelectorAll('.w-icon-picker-category').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentCategory = this.dataset.category || null;
            renderIcons(searchInput.value);
        });
    });
}

// 渲染图标列表
function renderIcons(keyword = '') {
    keyword = (keyword || '').toLowerCase();
    let items = [];
    
    if (currentCategory) {
        items = icons[currentCategory] || [];
    } else {
        for (let cat in icons) {
            items = items.concat(icons[cat]);
        }
    }
    
    // 去重
    items = [...new Set(items)];
    
    // 搜索过滤
    if (keyword) {
        items = items.filter(icon => icon.toLowerCase().includes(keyword));
    }
    
    if (!items.length) {
        listContainer.innerHTML = '<div class="w-icon-picker-empty">{$t_no_results}</div>';
        return;
    }
    
    const currentValue = hidden.value;
    listContainer.innerHTML = items.map(icon => {
        const cls = getIconClass(icon);
        const selected = icon === currentValue ? 'selected' : '';
        return '<div class="w-icon-picker-item ' + selected + '" data-icon="' + escapeAttr(icon) + '" title="' + escapeAttr(icon) + '"><i class="' + escapeAttr(cls) + '"></i></div>';
    }).join('');
    
    listContainer.querySelectorAll('.w-icon-picker-item').forEach(el => {
        el.addEventListener('click', function() {
            selectIcon(this.dataset.icon);
        });
    });
}

// 选择图标
function selectIcon(iconName) {
    iconName = sanitizeIconName(iconName);
    if (!iconName || !isKnownIcon(iconName)) {
        clearSelection();
        return;
    }
    hidden.value = iconName;
    iconEl.className = getIconClass(iconName);
    textEl.textContent = iconName;
    container.classList.add('has-value');
    closeDropdown();
    
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
    
    listContainer.querySelectorAll('.w-icon-picker-item').forEach(el => {
        el.classList.toggle('selected', el.dataset.icon === iconName);
    });
}

// 清空选择
function clearSelection() {
    hidden.value = '';
    iconEl.className = '';
    textEl.textContent = placeholder;
    container.classList.remove('has-value');
    
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
    
    listContainer.querySelectorAll('.w-icon-picker-item').forEach(el => {
        el.classList.remove('selected');
    });
}

// 打开下拉
function openDropdown() {
    if (disabled) return;
    dropdown.style.display = 'block';
    container.classList.add('open');
    isOpen = true;
    searchInput.focus();
}

// 关闭下拉
function closeDropdown() {
    dropdown.style.display = 'none';
    container.classList.remove('open');
    isOpen = false;
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

searchInput.addEventListener('input', function() {
    renderIcons(this.value);
});

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

// 初始化
renderCategories();
renderIcons();

// 如果有初始值，设置显示
if (hidden.value) {
    const initialIcon = sanitizeIconName(hidden.value);
    if (initialIcon && isKnownIcon(initialIcon)) {
        hidden.value = initialIcon;
        iconEl.className = getIconClass(initialIcon);
        textEl.textContent = initialIcon;
        container.classList.add('has-value');
    } else {
        hidden.value = '';
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
<h3><code>&lt;w:theme:icon-picker&gt;</code> 图标选择器组件</h3>

<p><strong>功能</strong>：图标库浏览和选择，支持 FontAwesome 和 Material Design Icons</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>name</code>：表单字段名（必填）</li>
    <li><code>value</code>：当前选中值（图标类名，如 fa-home）</li>
    <li><code>library</code>：图标库，fontawesome 或 material，默认 fontawesome</li>
    <li><code>placeholder</code>：占位文本</li>
    <li><code>clearable</code>：是否可清空</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- FontAwesome 图标 --&gt;
&lt;w:theme:icon-picker 
    id="menu-icon"
    name="icon"
    value="fa-home"
    clearable="true"
/&gt;

&lt;!-- Material Design Icons --&gt;
&lt;w:theme:icon-picker 
    id="action-icon"
    name="action_icon"
    library="material"
    placeholder="选择操作图标"
/&gt;
</pre>

<h4>图标分类</h4>
<ul>
    <li>常用 - 常见的通用图标</li>
    <li>箭头 - 方向和导航图标</li>
    <li>文件 - 文件和文档图标</li>
    <li>商务 - 商业相关图标</li>
    <li>用户 - 用户和账户图标</li>
    <li>界面 - UI组件图标</li>
    <li>状态 - 状态指示图标</li>
    <li>社交 - 社交媒体图标</li>
</ul>

<h4>注意事项</h4>
<p>使用前请确保已引入对应的图标库CSS：</p>
<ul>
    <li>FontAwesome: <code>&lt;link rel="stylesheet" href="fontawesome/css/all.min.css"&gt;</code></li>
    <li>Material Design Icons: <code>&lt;link rel="stylesheet" href="@mdi/font/css/materialdesignicons.min.css"&gt;</code></li>
</ul>
DOC;
    }
}

