<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Region;

use Weline\Framework\App\Env;
use Weline\Theme\Region\FragmentRegionProvider;
use Weline\Theme\Region\GlobalRegion;
use Weline\Theme\Region\GlobalRegionFragmentScanner;
use Weline\Theme\Region\GlobalRegionSwitch;

/**
 * Site-panel side of the global-region contract: list contributions, toggle them, edit
 * their widget config.
 *
 * Everything lands in one place — `theme/base_contributions` in the site env config — which
 * is the same tree {@see GlobalRegionSwitch} reads at render time and the same one
 * {@see FragmentRegionProvider} uses for per-fragment config. The panel therefore only
 * needs this service; nothing else has to know how the contract is stored.
 */
final class GlobalRegionConfigService
{
    public const CONFIG_KEY = 'theme/base_contributions';

    /** @return list<array{id:string,region:string,group:string,name:string,source:string,widget_code:string,eager:bool,enabled:bool,config:array<string,mixed>}> */
    public function listContributions(string $projectRoot = ''): array
    {
        $root = $projectRoot !== '' ? $projectRoot : $this->projectRoot();
        if ($root === '') {
            return [];
        }
        $switch = $this->switch();
        $scanned = (new GlobalRegionFragmentScanner(GlobalRegionFragmentScanner::defaultRoots($root)))->scan();
        $out = [];
        foreach ($scanned['fragments'] as $fragment) {
            $out[] = [
                'id' => $fragment->id,
                'region' => $fragment->region,
                'group' => $fragment->group,
                'name' => $fragment->name,
                'source' => $fragment->source,
                'widget_code' => $fragment->widgetCode,
                'eager' => $fragment->eager,
                'enabled' => $switch->allows($fragment),
                'config' => $switch->configFor($fragment),
            ];
        }

        return $out;
    }

    /** Toggle one contribution (or a whole region / group) without touching its siblings. */
    public function setEnabled(string $key, bool $enabled): void
    {
        $key = \trim($key);
        if ($key === '') {
            return;
        }
        $config = $this->config();
        // Dotted keys without a region prefix are fragment ids (base.<region>.<group>.<name>).
        $config['items'][$key] = $enabled;
        $this->save($config);
        $this->resetRuntime();
    }

    public function setRegionEnabled(string $region, bool $enabled): void
    {
        $config = $this->config();
        $config['regions'][\trim($region)] = $enabled;
        $this->save($config);
        $this->resetRuntime();
    }

    public function setGroupEnabled(string $region, string $group, bool $enabled): void
    {
        $config = $this->config();
        $config['groups'][\trim($region) . '.' . \trim($group)] = $enabled;
        $this->save($config);
        $this->resetRuntime();
    }

    /** @param array<string, mixed> $values */
    public function setConfig(string $fragmentId, array $values): void
    {
        $fragmentId = \trim($fragmentId);
        if ($fragmentId === '') {
            return;
        }
        $config = $this->config();
        $config['config'][$fragmentId] = $values;
        $this->save($config);
        $this->resetRuntime();
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        try {
            $config = Env::getInstance()->getConfig(self::CONFIG_KEY, []);
        } catch (\Throwable) {
            $config = [];
        }

        return \is_array($config) ? $config : [];
    }

    /** @param array<string, mixed> $config */
    private function save(array $config): void
    {
        Env::getInstance()->setConfig(self::CONFIG_KEY, $config);
    }

    /**
     * The switch and the scan cache are per-request by design; the panel mutates them
     * between requests, so they have to be dropped for the change to be observable.
     */
    private function resetRuntime(): void
    {
        GlobalRegion::reset();
        FragmentRegionProvider::flushScanCache();
    }

    private function switch(): GlobalRegionSwitch
    {
        return new GlobalRegionSwitch($this->config());
    }

    private function projectRoot(): string
    {
        return \defined('BP') ? \rtrim((string)\BP, '/\\') : '';
    }
}
