<?php
declare(strict_types=1);

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeReleaseBatch;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\LayoutContentValidationRegistry;
use Weline\Theme\Service\Scoped\ThemeLayoutPayloadDiffer;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\Scoped\ThemePatchEngine;
use Weline\Theme\Service\Scoped\ThemeScopedReleaseBatch;
use Weline\Theme\Service\Scoped\ThemeScopedWorkspace;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function read_payload(): array
{
    $raw = stream_get_contents(STDIN);
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    return is_array($payload) ? $payload : [];
}

function output_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function fixture_token(array $payload): string
{
    $token = strtolower(trim((string)($payload['token'] ?? '')));
    $token = preg_replace('/[^a-z0-9_\\-]+/', '-', $token) ?: '';
    $token = trim($token, '-_');
    return $token !== '' ? substr($token, 0, 48) : 'theme-editor-default-injection';
}

function resolve_layout_identity(array $payload): array
{
    $source = $payload['identity'] ?? [];
    $source = is_array($source) ? $source : [];
    foreach (['layout_option', 'scope', 'target_type', 'target_id'] as $key) {
        if (array_key_exists($key, $payload) && !array_key_exists($key, $source)) {
            $source[$key] = $payload[$key];
        }
    }

    $hasIdentity = false;
    foreach (['layout_option', 'scope', 'target_type', 'target_id'] as $key) {
        if (array_key_exists($key, $source) && trim((string)$source[$key]) !== '') {
            $hasIdentity = true;
            break;
        }
    }
    if (!$hasIdentity) {
        return [];
    }

    return [
        'layout_option' => trim((string)($source['layout_option'] ?? 'default')) ?: 'default',
        'scope' => trim((string)($source['scope'] ?? 'default')) ?: 'default',
        'target_type' => trim((string)($source['target_type'] ?? 'global')) ?: 'global',
        'target_id' => max(0, (int)($source['target_id'] ?? 0)),
    ];
}

function apply_layout_identity_filter($query, array $identity, string $modelClass)
{
    if ($identity === []) {
        return $query;
    }

    return $query
        ->where($modelClass::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
        ->where($modelClass::schema_fields_SCOPE, $identity['scope'])
        ->where($modelClass::schema_fields_TARGET_TYPE, $identity['target_type'])
        ->where($modelClass::schema_fields_TARGET_ID, $identity['target_id']);
}

function theme_layout_table_exists(ThemeLayout $layout): bool
{
    try {
        return (bool)$layout->getConnection()->getConnector()->tableExist(ThemeLayout::schema_table);
    } catch (Throwable) {
        return false;
    }
}

function theme_scope_release_batch_table_exists(ThemeScopeReleaseBatch $batch): bool
{
    try {
        return (bool)$batch->getConnection()->getConnector()->tableExist(
            ThemeScopeReleaseBatch::schema_table,
        );
    } catch (Throwable) {
        return false;
    }
}

function cleanup_theme_editor_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $pageType,
    array $identity = []
): void
{
    if (!theme_layout_table_exists($layout)) {
        // Greenfield: theme_layout dropped; scoped cleanup is handled separately.
        try {
            $versionQuery = $version->clearQuery()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType);
            apply_layout_identity_filter($versionQuery, $identity, ThemeLayoutVersion::class)
                ->delete()
                ->fetch();
        } catch (Throwable) {
            // version table may also be absent / empty after reset
        }
        cleanup_scoped_layout_workspaces($themeId, $pageType, $identity);
        return;
    }

    $layoutQuery = $layout->clearQuery()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType);
    apply_layout_identity_filter($layoutQuery, $identity, ThemeLayout::class)
        ->delete()
        ->fetch();

    $versionQuery = $version->clearQuery()
        ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType);
    apply_layout_identity_filter($versionQuery, $identity, ThemeLayoutVersion::class)
        ->delete()
        ->fetch();

    cleanup_scoped_layout_workspaces($themeId, $pageType, $identity);
}

/**
 * Clear scoped workspace/patch/revision/release rows for a Theme editor fixture identity.
 *
 * @param array<string,mixed> $identity
 * @return array{patches:int,revisions:int,releases:int,workspaces:int}
 */
function cleanup_scoped_layout_workspaces(int $themeId, string $pageType, array $identity = []): array
{
    $deleted = ['patches' => 0, 'revisions' => 0, 'releases' => 0, 'workspaces' => 0];
    if ($themeId <= 0 || trim($pageType) === '') {
        return $deleted;
    }

    /** @var ThemeScopeWorkspace $workspaceModel */
    $workspaceModel = clone ObjectManager::getInstance(ThemeScopeWorkspace::class);
    $query = $workspaceModel->clearData()->clearQuery()
        ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $themeId)
        ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $pageType)
        ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, 'layout');
    if ($identity !== []) {
        if (isset($identity['layout_option']) && trim((string)$identity['layout_option']) !== '') {
            $query->where(
                ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION,
                (string)$identity['layout_option'],
            );
        }
        if (isset($identity['scope']) && trim((string)$identity['scope']) !== '') {
            $query->where(ThemeScopeWorkspace::schema_fields_SCOPE, (string)$identity['scope']);
        }
        if (isset($identity['target_type']) && trim((string)$identity['target_type']) !== '') {
            $query->where(
                ThemeScopeWorkspace::schema_fields_TARGET_TYPE,
                (string)$identity['target_type'],
            );
        }
        if (array_key_exists('target_id', $identity)) {
            $query->where(
                ThemeScopeWorkspace::schema_fields_TARGET_ID,
                (int)$identity['target_id'],
            );
        }
    }

    $rows = $query->select()->fetchArray();
    foreach (is_array($rows) ? $rows : [] as $row) {
        $workspaceId = (int)($row[ThemeScopeWorkspace::schema_fields_ID] ?? 0);
        if ($workspaceId <= 0) {
            continue;
        }
        $deleted['patches'] += delete_theme_scope_rows(
            ThemeScopePatch::class,
            ThemeScopePatch::schema_fields_WORKSPACE_ID,
            $workspaceId,
        );
        $deleted['revisions'] += delete_theme_scope_rows(
            ThemeScopeRevision::class,
            ThemeScopeRevision::schema_fields_WORKSPACE_ID,
            $workspaceId,
        );
        $deleted['releases'] += delete_theme_scope_rows(
            ThemeScopeRelease::class,
            ThemeScopeRelease::schema_fields_WORKSPACE_ID,
            $workspaceId,
        );
        $workspaceModel->getConnection()->getQuery()
            ->table($workspaceModel->getTable())
            ->where(ThemeScopeWorkspace::schema_fields_ID, $workspaceId)
            ->delete()
            ->fetch();
        $deleted['workspaces']++;
    }

    return $deleted;
}

