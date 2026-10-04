<?php
declare(strict_types=1);
namespace Weline\SystemConfig\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Service\BackendThemeApplicationService;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;

final class InstallBackendThemeApplicationContext implements ObserverInterface
{
    public function __construct(private readonly ConfigStore $config, private readonly RuntimeProviderResolver $providers) {}

    public function execute(Event &$event): void
    {
        if ($event->getData('area') !== 'backend' || ThemeApplicationContext::current('backend','runtime') !== null) { return; }
        $resolution=$this->providers->resolveDetailed(ThemeApplicationReferenceReaderInterface::class);
        if ($resolution->status===RuntimeProviderResolution::NOT_CONFIGURED) { return; }
        if (!$resolution->provider instanceof ThemeApplicationReferenceReaderInterface) {
            throw new \RuntimeException('backend_theme_application_reader_unavailable');
        }
        /** @var DefaultThemeInterface $defaultTheme */
        $defaultTheme = ObjectManager::getInstance(DefaultThemeInterface::class);
        (new BackendThemeApplicationService($this->config, $resolution->provider, $defaultTheme))->buildContext()->install();
    }
}
