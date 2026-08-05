<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderArtifact;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderMessage;
use Weline\Websites\Model\AiSiteBuilderSession;
use Weline\Websites\Model\AiSitePlanDraft;
use Weline\Websites\Model\AiSitePlanVersion;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainAutoResolveTask;
use Weline\Websites\Model\DomainDnsRecord;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPoolFlowLog;
use Weline\Websites\Model\MaintenancePreviewToken;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\ScopeMaintenanceAudit;
use Weline\Websites\Model\ScopeMaintenanceState;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\AiWorkbench\SessionService;
use Weline\Websites\Service\WebsiteBackupService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_mutable_read_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);
    return is_array($decoded) ? $decoded : [];
}

function r43_mutable_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

function r43_mutable_assert_isolated(): string
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_websites_mutable_requires_isolated_database_flag');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $database = trim((string)($env['db']['master']['database'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_websites_mutable_requires_mig_clone_database:' . $database);
    }
    /** @var Website $website */
    $website = ObjectManager::getInstance(Website::class, [], false);
    $connector = get_class($website->getConnection()->getConnector());
    $driver = strtolower($connector);
    if (!str_contains($driver, 'pgsql') && !str_contains($driver, 'postgres')) {
        throw new RuntimeException('r43_websites_mutable_requires_postgresql:' . $connector);
    }
    return $connector;
}

function r43_mutable_token(array $input): string
{
    $token = strtolower(trim((string)($input['token'] ?? '')));
    if (preg_match('/^[a-z0-9]{6,32}$/D', $token) !== 1) {
        throw new InvalidArgumentException('token must be 6..32 lowercase letters or digits');
    }
    return $token;
}

/** @return list<array<string,mixed>> */
function r43_mutable_rows(string $modelClass, array $where): array
{
    $model = ObjectManager::getInstance($modelClass, [], false);
    $model->clearData()->clearQuery();
    foreach ($where as $field => $value) {
        $model->where((string)$field, $value);
    }
    $rows = $model->select()->fetchArray();
    return is_array($rows) ? array_values($rows) : [];
}

function r43_mutable_delete(string $modelClass, array $where): void
{
    $model = ObjectManager::getInstance($modelClass, [], false);
    $query = $model->getConnection()->getQuery()->table($model->getTable());
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $query->delete()->fetch();
}

function r43_mutable_assert_owned_code(string $code): void
{
    if (preg_match('/^r43_[a-z0-9_]{6,40}$/D', $code) !== 1) {
        throw new RuntimeException('refusing Websites cleanup outside r43 namespace:' . $code);
    }
}

/** @return array<string,mixed> */
function r43_mutable_data(string $kind, string $token): array
{
    $code = 'r43_' . substr($kind, 0, 4) . '_' . $token;
    $domain = 'r43-' . substr($kind, 0, 4) . '-' . $token . '.test';
    return [
        'kind' => $kind,
        'token' => $token,
        'website_code' => $code,
        'website_name' => 'R43 ' . ucfirst(str_replace('_', ' ', $kind)) . ' ' . strtoupper(substr($token, -6)),
        'domain' => $domain,
        'description' => 'R43 ' . $kind . ' ' . $token,
        'initial_enabled' => false,
    ];
}

function r43_mutable_create_domain_pool(string $domain, string $description): int
{
    /** @var Domain $root */
    $root = ObjectManager::getInstance(Domain::class, [], false);
    $root->clearData()->clearQuery()
        ->setAccountId(0)
        ->setDomain($domain)
        ->setStatus(Domain::STATUS_ACTIVE)
        ->setResolveStatus(Domain::RESOLVE_STATUS_PENDING)
        ->setHttpsStatus(Domain::HTTPS_STATUS_NONE)
        ->setDnsAccountId(0)
        ->setCdnAccountId(0)
        ->setDnsProvider('')
        ->setCdnProvider('')
        ->forceCheck(false)
        ->save();
    $rootId = (int)$root->getData(Domain::schema_fields_ID);
    if ($rootId <= 0) {
        throw new RuntimeException('failed to create task-owned root domain');
    }

    /** @var DomainPool $pool */
    $pool = ObjectManager::getInstance(DomainPool::class, [], false);
    $pool->clearData()->clearQuery()
        ->setDomain($domain)
        ->setParentDomainId($rootId)
        ->setDescription($description)
        ->setStatus(DomainPool::STATUS_ACTIVE)
        ->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING)
        ->setDnsStatus(DomainPool::INFRA_STATUS_READY)
        ->setCdnStatus(DomainPool::INFRA_STATUS_READY)
        ->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE)
        ->setSiteReady(false)
        ->save();
    $poolId = (int)$pool->getData(DomainPool::schema_fields_ID);
    if ($poolId <= 0) {
        throw new RuntimeException('failed to create task-owned domain pool');
    }
    return $poolId;
}

