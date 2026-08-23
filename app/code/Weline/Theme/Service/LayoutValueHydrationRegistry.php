<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Layout\HydratedLayoutValue;
use Weline\Theme\Api\Layout\LayoutValueHydratorInterface;

final class LayoutValueHydrationRegistry
{
    public const CAPABILITY_PREFIX = 'theme.layout_value_hydrator.';
    private const MAX_NODES = 10000;
    private const MAX_DEPTH = 64;
    private const MAX_HYDRATORS = 64;
    private const RESERVED_COMPANION_SUFFIXES = [
        '_file_html',
        '_file_usage',
        '_file_alt',
        '_file_asset_id',
    ];

    /**
     * Process-safe compiled descriptions only. The actual hydrator services are
     * resolved from the current Fiber/ObjectManager bucket for every operation.
     *
     * @var list<class-string>|null
     */
    private ?array $hydratorImplementations = null;

    public function __construct(private readonly ?ServiceProviderRegistry $providers = null)
    {
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $context */
    public function hydrate(array $config, array $context): array
    {
        $count = 0;
        $hydrators = $this->hydrators();
        $result = $this->walk($config, $context, $hydrators, 0, $count);
        return $result instanceof HydratedLayoutValue ? ['value' => $result->value] : $result;
    }

    /** @param array<string,mixed> $node */
    public function supports(array $node): bool
    {
        foreach ($this->hydrators() as $hydrator) {
            if ($hydrator->supports($node)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string|int,mixed> $value @param array<string,mixed> $context */
    private function walk(
        array $value,
        array $context,
        array $hydrators,
        int $depth,
        int &$count,
    ): array|HydratedLayoutValue
    {
        if (++$count > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \RuntimeException((string)__('Theme 布局类型化值超过运行时解析上限。'));
        }
        foreach ($hydrators as $hydrator) {
            if ($hydrator->supports($value)) {
                return $hydrator->hydrate($value, $context);
            }
        }
        if (($value['type'] ?? null) === 'file-image') {
            throw new \RuntimeException((string)__('file-image 运行时适配器不可用。'));
        }

        $hydrated = [];
        foreach ($value as $key => $child) {
            // Hydration companion values are runtime-only capabilities. Never
            // trust or expose same-named values persisted in layout JSON.
            if (is_string($key) && self::isReservedCompanionKey($key)) {
                continue;
            }
            if (!is_array($child)) {
                $hydrated[$key] = $child;
                continue;
            }
            $resolved = $this->walk($child, $context, $hydrators, $depth + 1, $count);
            if (!$resolved instanceof HydratedLayoutValue) {
                $hydrated[$key] = $resolved;
                continue;
            }
            $hydrated[$key] = $resolved->value;
            if (is_string($key) && $key !== '') {
                foreach ($resolved->metadata as $metaKey => $metaValue) {
                    $companion = $key . '_' . trim((string)$metaKey, '_');
                    if (!array_key_exists($companion, $hydrated)) {
                        $hydrated[$companion] = $metaValue;
                    }
                }
            }
        }
        return $hydrated;
    }

    private static function isReservedCompanionKey(string $key): bool
    {
        foreach (self::RESERVED_COMPANION_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<LayoutValueHydratorInterface> */
    private function hydrators(): array
    {
        $hydrators = [];
        foreach ($this->hydratorImplementations() as $implementation) {
            $hydrator = ObjectManager::getInstance($implementation);
            if (!$hydrator instanceof LayoutValueHydratorInterface) {
                throw new \RuntimeException($implementation . ' must implement ' . LayoutValueHydratorInterface::class);
            }
            $hydrators[] = $hydrator;
        }
        return $hydrators;
    }

    /** @return list<class-string> */
    private function hydratorImplementations(): array
    {
        if ($this->hydratorImplementations !== null) {
            return $this->hydratorImplementations;
        }
        $registry = $this->providers ?? ObjectManager::getInstance(ServiceProviderRegistry::class);
        $implementations = [];
        foreach ($registry->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $implementation) {
            if (!is_string($implementation) || trim($implementation) === '') {
                throw new \RuntimeException('theme_layout_hydrator_registration_invalid');
            }
            $implementations[$implementation] = true;
            if (count($implementations) > self::MAX_HYDRATORS) {
                throw new \RuntimeException('theme_layout_hydrator_registration_limit_exceeded');
            }
        }
        return $this->hydratorImplementations = array_keys($implementations);
    }
}
