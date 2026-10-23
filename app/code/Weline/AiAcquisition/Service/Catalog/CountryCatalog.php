<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Catalog;

/**
 * 国家目录：ISO2 国家代码与洲、时区、近似经纬度的通用映射。
 *
 * 数据仅用于获客线索的地理归一化，不包含站点专属市场配置。
 * 未知国家统一回退为 XX / UNKNOWN，避免把猜测写进线索。
 */
final class CountryCatalog
{
    public const UNKNOWN_CODE = 'XX';
    public const UNKNOWN_NAME = 'UNKNOWN';

    /**
     * 国家代码 => [英文名, 中文名, 洲代码, 时区, 纬度, 经度]。
     *
     * @var array<string,array{0:string,1:string,2:string,3:string,4:float,5:float}>
     */
    private const COUNTRIES = [
        // 北美洲
        'US' => ['United States', '美国', ContinentCatalog::NA, 'America/New_York', 37.0902, -95.7129],
        'CA' => ['Canada', '加拿大', ContinentCatalog::NA, 'America/Toronto', 56.1304, -106.3468],
        'MX' => ['Mexico', '墨西哥', ContinentCatalog::NA, 'America/Mexico_City', 23.6345, -102.5528],
        'GT' => ['Guatemala', '危地马拉', ContinentCatalog::NA, 'America/Guatemala', 15.7835, -90.2308],
        'CR' => ['Costa Rica', '哥斯达黎加', ContinentCatalog::NA, 'America/Costa_Rica', 9.7489, -83.7534],
        'PA' => ['Panama', '巴拿马', ContinentCatalog::NA, 'America/Panama', 8.5380, -80.7821],
        'DO' => ['Dominican Republic', '多米尼加', ContinentCatalog::NA, 'America/Santo_Domingo', 18.7357, -70.1627],
        'CU' => ['Cuba', '古巴', ContinentCatalog::NA, 'America/Havana', 21.5218, -77.7812],
        'HN' => ['Honduras', '洪都拉斯', ContinentCatalog::NA, 'America/Tegucigalpa', 15.2000, -86.2419],
        'SV' => ['El Salvador', '萨尔瓦多', ContinentCatalog::NA, 'America/El_Salvador', 13.7942, -88.8965],
        'NI' => ['Nicaragua', '尼加拉瓜', ContinentCatalog::NA, 'America/Managua', 12.8654, -85.2072],

        // 南美洲
        'BR' => ['Brazil', '巴西', ContinentCatalog::SA, 'America/Sao_Paulo', -14.2350, -51.9253],
        'AR' => ['Argentina', '阿根廷', ContinentCatalog::SA, 'America/Argentina/Buenos_Aires', -38.4161, -63.6167],
        'CL' => ['Chile', '智利', ContinentCatalog::SA, 'America/Santiago', -35.6751, -71.5430],
        'CO' => ['Colombia', '哥伦比亚', ContinentCatalog::SA, 'America/Bogota', 4.5709, -74.2973],
        'PE' => ['Peru', '秘鲁', ContinentCatalog::SA, 'America/Lima', -9.1900, -75.0152],
        'VE' => ['Venezuela', '委内瑞拉', ContinentCatalog::SA, 'America/Caracas', 6.4238, -66.5897],
        'EC' => ['Ecuador', '厄瓜多尔', ContinentCatalog::SA, 'America/Guayaquil', -1.8312, -78.1834],
        'UY' => ['Uruguay', '乌拉圭', ContinentCatalog::SA, 'America/Montevideo', -32.5228, -55.7658],
        'PY' => ['Paraguay', '巴拉圭', ContinentCatalog::SA, 'America/Asuncion', -23.4425, -58.4438],
        'BO' => ['Bolivia', '玻利维亚', ContinentCatalog::SA, 'America/La_Paz', -16.2902, -63.5887],

        // 欧洲
        'GB' => ['United Kingdom', '英国', ContinentCatalog::EU, 'Europe/London', 55.3781, -3.4360],
        'DE' => ['Germany', '德国', ContinentCatalog::EU, 'Europe/Berlin', 51.1657, 10.4515],
        'FR' => ['France', '法国', ContinentCatalog::EU, 'Europe/Paris', 46.2276, 2.2137],
        'IT' => ['Italy', '意大利', ContinentCatalog::EU, 'Europe/Rome', 41.8719, 12.5674],
        'ES' => ['Spain', '西班牙', ContinentCatalog::EU, 'Europe/Madrid', 40.4637, -3.7492],
        'NL' => ['Netherlands', '荷兰', ContinentCatalog::EU, 'Europe/Amsterdam', 52.1326, 5.2913],
        'BE' => ['Belgium', '比利时', ContinentCatalog::EU, 'Europe/Brussels', 50.5039, 4.4699],
        'SE' => ['Sweden', '瑞典', ContinentCatalog::EU, 'Europe/Stockholm', 60.1282, 18.6435],
        'NO' => ['Norway', '挪威', ContinentCatalog::EU, 'Europe/Oslo', 60.4720, 8.4689],
        'DK' => ['Denmark', '丹麦', ContinentCatalog::EU, 'Europe/Copenhagen', 56.2639, 9.5018],
        'FI' => ['Finland', '芬兰', ContinentCatalog::EU, 'Europe/Helsinki', 61.9241, 25.7482],
        'PL' => ['Poland', '波兰', ContinentCatalog::EU, 'Europe/Warsaw', 51.9194, 19.1451],
        'PT' => ['Portugal', '葡萄牙', ContinentCatalog::EU, 'Europe/Lisbon', 39.3999, -8.2245],
        'IE' => ['Ireland', '爱尔兰', ContinentCatalog::EU, 'Europe/Dublin', 53.1424, -7.6921],
        'AT' => ['Austria', '奥地利', ContinentCatalog::EU, 'Europe/Vienna', 47.5162, 14.5501],
        'CH' => ['Switzerland', '瑞士', ContinentCatalog::EU, 'Europe/Zurich', 46.8182, 8.2275],
        'CZ' => ['Czechia', '捷克', ContinentCatalog::EU, 'Europe/Prague', 49.8175, 15.4730],
        'GR' => ['Greece', '希腊', ContinentCatalog::EU, 'Europe/Athens', 39.0742, 21.8243],
        'TR' => ['Turkey', '土耳其', ContinentCatalog::EU, 'Europe/Istanbul', 38.9637, 35.2433],
        'RU' => ['Russia', '俄罗斯', ContinentCatalog::EU, 'Europe/Moscow', 61.5240, 105.3188],
        'UA' => ['Ukraine', '乌克兰', ContinentCatalog::EU, 'Europe/Kyiv', 48.3794, 31.1656],
        'RO' => ['Romania', '罗马尼亚', ContinentCatalog::EU, 'Europe/Bucharest', 45.9432, 24.9668],
        'HU' => ['Hungary', '匈牙利', ContinentCatalog::EU, 'Europe/Budapest', 47.1625, 19.5033],
        'SK' => ['Slovakia', '斯洛伐克', ContinentCatalog::EU, 'Europe/Bratislava', 48.6690, 19.6990],
        'BG' => ['Bulgaria', '保加利亚', ContinentCatalog::EU, 'Europe/Sofia', 42.7339, 25.4858],
        'HR' => ['Croatia', '克罗地亚', ContinentCatalog::EU, 'Europe/Zagreb', 45.1000, 15.2000],
        'RS' => ['Serbia', '塞尔维亚', ContinentCatalog::EU, 'Europe/Belgrade', 44.0165, 21.0059],
        'SI' => ['Slovenia', '斯洛文尼亚', ContinentCatalog::EU, 'Europe/Ljubljana', 46.1512, 14.9955],
        'LT' => ['Lithuania', '立陶宛', ContinentCatalog::EU, 'Europe/Vilnius', 55.1694, 23.8813],
        'LV' => ['Latvia', '拉脱维亚', ContinentCatalog::EU, 'Europe/Riga', 56.8796, 24.6032],
        'EE' => ['Estonia', '爱沙尼亚', ContinentCatalog::EU, 'Europe/Tallinn', 58.5953, 25.0136],
        'IS' => ['Iceland', '冰岛', ContinentCatalog::EU, 'Atlantic/Reykjavik', 64.9631, -19.0208],
        'LU' => ['Luxembourg', '卢森堡', ContinentCatalog::EU, 'Europe/Luxembourg', 49.8153, 6.1296],
        'MT' => ['Malta', '马耳他', ContinentCatalog::EU, 'Europe/Malta', 35.9375, 14.3754],
        'CY' => ['Cyprus', '塞浦路斯', ContinentCatalog::EU, 'Asia/Nicosia', 35.1264, 33.4299],

        // 亚洲
        'JP' => ['Japan', '日本', ContinentCatalog::AS, 'Asia/Tokyo', 36.2048, 138.2529],
        'KR' => ['South Korea', '韩国', ContinentCatalog::AS, 'Asia/Seoul', 35.9078, 127.7669],
        'KP' => ['North Korea', '朝鲜', ContinentCatalog::AS, 'Asia/Pyongyang', 40.3399, 127.5101],
        'CN' => ['China', '中国', ContinentCatalog::AS, 'Asia/Shanghai', 35.8617, 104.1954],
        'HK' => ['Hong Kong', '中国香港', ContinentCatalog::AS, 'Asia/Hong_Kong', 22.3193, 114.1694],
        'TW' => ['Taiwan', '中国台湾', ContinentCatalog::AS, 'Asia/Taipei', 23.6978, 120.9605],
        'MO' => ['Macau', '中国澳门', ContinentCatalog::AS, 'Asia/Macau', 22.1987, 113.5439],
        'SG' => ['Singapore', '新加坡', ContinentCatalog::AS, 'Asia/Singapore', 1.3521, 103.8198],
        'MY' => ['Malaysia', '马来西亚', ContinentCatalog::AS, 'Asia/Kuala_Lumpur', 4.2105, 101.9758],
        'TH' => ['Thailand', '泰国', ContinentCatalog::AS, 'Asia/Bangkok', 15.8700, 100.9925],
        'VN' => ['Vietnam', '越南', ContinentCatalog::AS, 'Asia/Ho_Chi_Minh', 14.0583, 108.2772],
        'ID' => ['Indonesia', '印度尼西亚', ContinentCatalog::AS, 'Asia/Jakarta', -0.7893, 113.9213],
        'PH' => ['Philippines', '菲律宾', ContinentCatalog::AS, 'Asia/Manila', 12.8797, 121.7740],
        'IN' => ['India', '印度', ContinentCatalog::AS, 'Asia/Kolkata', 20.5937, 78.9629],
        'PK' => ['Pakistan', '巴基斯坦', ContinentCatalog::AS, 'Asia/Karachi', 30.3753, 69.3451],
        'BD' => ['Bangladesh', '孟加拉国', ContinentCatalog::AS, 'Asia/Dhaka', 23.6850, 90.3563],
        'LK' => ['Sri Lanka', '斯里兰卡', ContinentCatalog::AS, 'Asia/Colombo', 7.8731, 80.7718],
        'NP' => ['Nepal', '尼泊尔', ContinentCatalog::AS, 'Asia/Kathmandu', 28.3949, 84.1240],
        'MM' => ['Myanmar', '缅甸', ContinentCatalog::AS, 'Asia/Yangon', 21.9162, 95.9560],
        'KH' => ['Cambodia', '柬埔寨', ContinentCatalog::AS, 'Asia/Phnom_Penh', 12.5657, 104.9910],
        'LA' => ['Laos', '老挝', ContinentCatalog::AS, 'Asia/Vientiane', 19.8563, 102.4955],
        'BN' => ['Brunei', '文莱', ContinentCatalog::AS, 'Asia/Brunei', 4.5353, 114.7277],
        'MN' => ['Mongolia', '蒙古', ContinentCatalog::AS, 'Asia/Ulaanbaatar', 46.8625, 103.8467],
        'AE' => ['United Arab Emirates', '阿联酋', ContinentCatalog::AS, 'Asia/Dubai', 23.4241, 53.8478],
        'SA' => ['Saudi Arabia', '沙特阿拉伯', ContinentCatalog::AS, 'Asia/Riyadh', 23.8859, 45.0792],
        'QA' => ['Qatar', '卡塔尔', ContinentCatalog::AS, 'Asia/Qatar', 25.3548, 51.1839],
        'KW' => ['Kuwait', '科威特', ContinentCatalog::AS, 'Asia/Kuwait', 29.3117, 47.4818],
        'BH' => ['Bahrain', '巴林', ContinentCatalog::AS, 'Asia/Bahrain', 26.0667, 50.5577],
        'OM' => ['Oman', '阿曼', ContinentCatalog::AS, 'Asia/Muscat', 21.4735, 55.9754],
        'IL' => ['Israel', '以色列', ContinentCatalog::AS, 'Asia/Jerusalem', 31.0461, 34.8516],
        'JO' => ['Jordan', '约旦', ContinentCatalog::AS, 'Asia/Amman', 30.5852, 36.2384],
        'LB' => ['Lebanon', '黎巴嫩', ContinentCatalog::AS, 'Asia/Beirut', 33.8547, 35.8623],
        'IQ' => ['Iraq', '伊拉克', ContinentCatalog::AS, 'Asia/Baghdad', 33.3152, 44.3661],
        'IR' => ['Iran', '伊朗', ContinentCatalog::AS, 'Asia/Tehran', 32.4279, 53.6880],
        'KZ' => ['Kazakhstan', '哈萨克斯坦', ContinentCatalog::AS, 'Asia/Almaty', 48.0196, 66.9237],
        'UZ' => ['Uzbekistan', '乌兹别克斯坦', ContinentCatalog::AS, 'Asia/Tashkent', 41.3775, 64.5853],
        'AZ' => ['Azerbaijan', '阿塞拜疆', ContinentCatalog::AS, 'Asia/Baku', 40.1431, 47.5769],
        'GE' => ['Georgia', '格鲁吉亚', ContinentCatalog::AS, 'Asia/Tbilisi', 42.3154, 43.3569],
        'AM' => ['Armenia', '亚美尼亚', ContinentCatalog::AS, 'Asia/Yerevan', 40.0691, 45.0382],

        // 非洲
        'EG' => ['Egypt', '埃及', ContinentCatalog::AF, 'Africa/Cairo', 26.8206, 30.8025],
        'ZA' => ['South Africa', '南非', ContinentCatalog::AF, 'Africa/Johannesburg', -30.5595, 22.9375],
        'NG' => ['Nigeria', '尼日利亚', ContinentCatalog::AF, 'Africa/Lagos', 9.0820, 8.6753],
        'KE' => ['Kenya', '肯尼亚', ContinentCatalog::AF, 'Africa/Nairobi', -0.0236, 37.9062],
        'MA' => ['Morocco', '摩洛哥', ContinentCatalog::AF, 'Africa/Casablanca', 31.7917, -7.0926],
        'ET' => ['Ethiopia', '埃塞俄比亚', ContinentCatalog::AF, 'Africa/Addis_Ababa', 9.1450, 40.4897],
        'GH' => ['Ghana', '加纳', ContinentCatalog::AF, 'Africa/Accra', 7.9465, -1.0232],
        'TZ' => ['Tanzania', '坦桑尼亚', ContinentCatalog::AF, 'Africa/Dar_es_Salaam', -6.3690, 34.8888],
        'UG' => ['Uganda', '乌干达', ContinentCatalog::AF, 'Africa/Kampala', 1.3733, 32.2903],
        'DZ' => ['Algeria', '阿尔及利亚', ContinentCatalog::AF, 'Africa/Algiers', 28.0339, 1.6596],
        'TN' => ['Tunisia', '突尼斯', ContinentCatalog::AF, 'Africa/Tunis', 33.8869, 9.5375],
        'CI' => ['Ivory Coast', '科特迪瓦', ContinentCatalog::AF, 'Africa/Abidjan', 7.5400, -5.5471],
        'SN' => ['Senegal', '塞内加尔', ContinentCatalog::AF, 'Africa/Dakar', 14.4974, -14.4524],
        'CM' => ['Cameroon', '喀麦隆', ContinentCatalog::AF, 'Africa/Douala', 7.3697, 12.3547],
        'AO' => ['Angola', '安哥拉', ContinentCatalog::AF, 'Africa/Luanda', -11.2027, 17.8739],
        'MZ' => ['Mozambique', '莫桑比克', ContinentCatalog::AF, 'Africa/Maputo', -18.6657, 35.5296],
        'ZM' => ['Zambia', '赞比亚', ContinentCatalog::AF, 'Africa/Lusaka', -13.1339, 27.8493],
        'ZW' => ['Zimbabwe', '津巴布韦', ContinentCatalog::AF, 'Africa/Harare', -19.0154, 29.1549],
        'BW' => ['Botswana', '博茨瓦纳', ContinentCatalog::AF, 'Africa/Gaborone', -22.3285, 24.6849],
        'NA' => ['Namibia', '纳米比亚', ContinentCatalog::AF, 'Africa/Windhoek', -22.9576, 18.4904],
        'MG' => ['Madagascar', '马达加斯加', ContinentCatalog::AF, 'Indian/Antananarivo', -18.7669, 46.8691],

        // 大洋洲
        'AU' => ['Australia', '澳大利亚', ContinentCatalog::OC, 'Australia/Sydney', -25.2744, 133.7751],
        'NZ' => ['New Zealand', '新西兰', ContinentCatalog::OC, 'Pacific/Auckland', -40.9006, 174.8860],
        'PG' => ['Papua New Guinea', '巴布亚新几内亚', ContinentCatalog::OC, 'Pacific/Port_Moresby', -6.3149, 143.9555],
        'FJ' => ['Fiji', '斐济', ContinentCatalog::OC, 'Pacific/Fiji', -17.7134, 178.0650],
    ];

