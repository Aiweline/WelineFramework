<?php

declare(strict_types=1);

namespace Weline\Acl\Service\Resource;

use Weline\Acl\Model\Acl;
use Weline\Framework\Authorization\Resource\AuthorizationResourceBinding;
use Weline\Framework\Authorization\Resource\AuthorizationResourceCatalog;
use Weline\Framework\Authorization\Resource\AuthorizationResourceCatalogBuilder;
use Weline\Framework\Authorization\Resource\AuthorizationResourceDefinition;
use Weline\Framework\Authorization\Resource\AuthorizationResourceOrigin;
use Weline\Framework\Authorization\Resource\AuthorizationResourceType;
use Weline\Framework\Authorization\Resource\SourceIdParser;
use Weline\Framework\Manager\ObjectManager;

/**
 * Persists Framework authorization catalog into weline_acl (data-only registry).
 */
final class AuthorizationResourceRegistry
{
    public function __construct(
        private readonly AuthorizationResourceCatalogBuilder $catalogBuilder = new AuthorizationResourceCatalogBuilder(),
    ) {
    }

    /**
     * @param list<AuthorizationResourceDefinition> $priorityDefinitions menu/controller first
     * @param list<AuthorizationResourceDefinition> $derivedDefinitions
     * @param list<AuthorizationResourceBinding> $bindings
     * @return list<string> live source ids written/kept this round from catalog
     */
    public function upsertCatalog(
        array $priorityDefinitions,
        array $derivedDefinitions,
        array $bindings,
    ): array {
        $catalog = $this->catalogBuilder->build(
            \array_merge($priorityDefinitions, $derivedDefinitions),
            $bindings,
        );
        return $this->persistCatalog($catalog, $bindings);
    }

    /**
     * @param list<AuthorizationResourceBinding> $bindings
     * @return list<string>
     */
    public function persistCatalog(AuthorizationResourceCatalog $catalog, array $bindings = []): array
    {
        $bindingsBySource = [];
        foreach ($bindings !== [] ? $bindings : $catalog->bindings as $binding) {
            if (!$binding instanceof AuthorizationResourceBinding) {
                continue;
            }
            $bindingsBySource[$binding->sourceId][] = $binding->toArray();
        }

        $live = [];
        foreach ($catalog->definitions as $definition) {
            if (!$definition instanceof AuthorizationResourceDefinition) {
                continue;
            }
            $sourceId = \trim($definition->sourceId);
            if ($sourceId === '' || !SourceIdParser::parse($sourceId)) {
                // Still allow historical IDs that parse via FrontendWorkerBackendAcl pattern
                if ($sourceId === '') {
                    continue;
                }
            }
            $this->upsertDefinition($definition, $bindingsBySource[$sourceId] ?? []);
            $live[] = $sourceId;
            LiveSourceSet::add($sourceId);
        }
        return \array_values(\array_unique($live));
    }

    /**
     * @param list<array<string,mixed>> $bindingRows
     */
    private function upsertDefinition(AuthorizationResourceDefinition $definition, array $bindingRows): void
    {
        $sourceId = $definition->sourceId;
        /** @var Acl $acl */
        $acl = ObjectManager::getInstance(Acl::class, [], false);
        $existing = ObjectManager::getInstance(Acl::class, [], false)
            ->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceId)
            ->find()
            ->fetch();

        $storageType = AuthorizationResourceType::toStorage($definition->resourceType);
        $metadata = [
            'resource_type' => $definition->resourceType,
            'tags' => $definition->tags,
            'code' => $definition->code,
            'bindings' => $bindingRows,
            'catalog_metadata' => $definition->metadata,
        ];

        if ($existing->getSourceId() === $sourceId) {
            $existingOrigin = (string)$existing->getData(Acl::schema_fields_ACL_ORIGIN);
            // Never overwrite user-created rows.
            if ($existingOrigin === AuthorizationResourceOrigin::USER || $existingOrigin === Acl::acl_origin_user) {
                return;
            }
            $existingType = (string)$existing->getData(Acl::schema_fields_TYPE);
            // Higher-priority already in DB as menus/pc/api: only enrich metadata/bindings, keep type/origin.
            if (\in_array($existingType, [Acl::type_MENUS, Acl::type_PC, Acl::type_API], true)
                && \in_array($definition->resourceType, [
                    AuthorizationResourceType::QUERY,
                    AuthorizationResourceType::RESUMABLE_TASK,
                    AuthorizationResourceType::OPERATION,
                ], true)
            ) {
                $prevMeta = $this->decodeMetadata((string)$existing->getData(Acl::schema_fields_RESOURCE_METADATA));
                $prevMeta['bindings'] = \array_values(\array_merge(
                    \is_array($prevMeta['bindings'] ?? null) ? $prevMeta['bindings'] : [],
                    $bindingRows,
                ));
                $existing->setData(Acl::schema_fields_RESOURCE_METADATA, \json_encode($prevMeta, JSON_UNESCAPED_UNICODE));
                $existing->save();
                return;
            }
        }

        $row = [
            Acl::schema_fields_SOURCE_ID => $sourceId,
            Acl::schema_fields_SOURCE_NAME => $definition->name !== '' ? $definition->name : $definition->code,
            Acl::schema_fields_DOCUMENT => $definition->description,
            Acl::schema_fields_PARENT_SOURCE => $definition->parentSource,
            Acl::schema_fields_MODULE => $definition->module,
            Acl::schema_fields_TYPE => $storageType,
            Acl::schema_fields_ACL_ORIGIN => $definition->origin,
            Acl::schema_fields_ACCESS_MODE => $definition->accessMode ?: Acl::ACCESS_MODE_EDIT,
            Acl::schema_fields_IS_BACKEND => $definition->isBackend ? 1 : 0,
            Acl::schema_fields_IS_ENABLE => 1,
            Acl::schema_fields_API_EXPOSABLE => $definition->apiExposable ? 1 : 0,
            Acl::schema_fields_SCOPE_GROUP => $definition->scopeGroup,
            Acl::schema_fields_RESOURCE_METADATA => \json_encode($metadata, JSON_UNESCAPED_UNICODE),
            Acl::schema_fields_ICON => '',
            Acl::schema_fields_CLASS => '',
            Acl::schema_fields_ROUTER => '',
            Acl::schema_fields_ROUTE => '',
            Acl::schema_fields_METHOD => '',
            Acl::schema_fields_REWRITE => '',
            Acl::schema_fields_ORDER => 0,
        ];

        if ($existing->getSourceId() === $sourceId) {
            foreach ($row as $key => $value) {
                // Keep route fields from controller if already set.
                if (\in_array($key, [
                    Acl::schema_fields_CLASS,
                    Acl::schema_fields_ROUTER,
                    Acl::schema_fields_ROUTE,
                    Acl::schema_fields_METHOD,
                    Acl::schema_fields_REWRITE,
                    Acl::schema_fields_ICON,
                    Acl::schema_fields_PARENT_SOURCE,
                ], true)) {
                    $current = (string)$existing->getData($key);
                    if ($current !== '' && ($value === '' || $value === null)) {
                        continue;
                    }
                }
                $existing->setData($key, $value);
            }
            $existing->save();
            return;
        }

        $acl->clear()->setData($row)->save();
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }
}
