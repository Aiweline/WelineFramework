<?php
declare(strict_types=1);
namespace Weline\SystemConfig\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\SystemConfig\Service\BackendThemeApplicationService;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;

final class Upgrade implements UpgradeInterface
{
    public const VERSION = '1.3.66';
    public function setup(Setup $setup, Context $context): void
    {
        if (version_compare($context->getFromSetupVersion(), self::VERSION, '>=')) { return; }
        $resolution=ObjectManager::getInstance(RuntimeProviderResolver::class)->resolveDetailed(ThemeApplicationReferenceReaderInterface::class);
        if ($resolution->status===RuntimeProviderResolution::NOT_CONFIGURED) { return; }
        if (!$resolution->provider instanceof ThemeApplicationReferenceReaderInterface) {
            throw new \RuntimeException('backend_theme_application_reader_unavailable');
        }
        (new BackendThemeApplicationService(
            ObjectManager::getInstance(ConfigStore::class),
            $resolution->provider,
            ObjectManager::getInstance(\Weline\Theme\Api\DefaultThemeInterface::class),
        ))->migrateLegacy();
    }
}