function snapshot_theme_editor_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $pageType,
    array $identity = []
): array
{
    $scoped = snapshot_scoped_layout_workspaces($themeId, $pageType, $identity);

    if (!theme_layout_table_exists($layout)) {
        return [
            'success' => true,
            'layout' => [],
            'versions' => [],
            'legacy_table' => false,
            'note' => 'theme_layout_missing_scoped_authority',
            'scoped' => $scoped,
        ];
    }

    $layoutQuery = $layout->clearQuery()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType);
    $layoutRows = apply_layout_identity_filter($layoutQuery, $identity, ThemeLayout::class)
        ->order(ThemeLayout::schema_fields_STATUS, 'ASC')
        ->order(ThemeLayout::schema_fields_AREA, 'ASC')
        ->order(ThemeLayout::schema_fields_SLOT_ID, 'ASC')
        ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
        ->order(ThemeLayout::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();

    $versionQuery = $version->clearQuery()
        ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType);
    $versionRows = apply_layout_identity_filter($versionQuery, $identity, ThemeLayoutVersion::class)
        ->order(ThemeLayoutVersion::schema_fields_VERSION_NUMBER, 'ASC')
        ->order(ThemeLayoutVersion::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();

    return [
        'success' => true,
        'layout' => is_array($layoutRows) ? array_values($layoutRows) : [],
        'versions' => is_array($versionRows) ? array_values($versionRows) : [],
        'legacy_table' => true,
        'scoped' => $scoped,
    ];
}

/**
 * @param array<string,mixed> $identity
 * @return array{workspaces:list<array<string,mixed>>,workspace_count:int,node_count:int}
 */
function snapshot_scoped_layout_workspaces(int $themeId, string $pageType, array $identity = []): array
{
    $workspaces = [];
    $nodeCount = 0;
    if ($themeId <= 0 || trim($pageType) === '') {
        return ['workspaces' => [], 'workspace_count' => 0, 'node_count' => 0];
    }

    /** @var ThemeScopeWorkspace $workspaceModel */
    $workspaceModel = clone ObjectManager::getInstance(ThemeScopeWorkspace::class);
    $query = $workspaceModel->clearData()->clearQuery()
        ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $themeId)
        ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $pageType)
        ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, 'layout');
    if ($identity !== []) {
        if (isset($identity['layout_option']) && trim((string)$identity['layout_option']) !== '') {
            $query->where(
                ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION,
                (string)$identity['layout_option'],
            );
        }
        if (isset($identity['scope']) && trim((string)$identity['scope']) !== '') {
            $query->where(ThemeScopeWorkspace::schema_fields_SCOPE, (string)$identity['scope']);
        }
        if (isset($identity['target_type']) && trim((string)$identity['target_type']) !== '') {
            $query->where(
                ThemeScopeWorkspace::schema_fields_TARGET_TYPE,
                (string)$identity['target_type'],
            );
        }
        if (array_key_exists('target_id', $identity)) {
            $query->where(
                ThemeScopeWorkspace::schema_fields_TARGET_ID,
                (int)$identity['target_id'],
            );
        }
    }

    $rows = $query->select()->fetchArray();
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $workspaceId = (int)($row[ThemeScopeWorkspace::schema_fields_ID] ?? 0);
        $revision = (int)($row[ThemeScopeWorkspace::schema_fields_REVISION] ?? 0);
        $patchCount = 0;
        $releaseCount = 0;
        if ($workspaceId > 0) {
            try {
                /** @var ThemeScopePatch $patchModel */
                $patchModel = clone ObjectManager::getInstance(ThemeScopePatch::class);
                $patches = $patchModel->clearData()->clearQuery()
                    ->where(ThemeScopePatch::schema_fields_WORKSPACE_ID, $workspaceId)
                    ->select()
                    ->fetchArray();
                $patchCount = is_array($patches) ? count($patches) : 0;
            } catch (Throwable) {
                $patchCount = 0;
            }
            try {
                /** @var ThemeScopeRelease $releaseModel */
                $releaseModel = clone ObjectManager::getInstance(ThemeScopeRelease::class);
                $releases = $releaseModel->clearData()->clearQuery()
                    ->where(ThemeScopeRelease::schema_fields_WORKSPACE_ID, $workspaceId)
                    ->select()
                    ->fetchArray();
                $releaseCount = is_array($releases) ? count($releases) : 0;
            } catch (Throwable) {
                $releaseCount = 0;
            }
        }
        $workspaces[] = [
            'workspace_id' => $workspaceId,
            'scope' => (string)($row[ThemeScopeWorkspace::schema_fields_SCOPE] ?? ''),
            'layout_option' => (string)($row[ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION] ?? ''),
            'target_type' => (string)($row[ThemeScopeWorkspace::schema_fields_TARGET_TYPE] ?? ''),
            'target_id' => (int)($row[ThemeScopeWorkspace::schema_fields_TARGET_ID] ?? 0),
            'locale' => (string)($row[ThemeScopeWorkspace::schema_fields_LOCALE] ?? ''),
            'revision' => $revision,
            'patch_count' => $patchCount,
            'release_count' => $releaseCount,
            'published_release_id' => (int)($row[ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID] ?? 0),
        ];
        $nodeCount += max(0, $revision > 0 ? 1 : 0);
    }

    return [
        'workspaces' => $workspaces,
        'workspace_count' => count($workspaces),
        'node_count' => $nodeCount,
    ];
}

function dashboard_identity(int $viewId, int $websiteId): array
{
    /** @var DashboardView $view */
    $view = clone ObjectManager::getInstance(DashboardView::class);
    $view->clearData()->clearQuery()->load($viewId);
    if ($view->getViewId() !== $viewId || $view->getWebsiteId() !== $websiteId) {
        fail('Dashboard layout identity cannot resolve its Website Scope.');
    }
    /** @var DashboardViewService $dashboardViews */
    $dashboardViews = ObjectManager::getInstance(DashboardViewService::class);
    return $dashboardViews->layoutIdentity($view)->toArray();
}

function cleanup_dashboard_identity_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $token
): void {
    $code = 'e2e-theme-default-' . $token;
    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $row = $website->clearQuery()->clearData()
        ->where(Website::schema_fields_CODE, $code)
        ->find()
        ->fetchArray();
    $websiteId = is_array($row) ? (int)($row[Website::schema_fields_ID] ?? 0) : 0;
    if ($websiteId <= 0) {
        return;
    }

    /** @var DashboardView $dashboardView */
    $dashboardView = clone ObjectManager::getInstance(DashboardView::class);
    $views = $dashboardView->clearQuery()->clearData()
        ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
        ->select()
        ->fetchArray();
    foreach (is_array($views) ? $views : [] as $viewRow) {
        $viewId = (int)($viewRow[DashboardView::schema_fields_ID] ?? 0);
        if ($viewId <= 0) {
            continue;
        }
        cleanup_theme_editor_fixture(
            $layout,
            $version,
            $themeId,
            DashboardView::PAGE_TYPE,
            dashboard_identity($viewId, $websiteId)
        );
    }

    $dashboardView->clearQuery()->clearData()
        ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
        ->delete()
        ->fetch();

    cleanup_fixture_website_relations($websiteId, $code);
}

