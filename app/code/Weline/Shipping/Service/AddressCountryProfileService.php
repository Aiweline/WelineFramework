<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * 按国家决定地址字段深度（是否显示 district 等）+ 合并 AddressSchemaProvider 标签（State/ZIP 等）。
 * 层级配置权威：data/address-country-profiles.json；文案权威：AddressSchemaProvider。
 */
final class AddressCountryProfileService
{
    private const PROFILE_PATH = __DIR__ . '/../data/address-country-profiles.json';

    /** @var array{schema_version?:string,default?:array<string,mixed>,countries?:array<string,array<string,mixed>>}|null */
    private static ?array $cache = null;

    public function __construct(
        private readonly AddressSchemaProvider $schemaProvider = new AddressSchemaProvider(),
    ) {
    }

    /**
     * @return array{levels:list<string>,autocomplete:bool,country_code:string,labels:array<string,string>,field_order:list<string>,name_mode:string}
     */
    public function profileFor(?string $countryCode): array
    {
        $doc = $this->document();
        $default = is_array($doc['default'] ?? null) ? $doc['default'] : [];
        $code = strtoupper(trim((string)$countryCode));
        $countries = is_array($doc['countries'] ?? null) ? $doc['countries'] : [];
        $override = ($code !== '' && isset($countries[$code]) && is_array($countries[$code]))
            ? $countries[$code]
            : [];

        $levels = $this->normalizeLevels($override['levels'] ?? $default['levels'] ?? ['country', 'province', 'city']);
        $autocomplete = $this->toBool($override['autocomplete'] ?? $default['autocomplete'] ?? true);
        $schema = $this->schemaProvider->getSchema($code !== '' ? $code : null);
        $labels = [];
        foreach ((array)($schema['labels'] ?? []) as $key => $value) {
            $label = trim((string)$value);
            if ($label !== '') {
                $labels[(string)$key] = $label;
            }
        }
        $fieldOrder = [];
        foreach ((array)($schema['field_order'] ?? []) as $field) {
            $field = trim((string)$field);
            if ($field !== '' && !in_array($field, $fieldOrder, true)) {
                $fieldOrder[] = $field;
            }
        }
        $nameMode = strtolower(trim((string)($schema['name_mode'] ?? 'single')));
        if ($nameMode !== 'split') {
            $nameMode = 'single';
        }

        return [
            'levels' => $levels,
            'autocomplete' => $autocomplete,
            'country_code' => $code,
            'labels' => $labels,
            'field_order' => $fieldOrder,
            'name_mode' => $nameMode,
        ];
    }

    /**
     * @return array{default:array<string,mixed>,countries:array<string,array<string,mixed>>}
     */
    public function allProfiles(): array
    {
        $doc = $this->document();
        $default = $this->profileFor(null);
        unset($default['country_code']);

        $countries = [];
        foreach (array_keys(is_array($doc['countries'] ?? null) ? $doc['countries'] : []) as $code) {
            $countries[strtoupper((string)$code)] = $this->profileFor((string)$code);
        }

        return [
            'default' => $default,
            'countries' => $countries,
        ];
    }

    /**
     * @return array{schema_version?:string,default?:array<string,mixed>,countries?:array<string,array<string,mixed>>}
     */
    private function document(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (!is_file(self::PROFILE_PATH)) {
            self::$cache = [
                'default' => [
                    'levels' => ['country', 'province', 'city'],
                    'autocomplete' => true,
                ],
                'countries' => [],
            ];

            return self::$cache;
        }

        $raw = file_get_contents(self::PROFILE_PATH);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        self::$cache = is_array($decoded) ? $decoded : [
            'default' => [
                'levels' => ['country', 'province', 'city'],
                'autocomplete' => true,
            ],
            'countries' => [],
        ];

        return self::$cache;
    }

    /**
     * @param mixed $levels
     * @return list<string>
     */
    private function normalizeLevels(mixed $levels): array
    {
        $valid = ['country', 'province', 'city', 'district'];
        if (!is_array($levels)) {
            return ['country', 'province', 'city'];
        }
        $out = [];
        foreach ($levels as $level) {
            $level = strtolower(trim((string)$level));
            if (in_array($level, $valid, true) && !in_array($level, $out, true)) {
                $out[] = $level;
            }
        }

        return $out !== [] ? $out : ['country', 'province', 'city'];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
