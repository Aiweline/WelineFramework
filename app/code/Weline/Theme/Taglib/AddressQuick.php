<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

/**
 * 地址选择 + 列表过滤 + 可选快捷添加的共用工具条。
 * 选择地址后立即按对接参数过滤目标行；添加与搜索共用同一选择源。
 */
class AddressQuick implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:address-quick';
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
            'id' => false,
            'code' => false,
            'class' => false,
            'style' => false,
            'mode' => false,
            'levels' => false,
            'catalog' => false,
            'searchable' => false,
            'cascade' => false,
            'street' => false,
            'country-name' => false,
            'province-name' => false,
            'city-name' => false,
            'district-name' => false,
            'filter-target' => false,
            'filter-attr' => false,
            'filter-match' => false,
            'filter-empty' => false,
            'add-action' => false,
            'add-method' => false,
            'add-label' => false,
            'add-node-kind' => false,
            'add-parent-id' => false,
            'add-website-id' => false,
            'add-mode-value' => false,
            'add-warehouse-type' => false,
            'add-name-placeholder' => false,
            'add-segment-name' => false,
            'add-name-field' => false,
            'show-code' => false,
            'show-name' => false,
            'code-readonly' => false,
            'title' => false,
            'testid' => false,
            'csrf' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $attributes = is_array($attributes) ? $attributes : [];
            $tagAttributes = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);

            return '<?php ' . $tagAttributes . ' ?>' . "\n" . self::buildMarkup($attributes);
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
    public static function renderHtml(array $attributes): string
    {
        return self::buildMarkup($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function buildMarkup(array $attributes): string
    {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $bool = static function (array $attributes, string $key, bool $default): bool {
            if (!array_key_exists($key, $attributes)) {
                return $default;
            }

            return in_array(strtolower((string)$attributes[$key]), ['1', 'true', 'yes', 'on'], true);
        };

        $code = trim((string)($attributes['code'] ?? ''));
        if ($code === '') {
            $code = 'aq_' . substr(md5(uniqid('', true)), 0, 8);
        }
        $id = trim((string)($attributes['id'] ?? $code));
        $mode = strtolower(trim((string)($attributes['mode'] ?? 'both')));
        if (!in_array($mode, ['both', 'filter', 'add'], true)) {
            $mode = 'both';
        }
        $levels = trim((string)($attributes['levels'] ?? 'country'));
        $catalog = strtolower(trim((string)($attributes['catalog'] ?? 'global')));
        if (!in_array($catalog, ['installed', 'global'], true)) {
            $catalog = 'global';
        }
        $filterTarget = trim((string)($attributes['filter-target'] ?? ''));
        $filterAttr = trim((string)($attributes['filter-attr'] ?? 'data-country-code'));
        $filterMatch = strtolower(trim((string)($attributes['filter-match'] ?? 'exact')));
        if (!in_array($filterMatch, ['exact', 'prefix', 'contains'], true)) {
            $filterMatch = 'exact';
        }
        $filterEmpty = strtolower(trim((string)($attributes['filter-empty'] ?? 'all')));
        if (!in_array($filterEmpty, ['all', 'none'], true)) {
            $filterEmpty = 'all';
        }
        $showCode = $bool($attributes, 'show-code', true);
        $showName = $bool($attributes, 'show-name', true);
        $codeReadonly = $bool($attributes, 'code-readonly', true);
        $enableFilter = $mode === 'filter' || $mode === 'both';
        $enableAdd = $mode === 'add' || $mode === 'both';

        $countryName = trim((string)($attributes['country-name'] ?? ($code . '_country_code')));
        $provinceName = trim((string)($attributes['province-name'] ?? ($code . '_province_code')));
        $title = trim((string)($attributes['title'] ?? ''));
        if ($title === '') {
            $title = '地址选择（搜索过滤与快捷添加共用）';
        }
        $addLabel = trim((string)($attributes['add-label'] ?? ''));
        if ($addLabel === '') {
            $addLabel = '添加';
        }
        $namePlaceholder = trim((string)($attributes['add-name-placeholder'] ?? ''));
        if ($namePlaceholder === '') {
            $namePlaceholder = '例如：中国';
        }
        $segmentField = trim((string)($attributes['add-segment-name'] ?? 'code_segment'));
        $nameField = trim((string)($attributes['add-name-field'] ?? 'name'));
        $addAction = trim((string)($attributes['add-action'] ?? ''));
        $addMethod = strtoupper(trim((string)($attributes['add-method'] ?? 'post')));
        if (!in_array($addMethod, ['POST', 'GET'], true)) {
            $addMethod = 'POST';
        }
        $nodeKind = trim((string)($attributes['add-node-kind'] ?? 'country'));
        $testid = trim((string)($attributes['testid'] ?? 'theme-address-quick'));
        $class = trim((string)($attributes['class'] ?? ''));
        $style = trim((string)($attributes['style'] ?? ''));
        $csrf = strtolower(trim((string)($attributes['csrf'] ?? 'auto')));

        $addressAttrs = [
            'id' => $id . '_address',
            'code' => $code . '_pick',
            'levels' => $levels,
            'catalog' => $catalog,
            'searchable' => $bool($attributes, 'searchable', true) ? 'true' : 'false',
            'cascade' => $bool($attributes, 'cascade', false) ? 'true' : 'false',
            'street' => $bool($attributes, 'street', false) ? 'true' : 'false',
            'country-name' => $countryName,
            'province-name' => $provinceName,
            'city-name' => (string)($attributes['city-name'] ?? ($code . '_city_code')),
            'district-name' => (string)($attributes['district-name'] ?? ($code . '_district_code')),
        ];

        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $html = [];
        $html[] = '<div class="w-address-quick' . ($class !== '' ? ' ' . $escape($class) : '') . '"'
            . ' id="' . $escape($id) . '"'
            . ' data-w-address-quick="1"'
            . ' data-testid="' . $escape($testid) . '"'
            . ' data-mode="' . $escape($mode) . '"'
            . ' data-filter-enabled="' . ($enableFilter ? '1' : '0') . '"'
            . ' data-add-enabled="' . ($enableAdd ? '1' : '0') . '"'
            . ' data-filter-target="' . $escape($filterTarget) . '"'
            . ' data-filter-attr="' . $escape($filterAttr) . '"'
            . ' data-filter-match="' . $escape($filterMatch) . '"'
            . ' data-filter-empty="' . $escape($filterEmpty) . '"'
            . ' data-country-field="' . $escape($countryName) . '"'
            . ' data-province-field="' . $escape($provinceName) . '"'
            . ($style !== '' ? ' style="' . $escape($style) . '"' : '')
            . '>';
        $html[] = '  <p class="w-text" data-tone="muted" style="--w-mb:var(--weline-space-2);--w-font-size:var(--weline-layout-font-size-sm,13px);">'
            . $escape($title) . '</p>';
        $html[] = '  <div class="w-grid w-address-quick__row" style="--w-gap:var(--weline-space-3);--w-align:end;">';
        $html[] = '    <div class="w-address-quick__pick" style="--w-span-md:' . ($enableAdd ? '4' : '12') . ';">';
        $html[] = Address::renderHtml($addressAttrs);
        $html[] = '    </div>';

        if ($enableAdd) {
            $html[] = '    <form method="' . $escape($addMethod) . '" action="' . $escape($addAction) . '"'
                . ' class="w-grid w-address-quick__add" data-testid="' . $escape($testid) . '-add-form"'
                . ' style="--w-gap:var(--weline-space-3);--w-span-md:8;">';
            if ($csrf === 'auto' && $addAction !== '') {
                try {
                    $html[] = '      ' . $template->getFormKey($addAction);
                } catch (\Throwable) {
                    // CSRF optional when FormKey unavailable in isolated render.
                }
            }
            $html[] = '      <input type="hidden" name="node_kind" value="' . $escape($nodeKind) . '">';
            $html[] = '      <input type="hidden" name="parent_id" value="' . $escape((string)($attributes['add-parent-id'] ?? '0')) . '">';
            if (array_key_exists('add-website-id', $attributes)) {
                $html[] = '      <input type="hidden" name="website_id" value="' . $escape((string)$attributes['add-website-id']) . '">';
            }
            $html[] = '      <input type="hidden" name="mode" value="' . $escape((string)($attributes['add-mode-value'] ?? 'normal')) . '">';
            $html[] = '      <input type="hidden" name="warehouse_type" value="' . $escape((string)($attributes['add-warehouse-type'] ?? 'logical')) . '">';
            $html[] = '      <input type="hidden" name="country_code" value="" data-aq-country-code>';
            $html[] = '      <input type="hidden" name="region_code" value="" data-aq-region-code>';
            if ($showCode) {
                $html[] = '      <div style="--w-span-md:3;">';
                $html[] = '        <label class="w-field__label" for="' . $escape($id) . '_segment">代码</label>';
                $html[] = '        <input class="w-input" id="' . $escape($id) . '_segment" name="' . $escape($segmentField) . '" maxlength="32" required'
                    . ($codeReadonly ? ' readonly' : '')
                    . ' data-aq-segment autocomplete="off">';
                $html[] = '      </div>';
            } else {
                $html[] = '      <input type="hidden" name="' . $escape($segmentField) . '" value="" data-aq-segment>';
            }
            if ($showName) {
                $html[] = '      <div style="--w-span-md:5;">';
                $html[] = '        <label class="w-field__label" for="' . $escape($id) . '_name">名称</label>';
                $html[] = '        <input class="w-input" id="' . $escape($id) . '_name" name="' . $escape($nameField) . '" maxlength="128" required'
                    . ' placeholder="' . $escape($namePlaceholder) . '" data-aq-name>';
                $html[] = '      </div>';
            }
            $html[] = '      <div class="w-cluster" data-align="end" style="--w-span-md:2;">';
            $html[] = '        <button class="w-button" type="submit" data-w-width="full" data-tone="primary" data-aq-submit>' . $escape($addLabel) . '</button>';
            $html[] = '      </div>';
            $html[] = '    </form>';
        }

        $html[] = '  </div>';
        $html[] = '  <p class="w-text" data-tone="muted" data-aq-status hidden style="--w-mt:var(--weline-space-2);--w-mb:0;--w-font-size:var(--weline-layout-font-size-sm,13px);"></p>';
        $html[] = '</div>';

        $loaderUrl = $escape((string)$template->fetchTagSource(
            DataInterface::dir_type_STATICS,
            'Weline_Theme::js/address-quick-loader.js',
        ));
        $loaderUrl .= (str_contains($loaderUrl, '?') ? '&' : '?') . 'v=20260911-aq1';
        $html[] = '<script src="' . $loaderUrl . '" data-w-address-quick-loader data-no-extract="true" defer></script>';

        return implode("\n", $html);
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
        $doc = <<<'DOC'
<h3><code>&lt;w:theme:address-quick&gt;</code> 使用文档</h3>
<p>地址选择 + 列表过滤 + 可选快捷添加的共用工具条。内嵌 <code>theme:address</code>；选择后立即按对接参数过滤目标行；添加与搜索共用同一选择源。</p>
<ul>
<li><code>mode</code>：<code>both</code>（默认）|<code>filter</code>|<code>add</code></li>
<li><code>filter-target</code>：CSS 选择器（如表格行）；<code>filter-attr</code> 默认 <code>data-country-code</code>；<code>filter-match</code>：<code>exact|prefix|contains</code></li>
<li><code>filter-empty</code>：无选择时 <code>all</code>（默认显示全部）或 <code>none</code></li>
<li><code>add-action</code> / <code>add-label</code> / <code>add-node-kind</code> / <code>add-website-id</code> 等：快捷添加表单对接</li>
<li><code>levels</code> / <code>catalog</code> / <code>searchable</code> / <code>cascade</code>：透传给内嵌 address</li>
<li>事件：<code>weline:address-quick:change</code>（detail 含 country/province/needle/visible）</li>
</ul>
<pre>&lt;w:theme:address-quick
  code="inventory-warehouse-country"
  mode="both"
  levels="country"
  catalog="global"
  filter-target="[data-testid=inventory-warehouse-tree-table] tbody tr"
  filter-attr="data-country-code"
  add-action="/backend/.../create-child-warehouse"
  add-label="添加国家"
/&gt;</pre>
DOC;

        return \htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
