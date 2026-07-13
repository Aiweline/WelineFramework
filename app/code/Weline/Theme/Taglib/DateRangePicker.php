<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 日期范围选择器组件
 * 
 * 双日期选择器，支持快捷选项，用于时间筛选场景
 */
class DateRangePicker implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:date-range';
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
            'start-name' => true,       // 开始日期字段名（必填）
            'end-name' => true,         // 结束日期字段名（必填）
            'start-value' => false,     // 开始日期初始值
            'end-value' => false,       // 结束日期初始值
            'format' => false,          // 日期格式，默认 Y-m-d
            'type' => false,            // 类型：date, datetime, 默认 date
            'placeholder-start' => false,// 开始日期占位文本
            'placeholder-end' => false, // 结束日期占位文本
            'separator' => false,       // 分隔符，默认 '至'
            'shortcuts' => false,       // 快捷选项，逗号分隔
            'min-date' => false,        // 最小日期
            'max-date' => false,        // 最大日期
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
            $id = $attributes['id'] ?? 'date-range-' . uniqid();
            $startName = $attributes['start-name'] ?? 'start_date';
            $endName = $attributes['end-name'] ?? 'end_date';
            $startValue = $attributes['start-value'] ?? '';
            $endValue = $attributes['end-value'] ?? '';
            $format = $attributes['format'] ?? 'Y-m-d';
            $type = $attributes['type'] ?? 'date';
            $placeholderStart = $attributes['placeholder-start'] ?? __('开始日期');
            $placeholderEnd = $attributes['placeholder-end'] ?? __('结束日期');
            $separator = $attributes['separator'] ?? __('至');
            $shortcuts = $attributes['shortcuts'] ?? '';
            $minDate = $attributes['min-date'] ?? '';
            $maxDate = $attributes['max-date'] ?? '';
            $clearable = isset($attributes['clearable']) && ($attributes['clearable'] === 'true' || $attributes['clearable'] === '1');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $disabled = isset($attributes['disabled']) && ($attributes['disabled'] === 'true' || $attributes['disabled'] === '1');
            $required = isset($attributes['required']) && ($attributes['required'] === 'true' || $attributes['required'] === '1');

            // 解析快捷选项
            $shortcutsList = [];
            if ($shortcuts) {
                $shortcutsList = array_map('trim', explode(',', $shortcuts));
            }

            // 解析属性
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);

            // 翻译文本
            $t_today = addslashes(__('今天'));
            $t_yesterday = addslashes(__('昨天'));
            $t_last7days = addslashes(__('最近7天'));
            $t_last30days = addslashes(__('最近30天'));
            $t_thisMonth = addslashes(__('本月'));
            $t_lastMonth = addslashes(__('上月'));
            $t_thisYear = addslashes(__('今年'));
            $t_clear = addslashes(__('清空'));

            $inputType = $type === 'datetime' ? 'datetime-local' : 'date';
            $disabledAttr = $disabled ? 'disabled' : '';
            $requiredAttr = $required ? 'required' : '';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="w-date-range ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>_container" style="' . htmlspecialchars($style) . '" data-component="date-range">';
            
            // 快捷选项
            if (!empty($shortcutsList)) {
                $html[] = '  <div class="w-date-range-shortcuts" id="<?= htmlspecialchars($Taglib__id) ?>_shortcuts">';
                foreach ($shortcutsList as $shortcut) {
                    $html[] = '    <button type="button" class="w-date-range-shortcut" data-shortcut="' . htmlspecialchars($shortcut) . '"></button>';
                }
                $html[] = '  </div>';
            }
            
            // 日期输入区域
            $html[] = '  <div class="w-date-range-inputs">';
            $html[] = '    <div class="w-date-range-input-wrapper">';
            $html[] = '      <input type="' . $inputType . '" class="w-date-range-input" id="<?= htmlspecialchars($Taglib__id) ?>_start" name="<?= htmlspecialchars($Taglib__start_name ?? \'' . $startName . '\') ?>" value="<?= htmlspecialchars($Taglib__start_value ?? \'\') ?>" placeholder="' . htmlspecialchars($placeholderStart) . '" ' . $disabledAttr . ' ' . $requiredAttr . '>';
            $html[] = '    </div>';
            $html[] = '    <span class="w-date-range-separator">' . htmlspecialchars($separator) . '</span>';
            $html[] = '    <div class="w-date-range-input-wrapper">';
            $html[] = '      <input type="' . $inputType . '" class="w-date-range-input" id="<?= htmlspecialchars($Taglib__id) ?>_end" name="<?= htmlspecialchars($Taglib__end_name ?? \'' . $endName . '\') ?>" value="<?= htmlspecialchars($Taglib__end_value ?? \'\') ?>" placeholder="' . htmlspecialchars($placeholderEnd) . '" ' . $disabledAttr . ' ' . $requiredAttr . '>';
            $html[] = '    </div>';
            if ($clearable) {
                $html[] = '    <button type="button" class="w-date-range-clear" id="<?= htmlspecialchars($Taglib__id) ?>_clear" title="' . $t_clear . '">&times;</button>';
            }
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = '.w-date-range { display: flex; flex-direction: column; gap: 8px; font-family: inherit; }';
            $html[] = '.w-date-range-shortcuts { display: flex; flex-wrap: wrap; gap: 4px; }';
            $html[] = '.w-date-range-shortcut { padding: 4px 10px; font-size: 12px; border: 1px solid #ced4da; border-radius: 3px; background: #fff; cursor: pointer; transition: all 0.15s; }';
            $html[] = '.w-date-range-shortcut:hover { border-color: #007bff; color: #007bff; }';
            $html[] = '.w-date-range-shortcut.active { background: #007bff; border-color: #007bff; color: #fff; }';
            $html[] = '.w-date-range-inputs { display: flex; align-items: center; gap: 8px; }';
            $html[] = '.w-date-range-input-wrapper { flex: 1; }';
            $html[] = '.w-date-range-input { width: 100%; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: inherit; }';
            $html[] = '.w-date-range-input:focus { border-color: #80bdff; outline: none; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }';
            $html[] = '.w-date-range-input:disabled { background: #e9ecef; cursor: not-allowed; }';
            $html[] = '.w-date-range-separator { color: #6c757d; font-size: 14px; flex-shrink: 0; }';
            $html[] = '.w-date-range-clear { padding: 4px 8px; border: none; background: transparent; color: #6c757d; cursor: pointer; font-size: 18px; line-height: 1; }';
            $html[] = '.w-date-range-clear:hover { color: #dc3545; }';
            $html[] = '@media (max-width: 576px) { .w-date-range-inputs { flex-direction: column; } .w-date-range-separator { display: none; } }';
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const shortcuts = ' . json_encode($shortcutsList) . ';';
            $html[] = 'const minDate = ' . json_encode($minDate) . ';';
            $html[] = 'const maxDate = ' . json_encode($maxDate) . ';';
            $html[] = 'const clearable = ' . ($clearable ? 'true' : 'false') . ';';

            // 快捷选项配置
            $html[] = <<<JS