function r43_mutable_cleanup_domain(string $domain): void
{
    if (!str_starts_with($domain, 'r43-') || !str_ends_with($domain, '.test')) {
        throw new RuntimeException('refusing non-R43 domain cleanup:' . $domain);
    }
    $pools = r43_mutable_rows(DomainPool::class, [DomainPool::schema_fields_DOMAIN => $domain]);
    foreach ($pools as $pool) {
        $poolId = (int)($pool[DomainPool::schema_fields_ID] ?? 0);
        if ($poolId > 0) {
            r43_mutable_delete(DomainPoolFlowLog::class, [DomainPoolFlowLog::schema_fields_POOL_ID => $poolId]);
        }
    }
    r43_mutable_delete(WebsiteDomain::class, [WebsiteDomain::schema_fields_DOMAIN => $domain]);
    r43_mutable_delete(DomainAutoResolveTask::class, [DomainAutoResolveTask::schema_fields_DOMAIN => $domain]);
    r43_mutable_delete(DomainPool::class, [DomainPool::schema_fields_DOMAIN => $domain]);

    $roots = r43_mutable_rows(Domain::class, [Domain::schema_fields_DOMAIN => $domain]);
    foreach ($roots as $root) {
        $rootId = (int)($root[Domain::schema_fields_ID] ?? 0);
        if ($rootId > 0) {
            r43_mutable_delete(DomainDnsRecord::class, [DomainDnsRecord::schema_fields_DOMAIN_ID => $rootId]);
        }
    }
    r43_mutable_delete(Domain::class, [Domain::schema_fields_DOMAIN => $domain]);
}

function r43_mutable_create_website(array $data): int
{
    $code = (string)$data['website_code'];
    r43_mutable_assert_owned_code($code);

    /** @var Website $defaults */
    $defaults = ObjectManager::getInstance(Website::class, [], false);
    $defaults->clearData()->clearQuery()
        ->where(Website::schema_fields_ID, Website::ID_DEFAULT)
        ->find()
        ->fetch();

    /** @var Website $website */
    $website = ObjectManager::getInstance(Website::class, [], false);
    $website->clearData()->clearQuery()
        ->setName((string)$data['website_name'])
        ->setCode($code)
        ->setUrl('https://' . (string)$data['domain'] . '/')
        ->setDefaultCurrency(trim((string)$defaults->getData(Website::schema_fields_DEFAULT_CURRENCY)) ?: 'CNY')
        ->setDefaultLanguage(trim((string)$defaults->getData(Website::schema_fields_DEFAULT_LANGUAGE)) ?: 'zh_Hans_CN')
        ->setDefaultTimezone(trim((string)$defaults->getData(Website::schema_fields_DEFAULT_TIMEZONE)) ?: 'Asia/Shanghai')
        ->setScope(trim((string)$defaults->getData(Website::schema_fields_SCOPE)) ?: 'default')
        ->save(true);
    $websiteId = (int)$website->getData(Website::schema_fields_ID);
    if ($websiteId <= Website::ID_DEFAULT) {
        throw new RuntimeException('failed to create task-owned fixture website');
    }
    return $websiteId;
}

/** @return list<array<string,mixed>> */
function r43_mutable_backups_for_website(int $websiteId): array
{
    /** @var WebsiteBackupService $service */
    $service = ObjectManager::getInstance(WebsiteBackupService::class);
    $result = [];
    foreach ($service->listBackups() as $backup) {
        if ((int)($backup['website_id'] ?? 0) !== $websiteId) {
            continue;
        }
        $filename = (string)($backup['filename'] ?? '');
        $exists = false;
        if ($filename !== '') {
            try {
                $exists = is_file($service->getBackupPath($filename));
            } catch (Throwable) {
                $exists = false;
            }
        }
        $backup['exists'] = $exists;
        $result[] = $backup;
    }
    return $result;
}