function cleanup_fixture_website_relations(int $websiteId, string $code): void
{
    if ($websiteId <= Website::ID_DEFAULT || !str_starts_with($code, 'e2e-theme-default-')) {
        throw new RuntimeException('Refusing Theme E2E website cleanup outside its owned namespace.');
    }

    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $owned = $website->clearQuery()->clearData()
        ->where(Website::schema_fields_ID, $websiteId)
        ->where(Website::schema_fields_CODE, $code)
        ->find()
        ->fetchArray();
    if (!is_array($owned) || (int)($owned[Website::schema_fields_ID] ?? 0) !== $websiteId) {
        return;
    }

    foreach ([
        SalesChannel::class,
        Store::class,
        WebsiteDomain::class,
        WebsiteCurrency::class,
        WebsiteLanguage::class,
    ] as $modelClass) {
        $model = clone ObjectManager::getInstance($modelClass);
        $model->getConnection()->getQuery()
            ->table($model->getTable())
            ->where($modelClass::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
    }

    $website->getConnection()->getQuery()
        ->table($website->getTable())
        ->where(Website::schema_fields_ID, $websiteId)
        ->where(Website::schema_fields_CODE, $code)
        ->delete()
        ->fetch();
}

function prepare_dashboard_identity_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $token
): array {
    cleanup_dashboard_identity_fixture($layout, $version, $themeId, $token);
    $code = 'e2e-theme-default-' . $token;

    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $website->clearQuery()->clearData()
        ->setName('E2E Theme Default ' . $token)
        ->setCode($code)
        ->setUrl($code . '.test')
        ->setDefaultCurrency('CNY')
        ->setDefaultLanguage('zh_Hans_CN')
        ->setDefaultTimezone('Asia/Shanghai')
        ->setScope('e2e-theme-default')
        ->save();

    /** @var DashboardView $view */
    $view = clone ObjectManager::getInstance(DashboardView::class);
    $view->clearQuery()->clearData()
        ->setWebsiteId($website->getWebsiteId())
        ->setOwnerAdminId(null)
        ->setName('E2E 默认概览')
        ->setCode('default')
        ->setVisibility(DashboardView::VISIBILITY_SYSTEM)
        ->setIsDefault(true)
        ->setIsActive(true)
        ->setSortOrder(0)
        ->save();

    $identity = dashboard_identity($view->getViewId(), $website->getWebsiteId());
    ObjectManager::getInstance(DashboardViewService::class)->ensureLayoutInitialized($view);

    return [
        'success' => true,
        'website_id' => $website->getWebsiteId(),
        'view_id' => $view->getViewId(),
        'identity' => $identity,
    ];
}

function prepare_dashboard_identities_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $token,
    int $count = 2
): array {
    cleanup_dashboard_identity_fixture($layout, $version, $themeId, $token);
    $count = max(2, min(5, $count));
    $code = 'e2e-theme-default-' . $token;

    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $website->clearQuery()->clearData()
        ->setName('E2E Theme Default ' . $token)
        ->setCode($code)
        ->setUrl($code . '.test')
        ->setDefaultCurrency('CNY')
        ->setDefaultLanguage('zh_Hans_CN')
        ->setDefaultTimezone('Asia/Shanghai')
        ->setScope('e2e-theme-default')
        ->save();

    /** @var DashboardViewService $dashboardService */
    $dashboardService = ObjectManager::getInstance(DashboardViewService::class);
    $views = [];
    $identities = [];
    for ($i = 0; $i < $count; $i++) {
        /** @var DashboardView $view */
        $view = clone ObjectManager::getInstance(DashboardView::class);
        $view->clearQuery()->clearData()
            ->setWebsiteId($website->getWebsiteId())
            ->setOwnerAdminId(null)
            ->setName($i === 0 ? 'E2E 默认概览' : 'E2E 身份视图 ' . ($i + 1))
            ->setCode($i === 0 ? 'default' : 'identity-' . ($i + 1))
            ->setVisibility($i === 0 ? DashboardView::VISIBILITY_SYSTEM : DashboardView::VISIBILITY_PUBLIC)
            ->setIsDefault($i === 0)
            ->setIsActive(true)
            ->setSortOrder($i * 10)
            ->save();
        $dashboardService->ensureLayoutInitialized($view);

        $identity = dashboard_identity($view->getViewId(), $website->getWebsiteId());
        $views[] = [
            'view_id' => $view->getViewId(),
            'code' => $view->getCode(),
            'identity' => $identity,
        ];
        $identities[] = $identity;
    }

    return [
        'success' => true,
        'website_id' => $website->getWebsiteId(),
        'views' => $views,
        'identities' => $identities,
    ];
}

/** @param class-string<\Weline\Framework\Database\Model> $modelClass */
function delete_theme_scope_rows(string $modelClass, string $field, int $workspaceId): int
{
    $model = clone ObjectManager::getInstance($modelClass);
    $rows = $model->clearData()->clearQuery()
        ->where($field, $workspaceId)
        ->select()
        ->fetchArray();
    $count = is_array($rows) ? count($rows) : 0;
    if ($count === 0) {
        return 0;
    }
    $model->getConnection()->getQuery()
        ->table($model->getTable())
        ->where($field, $workspaceId)
        ->delete()
        ->fetch();

    return $count;
}

/**
 * @param list<string> $scopes
 * @return array{batches:int,patches:int,revisions:int,releases:int,workspaces:int}
 */
