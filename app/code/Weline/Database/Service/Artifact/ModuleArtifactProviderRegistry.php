<?php

declare(strict_types=1);

namespace Weline\Database\Service\Artifact;

use Weline\Database\Api\ModuleArtifactProviderInterface;
use Weline\Framework\Event\EventsManager;

final class ModuleArtifactProviderRegistry
{
    public const EVENT_COLLECT = 'Weline_Database_ModuleArtifact::collect';

    public function __construct(
        private readonly LocalModuleArtifactProvider $localProvider,
        private readonly EventsManager $eventsManager,
    ) {
    }

    /** @return list<ModuleArtifactProviderInterface> */
    public function getProviders(): array
    {
        $payload = ['providers' => [$this->localProvider]];
        $this->eventsManager->dispatch(self::EVENT_COLLECT, $payload);
        $providers = [];
        foreach ((array)($payload['providers'] ?? []) as $provider) {
            if ($provider instanceof ModuleArtifactProviderInterface) {
                $providers[$provider->getName()] = $provider;
            }
        }
        uasort($providers, static fn(ModuleArtifactProviderInterface $a, ModuleArtifactProviderInterface $b): int => $b->getPriority() <=> $a->getPriority());
        return array_values($providers);
    }

    /** @return list<array<string, mixed>> */
    public function listVersions(string $moduleName): array
    {
        $versions = [];
        foreach ($this->getProviders() as $provider) {
            foreach ($provider->listVersions($moduleName) as $item) {
                $version = trim((string)($item['version'] ?? ''));
                if ($version === '') {
                    continue;
                }
                $versions[$version] ??= $item + ['source' => $provider->getName()];
            }
        }
        uksort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
        return array_values($versions);
    }

    /** @return array<string, mixed> */
    public function stage(string $moduleName, string $version, string $operationId): array
    {
        $errors = [];
        foreach ($this->getProviders() as $provider) {
            $result = $provider->stage($moduleName, $version, $operationId);
            if (!empty($result['success'])) {
                return $result;
            }
            $errors[] = $provider->getName() . ': ' . (string)($result['error'] ?? __('不可用'));
        }
        return [
            'success' => false,
            'module_name' => $moduleName,
            'version' => $version,
            'source' => '',
            'error' => implode('; ', $errors),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshotCurrent(string $moduleName, string $version, string $operationId): array
    {
        return $this->localProvider->snapshotCurrent($moduleName, $version, $operationId);
    }
}
