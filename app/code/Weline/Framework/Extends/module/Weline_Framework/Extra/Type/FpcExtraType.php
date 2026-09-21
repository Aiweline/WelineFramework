<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Extra\Type;

use Weline\Framework\Controller\Extra\ExtraTypeProviderInterface;

final class FpcExtraType implements ExtraTypeProviderInterface
{
    public function type(): string
    {
        return 'fpc';
    }

    public function description(): string
    {
        return '全页缓存读策略声明';
    }

    public function normalize(array $attrs): array
    {
        $enabled = \array_key_exists('enabled', $attrs)
            ? (bool)$attrs['enabled']
            : true;
        $ttl = isset($attrs['ttl']) ? \max(0, (int)$attrs['ttl']) : 600;
        $namespaces = [];
        foreach ((array)($attrs['namespaces'] ?? []) as $ns) {
            $ns = \trim((string)$ns);
            if ($ns !== '') {
                $namespaces[] = $ns;
            }
        }
        $patterns = [];
        foreach ((array)($attrs['public_path_patterns'] ?? []) as $p) {
            $p = \trim((string)$p);
            if ($p !== '') {
                $patterns[] = $p;
            }
        }

        return [
            'type' => 'fpc',
            'enabled' => $enabled,
            'ttl' => $ttl,
            'namespaces' => \array_values(\array_unique($namespaces)),
            'public_path_patterns' => \array_values(\array_unique($patterns)),
        ];
    }
}
