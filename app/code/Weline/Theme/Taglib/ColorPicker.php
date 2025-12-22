<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

/**
 * 颜色选择器组件
 * 
 * 支持颜色选择、预设颜色和多种颜色格式
 */
class ColorPicker implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:color-picker';
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
            'format' => false,      // 颜色格式：hex, rgb, rgba, 默认 hex
            'presets' => false,     // 预设颜色，逗号分隔
            'show-alpha' => false,  // 是否显示透明度选择
            'show-input' => false,  // 是否显示输入框
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
            $id = $attributes['id'] ?? 'color-picker-' . uniqid();
            $name = $attributes['name'] ?? 'color';
            $value = $attributes['value'] ?? '#007bff';
            $format = $attributes['format'] ?? 'hex';
            $presets = $attributes['presets'] ?? '';
            $showAlpha = isset($attributes['show-alpha']) && ($attributes['show-alpha'] === 'true' || $attributes['show-alpha'] === '1');
            $showInput = isset($attributes['show-input']) && ($attributes['show-input'] === 'true' || $attributes['show-input'] === '1');
            $clearable = isset($attributes['clearable']) && ($attributes['clearable'] === 'true' || $attributes['clearable'] === '1');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $disabled = isset($attributes['disabled']) && ($attributes['disabled'] === 'true' || $attributes['disabled'] === '1');
            $required = isset($attributes['required']) && ($attributes['required'] === 'true' || $attributes['required'] === '1');

            // 默认预设颜色
            $defaultPresets = [
                '#ff0000', '#ff4500', '#ff8c00', '#ffd700', '#ffff00',
                '#9acd32', '#32cd32', '#00fa9a', '#00ffff', '#1e90ff',
                '#0000ff', '#8a2be2', '#ff00ff', '#ff1493', '#000000',
                '#333333', '#666666', '#999999', '#cccccc', '#ffffff'
            ];

            // 解析预设颜色
            $presetColors = $presets ? array_map('trim', explode(',', $presets)) : $defaultPresets;

            // 解析属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);

            // 翻译文本
            $t_clear = addslashes(__('清空'));
            $t_presets = addslashes(__('预设颜色'));

            $disabledAttr = $disabled ? 'disabled' : '';
            $requiredAttr = $required ? 'required' : '';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="w-color-picker ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="color-picker">';
            
            // 隐藏字段
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value ?? \'#007bff\') ?>" ' . $requiredAttr . '>';
            
            // 触发器
            $html[] = '  <div class="w-color-picker-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            $html[] = '    <span class="w-color-picker-preview" id="<?= htmlspecialchars($Taglib__id) ?>_preview"></span>';
            if ($showInput) {
                $html[] = '    <input type="text" class="w-color-picker-input" id="<?= htmlspecialchars($Taglib__id) ?>_input" value="<?= htmlspecialchars($Taglib__value ?? \'#007bff\') ?>" ' . $disabledAttr . '>';
            } else {
                $html[] = '    <span class="w-color-picker-text" id="<?= htmlspecialchars($Taglib__id) ?>_text"><?= htmlspecialchars($Taglib__value ?? \'#007bff\') ?></span>';
            }
            if ($clearable) {
                $html[] = '    <span class="w-color-picker-clear" id="<?= htmlspecialchars($Taglib__id) ?>_clear" title="' . $t_clear . '">&times;</span>';
            }
            $html[] = '    <span class="w-color-picker-arrow">&#9662;</span>';
            $html[] = '  </div>';
            
            // 下拉面板
            $html[] = '  <div class="w-color-picker-dropdown" id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" style="display:none;">';
            
            // 颜色选择区域
            $html[] = '    <div class="w-color-picker-panel">';
            $html[] = '      <div class="w-color-picker-saturation" id="<?= htmlspecialchars($Taglib__id) ?>_saturation">';
            $html[] = '        <div class="w-color-picker-saturation-white"></div>';
            $html[] = '        <div class="w-color-picker-saturation-black"></div>';
            $html[] = '        <div class="w-color-picker-saturation-pointer" id="<?= htmlspecialchars($Taglib__id) ?>_saturation_pointer"></div>';
            $html[] = '      </div>';
            $html[] = '      <div class="w-color-picker-hue" id="<?= htmlspecialchars($Taglib__id) ?>_hue">';
            $html[] = '        <div class="w-color-picker-hue-pointer" id="<?= htmlspecialchars($Taglib__id) ?>_hue_pointer"></div>';
            $html[] = '      </div>';
            if ($showAlpha) {
                $html[] = '      <div class="w-color-picker-alpha" id="<?= htmlspecialchars($Taglib__id) ?>_alpha">';
                $html[] = '        <div class="w-color-picker-alpha-gradient" id="<?= htmlspecialchars($Taglib__id) ?>_alpha_gradient"></div>';
                $html[] = '        <div class="w-color-picker-alpha-pointer" id="<?= htmlspecialchars($Taglib__id) ?>_alpha_pointer"></div>';
                $html[] = '      </div>';
            }
            $html[] = '    </div>';
            
            // 预设颜色
            $html[] = '    <div class="w-color-picker-presets">';
            $html[] = '      <div class="w-color-picker-presets-label">' . $t_presets . '</div>';
            $html[] = '      <div class="w-color-picker-presets-list" id="<?= htmlspecialchars($Taglib__id) ?>_presets">';
            foreach ($presetColors as $preset) {
                $html[] = '        <span class="w-color-picker-preset" data-color="' . htmlspecialchars($preset) . '" style="background-color: ' . htmlspecialchars($preset) . '"></span>';
            }
            $html[] = '      </div>';
            $html[] = '    </div>';
            
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-color-picker { position: relative; display: inline-block; width: 100%; font-family: inherit; }';
            $html[] = '.w-color-picker-trigger { display: flex; align-items: center; border: 1px solid #ced4da; border-radius: 4px; background: #fff; cursor: pointer; min-height: 38px; padding: 4px 30px 4px 8px; position: relative; gap: 8px; }';
            $html[] = '.w-color-picker-trigger:hover { border-color: #80bdff; }';
            $html[] = '.w-color-picker-preview { width: 24px; height: 24px; border-radius: 3px; border: 1px solid #ced4da; flex-shrink: 0; background-image: linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%); background-size: 8px 8px; background-position: 0 0, 0 4px, 4px -4px, -4px 0px; }';
            $html[] = '.w-color-picker-text { flex: 1; font-family: monospace; font-size: 13px; color: #495057; }';
            $html[] = '.w-color-picker-input { flex: 1; border: none; outline: none; font-family: monospace; font-size: 13px; background: transparent; padding: 0; }';
            $html[] = '.w-color-picker-arrow { position: absolute; right: 10px; color: #6c757d; font-size: 10px; transition: transform 0.2s; }';
            $html[] = '.w-color-picker.open .w-color-picker-arrow { transform: rotate(180deg); }';
            $html[] = '.w-color-picker-clear { position: absolute; right: 25px; color: #6c757d; cursor: pointer; display: none; font-size: 16px; line-height: 1; }';
            $html[] = '.w-color-picker-clear:hover { color: #dc3545; }';
            $html[] = '.w-color-picker.has-value .w-color-picker-clear { display: block; }';
            $html[] = '.w-color-picker-dropdown { position: absolute; left: 0; top: 100%; margin-top: 2px; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 1050; padding: 12px; width: 240px; }';
            $html[] = '.w-color-picker-panel { }';
            $html[] = '.w-color-picker-saturation { position: relative; width: 100%; height: 150px; border-radius: 3px; cursor: crosshair; }';
            $html[] = '.w-color-picker-saturation-white { position: absolute; inset: 0; background: linear-gradient(to right, #fff, transparent); border-radius: 3px; }';
            $html[] = '.w-color-picker-saturation-black { position: absolute; inset: 0; background: linear-gradient(to top, #000, transparent); border-radius: 3px; }';
            $html[] = '.w-color-picker-saturation-pointer { position: absolute; width: 12px; height: 12px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 2px rgba(0,0,0,0.5); transform: translate(-50%, -50%); pointer-events: none; }';
            $html[] = '.w-color-picker-hue { position: relative; width: 100%; height: 14px; margin-top: 10px; border-radius: 3px; background: linear-gradient(to right, #f00 0%, #ff0 17%, #0f0 33%, #0ff 50%, #00f 67%, #f0f 83%, #f00 100%); cursor: pointer; }';
            $html[] = '.w-color-picker-hue-pointer { position: absolute; width: 6px; height: 100%; background: #fff; border: 1px solid #999; border-radius: 2px; transform: translateX(-50%); pointer-events: none; }';
            $html[] = '.w-color-picker-alpha { position: relative; width: 100%; height: 14px; margin-top: 8px; border-radius: 3px; background-image: linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%); background-size: 8px 8px; background-position: 0 0, 0 4px, 4px -4px, -4px 0px; cursor: pointer; }';
            $html[] = '.w-color-picker-alpha-gradient { position: absolute; inset: 0; border-radius: 3px; }';
            $html[] = '.w-color-picker-alpha-pointer { position: absolute; width: 6px; height: 100%; background: #fff; border: 1px solid #999; border-radius: 2px; transform: translateX(-50%); pointer-events: none; }';
            $html[] = '.w-color-picker-presets { margin-top: 12px; padding-top: 12px; border-top: 1px solid #e9ecef; }';
            $html[] = '.w-color-picker-presets-label { font-size: 12px; color: #6c757d; margin-bottom: 8px; }';
            $html[] = '.w-color-picker-presets-list { display: flex; flex-wrap: wrap; gap: 4px; }';
            $html[] = '.w-color-picker-preset { width: 20px; height: 20px; border-radius: 3px; cursor: pointer; border: 1px solid #ced4da; transition: transform 0.15s; }';
            $html[] = '.w-color-picker-preset:hover { transform: scale(1.2); }';
            $html[] = '.w-color-picker[data-disabled="true"] .w-color-picker-trigger { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const format = ' . json_encode($format) . ';';
            $html[] = 'const showAlpha = ' . ($showAlpha ? 'true' : 'false') . ';';
            $html[] = 'const showInput = ' . ($showInput ? 'true' : 'false') . ';';
            $html[] = 'const clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'const disabled = ' . ($disabled ? 'true' : 'false') . ';';

            $html[] = <<<'JS'
