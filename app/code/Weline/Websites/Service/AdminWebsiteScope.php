<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Http\Request;

/**
 * 后台列表级站点范围：缺参=全部；有参且 >=0（含默认站 0）=具体站。
 *
 * 禁止用 website_id=0 表示「全部站」（0 = Website::ID_DEFAULT）。
 */
final class AdminWebsiteScope
{
    /** 列表筛选「全部站」：query 缺省 / 空 / all */
    public const FILTER_ALL = null;

    /**
     * @return int|null null=全部；>=0=具体站（含默认站 0）
     */
    public static function resolveFilterId(Request $request, string $key = 'website_id'): ?int
    {
        if ($request->hasGet($key)) {
            return self::parseNonNegativeId($request->getGet($key, ''));
        }

        $param = $request->getParam($key, null);
        if ($param === null || $param === '') {
            return self::FILTER_ALL;
        }

        return self::parseNonNegativeId($param);
    }

    /**
     * 仅从 GET 解析（列表筛选表单）。
     *
     * @return int|null null=全部；>=0=具体站
     */
    public static function resolveFilterIdFromGet(Request $request, string $key = 'website_id'): ?int
    {
        if (!$request->hasGet($key)) {
            return self::FILTER_ALL;
        }

        return self::parseNonNegativeId($request->getGet($key, ''));
    }

    /**
     * @return array{value:string,display:string,options_json:string,options:list<array<string,string>>}
     */
    public static function buildSelectView(?int $websiteId, string $area = 'backend'): array
    {
        $value = $websiteId === null ? '' : (string)$websiteId;
        $pack = WebsiteSelectOptions::forSelect($value, $area);

        return [
            'value' => $value,
            'display' => (string)($pack['display'] ?? ''),
            'options_json' => (string)($pack['options_json'] ?? '[]'),
            'options' => is_array($pack['options'] ?? null) ? $pack['options'] : [],
        ];
    }

    /**
     * @return int|null
     */
    public static function parseNonNegativeId(mixed $raw): ?int
    {
        if ($raw === null) {
            return self::FILTER_ALL;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || strtolower($raw) === 'all') {
            return self::FILTER_ALL;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            return self::FILTER_ALL;
        }
        $id = (int)$raw;
        if ($id < 0) {
            return self::FILTER_ALL;
        }

        return $id;
    }
}
