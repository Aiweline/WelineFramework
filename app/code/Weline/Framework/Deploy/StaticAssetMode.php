<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Console\Console\Deploy\Upgrade;
use Weline\Framework\Manager\ObjectManager;

/**
 * Framework-owned static publish mode.
 *
 * dev: module view/statics stay on the source URL; contributors may still publish
 * theme-namespace files because those URLs are /static/{theme}/... in both modes.
 * prod: module view/statics go to the flat pub/static tree (staging root when open).
 * Public URL shape is unchanged.
 */
final class StaticAssetMode
{
    public const CAPABILITY_PREFIX = 'deploy.static_asset_mode.';

    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $upgradeData
     */
    public function publishForModeSet(string $mode, array $upgradeData = []): void
    {
        if ($mode !== 'prod') {
            return;
        }

        ObjectManager::getInstance(Upgrade::class)->execute([], $upgradeData);
        $this->runContributors(new StaticAssetModeContext(
            'prod',
            'deploy',
            DeployStagingSession::staticRoot(),
        ));
    }

    /**
     * Theme command: always let contributors publish theme-namespace files.
     * Production also lays the shared module flat tree first, so a later theme
     * overwrite cannot be clobbered by the module copy.
     *
     * @param list<mixed> $subjects
     * @param list<string> $modules
     */
    public function publishForThemeUpgrade(array $subjects, array $modules = []): int
    {
        $production = DeployPublicUrlMode::useProduction();
        if ($production) {
            ObjectManager::getInstance(Upgrade::class)->publishStaticTree();
        }

        $context = new StaticAssetModeContext(
            $production ? 'prod' : 'dev',
            'theme',
            DeployStagingSession::staticRoot(),
            array_values($subjects),
            array_values($modules),
        );

        return $this->runContributors($context);
    }

    private function runContributors(StaticAssetModeContext $context): int
    {
        $count = 0;
        foreach ($this->providers->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $implementation) {
            if (!is_string($implementation) || $implementation === '') {
                continue;
            }
            $contributor = ObjectManager::getInstance($implementation);
            if (!$contributor instanceof StaticAssetModeContributorInterface) {
                continue;
            }
            $contributor->publish($context);
            $count++;
        }

        return $count;
    }
}