function cleanup_theme_scope_workspaces(array $scopes): array
{
    $deleted = ['batches' => 0, 'patches' => 0, 'revisions' => 0, 'releases' => 0, 'workspaces' => 0];
    foreach (array_values(array_unique($scopes)) as $scope) {
        if (!str_starts_with($scope, 'e2e-theme-scope-')) {
            throw new RuntimeException('Refusing Theme Scope cleanup outside its owned namespace.');
        }
        /** @var ThemeScopeReleaseBatch $batchModel */
        $batchModel = clone ObjectManager::getInstance(ThemeScopeReleaseBatch::class);
        if (theme_scope_release_batch_table_exists($batchModel)) {
            $batchRows = $batchModel->clearData()->clearQuery()
                ->where(ThemeScopeReleaseBatch::schema_fields_SCOPE, $scope)
                ->select()
                ->fetchArray();
            $batchCount = is_array($batchRows) ? count($batchRows) : 0;
            if ($batchCount > 0) {
                $batchModel->getConnection()->getQuery()
                    ->table($batchModel->getTable())
                    ->where(ThemeScopeReleaseBatch::schema_fields_SCOPE, $scope)
                    ->delete()
                    ->fetch();
                $deleted['batches'] += $batchCount;
            }
        }
        /** @var ThemeScopeWorkspace $workspaceModel */
        $workspaceModel = clone ObjectManager::getInstance(ThemeScopeWorkspace::class);
        $rows = $workspaceModel->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_SCOPE, $scope)
            ->select()
            ->fetchArray();
        foreach (is_array($rows) ? $rows : [] as $row) {
            $workspaceId = (int)($row[ThemeScopeWorkspace::schema_fields_ID] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }
            $deleted['patches'] += delete_theme_scope_rows(
                ThemeScopePatch::class,
                ThemeScopePatch::schema_fields_WORKSPACE_ID,
                $workspaceId,
            );
            $deleted['revisions'] += delete_theme_scope_rows(
                ThemeScopeRevision::class,
                ThemeScopeRevision::schema_fields_WORKSPACE_ID,
                $workspaceId,
            );
            $deleted['releases'] += delete_theme_scope_rows(
                ThemeScopeRelease::class,
                ThemeScopeRelease::schema_fields_WORKSPACE_ID,
                $workspaceId,
            );
            $workspaceModel->getConnection()->getQuery()
                ->table($workspaceModel->getTable())
                ->where(ThemeScopeWorkspace::schema_fields_ID, $workspaceId)
                ->delete()
                ->fetch();
            $deleted['workspaces']++;
        }
    }

    return $deleted;
}

/** @return array{website_code:string,store_code:string,channel_code:string,scopes:array<string,string>} */
function theme_scope_fixture_identity(string $token): array
{
    $websiteCode = 'e2e-theme-scope-' . str_replace('_', '-', $token);
    $storeCode = 'scope_store';
    $channelCode = 'scope_channel';
    /** @var ScopeHierarchyInterface $scopes */
    $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);

    return [
        'website_code' => $websiteCode,
        'store_code' => $storeCode,
        'channel_code' => $channelCode,
        'scopes' => [
            'website' => $scopes->contextFromIdentity(ScopeIdentity::website(1, $websiteCode))->storageScope,
            'store' => $scopes->contextFromIdentity(
                ScopeIdentity::store(1, $websiteCode, $storeCode, ScopeIdentity::MODE_NORMAL),
            )->storageScope,
            'channel' => $scopes->contextFromIdentity(
                ScopeIdentity::channel(
                    1,
                    $websiteCode,
                    $storeCode,
                    $channelCode,
                    ScopeIdentity::MODE_NORMAL,
                ),
            )->storageScope,
        ],
    ];
}

function cleanup_theme_scope_hierarchy(int $themeId, string $pageType, string $token): array
{
    $fixture = theme_scope_fixture_identity($token);
    $websiteCode = $fixture['website_code'];
    if (!str_starts_with($websiteCode, 'e2e-theme-scope-')) {
        throw new RuntimeException('Refusing Theme Scope hierarchy cleanup outside its owned namespace.');
    }

    /** @var ThemeLayout $layoutModel */
    $layoutModel = clone ObjectManager::getInstance(ThemeLayout::class);
    /** @var ThemeLayoutVersion $versionModel */
    $versionModel = clone ObjectManager::getInstance(ThemeLayoutVersion::class);
    foreach ($fixture['scopes'] as $scope) {
        cleanup_theme_editor_fixture($layoutModel, $versionModel, $themeId, $pageType, [
            'layout_option' => 'default',
            'scope' => $scope,
            'target_type' => 'global',
            'target_id' => 0,
        ]);
    }
    $deletedScopes = cleanup_theme_scope_workspaces(array_values($fixture['scopes']));

    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $row = $website->clearData()->clearQuery()
        ->where(Website::schema_fields_CODE, $websiteCode)
        ->find()
        ->fetchArray();
    $websiteId = is_array($row) ? (int)($row[Website::schema_fields_ID] ?? 0) : 0;
    if ($websiteId > 0) {
        foreach ([
            SalesChannel::class,
            Store::class,
            WebsiteDomain::class,
            WebsiteCurrency::class,
            WebsiteLanguage::class,
        ] as $modelClass) {
            $model = clone ObjectManager::getInstance($modelClass);
            $model->getConnection()->getQuery()
                ->table($model->getTable())
                ->where($modelClass::schema_fields_WEBSITE_ID, $websiteId)
                ->delete()
                ->fetch();
        }
        $website->getConnection()->getQuery()
            ->table($website->getTable())
            ->where(Website::schema_fields_ID, $websiteId)
            ->where(Website::schema_fields_CODE, $websiteCode)
            ->delete()
            ->fetch();
    }

    return ['success' => true, 'deleted_scopes' => $deletedScopes];
}

function prepare_theme_scope_hierarchy(int $themeId, string $pageType, string $token): array
{
    cleanup_theme_scope_hierarchy($themeId, $pageType, $token);
    $fixture = theme_scope_fixture_identity($token);
    $websiteCode = $fixture['website_code'];

    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $website->clearData()->clearQuery()
        ->setName('E2E 主题作用范围 Website With A Deliberately Long Name ' . $token)
        ->setCode($websiteCode)
        ->setUrl('https://' . $websiteCode . '.test')
        ->setDefaultCurrency('CNY')
        ->setDefaultLanguage('zh_Hans_CN')
        ->setDefaultTimezone('Asia/Shanghai')
        ->setScope('e2e-theme-scope')
        ->save();
    $websiteId = $website->getWebsiteId();

    /** @var Store $store */
    $store = clone ObjectManager::getInstance(Store::class);
    $store->clearData()->clearQuery()
        ->setWebsiteId($websiteId)
        ->setCode($fixture['store_code'])
        ->setName('E2E 店铺继承范围 Store With A Deliberately Long Name')
        ->setStoreMode(Store::MODE_NORMAL)
        ->setIsDefault(false)
        ->setStatus(true)
        ->setUrl(null)
        ->save();

    /** @var SalesChannel $channel */
    $channel = clone ObjectManager::getInstance(SalesChannel::class);
    $channel->clearData()->clearQuery()
        ->setWebsiteId($websiteId)
        ->setStoreId($store->getStoreId())
        ->setCode($fixture['channel_code'])
        ->setName('E2E 渠道继承范围 Channel With A Deliberately Long Name')
        ->setIsDefault(false)
        ->setStatus(true)
        ->save();

    $identities = [
        'website' => ScopeIdentity::website($websiteId, $websiteCode),
        'store' => ScopeIdentity::store(
            $websiteId,
            $websiteCode,
            $fixture['store_code'],
            ScopeIdentity::MODE_NORMAL,
        ),
        'channel' => ScopeIdentity::channel(
            $websiteId,
            $websiteCode,
            $fixture['store_code'],
            $fixture['channel_code'],
            ScopeIdentity::MODE_NORMAL,
        ),
    ];
    /** @var ScopeHierarchyInterface $scopes */
    $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);

    return [
        'success' => true,
        'website_id' => $websiteId,
        'store_id' => $store->getStoreId(),
        'channel_id' => $channel->getChannelId(),
        'identities' => array_map(static fn(ScopeIdentity $identity): array => $identity->toArray(), $identities),
        'scopes' => array_map(
            static fn(ScopeIdentity $identity): string => $scopes->contextFromIdentity($identity)->storageScope,
            $identities,
        ),
    ];
}