    /**
     * 常见别名 => 国家代码；键统一小写。
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'usa' => 'US',
        'u.s.' => 'US',
        'u.s.a.' => 'US',
        'america' => 'US',
        'united states of america' => 'US',
        'uk' => 'GB',
        'u.k.' => 'GB',
        'great britain' => 'GB',
        'england' => 'GB',
        'holland' => 'NL',
        'uae' => 'AE',
        'u.a.e.' => 'AE',
        'emirates' => 'AE',
        'korea' => 'KR',
        'republic of korea' => 'KR',
        '南韩' => 'KR',
        'viet nam' => 'VN',
        'czech republic' => 'CZ',
        'burma' => 'MM',
        'cote d\'ivoire' => 'CI',
        'macao' => 'MO',
        '香港' => 'HK',
        '台湾' => 'TW',
        '澳门' => 'MO',
        'russia federation' => 'RU',
        'russian federation' => 'RU',
        'turkiye' => 'TR',
    ];

    /** @var array<string,string>|null 名称/别名索引，按请求懒加载。 */
    private static ?array $nameIndex = null;

    /**
     * 解析国家代码或名称。
     *
     * @return array{country_code:string,country_name:string,continent_code:string,timezone:string,latitude:float,longitude:float}
     */
    public static function resolve(string $codeOrName): array
    {
        $value = trim($codeOrName);
        if ($value === '') {
            return self::unknown();
        }

        $code = strtoupper($value);
        if (isset(self::COUNTRIES[$code])) {
            return self::row($code);
        }

        $index = self::nameIndex();
        $nameKey = self::nameKey($value);
        if ($nameKey !== '' && isset($index[$nameKey])) {
            return self::row($index[$nameKey]);
        }

        return self::unknown();
    }

