<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Deploy;

use Weline\Framework\Deploy\StaticAssetModeContext;
use Weline\Framework\Deploy\StaticAssetModeContributorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Console\Theme\Upgrade;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeApplicationUsageService;

/**
 * Theme-namespace static files only. Module view/statics stay on the framework flat tree.
 */
final class ThemeStaticAssetModeContributor implements StaticAssetModeContributorInterface
{
    public function publish(StaticAssetModeContext $context): void
    {
        /** @var Upgrade $upgrade */
        $upgrade = ObjectManager::getInstance(Upgrade::class);
        foreach ($this->themesFor($context) as $theme) {
            $upgrade->publishStaticAssets($theme, $context->modules);
        }
    }

    /**
     * @return list<WelineTheme>
     */
    private function themesFor(StaticAssetModeContext $context): array
    {
        if ($context->channel === 'theme') {
            $themes = [];
            foreach ($context->subjects as $subject) {
                if ($subject instanceof WelineTheme) {
                    $themes[] = $subject;
                }
            }

            return $themes;
        }
        if ($context->channel !== 'deploy') {
            return [];
        }

        /** @var ThemeApplicationUsageService $usage */
        $usage = ObjectManager::getInstance(ThemeApplicationUsageService::class);

        return $usage->themesForDefaultUpgrade();
    }
}
