<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * Resolves whether one fragment is on for the current site.
 *
 * Four layers, all of them veto-only except the innermost:
 *
 *   ① enabled               site-wide kill switch
 *   ② regions.{region}      whole region off
 *   ③ groups.{region}.{group}
 *   ④ items.{fragment id}
 *
 * A `false` anywhere wins. An explicit `true` at the item level can turn a module
 * fragment on. When nothing is configured the fragment keeps the default derived
 * from its path — theme fragments on, module fragments off — so enabling a region
 * never silently activates every third-party contribution in it.
 *
 * Storage is deliberately not baked in: the caller passes the array, which keeps
 * this class testable and lets the site-config plumbing land separately.
 */
final class GlobalRegionSwitch
{
    public function __construct(private readonly array $config)
    {
    }

    /** Reads the switch tree from the framework config (`theme/base_contributions`). */
    public static function fromEnv(): self
    {
        try {
            $config = \Weline\Framework\App\Env::getInstance()->getConfig('theme/base_contributions', []);
        } catch (\Throwable) {
            $config = [];
        }

        return new self(\is_array($config) ? $config : []);
    }

    public function allows(GlobalRegionFragment $fragment): bool
    {
        if (($this->config['enabled'] ?? true) === false) {
            return false;
        }
        $region = $this->config['regions'] ?? [];
        if (\is_array($region) && ($region[$fragment->region] ?? true) === false) {
            return false;
        }

        $groups = $this->config['groups'] ?? [];
        $groupKey = $fragment->region . '.' . $fragment->group;
        if (\is_array($groups) && \array_key_exists($groupKey, $groups) && $groups[$groupKey] === false) {
            return false;
        }

        $items = $this->config['items'] ?? [];
        if (\is_array($items) && \array_key_exists($fragment->id, $items)) {
            return (bool)$items[$fragment->id];
        }

        return $fragment->defaultEnabled;
    }

    /**
     * Per-fragment widget config (panel-edited values), empty when none are stored.
     *
     * Base contributions are widgets: the panel writes their `@param` values here so the
     * fragment renders with the same config a slot-mounted widget used to receive.
     *
     * @return array<string, mixed>
     */
    public function configFor(GlobalRegionFragment $fragment): array
    {
        $config = $this->config['config'] ?? [];
        if (!\is_array($config)) {
            return [];
        }
        $values = $config[$fragment->id] ?? [];

        return \is_array($values) ? $values : [];
    }
}
