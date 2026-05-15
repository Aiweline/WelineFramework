<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

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
            'class' => false,
            'style' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            $bool = static function (array $attributes, string $key, bool $default): bool {
                if (!array_key_exists($key, $attributes)) {
                    return $default;
                }

                return in_array(strtolower((string)$attributes[$key]), ['1', 'true', 'yes', 'on'], true);
            };

            $for = trim((string)($attributes['for'] ?? 'country|province|city'));
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

            $isCombo = count($levels) > 1 || !isset($attributes['for']);
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

            $tagAttributes = \Weline\Taglib\Taglib::attributes($attributes);
            $data = [
                'for' => implode('|', $levels),
                'code' => $code,
                'names' => $names,
                'labels' => $labels,
                'filters' => $filters,
                'sourceUrl' => $sourceUrl,
                'searchable' => $searchable,
                'cascade' => $cascade,
            ];

            $idAttr = $id !== '' ? ' id="' . $escape($id) . '"' : '';
            $html = [];
            $html[] = '<?php ' . $tagAttributes . ' ?>';
            $html[] = '<div' . $idAttr . ' class="w-address ' . $escape($class) . '" style="' . $escape($style) . '" data-w-address data-address-config="' . $escape(json_encode($data, JSON_UNESCAPED_UNICODE)) . '"></div>';
            $html[] = '<script src="/Weline/Theme/view/statics/js/address-loader.js?v=20260514-address-loader-8" data-w-address-loader data-no-extract="true" defer></script>';

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
        return '<w:theme:address for="country|province|city" code="shipping" district="true" searchable="true" />';
    }
}
