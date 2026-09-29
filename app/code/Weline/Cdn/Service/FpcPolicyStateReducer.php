<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;

/** 控制面变更的纯函数：版本化清理集合不会丢失连续保存。 */
final class FpcPolicyStateReducer
{
    public static function targetKey(array $target): string
    {
        return hash('sha256', implode("\n", [(string)$target['domain_id'], $target['kind'], $target['value']]));
    }

    public static function accumulateTargets(array $pending, array $targets, int $version): array
    {
        foreach ($targets as $target) {
            if (!in_array($target['kind'] ?? '', ['url', 'prefix', 'host'], true) || empty($target['value'])) {
                throw new \InvalidArgumentException('cdn_fpc_invalid_purge_target');
            }
            $key = self::targetKey($target);
            $pending[$key] = ['target' => $target, 'version' => max($version, (int)($pending[$key]['version'] ?? 0))];
        }
        ksort($pending);
        return $pending;
    }

    public static function acknowledgeTargets(array $pending, array $targets, int $version): array
    {
        foreach ($targets as $target) {
            $key = self::targetKey($target);
            if (isset($pending[$key]) && $pending[$key]['version'] <= $version) {
                unset($pending[$key]);
            }
        }
        return $pending;
    }

    public static function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) { $item = self::canonical($item); }
        }
        unset($item);
        if (!array_is_list($value)) { ksort($value); }
        return $value;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function declaration(array $row): array
    {
        $row['type'] = 'fpc';
        $row['class'] = ltrim((string)$row['class'], '\\');
        $row['method'] = strtolower((string)$row['method']);
        $row['declaration_id'] = hash('sha256', implode("\n", [$row['module'], $row['class'], $row['method'], 'fpc']));
        $row['attrs'] = ['enabled' => (bool)($row['attrs']['enabled'] ?? true), 'ttl' => (int)($row['attrs']['ttl'] ?? 600), 'namespaces' => array_values((array)($row['namespaces'] ?? $row['attrs']['namespaces'] ?? []))];
        $row['namespaces'] = $row['attrs']['namespaces'];
        $row['public_path_patterns'] = array_values(array_unique((array)($row['public_path_patterns'] ?? [])));
        sort($row['public_path_patterns']);
        return array_intersect_key($row, array_flip(['declaration_id','module','class','method','type','path_pattern','public_path_patterns','attrs','namespaces']));
    }
}
