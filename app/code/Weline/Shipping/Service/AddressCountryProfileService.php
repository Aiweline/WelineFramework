<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * 按国家决定地址字段深度（是否显示 district 等）。
 * 配置权威：data/address-country-profiles.json
 */
final class AddressCountryProfileService
{
    private const PROFILE_PATH = __DIR__ . '/../data/address-country-profiles.json';

    /** @var array{schema_version?:string,default?:array<string,mixed>,countries?:array<string,array<string,mixed>>}|null */
    private static ?array $cache = null;

    /**
     * @return array{levels:list<string>,autocomplete:bool,country_code:string}
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

        return [
            'levels' => $levels,
            'autocomplete' => $autocomplete,
            'country_code' => $code,
        ];
    }

    /**
     * @return array{default:array{levels:list<string>,autocomplete:bool},countries:array<string,array{levels:list<string>,autocomplete:bool}>}
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
