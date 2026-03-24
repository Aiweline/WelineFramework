<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

class AnalyticsAdminPageDataService
{
    public function __construct(
        private readonly AnalyticsConfigService $analyticsConfigService,
        private readonly AnalyticsSnippetService $analyticsSnippetService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(?string $editingProvider = null): array
    {
        $definitions = $this->analyticsConfigService->getProviderDefinitions();
        $editingProvider = isset($definitions[(string) $editingProvider])
            ? (string) $editingProvider
            : (string) array_key_first($definitions);

        $providers = [];
        $enabledProviders = 0;
        $readyProviders = 0;

        foreach ($definitions as $providerCode => $definition) {
            $config = $this->analyticsConfigService->getProviderConfig($providerCode);
            $enabled = !empty($config['enabled']);
            $ready = $this->analyticsConfigService->isProviderReady($providerCode, $config);

            if ($enabled) {
                $enabledProviders++;
            }
            if ($ready) {
                $readyProviders++;
            }

            $providers[] = [
                'code' => $providerCode,
                'label' => (string) ($definition['label'] ?? $providerCode),
                'description' => (string) ($definition['description'] ?? ''),
                'enabled' => $enabled,
                'ready' => $ready,
                'fields' => $definition['fields'] ?? [],
                'config' => $config,
            ];
        }

        $editingDefinition = $definitions[$editingProvider] ?? [];

        return [
            'providers' => $providers,
            'summary' => [
                'total_providers' => count($providers),
                'enabled_providers' => $enabledProviders,
                'ready_providers' => $readyProviders,
                'snippet_count' => count($this->analyticsSnippetService->getFrontendPixelSnippets()),
            ],
            'trackedEvents' => $this->analyticsConfigService->getTrackedEvents(),
            'editingProvider' => [
                'code' => $editingProvider,
                'label' => (string) ($editingDefinition['label'] ?? $editingProvider),
                'description' => (string) ($editingDefinition['description'] ?? ''),
                'fields' => $editingDefinition['fields'] ?? [],
                'config' => $this->analyticsConfigService->getProviderConfig($editingProvider),
            ],
            'snippetPreview' => $this->analyticsSnippetService->getFrontendPixelSnippets(),
        ];
    }
}
