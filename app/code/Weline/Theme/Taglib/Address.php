<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

class Address implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:address';
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
            'for' => false,
            'levels' => false,
            'code' => false,
            'name' => false,
            'country-name' => false,
            'province-name' => false,
            'city-name' => false,
            'district-name' => false,
            'street-name' => false,
            'country' => false,
            'province' => false,
            'city' => false,
            'district' => false,
            'street' => false,
            'cascade' => false,
            'searchable' => false,
            'url' => false,
            'catalog' => false,
            'class' => false,
            'style' => false,
            'selection' => false,
            'multi-levels' => false,
            'multi-country' => false,
            'multi-province' => false,
            'multi-city' => false,
            'multi-district' => false,
            'multi-street' => false,
            'postal' => false,
            'postal-lookup' => false,
            'postal-name' => false,
            'detail' => false,
            'detail-name' => false,
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

    /**
     * AST 动态属性（如 country="<?= $esc($countryCode) ?>"）会走 renderRuntimeTag。
     * 运行期必须直接输出 HTML；若回落 compile-time callback，<?php ... ?> 会被当成文本写出，
     * 再经 htmlspecialchars 后浏览器把未闭合的 <?php 当 PI 吞掉后续整页主内容。
     */
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

            $attributes = is_array($attributes) ? $attributes : [];
            if (empty($attributes['id'])) {
                $attributes['id'] = 'ms_' . substr(md5(uniqid('', true)), 0, 6);
            }

            return self::buildMarkup($attributes);
        };
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

        // levels 优先：内置 <for> 标签会吞掉同名属性 for，导致级联回落到默认 country|province|city。
        $for = trim((string)($attributes['levels'] ?? $attributes['for'] ?? 'country|province|city'));
        $code = trim((string)($attributes['code'] ?? ''));
        $id = trim((string)($attributes['id'] ?? ''));
        $name = trim((string)($attributes['name'] ?? ''));
        $class = trim((string)($attributes['class'] ?? ''));
        $style = trim((string)($attributes['style'] ?? ''));
        $sourceUrl = trim((string)($attributes['url'] ?? ''));
        if ($sourceUrl === '') {
            // Always resolve against frontend area: backend-prefixed /{admin}/shipping/frontend/... 404s.
            $sourceUrl = w_url('/shipping/frontend/region/list', [], 'frontend');
        } elseif (!preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $sourceUrl)) {
            $sourceUrl = w_url($sourceUrl, [], 'frontend');
        }
        $searchable = $bool($attributes, 'searchable', true);
        $cascade = $bool($attributes, 'cascade', true);
        $includeDistrict = $bool($attributes, 'district', true);
        $catalog = strtolower(trim((string)($attributes['catalog'] ?? 'installed')));
        if (!in_array($catalog, ['installed', 'global'], true)) {
            $catalog = 'installed';
        }
        $locale = (string)(w_env('user.lang') ?: \Weline\Framework\Http\Cookie::getLangLocal() ?: 'zh_Hans_CN');
        $useEnglishFallback = !str_starts_with($locale, 'zh');
        $translate = static function (string $source, string $fallback) use ($useEnglishFallback): string {
            $translated = (string)__($source);
            if ($useEnglishFallback && ($translated === '' || $translated === $source)) {
                return $fallback;
            }

            return $translated ?: $source;
        };

        $names = [
            'country' => (string)($attributes['country-name'] ?? 'country'),
            'province' => (string)($attributes['province-name'] ?? 'province'),
            'city' => (string)($attributes['city-name'] ?? 'city'),
            'district' => (string)($attributes['district-name'] ?? 'district'),
            'street' => (string)($attributes['street-name'] ?? 'street'),
        ];

        $levels = array_values(array_filter(array_map('trim', preg_split('/[|,]+/', $for) ?: [])));
        $validLevels = ['country', 'province', 'city', 'district', 'street'];
        $levels = array_values(array_intersect($validLevels, $levels));
        if (empty($levels)) {
            $levels = ['country', 'province', 'city'];
        }

        $hasExplicitLevels = isset($attributes['levels']) || isset($attributes['for']);
        $isCombo = count($levels) > 1 || !$hasExplicitLevels;
        if ($isCombo && $includeDistrict && !in_array('district', $levels, true)) {
            $levels[] = 'district';
        }
        $includeStreet = $bool($attributes, 'street', true);
        if ($isCombo && $includeStreet && !in_array('street', $levels, true)) {
            // street is cascade_if_available: JS probes has_streets; keep level declared
            $levels[] = 'street';
        }
        $levels = array_values(array_intersect($validLevels, $levels));

        if (count($levels) === 1 && $name !== '') {
            $names[$levels[0]] = $name;
        }

        $filters = [
            'country' => (string)($attributes['country'] ?? ''),
            'province' => (string)($attributes['province'] ?? ''),
            'city' => (string)($attributes['city'] ?? ''),
            'district' => (string)($attributes['district-filter'] ?? $attributes['district'] ?? ''),
            'street' => (string)($attributes['street-filter'] ?? ''),
        ];
        // street/district boolean toggles must not leak into value filters
        foreach (['district', 'street'] as $flagKey) {
            $raw = strtolower(trim((string)($attributes[$flagKey] ?? '')));
            if (in_array($raw, ['1', 'true', 'yes', 'on', '0', 'false', 'no', 'off', ''], true)) {
                $filters[$flagKey] = '';
            }
        }
        $labels = [
            'country' => $translate('国家/地区', 'Country/Region'),
            'province' => $translate('省份', 'Province'),
            'city' => $translate('城市', 'City'),
            'district' => $translate('区县', 'District'),
            'street' => $translate('街道', 'Street'),
            'empty' => $translate('暂无可选地区', 'No regions available'),
            'manual' => $translate('可直接输入该地区', 'You can enter this region manually'),
            'selectCountry' => $translate('请选择国家/地区', 'Please select country/region'),
            'selectProvince' => $translate('请选择省份', 'Please select province'),
            'selectCity' => $translate('请选择城市', 'Please select city'),
            'selectDistrict' => $translate('请选择区县', 'Please select district'),
            'selectStreet' => $translate('请选择街道', 'Please select street'),
            'selectCountryFirst' => $translate('请先选择国家/地区', 'Please select country/region first'),
            'selectProvinceFirst' => $translate('请先选择省份', 'Please select province first'),
            'selectCityFirst' => $translate('请先选择城市', 'Please select city first'),
            'enterStreet' => $translate('请填写详细地址', 'Enter street address'),
            'loading' => $translate('加载中…', 'Loading…'),
            'selectPostalCountry' => $translate('该邮编匹配多个国家，请选择', 'This postal matches multiple countries — please choose'),
            'unsupportedCountry' => $translate('本站不支持', 'Not supported by this store'),
            'embargoedRegion' => $translate('不支持配送', 'Delivery not supported'),
            'postalCountryGroup' => $translate('邮编匹配', 'Postal matches'),
            'multiHint' => $translate('尚未选择，请搜索后添加', 'Nothing selected yet — search to add'),
            'searchCountry' => $translate('搜索并添加国家/地区', 'Search and add country/region'),
            'searchProvince' => $translate('搜索并添加省份', 'Search and add province'),
            'searchCity' => $translate('搜索并添加城市', 'Search and add city'),
            'searchDistrict' => $translate('搜索并添加区县', 'Search and add district'),
            'searchStreet' => $translate('搜索并添加街道', 'Search and add street'),
            'typeToFilter' => $translate('输入关键字筛选更多', 'Type to filter more results'),
            'poolCountHint' => $translate('共 {n} 项可选，输入关键字筛选', '{n} options available — type to filter'),
            'selectDistrictFirst' => $translate('请先选择区县', 'Please select district first'),
        ];

        $postalLookup = $bool($attributes, 'postal-lookup', false);
        // postal=true renders the field; postal-lookup alone can drive an external data-postal-first input.
        $includePostal = $bool($attributes, 'postal', false);
        if ($postalLookup && !array_key_exists('postal', $attributes)) {
            $includePostal = true;
        }
        $includeDetail = $bool($attributes, 'detail', false);
        $postalName = trim((string)($attributes['postal-name'] ?? 'postal_code')) ?: 'postal_code';
        $detailName = trim((string)($attributes['detail-name'] ?? 'address1')) ?: 'address1';
        $postalLabel = $translate('邮编', 'Postal code');
        $detailLabel = $translate('详细地址', 'Street address');

        $data = [
            'for' => implode('|', $levels),
            'code' => $code,
            'names' => $names,
            'labels' => $labels,
            'filters' => $filters,
            'sourceUrl' => $sourceUrl,
            'searchable' => $searchable,
            'cascade' => $cascade,
            'catalog' => $catalog,
            'selection' => strtolower(trim((string)($attributes['selection'] ?? 'single'))) === 'multi' ? 'multi' : 'single',
            'multiLevels' => self::resolveMultiLevels($attributes, $levels),
            'postalLookup' => $postalLookup,
            'postalName' => $postalName,
            'detailName' => $detailName,
        ];

        $idAttr = $id !== '' ? ' id="' . $escape($id) . '"' : '';
        $configJson = $escape(json_encode($data, JSON_UNESCAPED_UNICODE));
        $cascadeHtml = '<div' . $idAttr . ' class="w-address ' . $escape($class) . '" style="' . $escape($style) . '" data-w-address data-address-config="' . $configJson . '"'
            . ($postalLookup ? ' data-postal-lookup="1"' : '')
            . '></div>';

        $html = [];
        if ($includePostal || $includeDetail) {
            $shellClass = 'w-address-shell' . ($postalLookup ? ' w-address-shell--postal-lookup' : '');
            $shell = '<div class="' . $escape($shellClass) . '" data-w-address-shell'
                . ($code !== '' ? ' data-address-code="' . $escape($code) . '"' : '')
                . ($postalLookup ? ' data-postal-lookup="1"' : '')
                . '>';
            if ($includePostal) {
                $shell .= '<label class="w-address__postal">'
                    . '<span class="w-address__label">' . $escape($postalLabel) . '</span>'
                    . '<input class="w-form-control w-input w-address__postal-input" type="text" name="'
                    . $escape($postalName) . '" autocomplete="postal-code" data-w-address-postal data-postal-first>'
                    . '</label>';
            }
            $shell .= $cascadeHtml;
            if ($includeDetail) {
                $shell .= '<label class="w-address__detail">'
                    . '<span class="w-address__label">' . $escape($detailLabel) . '</span>'
                    . '<input class="w-form-control w-input w-address__detail-input" type="text" name="'
                    . $escape($detailName) . '" autocomplete="street-address" data-w-address-detail'
                    . ' placeholder="' . $escape($translate('门牌号 / 楼栋单元等', 'Building / unit number')) . '">'
                    . '</label>';
            }
            $shell .= '</div>';
            $html[] = $shell;
        } else {
            $html[] = $cascadeHtml;
        }
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        $loaderUrl = $escape((string)$template->fetchTagSource(
            DataInterface::dir_type_STATICS,
            'Weline_Theme::js/address-loader.js',
        ));
        $html[] = '<script src="' . $loaderUrl . '" data-w-address-loader data-no-extract="true" defer></script>';

        return implode("\n", $html);
    }

    private static function resolveMultiLevels(array $attributes, array $levels): array
    {
        $valid = ['country', 'province', 'city', 'district', 'street'];
        $explicit = trim((string)($attributes['multi-levels'] ?? ''));
        if ($explicit !== '') {
            $parsed = array_values(array_filter(array_map('trim', preg_split('/[|,]+/', $explicit) ?: [])));
            $parsed = array_values(array_intersect($valid, $parsed));
            if ($parsed !== []) {
                return $parsed;
            }
        }
        $fieldFlags = [];
        foreach ($valid as $level) {
            $key = 'multi-' . $level;
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $raw = strtolower(trim((string)$attributes[$key]));
            if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
                $fieldFlags[] = $level;
            }
        }
        if ($fieldFlags !== []) {
            return $fieldFlags;
        }

        return $levels;
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
<h3><code>&lt;w:theme:address&gt;</code> 使用文档</h3>
<p>国家/省/市/区/街道地址选择器，配合 <code>address-loader.js</code> 与 <code>data-w-address</code>。数据来自 Shipping <code>w_shipping_regions</code> / <code>address-catalog</code>（经 <code>region</code> Query + <code>Weline.Api</code>），不接外网 Places。</p>
<ul>
<li><code>levels</code> / <code>for</code>：级联层级，可含 <code>street</code>。街道为 <strong>有数据可选、无数据手填</strong>（JS 调 <code>has_streets</code>）。</li>
<li><code>catalog</code>：<code>installed</code>（默认）或 <code>global</code> 国家目录。</li>
<li><code>cascade="false"</code>：关闭下级联动；<code>searchable="true"</code>：可搜索。</li>
<li><code>postal="true"</code>：壳内输出邮编；<code>postal-lookup="true"</code>：邮编 debounce 反查回填（逻辑在 address.js）。仅 lookup 时可驱动壳外 <code>data-postal-first</code> 邮编框。</li>
<li><code>detail="true"</code>：输出详细地址字段（默认 name=address1）。</li>
<li><code>selection="multi"</code>：批量多选模式（承运商覆盖/可售目的地）。配合 <code>multi-levels="country|province|district"</code> 或字段级 <code>multi-country</code> 等；输出 <code>data-multi-selection</code> JSON，并触发 <code>weline:address:multi-change</code>。</li>
<li><strong>浮层硬约束</strong>：multi 搜索下拉必须经 <code>Weline.UI.floating.attach</code>（<code>anchored-float</code>）做 portal / flip / 视口限界；禁止在业务脚本手写 <code>left/top</code> 或自研边界检测。单选级联菜单后续也应收敛到同一浮层内核。</li>
<li>门牌/补充：有街选择时，详细地址可作为可选补充；无街时 <code>address1</code> 为必填整段街道门牌。</li>
</ul>
<p>示例（全球国家，不联动省市区）：</p>
<pre>&lt;w:theme:address levels="country" catalog="global" code="supplier-country" searchable="true" cascade="false" /&gt;</pre>
<pre>&lt;w:theme:address levels="country,province,city" district="true" postal="true" postal-lookup="true" detail="true" code="product-quote-address" /&gt;</pre>
<p>示例（承运商三级多选）：</p>
<pre>&lt;w:theme:address selection="multi" multi-levels="country|province|district" levels="country|province|district" catalog="global" code="carrier-coverage" /&gt;</pre>
DOC;

        return \htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
