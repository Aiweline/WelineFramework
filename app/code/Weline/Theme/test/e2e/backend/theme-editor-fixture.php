<?php
declare(strict_types=1);

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
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

function cleanup_theme_editor_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $pageType,
    array $identity = []
): void
{
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
}

function snapshot_theme_editor_fixture(
    ThemeLayout $layout,
    ThemeLayoutVersion $version,
    int $themeId,
    string $pageType,
    array $identity = []
): array
{
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

/** @param list<string> $scopes */
function cleanup_theme_scope_workspaces(array $scopes): array
{
    $deleted = ['patches' => 0, 'revisions' => 0, 'releases' => 0, 'workspaces' => 0];
    foreach (array_values(array_unique($scopes)) as $scope) {
        if (!str_starts_with($scope, 'e2e-theme-scope-')) {
            throw new RuntimeException('Refusing Theme Scope cleanup outside its owned namespace.');
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
            $result = [
                'items' => $item ? [$item] : [],
                'current_item' => $item,
                'applied_count' => $item && !empty($item['layout_id']) ? 1 : 0,
                'skipped_count' => $item && !empty($item['layout_id']) ? 0 : 1,
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