function r43_mutable_delete_backups(int $websiteId): int
{
    /** @var WebsiteBackupService $service */
    $service = ObjectManager::getInstance(WebsiteBackupService::class);
    $deleted = 0;
    foreach (r43_mutable_backups_for_website($websiteId) as $backup) {
        $filename = (string)($backup['filename'] ?? '');
        if ($filename !== '' && $service->deleteBackup($filename)) {
            $deleted++;
        }
    }
    return $deleted;
}

function r43_mutable_cleanup_website(string $code): int
{
    r43_mutable_assert_owned_code($code);
    $deletedBackups = 0;
    $websites = r43_mutable_rows(Website::class, [Website::schema_fields_CODE => $code]);
    foreach ($websites as $website) {
        $websiteId = (int)($website[Website::schema_fields_ID] ?? 0);
        if ($websiteId <= Website::ID_DEFAULT) {
            throw new RuntimeException('refusing cleanup of default Website');
        }
        $deletedBackups += r43_mutable_delete_backups($websiteId);
        $states = r43_mutable_rows(
            ScopeMaintenanceState::class,
            [ScopeMaintenanceState::schema_fields_WEBSITE_ID => $websiteId]
        );
        foreach ($states as $state) {
            $scopeKey = (string)($state[ScopeMaintenanceState::schema_fields_SCOPE_KEY] ?? '');
            if ($scopeKey !== '') {
                r43_mutable_delete(
                    ScopeMaintenanceAudit::class,
                    [ScopeMaintenanceAudit::schema_fields_SCOPE_KEY => $scopeKey]
                );
                r43_mutable_delete(
                    MaintenancePreviewToken::class,
                    [MaintenancePreviewToken::schema_fields_SCOPE_KEY => $scopeKey]
                );
            }
        }
        r43_mutable_delete(
            ScopeMaintenanceState::class,
            [ScopeMaintenanceState::schema_fields_WEBSITE_ID => $websiteId]
        );
        r43_mutable_delete(
            SalesChannel::class,
            [SalesChannel::schema_fields_WEBSITE_ID => $websiteId]
        );
        r43_mutable_delete(Store::class, [Store::schema_fields_WEBSITE_ID => $websiteId]);
        r43_mutable_delete(
            WebsiteDomain::class,
            [WebsiteDomain::schema_fields_WEBSITE_ID => $websiteId]
        );
        r43_mutable_delete(
            WebsiteCurrency::class,
            [WebsiteCurrency::schema_fields_WEBSITE_ID => $websiteId]
        );
        r43_mutable_delete(
            WebsiteLanguage::class,
            [WebsiteLanguage::schema_fields_WEBSITE_ID => $websiteId]
        );
        r43_mutable_delete(Website::class, [Website::schema_fields_ID => $websiteId]);
    }
    return $deletedBackups;
}

/** @return array<string,mixed> */
function r43_mutable_inspect_website(array $data): array
{
    $websites = r43_mutable_rows(
        Website::class,
        [Website::schema_fields_CODE => (string)$data['website_code']]
    );
    $websiteIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row[Website::schema_fields_ID] ?? 0),
        $websites
    )));
    $websiteDomains = [];
    foreach ($websiteIds as $websiteId) {
        $websiteDomains = array_merge(
            $websiteDomains,
            r43_mutable_rows(
                WebsiteDomain::class,
                [WebsiteDomain::schema_fields_WEBSITE_ID => $websiteId]
            )
        );
    }
    return [
        'websites' => array_map(static fn(array $row): array => [
            'website_id' => (int)($row[Website::schema_fields_ID] ?? 0),
            'name' => (string)($row[Website::schema_fields_NAME] ?? ''),
            'code' => (string)($row[Website::schema_fields_CODE] ?? ''),
            'url' => (string)($row[Website::schema_fields_URL] ?? ''),
        ], $websites),
        'website_domains' => array_map(static fn(array $row): array => [
            'website_id' => (int)($row[WebsiteDomain::schema_fields_WEBSITE_ID] ?? 0),
            'pool_id' => (int)($row[WebsiteDomain::schema_fields_POOL_ID] ?? 0),
            'domain' => (string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? ''),
        ], $websiteDomains),
        'pools' => r43_mutable_rows(
            DomainPool::class,
            [DomainPool::schema_fields_DOMAIN => (string)$data['domain']]
        ),
        'root_domains' => r43_mutable_rows(
            Domain::class,
            [Domain::schema_fields_DOMAIN => (string)$data['domain']]
        ),
    ];
}