function require_theme_scope_batch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Build the production scoped-workspace service with an isolated projection boundary.
 *
 * Base loading and compilation remain real. Published projections are deliberately
 * no-op for this synthetic Website so the fixture can inject a failure after any
 * resource without writing compatibility tables, disk assets, or active bindings.
 */
function theme_scope_batch_workspace(int $failProjectionAt = 0): ThemeScopedWorkspace
{
    /** @var ThemeScopedResourceAdapterInterface $delegate */
    $delegate = ObjectManager::getInstance(ThemeScopedResourceAdapterInterface::class);
    $adapter = new class($delegate, $failProjectionAt) implements ThemeScopedResourceAdapterInterface {
        private int $publishedCalls = 0;

        public function __construct(
            private readonly ThemeScopedResourceAdapterInterface $delegate,
            private readonly int $failProjectionAt,
        ) {
        }

        public function loadBase(ThemeEditorContext $context): array
        {
            return $this->delegate->loadBase($context);
        }

        public function loadLegacyPublished(ThemeEditorContext $context): array
        {
            return $this->delegate->loadLegacyPublished($context);
        }

        public function compile(ThemeEditorContext $context, array $effectivePayload): array
        {
            return $this->delegate->compile($context, $effectivePayload);
        }

        public function projectPublished(
            ThemeEditorContext $context,
            array $effectivePayload,
            int $releaseId,
        ): void {
            unset($context, $effectivePayload, $releaseId);
            $this->publishedCalls++;
            if ($this->failProjectionAt > 0 && $this->publishedCalls === $this->failProjectionAt) {
                throw new RuntimeException(
                    'e2e_injected_batch_projection_failure:' . $this->failProjectionAt,
                );
            }
        }

        public function projectDraft(ThemeEditorContext $context, array $effectivePayload): void
        {
            unset($context, $effectivePayload);
        }
    };

    return new ThemeScopedWorkspace(
        workspaces: clone ObjectManager::getInstance(ThemeScopeWorkspace::class),
        revisions: clone ObjectManager::getInstance(ThemeScopeRevision::class),
        patches: clone ObjectManager::getInstance(ThemeScopePatch::class),
        releases: clone ObjectManager::getInstance(ThemeScopeRelease::class),
        scopes: ObjectManager::getInstance(ScopeHierarchyInterface::class),
        adapter: $adapter,
        patchEngine: ObjectManager::getInstance(ThemePatchEngine::class),
        layoutDiffer: ObjectManager::getInstance(ThemeLayoutPayloadDiffer::class),
        transactions: ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class),
        contentValidators: ObjectManager::getInstance(LayoutContentValidationRegistry::class),
        layoutSnapshots: ObjectManager::getInstance(ThemeLayoutSnapshotNormalizer::class),
        releaseBatches: clone ObjectManager::getInstance(ThemeScopeReleaseBatch::class),
    );
}

/** @return array<string,list<array<string,mixed>>> */
function theme_scope_batch_changes(int $themeId, string $marker): array
{
    return [
        ThemeEditorContext::RESOURCE_THEME_BINDING => [[
            'op' => 'set',
            'path' => '/theme_id',
            'value' => $themeId,
        ]],
        ThemeEditorContext::RESOURCE_LAYOUT => [[
            'op' => 'set',
            'path' => '/selection/e2e_batch_marker',
            'value' => $marker,
        ]],
        ThemeEditorContext::RESOURCE_META => [[
            'op' => 'set',
            'path' => '/values/e2e_batch_marker',
            'value' => $marker,
        ]],
        ThemeEditorContext::RESOURCE_APPEARANCE => [[
            'op' => 'set',
            'path' => '/tokens/e2e_batch_marker',
            'value' => $marker,
        ]],
        ThemeEditorContext::RESOURCE_I18N => [[
            'op' => 'set',
            'path' => '/translations/e2e_batch_marker',
            'value' => $marker,
        ]],
    ];
}

/**
 * Create one new immutable draft revision for every release resource.
 *
 * @return array<string,array{expected_revision:int,expected_parent_release_id:?int}>
 */
function apply_theme_scope_batch_drafts(
    ThemeScopedWorkspace $workspace,
    ThemeEditorContext $baseContext,
    int $themeId,
    string $marker,
): array {
    $changes = theme_scope_batch_changes($themeId, $marker);
    $expectations = [];
    foreach (ThemeEditorContext::RESOURCES as $resourceType) {
        $context = $baseContext->withResource($resourceType);
        $before = $workspace->load($context, true);
        $workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($before['revision'] ?? 0),
            expectedParentReleaseId: isset($before['expected_parent_release_id'])
                ? (int)$before['expected_parent_release_id']
                : null,
            changes: $changes[$resourceType],
            actorId: 'e2e-theme-batch',
            actorName: 'Theme batch runtime fixture',
            summary: 'E2E five-resource draft ' . $marker,
        );
        $after = $workspace->load($context, true);
        $expectations[$resourceType] = [
            'expected_revision' => (int)($after['revision'] ?? 0),
            'expected_parent_release_id' => isset($after['expected_parent_release_id'])
                ? (int)$after['expected_parent_release_id']
                : null,
        ];
    }

    return $expectations;
}

