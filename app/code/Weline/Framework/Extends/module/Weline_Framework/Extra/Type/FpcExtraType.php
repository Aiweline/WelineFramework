<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Extra\Type;

use Weline\Framework\Controller\Extra\ExtraAttributeParser;
use Weline\Framework\Controller\Extra\ExtraTypeProviderInterface;

final class FpcExtraType implements ExtraTypeProviderInterface
{
    /** Hard cap — fail-closed to path-only when exceeded (anti leaf/key explosion). */
    public const MAX_QUERY_KEYS = 32;

    /** 限流窗口缺省值（秒）。*/ 
    public const RATE_WINDOW_DEFAULT = 10;

    /**
     * Never admit diagnostic/pierce/session carriage into the guest shared allowlist.
     *
     * @var array<string, bool>
     */
    private const FORBIDDEN_QUERY_KEYS = [
        '*' => true,
        '_wb_nc' => true,
        'wls_tpl_perf' => true,
        'wls_tpl_route' => true,
        'w_fpc_pierce' => true,
        'nocache' => true,
        'no_cache' => true,
    ];

    public function type(): string
    {
        return 'fpc';
    }

    public function description(): string
    {
        return '全页缓存读策略（默认允许公开店面；enabled=false 禁用；ttl/namespaces/query_keys 显式控）';
    }

    public function normalize(array $attrs): array
    {
        $enabled = \array_key_exists('enabled', $attrs)
            ? ExtraAttributeParser::toBool($attrs['enabled'])
            : true;
        // Match Coordinator default-allow TTL when Extra omits ttl=.
        $ttl = isset($attrs['ttl']) ? \max(0, ExtraAttributeParser::toInt($attrs['ttl'], 0)) : 31536000;
        $namespaces = ExtraAttributeParser::toStringList($attrs['namespaces'] ?? []);
        $patterns = ExtraAttributeParser::toStringList($attrs['public_path_patterns'] ?? []);

        // 每 IP 窗口限流：缺省关；开启则必须给正数上限。构建期直接报错，
        // 不允许「配了限流但上限为 0」这种隐性失效。
        $rateEnabled = \array_key_exists('rate_enabled', $attrs) && ExtraAttributeParser::toBool($attrs['rate_enabled']);
        $rateWindow = \array_key_exists('rate_window', $attrs)
            ? ExtraAttributeParser::toInt($attrs['rate_window'], self::RATE_WINDOW_DEFAULT)
            : self::RATE_WINDOW_DEFAULT;
        $rateMax = \array_key_exists('rate_max_requests', $attrs)
            ? ExtraAttributeParser::toInt($attrs['rate_max_requests'], 0)
            : 0;
        $rateBlock = \array_key_exists('rate_block_duration', $attrs)
            ? ExtraAttributeParser::toInt($attrs['rate_block_duration'], 0)
            : 0;
        if ($rateWindow <= 0) {
            throw new \InvalidArgumentException('rate_window must be > 0');
        }
        if ($rateEnabled && $rateMax <= 0) {
            throw new \InvalidArgumentException('rate_max_requests must be > 0 when rate_enabled');
        }

        return [
            'type' => 'fpc',
            'enabled' => $enabled,
            'ttl' => $ttl,
            'namespaces' => \array_values(\array_unique($namespaces)),
            'public_path_patterns' => \array_values(\array_unique($patterns)),
            'query_keys' => self::normalizeQueryKeys($attrs['query_keys'] ?? []),
            'rate_enabled' => $rateEnabled,
            'rate_window' => $rateWindow,
            'rate_max_requests' => $rateMax,
            'rate_block_duration' => $rateBlock,
        ];
    }

    /**
     * Explicit allowlist only. `*` / illegal / over-cap → empty (= path-only).
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalizeQueryKeys(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = \array_map('trim', \explode(',', $raw));
        }
        if (!\is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                // Non-scalar entry → fail-closed.
                return [];
            }
            $key = \strtolower(\trim((string)$item));
            if ($key === '') {
                continue;
            }
            if (isset(self::FORBIDDEN_QUERY_KEYS[$key]) || \str_contains($key, '*')) {
                return [];
            }
            if (!\preg_match('/^[a-z0-9_][a-z0-9_-]*$/', $key)) {
                return [];
            }
            $keys[$key] = true;
        }

        $list = \array_keys($keys);
        \sort($list, \SORT_STRING);
        if (\count($list) > self::MAX_QUERY_KEYS) {
            return [];
        }

        return $list;
    }
}
