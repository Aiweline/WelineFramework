<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Catalog;

/**
 * 洲目录：AI 获客线索的洲归属常量、展示信息与名称归一化。
 *
 * 只做通用七大洲映射，不包含任何站点专属常量。
 */
final class ContinentCatalog
{
    public const AS = 'AS';
    public const EU = 'EU';
    public const AF = 'AF';
    public const NA = 'NA';
    public const SA = 'SA';
    public const OC = 'OC';
    public const AN = 'AN';
    public const UNKNOWN = 'UNKNOWN';

    /**
     * 洲代码 => 展示信息（sort 越小越靠前）。
     *
     * @var array<string,array{name_zh:string,name_en:string,sort:int}>
     */
    private const CONTINENTS = [
        self::AS => ['name_zh' => '亚洲', 'name_en' => 'Asia', 'sort' => 10],
        self::EU => ['name_zh' => '欧洲', 'name_en' => 'Europe', 'sort' => 20],
        self::AF => ['name_zh' => '非洲', 'name_en' => 'Africa', 'sort' => 30],
        self::NA => ['name_zh' => '北美洲', 'name_en' => 'North America', 'sort' => 40],
        self::SA => ['name_zh' => '南美洲', 'name_en' => 'South America', 'sort' => 50],
        self::OC => ['name_zh' => '大洋洲', 'name_en' => 'Oceania', 'sort' => 60],
        self::AN => ['name_zh' => '南极洲', 'name_en' => 'Antarctica', 'sort' => 70],
    ];

    /**
     * 别名 => 洲代码；键统一小写。
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'as' => self::AS,
        'asia' => self::AS,
        '亚洲' => self::AS,
        'eu' => self::EU,
        'europe' => self::EU,
        '欧洲' => self::EU,
        'af' => self::AF,
        'africa' => self::AF,
        '非洲' => self::AF,
        'na' => self::NA,
        'north america' => self::NA,
        'north-america' => self::NA,
        '北美洲' => self::NA,
        '北美' => self::NA,
        'sa' => self::SA,
        'south america' => self::SA,
        'south-america' => self::SA,
        '南美洲' => self::SA,
        '南美' => self::SA,
        'oc' => self::OC,
        'oceania' => self::OC,
        'australia and oceania' => self::OC,
        '大洋洲' => self::OC,
        '澳洲' => self::OC,
        'an' => self::AN,
        'antarctica' => self::AN,
        '南极洲' => self::AN,
        '南极' => self::AN,
        'unknown' => self::UNKNOWN,
        '未知' => self::UNKNOWN,
        'n/a' => self::UNKNOWN,
        'none' => self::UNKNOWN,
    ];

    /**
     * 返回按 sort 排序的洲列表，供后台下拉与安装种子使用。
     *
     * @return list<array{code:string,name_zh:string,name_en:string,sort:int}>
     */
    public static function all(): array
    {
        $rows = [];
        foreach (self::CONTINENTS as $code => $row) {
            $rows[] = [
                'code' => $code,
                'name_zh' => $row['name_zh'],
                'name_en' => $row['name_en'],
                'sort' => $row['sort'],
            ];
        }

        return $rows;
    }

    /**
     * 将洲代码/中文名/英文名归一化为洲代码，未知返回 UNKNOWN。
     */
    public static function normalize(string $value): string
    {
        $key = strtolower(trim($value));
        if ($key === '') {
            return self::UNKNOWN;
        }
        if (isset(self::CONTINENTS[strtoupper($key)])) {
            return strtoupper($key);
        }

        return self::ALIASES[$key] ?? self::UNKNOWN;
    }
}
