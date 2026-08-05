<?php

declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Acl\Service\Resource\AuthorizationResourceRegistry;
use Weline\Acl\Service\Resource\LiveSourceSet;
use Weline\Acl\Service\Resource\RoleTagGrantSyncService;
use Weline\Acl\Service\Resource\SourceIdRenameMap;
use Weline\Acl\Service\Resource\SourceIdRenameMigrator;
use Weline\Framework\Authorization\Resource\AuthorizationResourceBinding;
use Weline\Framework\Authorization\Resource\AuthorizationResourceCatalogBuilder;
use Weline\Framework\Authorization\Resource\AuthorizationResourceDefinition;
use Weline\Framework\Authorization\Resource\AuthorizationResourceOrigin;
use Weline\Framework\Authorization\Resource\AuthorizationResourceType;
use Weline\Framework\Authorization\Resource\ResumableTaskAclScanner;
use Weline\Framework\Authorization\Resource\SourceIdParser;
use Weline\Framework\Compilation\FrameworkCompiler;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * After controller/menu collection: ensure Query compile freshness (D-8),
 * project Query/task bindings into ACL, feed LiveSourceSet (D-9/D-10).
 */
final class CollectFrameworkAuthorizationCatalog implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $touched = $event->getData('touched_modules');
        if (\is_array($touched) && $touched !== []) {
            LiveSourceSet::setTouchedModules(\array_values(\array_map('strval', $touched)));
        } else {
            LiveSourceSet::setTouchedModules(null);
        }

        $this->ensureQueryCompileFresh();

        ObjectManager::getInstance(SourceIdRenameMigrator::class)->migrate(\array_merge(
            SourceIdRenameMap::QUERY,
            SourceIdRenameMap::ROLE_ACCESS,
        ));

        $registryPath = BP . 'generated' . DS . 'framework' . DS . 'query_providers.php';
        $queryRegistry = \is_file($registryPath) ? (require $registryPath) : [];
        if (!\is_array($queryRegistry)) {
            $queryRegistry = [];
        }

        $builder = new AuthorizationResourceCatalogBuilder();
        $fromQuery = $builder->fromQueryProviderRegistry($queryRegistry);
        $fromTasks = $builder->fromResumableTaskRows((new ResumableTaskAclScanner())->scan());

        $definitions = $this->remapDefinitions(\array_merge(
            $fromQuery['definitions'],
            $fromTasks['definitions'],
            $this->renamedCanonicalDefinitions(),
        ));
        $bindings = $this->remapBindings(\array_merge($fromQuery['bindings'], $fromTasks['bindings']));

        /** @var AuthorizationResourceRegistry $registry */
        $registry = ObjectManager::getInstance(AuthorizationResourceRegistry::class);
        $live = $registry->upsertCatalog([], $definitions, $bindings);
        CollectedAclSourceIdsRegistry::add(...$live);
        LiveSourceSet::addMany($live);

        ObjectManager::getInstance(RoleTagGrantSyncService::class)
            ->syncAddOnly(LiveSourceSet::touchedModules());
    }

    /** @return list<AuthorizationResourceDefinition> */
    private function renamedCanonicalDefinitions(): array
    {
        $out = [];
        foreach (SourceIdRenameMap::QUERY as $old => $new) {
            $parsed = SourceIdParser::parse($new);
            if ($parsed === null) {
                continue;
            }
            $out[] = new AuthorizationResourceDefinition(
                sourceId: $new,
                name: $parsed['code'],
                description: 'Migrated from ' . $old,
                module: $parsed['module'],
                resourceType: AuthorizationResourceType::QUERY,
                origin: AuthorizationResourceOrigin::QUERY_PROVIDER,
                tags: $parsed['tags'],
                code: $parsed['code'],
                metadata: ['renamed_from' => $old],
            );
        }
        return $out;
    }

    /**
     * @param list<AuthorizationResourceDefinition> $definitions
     * @return list<AuthorizationResourceDefinition>
     */
    private function remapDefinitions(array $definitions): array
    {
        $out = [];
        foreach ($definitions as $definition) {
            $sourceId = SourceIdRenameMap::QUERY[$definition->sourceId] ?? $definition->sourceId;
            if ($sourceId === $definition->sourceId) {
                $out[] = $definition;
                continue;
            }
            $parsed = SourceIdParser::parse($sourceId);
            $out[] = new AuthorizationResourceDefinition(
                sourceId: $sourceId,
                name: $definition->name,
                description: $definition->description,
                module: $definition->module,
                resourceType: AuthorizationResourceType::QUERY,
                origin: $definition->origin,
                accessMode: $definition->accessMode,
                isBackend: $definition->isBackend,
                apiExposable: $definition->apiExposable,
                scopeGroup: $definition->scopeGroup,
                parentSource: SourceIdRenameMap::QUERY[$definition->parentSource] ?? $definition->parentSource,
                tags: $parsed['tags'] ?? $definition->tags,
                code: $parsed['code'] ?? $definition->code,
                metadata: \array_merge($definition->metadata, ['renamed_from' => $definition->sourceId]),
            );
        }
        return $out;
    }

    /**
     * @param list<AuthorizationResourceBinding> $bindings
     * @return list<AuthorizationResourceBinding>
     */
    private function remapBindings(array $bindings): array
    {
        $out = [];
        foreach ($bindings as $binding) {
            $sourceId = SourceIdRenameMap::QUERY[$binding->sourceId] ?? $binding->sourceId;
            if ($sourceId === $binding->sourceId) {
                $out[] = $binding;
                continue;
            }
            $out[] = new AuthorizationResourceBinding(
                sourceId: $sourceId,
                surface: $binding->surface,
                surfaceId: $binding->surfaceId,
                module: $binding->module,
                metadata: \array_merge($binding->metadata, ['renamed_from' => $binding->sourceId]),
            );
        }
        return $out;
    }

    private function ensureQueryCompileFresh(): void
    {
        $modulesRoot = BP . 'app' . DS . 'code' . DS . 'Weline';
        $outputDirectory = BP . 'generated' . DS . 'framework';
        $hooksFile = BP . 'generated' . DS . 'hooks.php';
        /** @var FrameworkCompiler $compiler */
        $compiler = ObjectManager::getInstance(FrameworkCompiler::class);

        $registryPath = $outputDirectory . DS . 'query_providers.php';
        $hasBindingsKey = false;
        if (\is_file($registryPath)) {
            $loaded = require $registryPath;
            $hasBindingsKey = \is_array($loaded) && \array_key_exists('authorization_bindings', $loaded);
        }

        $fresh = $compiler->isFresh($modulesRoot, $outputDirectory)
            || (\is_file($hooksFile) && $compiler->isPublishedGenerationValid($modulesRoot, $outputDirectory, $hooksFile));

        if ($fresh && $hasBindingsKey) {
            return;
        }

        try {
            $compiler->compile($modulesRoot, $outputDirectory);
            if (\PHP_SAPI === 'cli') {
                ObjectManager::getInstance(Printing::class)->note(
                    (string)__('ACL catalog: framework Query compile refreshed before resource collection.'),
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'ACL resource catalog requires a fresh framework Query compile before setup:upgrade --route. '
                . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