/** @return array<string,mixed> */
function r43_mutable_inspect_domain(array $data): array
{
    $pools = r43_mutable_rows(
        DomainPool::class,
        [DomainPool::schema_fields_DOMAIN => (string)$data['domain']]
    );
    $logs = [];
    foreach ($pools as $pool) {
        $poolId = (int)($pool[DomainPool::schema_fields_ID] ?? 0);
        if ($poolId > 0) {
            $logs = array_merge(
                $logs,
                r43_mutable_rows(
                    DomainPoolFlowLog::class,
                    [DomainPoolFlowLog::schema_fields_POOL_ID => $poolId]
                )
            );
        }
    }
    return [
        'root_domains' => r43_mutable_rows(
            Domain::class,
            [Domain::schema_fields_DOMAIN => (string)$data['domain']]
        ),
        'pools' => $pools,
        'flow_logs' => $logs,
    ];
}

/** @return list<array<string,mixed>> */
function r43_mutable_site_sessions(array $data): array
{
    $publicId = trim((string)($data['public_id'] ?? ''));
    if ($publicId !== '') {
        return r43_mutable_rows(
            AiSiteBuilderSession::class,
            [AiSiteBuilderSession::schema_fields_PUBLIC_ID => $publicId]
        );
    }
    /** @var AiSiteBuilderSession $model */
    $model = ObjectManager::getInstance(AiSiteBuilderSession::class, [], false);
    $rows = $model->clearData()->clearQuery()
        ->where(AiSiteBuilderSession::schema_fields_SCOPE_JSON, '%' . (string)$data['token'] . '%', 'LIKE')
        ->select()
        ->fetchArray();
    return is_array($rows) ? array_values($rows) : [];
}

/** @return list<array<string,mixed>> */
function r43_mutable_site_plan_drafts(array $data): array
{
    $domain = strtolower(trim((string)($data['domain'] ?? '')));
    $token = trim((string)($data['token'] ?? ''));
    if (!str_starts_with($domain, 'r43-site-') || !str_ends_with($domain, '.test') || $token === '') {
        throw new RuntimeException('refusing non-R43 site-plan lookup');
    }

    $rows = r43_mutable_rows(
        AiSitePlanDraft::class,
        [AiSitePlanDraft::schema_fields_SELECTED_DOMAIN => $domain]
    );
    /** @var AiSitePlanDraft $model */
    $model = ObjectManager::getInstance(AiSitePlanDraft::class, [], false);
    $payloadRows = $model->clearData()->clearQuery()
        ->where(AiSitePlanDraft::schema_fields_PAYLOAD_JSON, '%' . $token . '%', 'LIKE')
        ->select()
        ->fetchArray();
    if (is_array($payloadRows)) {
        $rows = array_merge($rows, array_values($payloadRows));
    }

    $owned = [];
    foreach ($rows as $row) {
        $draftId = (int)($row[AiSitePlanDraft::schema_fields_ID] ?? 0);
        $selectedDomain = strtolower(trim((string)($row[AiSitePlanDraft::schema_fields_SELECTED_DOMAIN] ?? '')));
        $payload = (string)($row[AiSitePlanDraft::schema_fields_PAYLOAD_JSON] ?? '');
        if ($draftId > 0 && ($selectedDomain === $domain || str_contains($payload, $token))) {
            $owned[$draftId] = $row;
        }
    }

    return array_values($owned);
}