    /**
     * 返回国家所属洲代码；未知国家（或未知洲名）返回 UNKNOWN。
     */
    public static function continentFor(string $codeOrName): string
    {
        $country = self::resolve($codeOrName);
        if ($country['country_code'] !== self::UNKNOWN_CODE) {
            return $country['continent_code'];
        }

        return ContinentCatalog::normalize($codeOrName);
    }

    /**
     * @return array{country_code:string,country_name:string,continent_code:string,timezone:string,latitude:float,longitude:float}
     */
    private static function row(string $code): array
    {
        [$name, , $continent, $timezone, $latitude, $longitude] = self::COUNTRIES[$code];

        return [
            'country_code' => $code,
            'country_name' => $name,
            'continent_code' => $continent,
            'timezone' => $timezone,
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
        ];
    }

    /**
     * @return array{country_code:string,country_name:string,continent_code:string,timezone:string,latitude:float,longitude:float}
     */
    private static function unknown(): array
    {
        return [
            'country_code' => self::UNKNOWN_CODE,
            'country_name' => self::UNKNOWN_NAME,
            'continent_code' => ContinentCatalog::UNKNOWN,
            'timezone' => '',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function nameIndex(): array
    {
        if (self::$nameIndex !== null) {
            return self::$nameIndex;
        }

        $index = [];
        foreach (self::COUNTRIES as $code => [$name, $nameZh]) {
            $index[self::nameKey($name)] = $code;
            $index[self::nameKey($nameZh)] = $code;
        }
        foreach (self::ALIASES as $alias => $code) {
            $index[self::nameKey($alias)] = $code;
        }

        return self::$nameIndex = $index;
    }

    private static function nameKey(string $value): string
    {
        $value = strtolower(trim($value));
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return rtrim(\is_string($collapsed) ? $collapsed : $value, '.');
    }
}