const container = document.getElementById(id + '_container');
const trigger = document.getElementById(id + '_trigger');
const preview = document.getElementById(id + '_preview');
const textEl = document.getElementById(id + '_text');
const inputEl = document.getElementById(id + '_input');
const hidden = document.getElementById(id + '_value');
const dropdown = document.getElementById(id + '_dropdown');
const clearBtn = document.getElementById(id + '_clear');

const saturation = document.getElementById(id + '_saturation');
const saturationPointer = document.getElementById(id + '_saturation_pointer');
const hue = document.getElementById(id + '_hue');
const huePointer = document.getElementById(id + '_hue_pointer');
const alpha = document.getElementById(id + '_alpha');
const alphaGradient = document.getElementById(id + '_alpha_gradient');
const alphaPointer = document.getElementById(id + '_alpha_pointer');
const presetsContainer = document.getElementById(id + '_presets');

if (disabled) {
    container.setAttribute('data-disabled', 'true');
}

let currentColor = { h: 210, s: 100, v: 100, a: 1 };
let isOpen = false;

// 颜色转换函数
function hsvToRgb(h, s, v) {
    s /= 100; v /= 100;
    const f = (n) => {
        const k = (n + h / 60) % 6;
        return v - v * s * Math.max(Math.min(k, 4 - k, 1), 0);
    };
    return [Math.round(f(5) * 255), Math.round(f(3) * 255), Math.round(f(1) * 255)];
}