/** @return array<string,mixed> */
function r43_mutable_inspect_site_builder(array $data): array
{
    $sessions = r43_mutable_site_sessions($data);
    $drafts = r43_mutable_site_plan_drafts($data);
    $eventCount = 0;
    $messageCount = 0;
    $artifactCount = 0;
    $versionCount = 0;
    $normalized = [];
    foreach ($sessions as $row) {
        $sessionId = (int)($row[AiSiteBuilderSession::schema_fields_ID] ?? 0);
        if ($sessionId > 0) {
            $eventCount += count(r43_mutable_rows(
                AiSiteBuilderEvent::class,
                [AiSiteBuilderEvent::schema_fields_SESSION_ID => $sessionId]
            ));
            $messageCount += count(r43_mutable_rows(
                AiSiteBuilderMessage::class,
                [AiSiteBuilderMessage::schema_fields_SESSION_ID => $sessionId]
            ));
            $artifactCount += count(r43_mutable_rows(
                AiSiteBuilderArtifact::class,
                [AiSiteBuilderArtifact::schema_fields_SESSION_ID => $sessionId]
            ));
        }
        $scope = json_decode((string)($row[AiSiteBuilderSession::schema_fields_SCOPE_JSON] ?? '{}'), true);
        $normalized[] = [
            'session_id' => $sessionId,
            'public_id' => (string)($row[AiSiteBuilderSession::schema_fields_PUBLIC_ID] ?? ''),
            'admin_user_id' => (int)($row[AiSiteBuilderSession::schema_fields_ADMIN_USER_ID] ?? 0),
            'provider_code' => (string)($row[AiSiteBuilderSession::schema_fields_PROVIDER_CODE] ?? ''),
            'current_stage' => (string)($row[AiSiteBuilderSession::schema_fields_CURRENT_STAGE] ?? ''),
            'scope' => is_array($scope) ? $scope : [],
        ];
    }
    foreach ($drafts as $draft) {
        $draftId = (int)($draft[AiSitePlanDraft::schema_fields_ID] ?? 0);
        if ($draftId > 0) {
            $versionCount += count(r43_mutable_rows(
                AiSitePlanVersion::class,
                [AiSitePlanVersion::schema_fields_DRAFT_ID => $draftId]
            ));
        }
    }
    return [
        'sessions' => $normalized,
        'event_count' => $eventCount,
        'message_count' => $messageCount,
        'artifact_count' => $artifactCount,
        'plan_draft_count' => count($drafts),
        'plan_version_count' => $versionCount,
    ];
}

function r43_mutable_cleanup_site_builder(array $data): void
{
    /** @var SessionService $service */
    $service = ObjectManager::getInstance(SessionService::class);
    foreach (r43_mutable_site_sessions($data) as $row) {
        $publicId = (string)($row[AiSiteBuilderSession::schema_fields_PUBLIC_ID] ?? '');
        $adminId = (int)($row[AiSiteBuilderSession::schema_fields_ADMIN_USER_ID] ?? 0);
        if ($publicId !== '' && $adminId > 0) {
            $service->deleteSessionByPublicId($publicId, $adminId);
        }
    }
    foreach (r43_mutable_site_plan_drafts($data) as $draft) {
        $draftId = (int)($draft[AiSitePlanDraft::schema_fields_ID] ?? 0);
        if ($draftId <= 0) {
            continue;
        }
        r43_mutable_delete(
            AiSitePlanVersion::class,
            [AiSitePlanVersion::schema_fields_DRAFT_ID => $draftId]
        );
        r43_mutable_delete(
            AiSitePlanDraft::class,
            [AiSitePlanDraft::schema_fields_ID => $draftId]
        );
    }
}