/** @return array<string,mixed> */
function snapshot_theme_scope_batch_database(string $scope): array
{
    if (!str_starts_with($scope, 'e2e-theme-scope-')) {
        throw new RuntimeException('Refusing Theme Scope snapshot outside its owned namespace.');
    }

    /** @var ThemeScopeWorkspace $workspaceModel */
    $workspaceModel = clone ObjectManager::getInstance(ThemeScopeWorkspace::class);
    $workspaceRows = $workspaceModel->clearData()->clearQuery()
        ->where(ThemeScopeWorkspace::schema_fields_SCOPE, $scope)
        ->order(ThemeScopeWorkspace::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();
    $workspaces = [];
    $revisions = [];
    $releases = [];
    foreach (is_array($workspaceRows) ? $workspaceRows : [] as $row) {
        $workspaceId = (int)($row[ThemeScopeWorkspace::schema_fields_ID] ?? 0);
        if ($workspaceId <= 0) {
            continue;
        }
        $resourceType = (string)($row[ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE] ?? '');
        $workspaces[$resourceType] = [
            'workspace_id' => $workspaceId,
            'resource_type' => $resourceType,
            'identity_hash' => (string)($row[ThemeScopeWorkspace::schema_fields_IDENTITY_HASH] ?? ''),
            'revision' => (int)($row[ThemeScopeWorkspace::schema_fields_REVISION] ?? 0),
            'draft_revision_id' => isset($row[ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID])
                ? (int)$row[ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID]
                : null,
            'published_release_id' => isset($row[ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID])
                ? (int)$row[ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID]
                : null,
            'last_good_release_id' => isset($row[ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID])
                ? (int)$row[ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID]
                : null,
            'parent_release_id' => isset($row[ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID])
                ? (int)$row[ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID]
                : null,
            'status' => (string)($row[ThemeScopeWorkspace::schema_fields_STATUS] ?? ''),
        ];

        /** @var ThemeScopeRevision $revisionModel */
        $revisionModel = clone ObjectManager::getInstance(ThemeScopeRevision::class);
        $revisionRows = $revisionModel->clearData()->clearQuery()
            ->where(ThemeScopeRevision::schema_fields_WORKSPACE_ID, $workspaceId)
            ->order(ThemeScopeRevision::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        foreach (is_array($revisionRows) ? $revisionRows : [] as $revisionRow) {
            $revisions[] = [
                'revision_id' => (int)($revisionRow[ThemeScopeRevision::schema_fields_ID] ?? 0),
                'workspace_id' => $workspaceId,
                'revision_no' => (int)($revisionRow[ThemeScopeRevision::schema_fields_REVISION_NO] ?? 0),
                'parent_release_id' => isset($revisionRow[ThemeScopeRevision::schema_fields_PARENT_RELEASE_ID])
                    ? (int)$revisionRow[ThemeScopeRevision::schema_fields_PARENT_RELEASE_ID]
                    : null,
                'status' => (string)($revisionRow[ThemeScopeRevision::schema_fields_STATUS] ?? ''),
            ];
        }

        /** @var ThemeScopeRelease $releaseModel */
        $releaseModel = clone ObjectManager::getInstance(ThemeScopeRelease::class);
        $releaseRows = $releaseModel->clearData()->clearQuery()
            ->where(ThemeScopeRelease::schema_fields_WORKSPACE_ID, $workspaceId)
            ->order(ThemeScopeRelease::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        foreach (is_array($releaseRows) ? $releaseRows : [] as $releaseRow) {
            $releases[] = [
                'release_id' => (int)($releaseRow[ThemeScopeRelease::schema_fields_ID] ?? 0),
                'workspace_id' => $workspaceId,
                'resource_type' => (string)($releaseRow[ThemeScopeRelease::schema_fields_RESOURCE_TYPE] ?? ''),
                'revision_id' => isset($releaseRow[ThemeScopeRelease::schema_fields_REVISION_ID])
                    ? (int)$releaseRow[ThemeScopeRelease::schema_fields_REVISION_ID]
                    : null,
                'parent_release_id' => isset($releaseRow[ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID])
                    ? (int)$releaseRow[ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID]
                    : null,
                'fingerprint' => (string)($releaseRow[ThemeScopeRelease::schema_fields_FINGERPRINT] ?? ''),
                'status' => (string)($releaseRow[ThemeScopeRelease::schema_fields_STATUS] ?? ''),
            ];
        }
    }
    ksort($workspaces);
    usort($revisions, static fn(array $left, array $right): int => $left['revision_id'] <=> $right['revision_id']);
    usort($releases, static fn(array $left, array $right): int => $left['release_id'] <=> $right['release_id']);

    /** @var ThemeScopeReleaseBatch $batchModel */
    $batchModel = clone ObjectManager::getInstance(ThemeScopeReleaseBatch::class);
    $batchRows = $batchModel->clearData()->clearQuery()
        ->where(ThemeScopeReleaseBatch::schema_fields_SCOPE, $scope)
        ->order(ThemeScopeReleaseBatch::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();
    $batches = [];
    foreach (is_array($batchRows) ? $batchRows : [] as $row) {
        $batches[] = [
            'batch_id' => (int)($row[ThemeScopeReleaseBatch::schema_fields_ID] ?? 0),
            'batch_digest' => (string)($row[ThemeScopeReleaseBatch::schema_fields_BATCH_DIGEST] ?? ''),
            'state' => (string)($row[ThemeScopeReleaseBatch::schema_fields_STATE] ?? ''),
            'source_batch_id' => isset($row[ThemeScopeReleaseBatch::schema_fields_SOURCE_BATCH_ID])
                ? (int)$row[ThemeScopeReleaseBatch::schema_fields_SOURCE_BATCH_ID]
                : null,
        ];
    }

    return [
        'workspaces' => $workspaces,
        'revisions' => $revisions,
        'releases' => $releases,
        'batches' => $batches,
    ];
}

/** @return array<string,int> */
function theme_scope_batch_release_map(array $receipt): array
{
    $map = [];
    foreach ((array)($receipt['resources'] ?? []) as $resource) {
        if (!is_array($resource)) {
            continue;
        }
        $resourceType = (string)($resource['resource_type'] ?? '');
        $releaseId = (int)($resource['release_id'] ?? 0);
        if (in_array($resourceType, ThemeEditorContext::RESOURCES, true) && $releaseId > 0) {
            $map[$resourceType] = $releaseId;
        }
    }
    require_theme_scope_batch(
        array_keys($map) === ThemeEditorContext::RESOURCES,
        'theme_scope_batch_fixture_receipt_incomplete',
    );

    return $map;
}

/** @return array<string,?int> */
function theme_scope_batch_published_map(array $snapshot): array
{
    $map = [];
    foreach (ThemeEditorContext::RESOURCES as $resourceType) {
        $workspace = $snapshot['workspaces'][$resourceType] ?? null;
        $map[$resourceType] = is_array($workspace)
            ? ($workspace['published_release_id'] ?? null)
            : null;
    }

    return $map;
}

/** @return array<string,mixed> */
function verify_theme_scope_release_batch_atomicity(int $themeId, string $pageType, string $token): array
{
    $result = [];
    $failure = null;
    $cleanup = [];
    try {
        $hierarchy = prepare_theme_scope_hierarchy($themeId, $pageType, $token);
        $fixture = theme_scope_fixture_identity($token);
        /** @var ScopeHierarchyInterface $scopes */
        $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
        $scope = $scopes->contextFromIdentity(ScopeIdentity::website(
            (int)$hierarchy['website_id'],
            $fixture['website_code'],
        ));
        require_theme_scope_batch(
            str_starts_with($scope->storageScope, 'e2e-theme-scope-'),
            'theme_scope_batch_fixture_scope_invalid',
        );
        $baseContext = new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: $themeId,
            layoutType: $pageType,
            layoutOption: 'default',
            locale: 'default',
            targetType: 'global',
            targetId: 0,
        );

        $workspace = theme_scope_batch_workspace();
        $expectationsOne = apply_theme_scope_batch_drafts(
            $workspace,
            $baseContext,
            $themeId,
            'batch-one-' . $token,
        );
        $baseline = snapshot_theme_scope_batch_database($scope->storageScope);
        require_theme_scope_batch(
            count($baseline['workspaces']) === count(ThemeEditorContext::RESOURCES),
            'theme_scope_batch_fixture_workspace_count_invalid',
        );

        $failureEvidence = [];
        foreach (range(1, count(ThemeEditorContext::RESOURCES)) as $failAt) {
            $error = null;
            try {
                theme_scope_batch_workspace($failAt)->publishBatch(
                    ThemeScopedReleaseBatch::fromExpectations($baseContext, $expectationsOne),
                    'e2e-theme-batch',
                    'Theme batch runtime fixture',
                    'Injected atomicity failure at resource ' . $failAt,
                );
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
            require_theme_scope_batch(
                $error === 'e2e_injected_batch_projection_failure:' . $failAt,
                'theme_scope_batch_fixture_injected_failure_missing:' . $failAt . ':' . (string)$error,
            );
            require_theme_scope_batch(
                snapshot_theme_scope_batch_database($scope->storageScope) === $baseline,
                'theme_scope_batch_fixture_partial_commit:' . $failAt,
            );
            $failureEvidence[] = ['projection_position' => $failAt, 'error' => $error, 'rolled_back' => true];
        }

        $receiptOne = $workspace->publishBatch(
            ThemeScopedReleaseBatch::fromExpectations($baseContext, $expectationsOne),
            'e2e-theme-batch',
            'Theme batch runtime fixture',
            'First five-resource E2E publish',
        );
        $releaseMapOne = theme_scope_batch_release_map($receiptOne);
        $batchOneId = (int)($receiptOne['batch_id'] ?? 0);
        $readbackOne = $workspace->getReleaseBatch($batchOneId);
        require_theme_scope_batch(
            (int)($readbackOne['batch_id'] ?? 0) === $batchOneId
                && ($readbackOne['state'] ?? '') === ThemeScopeReleaseBatch::STATE_PUBLISHED,
            'theme_scope_batch_fixture_readback_invalid',
        );
        $afterOne = snapshot_theme_scope_batch_database($scope->storageScope);
        require_theme_scope_batch(
            count($afterOne['batches']) === count($baseline['batches']) + 1
                && count($afterOne['releases']) === count($baseline['releases']) + 5
                && theme_scope_batch_published_map($afterOne) === $releaseMapOne,
            'theme_scope_batch_fixture_first_publish_invalid',
        );

        $expectationsTwo = apply_theme_scope_batch_drafts(
            $workspace,
            $baseContext,
            $themeId,
            'batch-two-' . $token,
        );
        $receiptTwo = $workspace->publishBatch(
            ThemeScopedReleaseBatch::fromExpectations($baseContext, $expectationsTwo),
            'e2e-theme-batch',
            'Theme batch runtime fixture',
            'Second five-resource E2E publish',
        );
        $releaseMapTwo = theme_scope_batch_release_map($receiptTwo);
        foreach (ThemeEditorContext::RESOURCES as $resourceType) {
            require_theme_scope_batch(
                $releaseMapTwo[$resourceType] !== $releaseMapOne[$resourceType],
                'theme_scope_batch_fixture_second_release_not_immutable:' . $resourceType,
            );
        }
        $afterTwo = snapshot_theme_scope_batch_database($scope->storageScope);
        require_theme_scope_batch(
            count($afterTwo['batches']) === count($baseline['batches']) + 2
                && count($afterTwo['releases']) === count($baseline['releases']) + 10
                && theme_scope_batch_published_map($afterTwo) === $releaseMapTwo,
            'theme_scope_batch_fixture_second_publish_invalid',
        );

        $rollback = $workspace->rollbackReleaseBatch(
            $batchOneId,
            $baseContext,
            'e2e-theme-batch',
            'Theme batch runtime fixture',
            'Restore first five-resource E2E release',
        );
        $rollbackMap = theme_scope_batch_release_map($rollback);
        $rollbackBatchId = (int)($rollback['batch_id'] ?? 0);
        $rollbackReadback = $workspace->getReleaseBatch($rollbackBatchId);
        $afterRollback = snapshot_theme_scope_batch_database($scope->storageScope);
        require_theme_scope_batch(
            (int)($rollback['source_batch_id'] ?? 0) === $batchOneId
                && (int)($rollbackReadback['source_batch_id'] ?? 0) === $batchOneId
                && $rollbackMap === $releaseMapOne
                && theme_scope_batch_published_map($afterRollback) === $releaseMapOne,
            'theme_scope_batch_fixture_rollback_pointer_invalid',
        );
        require_theme_scope_batch(
            count($afterRollback['batches']) === count($baseline['batches']) + 3
                && $afterRollback['releases'] === $afterTwo['releases'],
            'theme_scope_batch_fixture_rollback_history_mutated',
        );

        $result = [
            'success' => true,
            'scope' => $scope->storageScope,
            'failure_matrix' => $failureEvidence,
            'first_batch' => [
                'batch_id' => $batchOneId,
                'release_ids' => $releaseMapOne,
                'readback_state' => $readbackOne['state'] ?? null,
            ],
            'second_batch' => [
                'batch_id' => (int)($receiptTwo['batch_id'] ?? 0),
                'release_ids' => $releaseMapTwo,
            ],
            'rollback_batch' => [
                'batch_id' => $rollbackBatchId,
                'source_batch_id' => (int)($rollback['source_batch_id'] ?? 0),
                'published_release_ids' => theme_scope_batch_published_map($afterRollback),
            ],
            'database_evidence' => [
                'workspace_count' => count($afterRollback['workspaces']),
                'revision_count' => count($afterRollback['revisions']),
                'release_count' => count($afterRollback['releases']),
                'batch_count' => count($afterRollback['batches']),
                'historical_releases_preserved' => true,
            ],
        ];
    } catch (Throwable $throwable) {
        $failure = $throwable;
    } finally {
        try {
            $cleanup = cleanup_theme_scope_hierarchy($themeId, $pageType, $token);
        } catch (Throwable $cleanupFailure) {
            $cleanup = ['success' => false, 'error' => $cleanupFailure->getMessage()];
            if (!$failure instanceof Throwable) {
                $failure = $cleanupFailure;
            }
        }
    }

    if ($failure instanceof Throwable) {
        throw new RuntimeException(
            $failure->getMessage() . ' | cleanup=' . json_encode($cleanup, JSON_UNESCAPED_SLASHES),
            0,
            $failure,
        );
    }
    $result['cleanup'] = $cleanup;

    return $result;
}

/**
 * Seed a scoped draft (+ optional publish) for Theme editor E2E without theme_layout.
 *
 * @param array<string,mixed> $identity
 * @return array<string,mixed>
 */
function prepare_scoped_layout_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $pageType,
    array $identity = [],
    bool $force = true,
    bool $publish = true,
): array {
    $identity = $identity !== [] ? $identity : [
        'layout_option' => 'default',
        'scope' => 'default.default.default',
        'target_type' => 'global',
        'target_id' => 0,
    ];

    cleanup_theme_editor_fixture($layout, $version, $themeId, $pageType, $identity);

    /** @var \Weline\Theme\Service\DefaultLayoutSeeder $seeder */
    $seeder = ObjectManager::getInstance(\Weline\Theme\Service\DefaultLayoutSeeder::class);
    $seeded = $seeder->seedDefaultLayout($themeId, $pageType, $force);

    $published = false;
    if ($publish) {
        /** @var \Weline\Theme\Service\ThemeLayoutService $layoutService */
        $layoutService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class);
        $published = $layoutService->publishLayout(
            $themeId,
            $pageType,
            [
                'layout_option' => (string)($identity['layout_option'] ?? 'default'),
                'scope' => (string)($identity['scope'] ?? 'default.default.default'),
                'locale_code' => '',
                'target_type' => (string)($identity['target_type'] ?? 'global'),
                'target_id' => (int)($identity['target_id'] ?? 0),
            ],
            true,
        );
    }

    return [
        'success' => true,
        'seeded' => (bool)$seeded,
        'published' => (bool)$published,
        'identity' => $identity,
        'snapshot' => snapshot_theme_editor_fixture($layout, $version, $themeId, $pageType, $identity),
    ];
}

$payload = read_payload();
$action = (string)($payload['action'] ?? '');
$themeId = (int)($payload['theme_id'] ?? 0);
$pageType = trim((string)($payload['page_type'] ?? ''));

if ($action === '') {
    fail('Missing fixture action.');
}
if ($themeId <= 0) {
    fail('Missing theme_id.');
}
if ($pageType === '') {
    fail('Missing page_type.');
}

$layout = clone ObjectManager::getInstance(ThemeLayout::class);
$version = clone ObjectManager::getInstance(ThemeLayoutVersion::class);
$identity = resolve_layout_identity($payload);
$token = fixture_token($payload);

try {
    if ($action === 'prepare_scope_hierarchy') {
        output_json(prepare_theme_scope_hierarchy($themeId, $pageType, $token));
        exit(0);
    }

    if ($action === 'cleanup_scope_hierarchy') {
        output_json(cleanup_theme_scope_hierarchy($themeId, $pageType, $token));
        exit(0);
    }

    if ($action === 'verify_scoped_release_batch_atomicity') {
        output_json(verify_theme_scope_release_batch_atomicity($themeId, $pageType, $token));
        exit(0);
    }

    if ($action === 'prepare_dashboard_identity') {
        output_json(prepare_dashboard_identity_fixture($layout, $version, $themeId, $token));
        exit(0);
    }

    if ($action === 'prepare_dashboard_identities') {
        output_json(prepare_dashboard_identities_fixture(
            $layout,
            $version,
            $themeId,
            $token,
            (int)($payload['count'] ?? 2)
        ));
        exit(0);
    }

    if ($action === 'cleanup_dashboard_identity') {
        cleanup_dashboard_identity_fixture($layout, $version, $themeId, $token);
        output_json(['success' => true]);
        exit(0);
    }

    if ($action === 'cleanup') {
        cleanup_theme_editor_fixture($layout, $version, $themeId, $pageType, $identity);
        output_json(['success' => true]);
        exit(0);
    }

    if ($action === 'snapshot') {
        output_json(snapshot_theme_editor_fixture($layout, $version, $themeId, $pageType, $identity));
        exit(0);
    }

    if ($action === 'prepare_scoped_layout' || $action === 'seed_scoped_layout') {
        $force = !array_key_exists('force', $payload) || (bool)$payload['force'];
        $publish = !array_key_exists('publish', $payload) || (bool)$payload['publish'];
        output_json(prepare_scoped_layout_fixture(
            $layout,
            $version,
            $themeId,
            $pageType,
            $identity,
            $force,
            $publish,
        ));
        exit(0);
    }

    if ($action === 'default_injections') {
        /** @var WidgetDefaultInjectionService $service */
        $service = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
        $items = $service->getMissingForLayout($themeId, $pageType, $identity, 'backend');
        output_json([
            'success' => true,
            'items' => $items,
            'total' => count($items),
        ]);
        exit(0);
    }

    if ($action === 'apply_default_injection') {
        $injectionKey = trim((string)($payload['injection_key'] ?? ''));
        if ($injectionKey === '') {
            fail('Missing injection_key.');
        }

        /** @var WidgetDefaultInjectionService $service */
        $service = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
        $scope = strtolower(trim((string)($payload['apply_scope'] ?? 'current')));
        if ($scope === 'all') {
            $result = $service->applyInjectionByKeyForAllLayoutIdentities(
                $themeId,
                $pageType,
                $injectionKey,
                $identity,
                ThemeLayout::STATUS_DRAFT,
                'backend'
            );
        } else {
            $item = $service->applyInjectionByKey(
                $themeId,
                $pageType,
                $injectionKey,
                $identity,
                ThemeLayout::STATUS_DRAFT,
                'backend'
            );
            $applied = $item && !empty($item['node_uid']);
            $result = [
                'items' => $item ? [$item] : [],
                'current_item' => $item,
                'applied_count' => $applied ? 1 : 0,
                'skipped_count' => $applied ? 0 : 1,
                'total_identities' => 1,
            ];
        }

        output_json([
            'success' => true,
            'apply_scope' => $scope === 'all' ? 'all' : 'current',
            'result' => $result,
        ]);
        exit(0);
    }

    fail('Unsupported fixture action: ' . $action);
} catch (Throwable $throwable) {
    fail($throwable->getMessage());
}