function rgbToHsv(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    const v = max, d = max - min;
    const s = max === 0 ? 0 : d / max;
    let h = 0;
    if (max !== min) {
        switch (max) {
            case r: h = (g - b) / d + (g < b ? 6 : 0); break;
            case g: h = (b - r) / d + 2; break;
            case b: h = (r - g) / d + 4; break;
        }
        h *= 60;
    }
    return [h, s * 100, v * 100];
}

function hexToRgb(hex) {
    hex = hex.replace('#', '');
    if (hex.length === 3) {
        hex = hex.split('').map(c => c + c).join('');
    }
    const num = parseInt(hex, 16);
    return [(num >> 16) & 255, (num >> 8) & 255, num & 255];
}

function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map(x => x.toString(16).padStart(2, '0')).join('');
}

function formatColor(h, s, v, a) {
    const [r, g, b] = hsvToRgb(h, s, v);
    if (format === 'rgb') {
        return `rgb(${r}, ${g}, ${b})`;
    } else if (format === 'rgba' || (showAlpha && a < 1)) {
        return `rgba(${r}, ${g}, ${b}, ${a.toFixed(2)})`;
    }
    return rgbToHex(r, g, b);
}

function parseColor(color) {
    if (!color) return { h: 0, s: 0, v: 100, a: 1 };
    
    color = color.trim();
    
    // HEX
    if (color.startsWith('#')) {
        const [r, g, b] = hexToRgb(color);
        const [h, s, v] = rgbToHsv(r, g, b);
        return { h, s, v, a: 1 };
    }
    
    // RGB/RGBA
    const rgbaMatch = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
    if (rgbaMatch) {
        const [h, s, v] = rgbToHsv(+rgbaMatch[1], +rgbaMatch[2], +rgbaMatch[3]);
        return { h, s, v, a: rgbaMatch[4] !== undefined ? +rgbaMatch[4] : 1 };
    }
    
    return { h: 0, s: 0, v: 100, a: 1 };
}