const shortcutConfig = {
    'today': { label: '{$t_today}', getRange: () => { const d = formatDate(new Date()); return [d, d]; } },
    'yesterday': { label: '{$t_yesterday}', getRange: () => { const d = new Date(); d.setDate(d.getDate() - 1); const f = formatDate(d); return [f, f]; } },
    'last7days': { label: '{$t_last7days}', getRange: () => { const e = new Date(); const s = new Date(); s.setDate(s.getDate() - 6); return [formatDate(s), formatDate(e)]; } },
    'last30days': { label: '{$t_last30days}', getRange: () => { const e = new Date(); const s = new Date(); s.setDate(s.getDate() - 29); return [formatDate(s), formatDate(e)]; } },
    'thisMonth': { label: '{$t_thisMonth}', getRange: () => { const d = new Date(); const s = new Date(d.getFullYear(), d.getMonth(), 1); const e = new Date(d.getFullYear(), d.getMonth() + 1, 0); return [formatDate(s), formatDate(e)]; } },
    'lastMonth': { label: '{$t_lastMonth}', getRange: () => { const d = new Date(); const s = new Date(d.getFullYear(), d.getMonth() - 1, 1); const e = new Date(d.getFullYear(), d.getMonth(), 0); return [formatDate(s), formatDate(e)]; } },
    'thisYear': { label: '{$t_thisYear}', getRange: () => { const d = new Date(); const s = new Date(d.getFullYear(), 0, 1); const e = new Date(d.getFullYear(), 11, 31); return [formatDate(s), formatDate(e)]; } }
};

function formatDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}

const container = document.getElementById(id + '_container');
const startInput = document.getElementById(id + '_start');
const endInput = document.getElementById(id + '_end');
const clearBtn = document.getElementById(id + '_clear');
const shortcutsContainer = document.getElementById(id + '_shortcuts');

// 设置日期限制
if (minDate) {
    startInput.min = minDate;
    endInput.min = minDate;
}
if (maxDate) {
    startInput.max = maxDate;
    endInput.max = maxDate;
}