/** @return array<string,mixed> */
function r43_mutable_inspect_maintenance(array $data): array
{
    $websites = r43_mutable_rows(
        Website::class,
        [Website::schema_fields_CODE => (string)$data['website_code']]
    );
    $states = [];
    $auditCount = 0;
    foreach ($websites as $website) {
        $websiteId = (int)($website[Website::schema_fields_ID] ?? 0);
        $rows = r43_mutable_rows(
            ScopeMaintenanceState::class,
            [ScopeMaintenanceState::schema_fields_WEBSITE_ID => $websiteId]
        );
        $states = array_merge($states, $rows);
        foreach ($rows as $row) {
            $scopeKey = (string)($row[ScopeMaintenanceState::schema_fields_SCOPE_KEY] ?? '');
            if ($scopeKey !== '') {
                $auditCount += count(r43_mutable_rows(
                    ScopeMaintenanceAudit::class,
                    [ScopeMaintenanceAudit::schema_fields_SCOPE_KEY => $scopeKey]
                ));
            }
        }
    }
    $enabled = false;
    foreach ($states as $state) {
        if ((int)($state[ScopeMaintenanceState::schema_fields_ENABLED] ?? 0) === 1) {
            $enabled = true;
            break;
        }
    }
    return [
        'websites' => $websites,
        'states' => $states,
        'enabled' => $enabled,
        'audit_count' => $auditCount,
    ];
}

/** @return array<string,mixed> */
function r43_mutable_inspect_backup(array $data): array
{
    $websites = r43_mutable_rows(
        Website::class,
        [Website::schema_fields_CODE => (string)$data['website_code']]
    );
    $backups = [];
    foreach ($websites as $website) {
        $websiteId = (int)($website[Website::schema_fields_ID] ?? 0);
        $backups = array_merge($backups, r43_mutable_backups_for_website($websiteId));
    }
    return ['websites' => $websites, 'backups' => $backups];
}

/** @return array<string,mixed> */
function r43_mutable_inspect(array $data): array
{
    return match ((string)$data['kind']) {
        'website' => r43_mutable_inspect_website($data),
        'domain' => r43_mutable_inspect_domain($data),
        'site_builder' => r43_mutable_inspect_site_builder($data),
        'maintenance' => r43_mutable_inspect_maintenance($data),
        'backup' => r43_mutable_inspect_backup($data),
        default => throw new InvalidArgumentException('unsupported kind'),
    };
}

try {
    $connector = r43_mutable_assert_isolated();
    $input = r43_mutable_read_input();
    $action = trim((string)($input['action'] ?? ''));
    $kind = trim((string)($input['kind'] ?? ''));
    $allowedKinds = ['website', 'domain', 'site_builder', 'maintenance', 'backup'];
    if (!in_array($kind, $allowedKinds, true)) {
        throw new InvalidArgumentException('kind must be website, domain, site_builder, maintenance, or backup');
    }
    $data = array_merge(r43_mutable_data($kind, r43_mutable_token($input)), $input);

    if ($action === 'prepare') {
        if ($kind === 'website') {
            r43_mutable_cleanup_website((string)$data['website_code']);
            r43_mutable_cleanup_domain((string)$data['domain']);
            $data['pool_id'] = r43_mutable_create_domain_pool(
                (string)$data['domain'],
                (string)$data['description']
            );
        } elseif ($kind === 'domain') {
            r43_mutable_cleanup_domain((string)$data['domain']);
        } elseif ($kind === 'site_builder') {
            r43_mutable_cleanup_site_builder($data);
        } else {
            r43_mutable_cleanup_website((string)$data['website_code']);
            $data['website_id'] = r43_mutable_create_website($data);
        }
        r43_mutable_output(['ok' => true, 'connector' => $connector] + $data);
    }

    if ($action === 'inspect') {
        r43_mutable_output(['ok' => true, 'connector' => $connector] + r43_mutable_inspect($data));
    }

    if ($action === 'cleanup') {
        $deletedBackups = 0;
        if ($kind === 'website') {
            $deletedBackups = r43_mutable_cleanup_website((string)$data['website_code']);
            r43_mutable_cleanup_domain((string)$data['domain']);
        } elseif ($kind === 'domain') {
            r43_mutable_cleanup_domain((string)$data['domain']);
        } elseif ($kind === 'site_builder') {
            r43_mutable_cleanup_site_builder($data);
        } else {
            $deletedBackups = r43_mutable_cleanup_website((string)$data['website_code']);
        }
        $remaining = r43_mutable_inspect($data);
        r43_mutable_output([
            'ok' => true,
            'connector' => $connector,
            'deleted_backups' => $deletedBackups,
            'remaining' => $remaining,
        ]);
    }

    throw new InvalidArgumentException('unsupported action:' . $action);
} catch (Throwable $throwable) {
    r43_mutable_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