// 更新UI
function updateUI() {
    const [r, g, b] = hsvToRgb(currentColor.h, currentColor.s, currentColor.v);
    const colorStr = formatColor(currentColor.h, currentColor.s, currentColor.v, currentColor.a);
    
    // 预览
    preview.style.backgroundColor = `rgba(${r}, ${g}, ${b}, ${currentColor.a})`;
    
    // 文本/输入
    if (showInput && inputEl) {
        inputEl.value = colorStr;
    } else if (textEl) {
        textEl.textContent = colorStr;
    }
    
    // 隐藏字段
    hidden.value = colorStr;
    
    // 饱和度面板背景
    const hueColor = hsvToRgb(currentColor.h, 100, 100);
    saturation.style.backgroundColor = `rgb(${hueColor[0]}, ${hueColor[1]}, ${hueColor[2]})`;
    
    // 饱和度指针
    saturationPointer.style.left = currentColor.s + '%';
    saturationPointer.style.top = (100 - currentColor.v) + '%';
    
    // 色相指针
    huePointer.style.left = (currentColor.h / 360 * 100) + '%';
    
    // 透明度
    if (showAlpha && alphaGradient && alphaPointer) {
        alphaGradient.style.background = `linear-gradient(to right, transparent, rgb(${r}, ${g}, ${b}))`;
        alphaPointer.style.left = (currentColor.a * 100) + '%';
    }
    
    container.classList.add('has-value');
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
}

// 饱和度拖拽
function handleSaturationDrag(e) {
    const rect = saturation.getBoundingClientRect();
    let x = (e.clientX - rect.left) / rect.width * 100;
    let y = (e.clientY - rect.top) / rect.height * 100;
    
    x = Math.max(0, Math.min(100, x));
    y = Math.max(0, Math.min(100, y));
    
    currentColor.s = x;
    currentColor.v = 100 - y;
    updateUI();
}