// 初始化快捷按钮
if (shortcutsContainer) {
    const buttons = shortcutsContainer.querySelectorAll('.w-date-range-shortcut');
    buttons.forEach(btn => {
        const key = btn.dataset.shortcut;
        const config = shortcutConfig[key];
        if (config) {
            btn.textContent = config.label;
            btn.addEventListener('click', function() {
                const [start, end] = config.getRange();
                setDateRange(start, end);
                
                // 更新按钮状态
                buttons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        } else {
            btn.style.display = 'none';
        }
    });
}

// 设置日期范围
function setDateRange(start, end) {
    startInput.value = start;
    endInput.value = end;
    
    // 确保结束日期不早于开始日期
    endInput.min = start || minDate;
    
    // 触发change事件
    startInput.dispatchEvent(new Event('change', { bubbles: true }));
    endInput.dispatchEvent(new Event('change', { bubbles: true }));
}

// 开始日期变化时，更新结束日期的最小值
startInput.addEventListener('change', function() {
    if (this.value) {
        endInput.min = this.value;
        // 如果结束日期早于开始日期，清空结束日期
        if (endInput.value && endInput.value < this.value) {
            endInput.value = '';
        }
    } else {
        endInput.min = minDate;
    }
    
    updateShortcutState();
});

endInput.addEventListener('change', function() {
    updateShortcutState();
});

// 更新快捷按钮状态
function updateShortcutState() {
    if (!shortcutsContainer) return;
    
    const buttons = shortcutsContainer.querySelectorAll('.w-date-range-shortcut');
    buttons.forEach(btn => {
        const key = btn.dataset.shortcut;
        const config = shortcutConfig[key];
        if (config) {
            const [start, end] = config.getRange();
            if (startInput.value === start && endInput.value === end) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
    });
}

// 清空按钮
if (clearBtn) {
    clearBtn.addEventListener('click', function() {
        startInput.value = '';
        endInput.value = '';
        endInput.min = minDate;
        
        if (shortcutsContainer) {
            shortcutsContainer.querySelectorAll('.w-date-range-shortcut').forEach(b => b.classList.remove('active'));
        }
        
        startInput.dispatchEvent(new Event('change', { bubbles: true }));
        endInput.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

// 初始化时检查快捷按钮状态
updateShortcutState();
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
<h3><code>&lt;w:theme:date-range&gt;</code> 日期范围选择器组件</h3>

<p><strong>功能</strong>：双日期选择器，支持快捷选项，用于时间筛选场景</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>start-name</code>：开始日期字段名（必填）</li>
    <li><code>end-name</code>：结束日期字段名（必填）</li>
    <li><code>start-value</code>：开始日期初始值</li>
    <li><code>end-value</code>：结束日期初始值</li>
    <li><code>format</code>：日期格式（仅影响显示，默认 Y-m-d）</li>
    <li><code>type</code>：类型，date 或 datetime，默认 date</li>
    <li><code>placeholder-start</code>：开始日期占位文本</li>
    <li><code>placeholder-end</code>：结束日期占位文本</li>
    <li><code>separator</code>：分隔符，默认 '至'</li>
    <li><code>shortcuts</code>：快捷选项，逗号分隔</li>
    <li><code>min-date</code>：最小可选日期</li>
    <li><code>max-date</code>：最大可选日期</li>
    <li><code>clearable</code>：是否可清空</li>
    <li><code>disabled</code>：是否禁用</li>
    <li><code>required</code>：是否必填</li>
</ul>

<h4>可用快捷选项</h4>
<ul>
    <li><code>today</code> - 今天</li>
    <li><code>yesterday</code> - 昨天</li>
    <li><code>last7days</code> - 最近7天</li>
    <li><code>last30days</code> - 最近30天</li>
    <li><code>thisMonth</code> - 本月</li>
    <li><code>lastMonth</code> - 上月</li>
    <li><code>thisYear</code> - 今年</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 基础用法 --&gt;
&lt;w:theme:date-range 
    id="date-filter"
    start-name="start_date"
    end-name="end_date"
    clearable="true"
/&gt;

&lt;!-- 带快捷选项 --&gt;
&lt;w:theme:date-range 
    id="order-date"
    start-name="order_start"
    end-name="order_end"
    shortcuts="today,yesterday,last7days,last30days,thisMonth"
    clearable="true"
/&gt;

&lt;!-- 日期时间选择 --&gt;
&lt;w:theme:date-range 
    id="log-time"
    start-name="log_start"
    end-name="log_end"
    type="datetime"
    min-date="2024-01-01"
/&gt;
</pre>
DOC;
    }
}

