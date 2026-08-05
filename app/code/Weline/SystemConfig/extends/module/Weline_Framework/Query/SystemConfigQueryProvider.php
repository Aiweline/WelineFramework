<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Extends\Module\Weline_Framework\Query;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\SystemConfig\Service\SystemConfigCenterService;
use Weline\SystemConfig\Service\ConfigEnvelopeService;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

class SystemConfigQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly SystemConfigTemplateService $templateService,
        private readonly SystemConfigCenterService $configCenterService,
        private readonly SystemConfigTargetScopeService $targetScopeService,
        private readonly BackendObjectAuthorizationGuardInterface $objectAuthorizationGuard,
    ) {
    }

    public function getProviderName(): string
    {
        return 'system_config';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getConfig' => $this->getConfig($params),
            'resolveConfig' => $this->resolveConfig($params),
            'getConfigs' => $this->getConfigs($params),
            'getFallbacks' => $this->getFallbacks($params),
            'getTemplates' => $this->getTemplates($params),
            'getTemplateMeta' => $this->getTemplateMeta($params),
            'getModules' => $this->getModules($params),
            'getTree' => $this->getTree($params),
            'saveTemplateConfig' => $this->saveTemplateConfig($params),
            'precheckTemplateConfigRollback' => $this->precheckTemplateConfigRollback($params),
            'rollbackTemplateConfigVersion' => $this->rollbackTemplateConfigVersion($params),
            'setConfig' => $this->setConfig($params),
            'setScopedConfig' => $this->setScopedConfig($params),
            'deleteScopedConfig' => $this->deleteScopedConfig($params),
            'saveScopeConfig' => $this->saveScopeConfig($params),
            'rollbackScopeConfigVersion' => $this->rollbackScopeConfigVersion($params),
            'getConfigVersions' => $this->getConfigVersions($params),
            'getConfigVersionDetail' => $this->getConfigVersionDetail($params),
            'previewScopeLock' => $this->previewScopeLock($params),
            'lockScope' => $this->lockScope($params),
            'unlockScope' => $this->unlockScope($params),
            'previewRestoreSuppressed' => $this->previewRestoreSuppressed($params),
            'restoreSuppressedRows' => $this->restoreSuppressedRows($params),
            'discardSuppressedRows' => $this->discardSuppressedRows($params),
            'registerSecurityPolicyLkg' => $this->registerSecurityPolicyLkg($params),
            'exportConfigEnvelope' => $this->exportConfigEnvelope($params),
            'previewConfigImport' => $this->previewConfigImport($params),
            'importConfigEnvelope' => $this->importConfigEnvelope($params),
            default => throw new \InvalidArgumentException(
                (string)__('SystemConfig query provider does not support: %{1}', $operation)
            ),
        };
    }

    private function getConfig(array $params): mixed
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        $returnType = strtolower(trim((string)($params['return_type'] ?? 'value')));

        if ($key === '' || $module === '') {
            return in_array($returnType, ['field', 'array'], true) ? [] : ($params['default'] ?? null);
        }

        if (in_array($returnType, ['field', 'array'], true)) {
            return $this->configCenterService->getFieldObject(
                module: $module,
                area: $area,
                key: $key,
                code: isset($params['code']) ? (string)$params['code'] : null,
                scope: isset($params['scope']) ? (string)$params['scope'] : null,
                locale: isset($params['locale']) ? (string)$params['locale'] : null,
                default: $params['default'] ?? null
            );
        }

        return $this->systemConfig->getConfig(
            key: $key,
            module: $module,
            area: $area,
            default: $params['default'] ?? null,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null
        );
    }

    private function resolveConfig(array $params): array
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return ['found' => false, 'value' => $params['default'] ?? null, 'source' => null];
        }

        return $this->systemConfig->resolveConfig(
            key: $key,
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            default: $params['default'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigs(array $params): array
    {
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        $returnType = strtolower(trim((string)($params['return_type'] ?? 'map')));

        if ($module === '') {
            return [];
        }

        if ($returnType === 'fields') {
            return $this->configCenterService->getFieldObjects(
                module: $module,
                area: $area,
                code: isset($params['code']) ? (string)$params['code'] : null,
                scope: isset($params['scope']) ? (string)$params['scope'] : null,
                locale: isset($params['locale']) ? (string)$params['locale'] : null
            );
        }

        return $this->systemConfig->getConfigMapByModule(
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null
        );
    }

    private function getFallbacks(array $params): array
    {
        $scope = isset($params['scope']) ? (string)$params['scope'] : null;
        $locale = isset($params['locale']) ? (string)$params['locale'] : null;

        return [
            'scope' => $this->systemConfig->normalizeScope($scope),
            'locale' => $this->systemConfig->normalizeLocale($locale),
            'fallback_scopes' => $this->systemConfig->getFallbackScopes($scope),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTemplates(array $params): array
    {
        return $this->templateService->getTemplates(
            module: isset($params['module']) ? (string)$params['module'] : null,
            area: isset($params['area']) ? (string)$params['area'] : null,
            forceReload: (bool)($params['force_reload'] ?? false)
        );
    }

    private function getTemplateMeta(array $params): ?array
    {
        return $this->templateService->getTemplateMeta(
            module: (string)($params['module'] ?? ''),
            area: (string)($params['area'] ?? SystemConfig::area_BACKEND),
            code: (string)($params['code'] ?? ''),
            forceReload: (bool)($params['force_reload'] ?? false)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getModules(array $params): array
    {
        return $this->templateService->getModules(
            area: isset($params['area']) ? (string)$params['area'] : null,
            search: isset($params['search']) ? (string)$params['search'] : null,
            forceReload: (bool)($params['force_reload'] ?? false)
        );
    }

    private function getTree(array $params): array
    {
        $tree = $this->templateService->getTree(
            module: isset($params['module']) ? (string)$params['module'] : null,
            area: isset($params['area']) ? (string)$params['area'] : null,
            search: isset($params['search']) ? (string)$params['search'] : null,
            forceReload: (bool)($params['force_reload'] ?? false)
        );

        if (!empty($params['with_values'])) {
            return $this->configCenterService->enrichTreeWithValues(
                $tree,
                isset($params['scope']) ? (string)$params['scope'] : null,
                isset($params['locale']) ? (string)$params['locale'] : null
            );
        }

        return $tree;
    }

    private function saveTemplateConfig(array $params): array
    {
        $target = $this->resolveWriteTarget($params);
        $options = $this->extractSaveOptions($params);
        $options['scope_identity'] = $target['identity'];
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::UPDATE,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );

        return $this->configCenterService->saveTemplateConfig(
            module: (string)($params['module'] ?? ''),
            area: (string)($params['area'] ?? SystemConfig::area_BACKEND),
            code: (string)($params['code'] ?? ''),
            values: is_array($params['values'] ?? null) ? $params['values'] : [],
            inheritKeys: array_values(array_map('strval', (array)($params['inherit_keys'] ?? []))),
            baseVersions: is_array($params['base_versions'] ?? null) ? $params['base_versions'] : [],
            scope: $target['storage_scope'],
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $options
        );
    }

    /**
     * TASK-P1C-004：写操作必须带显式 TargetScope（target_scope 或 website/store/channel 或完整三段 scope）。
     *
     * @param array<string, mixed> $params
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string,identity:\Weline\Framework\Runtime\ScopeIdentity}
     */
    private function resolveWriteTarget(array $params): array
    {
        $hasExplicit = trim((string)($params['target_scope'] ?? '')) !== ''
            || trim((string)($params['website_code'] ?? $params['target_website'] ?? '')) !== ''
            || trim((string)($params['store_code'] ?? $params['target_store'] ?? '')) !== ''
            || trim((string)($params['channel_code'] ?? $params['target_channel'] ?? '')) !== ''
            || trim((string)($params['scope'] ?? '')) !== '';
        if (!$hasExplicit) {
            throw new \InvalidArgumentException('system_config_write_requires_explicit_target_scope');
        }

        return $this->targetScopeService->resolveFromInput($params, allowSessionFallback: false);
    }

    private function precheckTemplateConfigRollback(array $params): array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        $scope = $this->versionScopeOrDeny($versionId, ObjectAction::VIEW);
        $this->objectAuthorizationGuard->requireForQuery(ObjectAction::VIEW, $scope);

        return $this->configCenterService->precheckTemplateConfigRollback(
            $versionId,
            $this->extractRollbackContext($params)
        );
    }

    private function rollbackTemplateConfigVersion(array $params): array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        $scope = $this->versionScopeOrDeny($versionId, ObjectAction::REPLAY);
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::REPLAY,
            $scope,
            $this->expectedGrantVersion($params),
        );

        return $this->configCenterService->rollbackTemplateConfigVersion(
            $versionId,
            array_merge($this->extractRollbackContext($params), $this->extractSaveOptions($params))
        );
    }

    private function setConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $value = (string)($params['value'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::UPDATE,
            ScopeIdentity::global(),
            $this->expectedGrantVersion($params),
        );

        return $this->systemConfig->setConfig($key, $value, $module, $area);
    }

    private function setScopedConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        $target = $this->resolveWriteTarget($params);
        $options = $this->extractSaveOptions($params);
        $options['scope_identity'] = $target['identity'];
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::UPDATE,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );

        return $this->systemConfig->setScopedConfig(
            key: $key,
            value: $params['value'] ?? null,
            module: $module,
            area: $area,
            scope: $target['storage_scope'],
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $options
        );
    }

    private function deleteScopedConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        $target = $this->resolveWriteTarget($params);
        $options = $this->extractSaveOptions($params);
        $options['scope_identity'] = $target['identity'];
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::DELETE,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );

        return $this->systemConfig->deleteScopedConfig(
            key: $key,
            module: $module,
            area: $area,
            scope: $target['storage_scope'],
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $options
        );
    }

    private function saveScopeConfig(array $params): array
    {
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        $values = is_array($params['values'] ?? null) ? $params['values'] : [];

        if ($module === '') {
            return ['success' => false, 'status' => 'invalid_module'];
        }

        $target = $this->resolveWriteTarget($params);
        $options = $this->extractSaveOptions($params);
        $options['scope_identity'] = $target['identity'];
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::UPDATE,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );

        return $this->systemConfig->saveScopeConfig(
            module: $module,
            area: $area,
            values: $values,
            scope: $target['storage_scope'],
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $options
        );
    }

    private function rollbackScopeConfigVersion(array $params): array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['success' => false, 'status' => 'invalid_version'];
        }

        $scope = $this->versionScopeOrDeny($versionId, ObjectAction::REPLAY);
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::REPLAY,
            $scope,
            $this->expectedGrantVersion($params),
        );

        return $this->systemConfig->rollbackScopeConfigVersion($versionId, $this->extractSaveOptions($params));
    }

    private function getConfigVersions(array $params): array
    {
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        if ($module === '') {
            return [];
        }

        $target = $this->resolveReadTarget($params);
        $this->objectAuthorizationGuard->requireForQuery(ObjectAction::LIST, $target['identity']);

        return $this->systemConfig->getConfigVersions(
            module: $module,
            area: $area,
            scope: $target['storage_scope'],
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            limit: (int)($params['limit'] ?? 50)
        );
    }

    private function getConfigVersionDetail(array $params): ?array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            return null;
        }

        $detail = $this->systemConfig->getConfigVersionDetail($versionId);
        if ($detail === null) {
            $this->objectAuthorizationGuard->denyForQuery(
                ObjectAction::VIEW,
                ScopeIdentity::global(),
            );
        }
        $scope = $this->scopeFromVersionDetail($detail);
        $this->objectAuthorizationGuard->requireForQuery(ObjectAction::VIEW, $scope);

        $changes = is_array($detail['changes'] ?? null) ? $detail['changes'] : [];
        foreach ($changes as $index => $change) {
            if (!is_array($change)) {
                continue;
            }
            if (is_array($change['old_row'] ?? null)) {
                $changes[$index]['old_row'] = $this->systemConfig->maskSensitiveRow($change['old_row']);
            }
            if (is_array($change['new_row'] ?? null)) {
                $changes[$index]['new_row'] = $this->systemConfig->maskSensitiveRow($change['new_row']);
            }
        }
        $detail['changes'] = $changes;

        return $detail;
    }

    private function previewScopeLock(array $params): array
    {
        $target = $this->resolveReadTarget($params);
        $this->objectAuthorizationGuard->requireForQuery(ObjectAction::VIEW, $target['identity']);

        return $this->configCenterService->previewLock(
            (string)($params['module'] ?? ''),
            (string)($params['area'] ?? SystemConfig::area_BACKEND),
            (string)($params['key'] ?? ''),
            $target['storage_scope'],
            (string)($params['locale'] ?? SystemConfig::LOCALE_DEFAULT),
        );
    }

    private function lockScope(array $params): array
    {
        return $this->mutateLockScope($params, ObjectAction::UPDATE, 'lock');
    }

    private function unlockScope(array $params): array
    {
        return $this->mutateLockScope($params, ObjectAction::UNLOCK, 'unlock');
    }

    private function previewRestoreSuppressed(array $params): array
    {
        $target = $this->resolveReadTarget($params);
        $this->objectAuthorizationGuard->requireForQuery(ObjectAction::VIEW, $target['identity']);

        return $this->configCenterService->previewRestoreSuppressed(
            (string)($params['module'] ?? ''),
            (string)($params['area'] ?? SystemConfig::area_BACKEND),
            (string)($params['key'] ?? ''),
            $target['storage_scope'],
        );
    }

    private function restoreSuppressedRows(array $params): array
    {
        return $this->mutateSuppressedRows($params, false);
    }

    private function discardSuppressedRows(array $params): array
    {
        return $this->mutateSuppressedRows($params, true);
    }

    private function registerSecurityPolicyLkg(array $params): array
    {
        $target = $this->resolveWriteTarget($params);
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::UPDATE,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );

        return \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\SystemConfig\Service\SecurityPolicyConfigGuard::class,
        )->registerLkg(
            $target['storage_scope'],
            isset($params['locale']) ? (string)$params['locale'] : SystemConfig::LOCALE_DEFAULT,
            \is_array($params['values'] ?? null) ? $params['values'] : [],
            \array_values(\array_map('strval', (array)($params['inherit_keys'] ?? []))),
        );
    }

    private function exportConfigEnvelope(array $params): array
    {
        $target = $this->resolveReadTarget($params);
        $grant = $this->objectAuthorizationGuard->requireForQuery(
            ObjectAction::EXPORT,
            $target['identity'],
        );
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        $locale = (string)($params['locale'] ?? SystemConfig::LOCALE_DEFAULT);
        if ($module === '') {
            throw new \InvalidArgumentException('system_config_export_module_required');
        }
        $payload = [
            'schema_version' => 1,
            'module' => $module,
            'area' => $area,
            'scope' => $target['storage_scope'],
            'scope_identity' => $target['identity']->toArray(),
            'locale' => $locale,
            'values' => $this->systemConfig->getConfigMapByModule(
                $module,
                $area,
                $target['storage_scope'],
                $locale,
            ),
        ];
        $envelope = $this->configEnvelopeService()->export(
            $payload,
            $target['identity'],
            (string)($params['filename'] ?? 'system-config-envelope.json'),
            isset($params['recipient_kid']) ? (string)$params['recipient_kid'] : null,
            isset($params['ttl_seconds']) ? (int)$params['ttl_seconds'] : null,
        );

        return [
            'success' => true,
            'grant_version' => $grant->matchedGrantVersion,
            'envelope' => $envelope,
        ];
    }

    private function previewConfigImport(array $params): array
    {
        $preview = $this->previewImportPayload($params);
        $grant = $this->objectAuthorizationGuard->requireForQuery(
            ObjectAction::VIEW,
            $preview['scope'],
        );

        return [
            'success' => true,
            'grant_version' => $grant->matchedGrantVersion,
            'package_uuid' => $preview['package_uuid'],
            'recipient_kid' => $preview['recipient_kid'],
            'module' => $preview['module'],
            'area' => $preview['area'],
            'scope' => $preview['storage_scope'],
            'locale' => $preview['locale'],
            'key_count' => \count($preview['values']),
            'keys' => \array_values(\array_map('strval', \array_keys($preview['values']))),
            'source_trusted' => false,
        ];
    }

    private function importConfigEnvelope(array $params): array
    {
        $preview = $this->previewImportPayload($params);
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::IMPORT,
            $preview['scope'],
            $this->expectedGrantVersion($params),
        );
        $result = null;
        $this->configEnvelopeService()->import(
            $preview['envelope'],
            function (array $payload, array $aad) use (&$result, $preview): void {
                $this->assertImportPayloadMatchesPreview($payload, $preview);
                $result = $this->systemConfig->saveScopeConfig(
                    module: $preview['module'],
                    area: $preview['area'],
                    values: $preview['values'],
                    scope: $preview['storage_scope'],
                    locale: $preview['locale'],
                    options: [
                        'operation' => 'import',
                        'reason' => 'config_envelope_import',
                        'scope_identity' => $preview['scope'],
                        'metadata' => [
                            'package_uuid' => $preview['package_uuid'],
                            'recipient_kid' => $preview['recipient_kid'],
                        ],
                    ],
                );
                if (!\is_array($result) || empty($result['success'])) {
                    throw new \RuntimeException('system_config_import_apply_failed');
                }
            },
            isset($params['filename']) ? (string)$params['filename'] : null,
            $preview['scope'],
        );

        return $result ?? ['success' => false, 'status' => 'import_not_applied'];
    }

    private function mutateLockScope(array $params, string $action, string $operation): array
    {
        $target = $this->resolveWriteTarget($params);
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            $action,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );
        $arguments = [
            (string)($params['module'] ?? ''),
            (string)($params['area'] ?? SystemConfig::area_BACKEND),
            (string)($params['key'] ?? ''),
            $target['storage_scope'],
            (string)($params['locale'] ?? SystemConfig::LOCALE_DEFAULT),
            $this->extractSaveOptions($params),
        ];

        return $operation === 'unlock'
            ? $this->configCenterService->unlockScope(...$arguments)
            : $this->configCenterService->lockScope(...$arguments);
    }

    private function mutateSuppressedRows(array $params, bool $discard): array
    {
        $target = $this->resolveWriteTarget($params);
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            ObjectAction::REPLAY,
            $target['identity'],
            $this->expectedGrantVersion($params),
        );
        $targets = \is_array($params['targets'] ?? null) ? $params['targets'] : [];
        $arguments = [
            (string)($params['module'] ?? ''),
            (string)($params['area'] ?? SystemConfig::area_BACKEND),
            (string)($params['key'] ?? ''),
            $targets,
            $this->extractSaveOptions($params),
        ];

        return $discard
            ? $this->configCenterService->discardSuppressedRows(...$arguments)
            : $this->configCenterService->restoreSuppressedRows(...$arguments);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   envelope:array<string,mixed>,scope:ScopeIdentity,storage_scope:string,module:string,
     *   area:string,locale:string,values:array<string,mixed>,package_uuid:string,recipient_kid:string
     * }
     */
    private function previewImportPayload(array $params): array
    {
        $envelope = \is_array($params['envelope'] ?? null) ? $params['envelope'] : [];
        $preview = $this->configEnvelopeService()->previewImport(
            $envelope,
            isset($params['filename']) ? (string)$params['filename'] : null,
        );
        $payload = \is_array($preview['payload'] ?? null) ? $preview['payload'] : [];
        if ((int)($payload['schema_version'] ?? 0) !== 1
            || !\is_array($payload['scope_identity'] ?? null)
            || !\is_array($payload['values'] ?? null)
        ) {
            throw new \RuntimeException('system_config_import_payload_invalid');
        }
        $scope = ScopeIdentity::fromArray($payload['scope_identity']);
        $storageScope = (string)($payload['scope'] ?? '');
        $resolvedTarget = $this->targetScopeService->resolveFromInput(
            ['target_scope' => $storageScope],
            allowSessionFallback: false,
        );
        if (!$scope->equals($resolvedTarget['identity'])) {
            throw new \RuntimeException('system_config_import_scope_identity_mismatch');
        }
        $aadScope = (string)($preview['aad']['scope'] ?? '');
        if ($aadScope !== $scope->canonicalKey()) {
            throw new \RuntimeException('system_config_import_aad_scope_mismatch');
        }
        $module = \trim((string)($payload['module'] ?? ''));
        $area = \trim((string)($payload['area'] ?? SystemConfig::area_BACKEND));
        $locale = \trim((string)($payload['locale'] ?? SystemConfig::LOCALE_DEFAULT));
        if ($module === '' || $area === '' || $locale === '') {
            throw new \RuntimeException('system_config_import_identity_invalid');
        }

        return [
            'envelope' => $envelope,
            'scope' => $scope,
            'storage_scope' => $resolvedTarget['storage_scope'],
            'module' => $module,
            'area' => $area,
            'locale' => $locale,
            'values' => $payload['values'],
            'package_uuid' => (string)($preview['package_uuid'] ?? ''),
            'recipient_kid' => (string)($preview['recipient_kid'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $preview
     */
    private function assertImportPayloadMatchesPreview(array $payload, array $preview): void
    {
        $hash = static fn(array $value): string => \hash(
            'sha256',
            (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
        if ($hash($payload) !== $hash([
            'schema_version' => 1,
            'module' => $preview['module'],
            'area' => $preview['area'],
            'scope' => $preview['storage_scope'],
            'scope_identity' => $preview['scope']->toArray(),
            'locale' => $preview['locale'],
            'values' => $preview['values'],
        ])) {
            throw new \RuntimeException('system_config_import_preview_changed');
        }
    }

    protected function configEnvelopeService(): ConfigEnvelopeService
    {
        return ConfigEnvelopeService::fromEnv();
    }

    /**
     * @param array<string, mixed> $params
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string,identity:ScopeIdentity}
     */
    private function resolveReadTarget(array $params): array
    {
        $hasExplicit = \array_key_exists('target_scope', $params)
            || \array_key_exists('scope', $params)
            || \array_key_exists('website_code', $params)
            || \array_key_exists('store_code', $params)
            || \array_key_exists('channel_code', $params);
        if (!$hasExplicit) {
            throw new \InvalidArgumentException('system_config_read_requires_explicit_target_scope');
        }

        return $this->targetScopeService->resolveFromInput($params, allowSessionFallback: false);
    }

    private function versionScopeOrDeny(int $versionId, string $action): ScopeIdentity
    {
        if ($versionId <= 0) {
            $this->objectAuthorizationGuard->denyForQuery($action, ScopeIdentity::global());
        }
        $detail = $this->systemConfig->getConfigVersionDetail($versionId);
        if ($detail === null) {
            $this->objectAuthorizationGuard->denyForQuery($action, ScopeIdentity::global());
        }

        return $this->scopeFromVersionDetail($detail);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function scopeFromVersionDetail(array $detail): ScopeIdentity
    {
        $storageScope = (string)(
            $detail[SystemConfigVersion::schema_fields_SCOPE]
            ?? SystemConfig::SCOPE_GLOBAL
        );

        return $this->targetScopeService->resolveFromInput(
            ['target_scope' => $storageScope],
            allowSessionFallback: false,
        )['identity'];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function expectedGrantVersion(array $params): int
    {
        $value = $params['expected_grant_version'] ?? null;
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }

    private function extractSaveOptions(array $params): array
    {
        $allowed = [
            'inherit_keys',
            'base_versions',
            'actor_id',
            'actor_name',
            'reason',
            'metadata',
            'field_metadata',
            'value_type',
            'is_sensitive',
            'operation',
            'parent_version_id',
        ];
        $options = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $options[$key] = $params[$key];
            }
        }

        return $options;
    }

    private function extractRollbackContext(array $params): array
    {
        $allowed = ['module', 'area', 'code', 'scope', 'locale'];
        $context = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $context[$key] = $params[$key];
            }
        }

        return $context;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'system_config',
            'name' => __('System config query'),
            'description' => __('Provides scope-aware system config read, write, version, and rollback operations.'),
            'module' => 'Weline_SystemConfig',
            'operations' => [
                [
                    'name' => 'getConfig',
                    'description' => __('Get a resolved config value, or a field object when return_type=field.'),
                    'params' => $this->getConfigParams(),
                ],
                [
                    'name' => 'resolveConfig',
                    'description' => __('Get a resolved config value with source and fallback metadata.'),
                    'params' => $this->commonReadParams(true),
                ],
                [
                    'name' => 'getConfigs',
                    'description' => __('Get resolved config values for a module, or field objects when return_type=fields.'),
                    'params' => $this->getConfigsParams(),
                ],
                [
                    'name' => 'getFallbacks',
                    'description' => __('Preview normalized scope and fallback scopes.'),
                    'params' => [
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTemplates',
                    'description' => __('List SystemConfig templates registered through Extends.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTemplateMeta',
                    'description' => __('Parse one SystemConfig PHTML template without executing it.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'code', 'type' => 'string', 'required' => true],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getModules',
                    'description' => __('List modules that provide SystemConfig templates.'),
                    'params' => [
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'search', 'type' => 'string', 'required' => false],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTree',
                    'description' => __('Build the SystemConfig template tree by module and area.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'search', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'with_values', 'type' => 'bool', 'required' => false],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'saveTemplateConfig',
                    'description' => __('Save a template-backed config batch after field whitelist validation.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'code', 'type' => 'string', 'required' => true],
                        ['name' => 'values', 'type' => 'object', 'required' => false],
                        ['name' => 'inherit_keys', 'type' => 'array', 'required' => false],
                        ['name' => 'base_versions', 'type' => 'object', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                        $this->grantVersionParam(),
                    ]),
                ],
                [
                    'name' => 'precheckTemplateConfigRollback',
                    'description' => __('Precheck whether a template-backed config version can rollback without mutation.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'code', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'rollbackTemplateConfigVersion',
                    'description' => __('Rollback a template-backed config version after rollback precheck.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'code', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'actor_id', 'type' => 'string', 'required' => false],
                        ['name' => 'actor_name', 'type' => 'string', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                        $this->grantVersionParam(),
                    ],
                ],
                [
                    'name' => 'setConfig',
                    'description' => __('Set a global compatibility config value.'),
                    'params' => [
                        ['name' => 'key', 'type' => 'string', 'required' => true],
                        ['name' => 'value', 'type' => 'string', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        $this->grantVersionParam(),
                    ],
                ],
                [
                    'name' => 'setScopedConfig',
                    'description' => __('Set one scoped config value and create a version batch.'),
                    'params' => $this->commonWriteParams(),
                ],
                [
                    'name' => 'deleteScopedConfig',
                    'description' => __('Delete one scoped value so resolution inherits from fallback scopes.'),
                    'params' => $this->commonWriteParams(false),
                ],
                [
                    'name' => 'saveScopeConfig',
                    'description' => __('Save a scoped config batch and return its version id.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'values', 'type' => 'object', 'required' => true],
                        ['name' => 'inherit_keys', 'type' => 'array', 'required' => false],
                        ['name' => 'base_versions', 'type' => 'object', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                        $this->grantVersionParam(),
                    ]),
                ],
                [
                    'name' => 'rollbackScopeConfigVersion',
                    'description' => __('Rollback a scoped config version batch.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'actor_id', 'type' => 'string', 'required' => false],
                        ['name' => 'actor_name', 'type' => 'string', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                        $this->grantVersionParam(),
                    ],
                ],
                [
                    'name' => 'getConfigVersions',
                    'description' => __('List scoped config version batches.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ]),
                ],
                [
                    'name' => 'getConfigVersionDetail',
                    'description' => __('Get one scoped config version detail with sensitive values masked.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'previewScopeLock',
                    'description' => __('Preview lower-scope rows affected by locking one config key.'),
                    'params' => $this->scopeMutationParams(false),
                ],
                [
                    'name' => 'lockScope',
                    'description' => __('Lock one config key at the explicit target scope.'),
                    'params' => $this->scopeMutationParams(),
                ],
                [
                    'name' => 'unlockScope',
                    'description' => __('Unlock one config key at the explicit target scope.'),
                    'params' => $this->scopeMutationParams(),
                ],
                [
                    'name' => 'previewRestoreSuppressed',
                    'description' => __('Preview suppressed lower-scope rows without mutation.'),
                    'params' => $this->scopeMutationParams(false),
                ],
                [
                    'name' => 'restoreSuppressedRows',
                    'description' => __('Restore selected suppressed rows after replay authorization.'),
                    'params' => array_merge($this->scopeMutationParams(), [
                        ['name' => 'targets', 'type' => 'array', 'required' => true],
                    ]),
                ],
                [
                    'name' => 'discardSuppressedRows',
                    'description' => __('Discard selected suppressed rows after replay authorization.'),
                    'params' => array_merge($this->scopeMutationParams(), [
                        ['name' => 'targets', 'type' => 'array', 'required' => true],
                    ]),
                ],
                [
                    'name' => 'registerSecurityPolicyLkg',
                    'description' => __('Register a reviewed Scope security-header candidate before activating the exact same values.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'values', 'type' => 'object', 'required' => false],
                        ['name' => 'inherit_keys', 'type' => 'array', 'required' => false],
                        $this->grantVersionParam(),
                    ]),
                ],
                [
                    'name' => 'exportConfigEnvelope',
                    'description' => __('Export one explicitly authorized scope as an encrypted configuration envelope.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'filename', 'type' => 'string', 'required' => false],
                        ['name' => 'recipient_kid', 'type' => 'string', 'required' => false],
                        ['name' => 'ttl_seconds', 'type' => 'int', 'required' => false],
                    ]),
                ],
                [
                    'name' => 'previewConfigImport',
                    'description' => __('Decrypt and validate configuration envelope metadata without mutation.'),
                    'params' => $this->configEnvelopeImportParams(false),
                ],
                [
                    'name' => 'importConfigEnvelope',
                    'description' => __('Import an encrypted configuration envelope after versioned submit authorization.'),
                    'params' => $this->configEnvelopeImportParams(true),
                ],
            ],
        ];
    }

    private function scopeMutationParams(bool $submit = true): array
    {
        $params = array_merge($this->commonModuleParams(), [
            ['name' => 'key', 'type' => 'string', 'required' => true],
            ['name' => 'reason', 'type' => 'string', 'required' => false],
        ]);
        if ($submit) {
            $params[] = $this->grantVersionParam();
        }

        return $params;
    }

    private function configEnvelopeImportParams(bool $submit): array
    {
        $params = [
            ['name' => 'envelope', 'type' => 'object', 'required' => true],
            ['name' => 'filename', 'type' => 'string', 'required' => false],
        ];
        if ($submit) {
            $params[] = $this->grantVersionParam();
        }

        return $params;
    }

    private function commonReadParams(bool $includeKey = false): array
    {
        $params = $this->commonModuleParams();
        if ($includeKey) {
            array_unshift($params, ['name' => 'key', 'type' => 'string', 'required' => true]);
        }
        $params[] = ['name' => 'default', 'type' => 'mixed', 'required' => false];

        return $params;
    }

    private function getConfigParams(): array
    {
        $params = $this->commonReadParams(true);
        $params[] = [
            'name' => 'return_type',
            'type' => 'string',
            'required' => false,
            'description' => __('value|field|array; default value keeps compatibility.'),
        ];
        $params[] = [
            'name' => 'code',
            'type' => 'string',
            'required' => false,
            'description' => __('Limit field metadata lookup to one template code when return_type=field.'),
        ];

        return $params;
    }

    private function getConfigsParams(): array
    {
        $params = $this->commonModuleParams();
        $params[] = [
            'name' => 'return_type',
            'type' => 'string',
            'required' => false,
            'description' => __('map|fields; default map keeps compatibility.'),
        ];
        $params[] = [
            'name' => 'code',
            'type' => 'string',
            'required' => false,
            'description' => __('Limit field metadata lookup to one template code when return_type=fields.'),
        ];

        return $params;
    }

    private function commonModuleParams(): array
    {
        return [
            ['name' => 'module', 'type' => 'string', 'required' => true],
            ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('backend|frontend')],
            ['name' => 'scope', 'type' => 'string', 'required' => false, 'description' => __('完整三段 scope；写操作与 target_scope 二选一')],
            ['name' => 'target_scope', 'type' => 'string', 'required' => false, 'description' => __('显式写目标；禁止依赖 Session')],
            ['name' => 'website_code', 'type' => 'string', 'required' => false],
            ['name' => 'store_code', 'type' => 'string', 'required' => false],
            ['name' => 'channel_code', 'type' => 'string', 'required' => false],
            ['name' => 'locale', 'type' => 'string', 'required' => false],
        ];
    }

    private function commonWriteParams(bool $includeValue = true): array
    {
        $params = [
            ['name' => 'key', 'type' => 'string', 'required' => true],
            ['name' => 'module', 'type' => 'string', 'required' => true],
            ['name' => 'area', 'type' => 'string', 'required' => false],
            ['name' => 'scope', 'type' => 'string', 'required' => false],
            ['name' => 'target_scope', 'type' => 'string', 'required' => false],
            ['name' => 'website_code', 'type' => 'string', 'required' => false],
            ['name' => 'store_code', 'type' => 'string', 'required' => false],
            ['name' => 'channel_code', 'type' => 'string', 'required' => false],
            ['name' => 'locale', 'type' => 'string', 'required' => false],
            ['name' => 'base_versions', 'type' => 'object', 'required' => false],
            ['name' => 'reason', 'type' => 'string', 'required' => false],
            $this->grantVersionParam(),
        ];
        if ($includeValue) {
            array_splice($params, 1, 0, [['name' => 'value', 'type' => 'mixed', 'required' => true]]);
        }

        return $params;
    }

    /**
     * @return array{name:string,type:string,required:bool,description:mixed}
     */
    private function grantVersionParam(): array
    {
        return [
            'name' => 'expected_grant_version',
            'type' => 'int',
            'required' => true,
            'description' => __('预览/读取时返回的对象授权版本；提交前必须保持一致'),
        ];
    }
}