saturation.addEventListener('mousedown', function(e) {
    handleSaturationDrag(e);
    
    function onMove(e) { handleSaturationDrag(e); }
    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    }
    
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
});

// 色相拖拽
function handleHueDrag(e) {
    const rect = hue.getBoundingClientRect();
    let x = (e.clientX - rect.left) / rect.width;
    x = Math.max(0, Math.min(1, x));
    
    currentColor.h = x * 360;
    updateUI();
}

hue.addEventListener('mousedown', function(e) {
    handleHueDrag(e);
    
    function onMove(e) { handleHueDrag(e); }
    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    }
    
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
});

// 透明度拖拽
if (showAlpha && alpha) {
    function handleAlphaDrag(e) {
        const rect = alpha.getBoundingClientRect();
        let x = (e.clientX - rect.left) / rect.width;
        x = Math.max(0, Math.min(1, x));
        
        currentColor.a = x;
        updateUI();
    }
    
    alpha.addEventListener('mousedown', function(e) {
        handleAlphaDrag(e);
        
        function onMove(e) { handleAlphaDrag(e); }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }
        
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });
}

// 预设颜色
presetsContainer.querySelectorAll('.w-color-picker-preset').forEach(el => {
    el.addEventListener('click', function() {
        currentColor = parseColor(this.dataset.color);
        updateUI();
    });
});

// 输入框
if (showInput && inputEl) {
    inputEl.addEventListener('change', function() {
        currentColor = parseColor(this.value);
        updateUI();
    });
    inputEl.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

// 打开/关闭
function openDropdown() {
    if (disabled) return;
    dropdown.style.display = 'block';
    container.classList.add('open');
    isOpen = true;
}

function closeDropdown() {
    dropdown.style.display = 'none';
    container.classList.remove('open');
    isOpen = false;
}

trigger.addEventListener('click', function(e) {
    if (disabled) return;
    if (e.target === clearBtn) return;
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
        hidden.value = '';
        preview.style.backgroundColor = 'transparent';
        if (textEl) textEl.textContent = '';
        if (inputEl) inputEl.value = '';
        container.classList.remove('has-value');
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

document.addEventListener('click', function(e) {
    if (!container.contains(e.target)) {
        closeDropdown();
    }
});

// 初始化
const initialColor = hidden.value || '#007bff';
currentColor = parseColor(initialColor);
updateUI();
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
<h3><code>&lt;w:theme:color-picker&gt;</code> 颜色选择器组件</h3>

<p><strong>功能</strong>：颜色选择和输入，支持多种颜色格式和预设颜色</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>name</code>：表单字段名（必填）</li>
    <li><code>value</code>：当前选中值（颜色值，如 #ff0000）</li>
    <li><code>format</code>：颜色格式，hex/rgb/rgba，默认 hex</li>
    <li><code>presets</code>：预设颜色，逗号分隔</li>
    <li><code>show-alpha</code>：是否显示透明度选择</li>
    <li><code>show-input</code>：是否显示输入框</li>
    <li><code>clearable</code>：是否可清空</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 基础用法 --&gt;
&lt;w:theme:color-picker 
    id="theme-color"
    name="primary_color"
    value="#007bff"
/&gt;

&lt;!-- 带透明度 --&gt;
&lt;w:theme:color-picker 
    id="bg-color"
    name="background_color"
    format="rgba"
    show-alpha="true"
    show-input="true"
/&gt;

&lt;!-- 自定义预设 --&gt;
&lt;w:theme:color-picker 
    id="brand-color"
    name="brand_color"
    presets="#ff0000,#00ff00,#0000ff,#ffff00,#ff00ff"
    clearable="true"
/&gt;
</pre>

<h4>颜色格式</h4>
<ul>
    <li><code>hex</code> - #RRGGBB 格式（默认）</li>
    <li><code>rgb</code> - rgb(R, G, B) 格式</li>
    <li><code>rgba</code> - rgba(R, G, B, A) 格式</li>
</ul>
DOC;
    }
}

