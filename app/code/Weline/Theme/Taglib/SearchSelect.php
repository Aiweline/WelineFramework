<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Http\Url;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

/**
 * 搜索下拉选择组件（可搜索 / 多选 chips / 动态 mountInto）。
 *
 * 动态属性（foreach 内 <?= ?>）走 renderRuntimeTag，必须通过 runtimeCallback 直接输出 HTML，
 * 不可再吐一层 <?php / <?= ?> 源码。
 */
class SearchSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:search-select';
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
            'id' => true,
            'name' => true,
            'url' => false,
            'options' => false,
            'options-json' => false,
            'value' => false,
            'value-field' => false,
            'label-field' => false,
            'placeholder' => false,
            'debounce' => false,
            'min-chars' => false,
            'class' => false,
            'style' => false,
            'disabled' => false,
            'required' => false,
            'clearable' => false,
            'multiple' => false,
            'limit' => false,
            'input-attrs' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $attributes = is_array($attributes) ? $attributes : [];
            $tagAttributes = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);

            // 编译期静态属性：先解析 Taglib__*，再由运行期 PHP 拼装（仅无动态属性时）。
            // 动态属性路径由 runtimeCallback 接管。
            return '<?php ' . $tagAttributes . ' ?>' . "\n"
                . '<?= \\' . self::class . '::buildMarkup(['
                . "'id' => (string)(\$Taglib__id ?? ''),"
                . "'name' => (string)(\$Taglib__name ?? ''),"
                . "'url' => (string)(\$Taglib__url ?? ''),"
                . "'options' => (string)(\$Taglib__options ?? ''),"
                . "'options-json' => (string)(\$Taglib__options_json ?? ''),"
                . "'value' => (string)(\$Taglib__value ?? ''),"
                . "'value-field' => (string)(\$Taglib__value_field ?? 'value'),"
                . "'label-field' => (string)(\$Taglib__label_field ?? 'label'),"
                . "'placeholder' => (string)(\$Taglib__placeholder ?? ''),"
                . "'debounce' => (string)(\$Taglib__debounce ?? '300'),"
                . "'min-chars' => (string)(\$Taglib__min_chars ?? '0'),"
                . "'class' => (string)(\$Taglib__class ?? ''),"
                . "'style' => (string)(\$Taglib__style ?? ''),"
                . "'disabled' => (string)(\$Taglib__disabled ?? ''),"
                . "'required' => (string)(\$Taglib__required ?? ''),"
                . "'clearable' => (string)(\$Taglib__clearable ?? ''),"
                . "'multiple' => (string)(\$Taglib__multiple ?? ''),"
                . "'limit' => (string)(\$Taglib__limit ?? '50'),"
                . "'input-attrs' => (string)(\$Taglib__input_attrs ?? ''),"
                . ']) ?>';
        };
    }

    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($template, $content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            return self::buildMarkup(is_array($attributes) ? $attributes : []);
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function buildMarkup(array $attributes): string
    {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $decode = static fn($value): string => html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bool = static function (array $attributes, string $key) use ($decode): bool {
            if (!array_key_exists($key, $attributes)) {
                return false;
            }

            return in_array(strtolower($decode($attributes[$key])), ['1', 'true', 'yes', 'on'], true);
        };

        // renderRuntimeTag 属性表达式常已 htmlspecialchars；先还原再统一转义输出。
        $id = trim($decode($attributes['id'] ?? ''));
        if ($id === '') {
            $id = 'search-select-' . substr(md5(uniqid('', true)), 0, 10);
        }
        $name = $decode($attributes['name'] ?? '');
        $url = trim($decode($attributes['url'] ?? ''));
        $optionsCsv = $decode($attributes['options'] ?? '');
        $optionsJson = $decode($attributes['options-json'] ?? '');
        $value = $decode($attributes['value'] ?? '');
        $valueField = $decode($attributes['value-field'] ?? 'value') ?: 'value';
        $labelField = $decode($attributes['label-field'] ?? 'label') ?: 'label';
        $placeholder = trim($decode($attributes['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = (string)__('请选择或搜索...');
        }
        $debounce = max(0, (int)$decode($attributes['debounce'] ?? '300'));
        $minChars = max(0, (int)$decode($attributes['min-chars'] ?? '0'));
        $class = $decode($attributes['class'] ?? '');
        $style = $decode($attributes['style'] ?? '');
        $limit = max(1, (int)$decode($attributes['limit'] ?? '50'));
        $disabled = $bool($attributes, 'disabled');
        $required = $bool($attributes, 'required');
        $clearable = $bool($attributes, 'clearable');
        $multiple = $bool($attributes, 'multiple');
        $inputAttrs = trim($decode($attributes['input-attrs'] ?? ''));

        $apiUrl = '';
        if ($url !== '') {
            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '/')) {
                $apiUrl = $url;
            } else {
                /** @var Url $urlBuilder */
                $urlBuilder = w_obj(Url::class);
                $apiUrl = $urlBuilder->getBackendUrl($url);
            }
        }

        $opts = [];
        if ($optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $opts[] = [
                        'value' => (string)($row['value'] ?? ''),
                        'label' => (string)($row['label'] ?? $row['value'] ?? ''),
                    ];
                }
            }
        } elseif ($optionsCsv !== '') {
            foreach (explode(',', $optionsCsv) as $pair) {
                $parts = explode(':', trim($pair), 2);
                if (count($parts) === 2) {
                    $opts[] = ['value' => trim($parts[0]), 'label' => trim($parts[1])];
                }
            }
        }

        $tClear = (string)__('清空');
        $tLoading = (string)__('搜索中...');
        $i18n = [
            'no_results' => (string)__('没有找到匹配的结果'),
            'loading' => $tLoading,
            'load_error' => (string)__('加载失败'),
            'type_to_search' => (string)__('输入关键词搜索'),
            'clear' => $tClear,
        ];

        $html = [];
        $html[] = '<div class="w-search-select ' . $escape($class) . ($multiple ? ' is-multiple' : '') . '"'
            . ' id="' . $escape($id) . '_container"'
            . ' style="' . $escape($style) . '"'
            . ' data-component="search-select"'
            . ' data-w-search-select="1"'
            . ' data-w-placement="bottom-start"'
            . ' data-multiple="' . ($multiple ? '1' : '0') . '"'
            . ($disabled ? ' data-disabled="true"' : '')
            . '>';
        $html[] = '  <input type="hidden" id="' . $escape($id) . '_value"'
            . ' name="' . $escape($name) . '"'
            . ' value="' . $escape($value) . '"'
            . ($required ? ' required' : '')
            . ($inputAttrs !== '' ? ' ' . $inputAttrs : '')
            . '>';
        if ($multiple) {
            $html[] = '  <div class="w-search-select-chips" id="' . $escape($id) . '_chips"></div>';
        }
        $html[] = '  <div class="w-search-select-trigger" id="' . $escape($id) . '_trigger">';
        $html[] = '    <input type="text" class="w-search-select-input" id="' . $escape($id) . '_input"'
            . ' placeholder="' . $escape($placeholder) . '" autocomplete="off"'
            . ($disabled ? ' disabled' : '')
            . '>';
        $html[] = '    <span class="w-search-select-display" id="' . $escape($id) . '_display"></span>';
        if ($clearable) {
            $html[] = '    <span class="w-search-select-clear" id="' . $escape($id) . '_clear" title="' . $escape($tClear) . '">&times;</span>';
        }
        $html[] = '    <span class="w-search-select-arrow">&#9662;</span>';
        $html[] = '  </div>';
        $html[] = '  <div class="w-search-select-dropdown" id="' . $escape($id) . '_dropdown" data-w-float-surface hidden>';
        $html[] = '    <div class="w-search-select-loading" id="' . $escape($id) . '_loading" hidden>' . $escape($tLoading) . '</div>';
        $html[] = '    <div class="w-search-select-list" id="' . $escape($id) . '_list"></div>';
        $html[] = '  </div>';
        $html[] = '</div>';

        $html[] = self::sharedAssetsHtml($i18n);
        $html[] = '<script>(function(){'
            . 'if(!window.WelineThemeSearchSelect||typeof window.WelineThemeSearchSelect.bind!=="function"){return;}'
            . 'window.WelineThemeSearchSelect.bind({'
            . 'id:' . json_encode($id, JSON_UNESCAPED_UNICODE) . ','
            . 'apiUrl:' . json_encode($apiUrl, JSON_UNESCAPED_UNICODE) . ','
            . 'options:' . json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ','
            . 'valueField:' . json_encode($valueField, JSON_UNESCAPED_UNICODE) . ','
            . 'labelField:' . json_encode($labelField, JSON_UNESCAPED_UNICODE) . ','
            . 'debounce:' . $debounce . ','
            . 'minChars:' . $minChars . ','
            . 'limit:' . $limit . ','
            . 'multiple:' . ($multiple ? 'true' : 'false') . ','
            . 'disabled:' . ($disabled ? 'true' : 'false')
            . '});'
            . '})();</script>';

        return implode("\n", $html);
    }

    /**
     * @param array{no_results:string,loading:string,load_error:string,type_to_search:string,clear:string} $i18n
     */
    private static function sharedAssetsHtml(array $i18n): string
    {
        $i18nJson = json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $style = <<<'CSS'
<style data-w-search-select-style>
.w-search-select{position:relative;display:block;width:100%;height:fit-content;align-self:flex-start;font:inherit;color:var(--weline-theme-text,inherit)}
.w-field:has(.w-search-select),.w-field__control:has(> .w-search-select){align-self:start;height:fit-content}
.w-search-select-chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 6px}
.w-search-select-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;background:var(--weline-theme-primary-subtle,rgba(37,99,235,.1));color:var(--weline-theme-primary-text-emphasis,var(--weline-theme-primary,#1d4ed8));font-size:12px}
.w-search-select-chip-remove{border:0;background:transparent;color:inherit;cursor:pointer;font:inherit;line-height:1;padding:0 0 0 2px}
.w-search-select-trigger{display:flex;align-items:center;border:1px solid var(--weline-theme-border-color,#ced4da);border-radius:var(--weline-theme-radius-md,8px);background:var(--weline-theme-surface,#fff);cursor:pointer;min-height:var(--weline-theme-control-height,38px);padding:0 30px 0 10px;position:relative}
.w-search-select-trigger:hover{border-color:var(--weline-theme-primary,#80bdff)}
.w-search-select-trigger:focus-within{border-color:var(--weline-theme-primary,#80bdff);box-shadow:var(--weline-theme-focus-ring,0 0 0 .2rem rgba(0,123,255,.25))}
.w-search-select-input{border:0;outline:0;flex:1;min-width:0;background:transparent;font:inherit;padding:6px 0;width:100%;color:inherit}
.w-search-select-display{display:none;position:absolute;left:10px;right:30px;pointer-events:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--weline-theme-text,#495057)}
.w-search-select.has-value:not(.is-multiple) .w-search-select-display{display:block}
.w-search-select.has-value:not(.is-multiple) .w-search-select-input::placeholder{color:transparent}
.w-search-select.is-filtering .w-search-select-display{display:none!important}
.w-search-select-arrow{position:absolute;top:50%;right:10px;transform:translateY(-50%);color:var(--weline-theme-text-muted,#6c757d);font-size:10px;line-height:1;transition:transform .2s}
.w-search-select.open .w-search-select-arrow{transform:translateY(-50%) rotate(180deg)}
.w-search-select-clear{position:absolute;top:50%;right:25px;transform:translateY(-50%);color:var(--weline-theme-text-muted,#6c757d);cursor:pointer;display:none;font-size:16px;line-height:1}
.w-search-select-clear:hover{color:var(--weline-theme-danger,#dc3545)}
.w-search-select.has-value .w-search-select-clear{display:inline-flex;align-items:center;justify-content:center}
.w-search-select.open{z-index:calc(var(--weline-z-menu,1050) + 3)}
.w-table td:has(.w-search-select.open),.w-table th:has(.w-search-select.open){z-index:calc(var(--weline-z-menu,1050) + 3)}
.w-search-select-dropdown{position:absolute;left:0;right:0;top:100%;margin-top:2px;background:var(--weline-theme-surface-raised,#fff);border:1px solid var(--weline-theme-border-color,#ced4da);border-radius:var(--weline-theme-radius-md,8px);box-shadow:var(--weline-theme-shadow-md,0 2px 8px rgba(0,0,0,.15));z-index:calc(var(--weline-z-menu,1050) + 3);max-height:300px;overflow-y:auto}
.w-search-select-dropdown[data-w-floating-positioned],.w-search-select-dropdown[data-w-floating-portal]{position:fixed;inset:auto;top:max(var(--w-floating-top,0px),var(--w-floating-viewport-top,.5rem));left:max(var(--w-floating-left,0px),var(--w-floating-viewport-left,.5rem));right:auto;bottom:auto;margin:0;inline-size:var(--w-floating-inline-size,auto);min-inline-size:var(--w-floating-inline-size,auto);max-inline-size:min(var(--w-floating-max-inline-size,calc(100dvw - 1rem)),calc(var(--w-floating-viewport-right,calc(100dvw - .5rem)) - max(var(--w-floating-left,0px),var(--w-floating-viewport-left,.5rem))));max-block-size:min(300px,var(--w-floating-max-block-size,70vh),calc(var(--w-floating-viewport-bottom,calc(100dvh - .5rem)) - max(var(--w-floating-top,0px),var(--w-floating-viewport-top,.5rem))));transform-origin:var(--w-floating-transform-origin,top)}
.w-search-select-dropdown[hidden]{display:none!important}
.w-search-select-loading{padding:10px;text-align:center;color:var(--weline-theme-text-muted,#6c757d)}
.w-search-select-loading[hidden]{display:none!important}
.w-search-select-item{padding:8px 12px;cursor:pointer;transition:background .15s}
.w-search-select-item:hover,.w-search-select-item.active{background:var(--weline-theme-surface-hover,#f8f9fa)}
.w-search-select-item.selected{background:var(--weline-theme-primary-subtle,#e9ecef);color:var(--weline-theme-primary-text-emphasis,inherit)}
.w-search-select-empty{padding:10px;text-align:center;color:var(--weline-theme-text-muted,#6c757d)}
.w-search-select[data-disabled="true"] .w-search-select-trigger{background:var(--weline-theme-surface-subtle,#e9ecef);cursor:not-allowed;opacity:.72}
.w-search-select[data-disabled="true"] .w-search-select-input{cursor:not-allowed}
</style>
<script>(function(){var s=document.querySelectorAll("style[data-w-search-select-style]");for(var i=1;i<s.length;i++){s[i].remove();}})();</script>
CSS;

        $runtime = <<<JS
<script data-w-search-select-runtime>
(function (global) {
  if (global.WelineThemeSearchSelect && global.WelineThemeSearchSelect.__ready) { return; }
  var I18N = {$i18nJson};

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(text == null ? '' : text)));
    return div.innerHTML;
  }
  function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function debounce(fn, delay) {
    var timer = null;
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
  }
  function normalizeOptions(list) {
    if (!Array.isArray(list)) { return []; }
    return list.map(function (row) {
      if (!row || typeof row !== 'object') { return null; }
      return {
        value: String(row.value != null ? row.value : ''),
        label: String(row.label != null ? row.label : (row.name != null ? row.name : (row.value != null ? row.value : '')))
      };
    }).filter(Boolean);
  }

  function bind(config) {
    config = config || {};
    var id = String(config.id || '');
    if (!id) { return null; }
    var container = document.getElementById(id + '_container');
    var input = document.getElementById(id + '_input');
    var display = document.getElementById(id + '_display');
    var hidden = document.getElementById(id + '_value');
    var dropdown = document.getElementById(id + '_dropdown');
    var list = document.getElementById(id + '_list');
    var loading = document.getElementById(id + '_loading');
    var clearBtn = document.getElementById(id + '_clear');
    var chips = document.getElementById(id + '_chips');
    if (!container || !input || !hidden || !dropdown || !list) { return null; }
    if (container.getAttribute('data-w-search-select-bound') === '1') { return hidden; }

    var apiUrl = String(config.apiUrl || '');
    var staticOptions = normalizeOptions(config.options || []);
    var valueField = String(config.valueField || 'value');
    var labelField = String(config.labelField || 'label');
    var debounceTime = Number(config.debounce || 300);
    var minChars = Number(config.minChars || 0);
    var limit = Number(config.limit || 50);
    var multiple = !!(config.multiple || container.getAttribute('data-multiple') === '1');
    var cache = null;
    var activeIndex = -1;
    var isOpen = false;

    function isDisabled() {
      return container.getAttribute('data-disabled') === 'true';
    }
    function applyDisabled(flag) {
      if (flag) {
        container.setAttribute('data-disabled', 'true');
        input.setAttribute('disabled', 'disabled');
        input.disabled = true;
      } else {
        container.removeAttribute('data-disabled');
        input.removeAttribute('disabled');
        input.disabled = false;
      }
    }
    if (config.disabled || container.getAttribute('data-disabled') === 'true') {
      applyDisabled(true);
    } else {
      applyDisabled(false);
    }
    container.classList.toggle('is-multiple', multiple);
    container.setAttribute('data-w-search-select-bound', '1');
    container.__welineSearchSelectSetDisabled = applyDisabled;
    container.__welineSearchSelectInvalidate = function () { cache = null; };

    function itemValue(item) { return String(item[valueField] || item.value || ''); }
    function itemLabel(item) { return String(item[labelField] || item.label || item.name || itemValue(item)); }
    function selectedValues() {
      if (!multiple) {
        var one = String(hidden.value || '').trim();
        return one ? [one] : [];
      }
      return String(hidden.value || '').split(/[,;]+/).map(function (v) { return v.trim(); }).filter(Boolean);
    }
    function setSelectedValues(values) {
      var unique = [];
      var seen = {};
      (values || []).forEach(function (value) {
        value = String(value || '').trim();
        if (!value || seen[value]) { return; }
        seen[value] = true;
        unique.push(value);
      });
      hidden.value = unique.join(',');
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
      syncDisplay();
    }
    function labelForValue(value) {
      var fromStatic = staticOptions.find(function (o) { return itemValue(o) === value; });
      if (fromStatic) { return itemLabel(fromStatic); }
      var fromCache = (cache || []).find(function (o) { return itemValue(o) === value; });
      if (fromCache) { return itemLabel(fromCache); }
      return value;
    }
    function syncDisplay() {
      var values = selectedValues();
      container.classList.toggle('has-value', values.length > 0);
      if (display) { display.style.display = ''; }
      if (!String(input.value || '').trim()) { container.classList.remove('is-filtering'); }
      if (multiple && chips) {
        chips.innerHTML = values.map(function (value) {
          return '<span class="w-search-select-chip" data-value="' + escapeAttr(value) + '">'
            + escapeHtml(labelForValue(value))
            + (isDisabled() ? '' : '<button type="button" class="w-search-select-chip-remove" data-value="' + escapeAttr(value) + '" aria-label="remove">&times;</button>')
            + '</span>';
        }).join('');
        chips.querySelectorAll('.w-search-select-chip-remove').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isDisabled()) { return; }
            setSelectedValues(selectedValues().filter(function (v) { return v !== btn.getAttribute('data-value'); }));
            if (isOpen) { renderOptions(cache || staticOptions || []); }
          });
        });
        display.textContent = '';
        return;
      }
      display.textContent = values.length ? labelForValue(values[0]) : '';
    }
    function renderOptions(items) {
      if (!items || !items.length) {
        list.innerHTML = '<div class="w-search-select-empty">' + escapeHtml(I18N.no_results) + '</div>';
        if (isOpen) { placeFloat(); }
        return;
      }
      var current = selectedValues();
      list.innerHTML = items.slice(0, limit).map(function (item, idx) {
        var val = itemValue(item);
        var lbl = itemLabel(item);
        var selectedClass = current.indexOf(val) > -1 ? 'selected' : '';
        var mark = multiple && selectedClass ? ' ✓' : '';
        return '<div class="w-search-select-item ' + selectedClass + '" data-value="' + escapeAttr(val) + '" data-label="' + escapeAttr(lbl) + '" data-index="' + idx + '">' + escapeHtml(lbl) + mark + '</div>';
      }).join('');
      list.querySelectorAll('.w-search-select-item').forEach(function (el) {
        el.addEventListener('click', function (e) {
          e.stopPropagation();
          if (isDisabled()) { return; }
          selectItem(el.getAttribute('data-value'), el.getAttribute('data-label'));
        });
      });
      activeIndex = -1;
      if (isOpen) { placeFloat(); }
    }
    function selectItem(value, label) {
      if (isDisabled()) { return; }
      value = String(value || '');
      if (multiple) {
        if (!value) { setSelectedValues([]); input.value = ''; return; }
        var current = selectedValues();
        var idx = current.indexOf(value);
        if (idx > -1) { current.splice(idx, 1); } else { current.push(value); }
        setSelectedValues(current);
        input.value = '';
        renderOptions(cache || staticOptions || []);
        input.focus();
        return;
      }
      setSelectedValues(value ? [value] : []);
      container.classList.remove('is-filtering');
      if (display) { display.style.display = ''; }
      if (value && label) { display.textContent = label; }
      input.value = '';
      closeDropdown();
      input.blur();
    }
    function liveApiUrl() {
      return String(container.getAttribute('data-api-url') || apiUrl || '');
    }
    var searchSeq = 0;
    var searchInFlight = false;
    function doSearch(keyword) {
      keyword = String(keyword || '').trim();
      var activeApiUrl = liveApiUrl();
      if (activeApiUrl) {
        if (keyword.length < minChars && minChars > 0) {
          list.innerHTML = '<div class="w-search-select-empty">' + escapeHtml(I18N.type_to_search) + '</div>';
          return;
        }
        // Fail-closed: never leave cache null after a failed fetch, or openDropdown
        // will re-fire doSearch('') on every focus/click/floating sync (request storm).
        if (searchInFlight) { return; }
        searchInFlight = true;
        var seq = ++searchSeq;
        loading.hidden = false;
        list.hidden = true;
        var searchUrl = activeApiUrl + (activeApiUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(keyword) + '&limit=' + limit;
        fetch(searchUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
          if (!r.ok) {
            throw new Error('search_http_' + r.status);
          }
          return r.json();
        }).then(function (res) {
          if (seq !== searchSeq) { return; }
          loading.hidden = true;
          list.hidden = false;
          var data = res && res.success !== undefined ? (res.data || []) : (Array.isArray(res) ? res : []);
          if (!Array.isArray(data)) { data = []; }
          cache = data;
          renderOptions(data);
        }).catch(function () {
          if (seq !== searchSeq) { return; }
          loading.hidden = true;
          list.hidden = false;
          cache = [];
          list.innerHTML = '<div class="w-search-select-empty">' + escapeHtml(I18N.load_error) + '</div>';
        }).finally(function () {
          if (seq === searchSeq) { searchInFlight = false; }
        });
        return;
      }
      if (staticOptions.length) {
        var kw = keyword.toLowerCase();
        var filtered = !kw ? staticOptions : staticOptions.filter(function (opt) {
          return itemLabel(opt).toLowerCase().indexOf(kw) > -1 || itemValue(opt).toLowerCase().indexOf(kw) > -1;
        });
        renderOptions(filtered);
      }
    }
    var debouncedSearch = debounce(doSearch, debounceTime);
    var floatApi = null;
    function uiFloating() {
      return (global.Weline && global.Weline.UI && global.Weline.UI.floating)
        ? global.Weline.UI.floating
        : null;
    }
    function syncDropdownWidth() {
      var trigger = container.querySelector('.w-search-select-trigger');
      if (!trigger || !dropdown) { return; }
      var width = Math.round(trigger.getBoundingClientRect().width);
      if (width > 0) {
        dropdown.style.setProperty('--w-floating-inline-size', width + 'px');
        dropdown.style.minWidth = width + 'px';
      }
    }
    function ensureFloat() {
      if (floatApi) { return floatApi; }
      var floating = uiFloating();
      if (!floating || typeof floating.attach !== 'function') { return null; }
      dropdown.setAttribute('data-w-float-surface', '');
      container.setAttribute('data-w-placement', 'bottom-start');
      // Unique per-instance anchor — class selector falls back to document.querySelector
      // and wrongly binds the first .w-search-select-trigger on the page (e.g. 远程仓).
      var triggerAnchor = '#' + id + '_trigger';
      container.setAttribute('data-w-float-anchor', triggerAnchor);
      container.setAttribute('data-w-gap', '2');
      floatApi = floating.attach(container, {
        placement: 'bottom-start',
        anchor: triggerAnchor,
      });
      return floatApi;
    }
    function placeFloat() {
      syncDropdownWidth();
      var api = ensureFloat();
      if (!api) { return; }
      if (typeof api.show === 'function') { api.show(); }
      else if (typeof api.sync === 'function') { api.sync(); }
      else if (typeof api.place === 'function') { api.place(); }
    }
    function openDropdown() {
      if (isDisabled()) { return; }
      dropdown.hidden = false;
      container.classList.add('open');
      isOpen = true;
      placeFloat();
      if (!cache) {
        if (staticOptions.length) { cache = staticOptions; renderOptions(staticOptions); }
        else if (liveApiUrl()) { doSearch(''); }
      } else { renderOptions(cache); }
      placeFloat();
    }
    function closeDropdown() {
      if (floatApi && typeof floatApi.hide === 'function') { floatApi.hide(); }
      dropdown.hidden = true;
      container.classList.remove('open');
      isOpen = false;
      activeIndex = -1;
    }
    function handleKeydown(e) {
      if (!isOpen) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') { openDropdown(); e.preventDefault(); }
        return;
      }
      var items = list.querySelectorAll('.w-search-select-item');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        items.forEach(function (el, idx) { el.classList.toggle('active', idx === activeIndex); });
        if (items[activeIndex]) { items[activeIndex].scrollIntoView({ block: 'nearest' }); }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        items.forEach(function (el, idx) { el.classList.toggle('active', idx === activeIndex); });
        if (items[activeIndex]) { items[activeIndex].scrollIntoView({ block: 'nearest' }); }
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIndex >= 0 && items[activeIndex]) {
          selectItem(items[activeIndex].getAttribute('data-value'), items[activeIndex].getAttribute('data-label'));
        }
      } else if (e.key === 'Escape') { closeDropdown(); }
    }

    input.addEventListener('focus', openDropdown);
    input.addEventListener('click', openDropdown);
    input.addEventListener('input', function () {
      var filtering = String(input.value || '').length > 0;
      container.classList.toggle('is-filtering', filtering);
      if (display) { display.style.display = filtering ? 'none' : ''; }
      if (!filtering) { syncDisplay(); }
      debouncedSearch(input.value);
    });
    input.addEventListener('keydown', handleKeydown);
    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        selectItem('', '');
        input.focus();
      });
    }
    document.addEventListener('click', function (e) {
      if (!container.contains(e.target) && !dropdown.contains(e.target)) { closeDropdown(); }
    });
    syncDisplay();
    return hidden;
  }

  function mountInto(host, config) {
    if (!(host instanceof HTMLElement)) { return null; }
    config = config || {};
    var id = String(config.id || ('search-select-' + Math.random().toString(36).slice(2, 10)));
    var multiple = !!config.multiple;
    var disabled = !!config.disabled;
    var clearable = config.clearable !== false;
    var name = String(config.name || '');
    var value = String(config.value || '');
    var placeholder = String(config.placeholder || I18N.type_to_search || 'Search...');
    var options = normalizeOptions(config.options || []);
    var hiddenAttrs = config.hiddenAttrs && typeof config.hiddenAttrs === 'object' ? config.hiddenAttrs : {};

    host.innerHTML = '';
    var root = document.createElement('div');
    root.className = 'w-search-select' + (multiple ? ' is-multiple' : '');
    root.id = id + '_container';
    root.setAttribute('data-component', 'search-select');
    root.setAttribute('data-w-search-select', '1');
    root.setAttribute('data-w-placement', 'bottom-start');
    root.setAttribute('data-multiple', multiple ? '1' : '0');
    if (disabled) { root.setAttribute('data-disabled', 'true'); }

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.id = id + '_value';
    if (name) { hidden.name = name; }
    hidden.value = value;
    Object.keys(hiddenAttrs).forEach(function (key) {
      hidden.setAttribute(key, String(hiddenAttrs[key]));
    });
    root.appendChild(hidden);

    if (multiple) {
      var chipsEl = document.createElement('div');
      chipsEl.className = 'w-search-select-chips';
      chipsEl.id = id + '_chips';
      root.appendChild(chipsEl);
    }

    var trigger = document.createElement('div');
    trigger.className = 'w-search-select-trigger';
    trigger.id = id + '_trigger';
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'w-search-select-input';
    input.id = id + '_input';
    input.placeholder = placeholder;
    input.autocomplete = 'off';
    if (disabled) { input.disabled = true; }
    trigger.appendChild(input);
    var display = document.createElement('span');
    display.className = 'w-search-select-display';
    display.id = id + '_display';
    trigger.appendChild(display);
    if (clearable) {
      var clearBtn = document.createElement('span');
      clearBtn.className = 'w-search-select-clear';
      clearBtn.id = id + '_clear';
      clearBtn.title = I18N.clear || 'Clear';
      clearBtn.innerHTML = '&times;';
      trigger.appendChild(clearBtn);
    }
    var arrow = document.createElement('span');
    arrow.className = 'w-search-select-arrow';
    arrow.innerHTML = '&#9662;';
    trigger.appendChild(arrow);
    root.appendChild(trigger);

    var dropdown = document.createElement('div');
    dropdown.className = 'w-search-select-dropdown';
    dropdown.id = id + '_dropdown';
    dropdown.setAttribute('data-w-float-surface', '');
    dropdown.hidden = true;
    var loading = document.createElement('div');
    loading.className = 'w-search-select-loading';
    loading.id = id + '_loading';
    loading.hidden = true;
    loading.textContent = I18N.loading || '';
    dropdown.appendChild(loading);
    var list = document.createElement('div');
    list.className = 'w-search-select-list';
    list.id = id + '_list';
    dropdown.appendChild(list);
    root.appendChild(dropdown);
    host.appendChild(root);

    return bind({
      id: id,
      apiUrl: config.apiUrl || '',
      options: options,
      valueField: config.valueField || 'value',
      labelField: config.labelField || 'label',
      debounce: config.debounce || 300,
      minChars: config.minChars || 0,
      limit: config.limit || 50,
      multiple: multiple,
      disabled: disabled
    });
  }

  global.WelineThemeSearchSelect = {
    __ready: true,
    bind: bind,
    mountInto: mountInto,
    normalizeOptions: normalizeOptions,
    setDisabled: function (target, flag) {
      var root = null;
      if (target instanceof HTMLElement) {
        root = target.getAttribute('data-w-search-select') === '1'
          ? target
          : target.closest('[data-w-search-select="1"]');
      }
      if (!(root instanceof HTMLElement)) { return false; }
      if (typeof root.__welineSearchSelectSetDisabled === 'function') {
        root.__welineSearchSelectSetDisabled(!!flag);
        return true;
      }
      var input = root.querySelector('.w-search-select-input');
      if (flag) {
        root.setAttribute('data-disabled', 'true');
        if (input) { input.disabled = true; input.setAttribute('disabled', 'disabled'); }
      } else {
        root.removeAttribute('data-disabled');
        if (input) { input.disabled = false; input.removeAttribute('disabled'); }
      }
      return true;
    }
  };
})(window);
</script>
<script>(function(){var s=document.querySelectorAll("script[data-w-search-select-runtime]");for(var i=1;i<s.length;i++){s[i].remove();}})();</script>
JS;

        return $style . "\n" . $runtime;
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
        return <<<'DOC'
<h3><code>&lt;w:theme:search-select&gt;</code> 搜索下拉</h3>
<p>可搜索；<code>options</code> / <code>options-json</code>；<code>multiple</code> chips。动态属性走 <code>runtimeCallback</code>。JS：<code>WelineThemeSearchSelect.mountInto</code>。</p>
DOC;
    }
}
