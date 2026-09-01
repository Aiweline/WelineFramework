<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;
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
            'country' => false,
            'province' => false,
            'city' => false,
            'district' => false,
            'cascade' => false,
            'searchable' => false,
            'url' => false,
            'catalog' => false,
            'class' => false,
            'style' => false,
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
            $sourceUrl = w_url('/shipping/frontend/region/list');
        } elseif (!preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $sourceUrl)) {
            $sourceUrl = w_url($sourceUrl);
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
        ];

        $levels = array_values(array_filter(array_map('trim', preg_split('/[|,]+/', $for) ?: [])));
        $validLevels = ['country', 'province', 'city', 'district'];
        $levels = array_values(array_intersect($validLevels, $levels));
        if (empty($levels)) {
            $levels = ['country', 'province', 'city'];
        }

        $hasExplicitLevels = isset($attributes['levels']) || isset($attributes['for']);
        $isCombo = count($levels) > 1 || !$hasExplicitLevels;
        if ($isCombo && $includeDistrict && !in_array('district', $levels, true)) {
            $levels[] = 'district';
        }
        $levels = array_values(array_intersect($validLevels, $levels));

        if (count($levels) === 1 && $name !== '') {
            $names[$levels[0]] = $name;
        }

        $filters = [
            'country' => (string)($attributes['country'] ?? ''),
            'province' => (string)($attributes['province'] ?? ''),
            'city' => (string)($attributes['city'] ?? ''),
        ];
        $labels = [
            'country' => $translate('国家/地区', 'Country/Region'),
            'province' => $translate('省份', 'Province'),
            'city' => $translate('城市', 'City'),
            'district' => $translate('区县', 'District'),
            'empty' => $translate('暂无可选地区', 'No regions available'),
            'manual' => $translate('可直接输入该地区', 'You can enter this region directly'),
            'selectCountry' => $translate('请选择国家/地区', 'Please select country/region'),
            'selectProvince' => $translate('请选择省份', 'Please select province'),
            'selectCity' => $translate('请选择城市', 'Please select city'),
            'selectDistrict' => $translate('请选择区县', 'Please select district'),
            'selectCountryFirst' => $translate('请先选择国家/地区', 'Please select country/region first'),
            'selectProvinceFirst' => $translate('请先选择省份', 'Please select province first'),
            'selectCityFirst' => $translate('请先选择城市', 'Please select city first'),
        ];

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
        ];

        $idAttr = $id !== '' ? ' id="' . $escape($id) . '"' : '';
        $html = [];
        $html[] = '<div' . $idAttr . ' class="w-address ' . $escape($class) . '" style="' . $escape($style) . '" data-w-address data-address-config="' . $escape(json_encode($data, JSON_UNESCAPED_UNICODE)) . '"></div>';
        $html[] = '<script src="/Weline/Theme/view/statics/js/address-loader.js?v=20260831-address-catalog-2" data-w-address-loader data-no-extract="true" defer></script>';

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
<h3><code>&lt;w:theme:address&gt;</code> 使用文档</h3>
<p>国家/省/市/区级联地址选择器，配合 <code>address-loader.js</code> 与 <code>data-w-address</code> 使用。</p>
<ul>
<li><code>levels</code> / <code>for</code>：级联层级，如 <code>country|province|city</code>；仅国家时用 <code>levels="country"</code>。</li>
<li><code>catalog</code>：国家目录范围。<code>installed</code>（默认）仅已安装/地区库国家；<code>global</code> 使用 Symfony Intl 全球国家列表，不限已安装国家（供应商申请等跨境场景）。全球目录走 HTTP JSON 接口，避免 Worker 200 条列表上限。</li>
<li><code>cascade="false"</code>：关闭下级联动；<code>searchable="true"</code>：可搜索下拉。</li>
</ul>
<p>示例（全球国家，不联动省市区）：</p>
<pre>&lt;w:theme:address levels="country" catalog="global" code="supplier-country" searchable="true" cascade="false" /&gt;</pre>
DOC;

        return \htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
