<?php

declare(strict_types=1);

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Websites\Model\Website;
use Weline\Widget\Model\WidgetRegistryEntry;
use Weline\Widget\Service\WidgetRegistryRefreshService;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

function fixture_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function fixture_payload(): array
{
    $raw = stream_get_contents(STDIN);
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    return is_array($payload) ? $payload : [];
}

function fixture_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function fixture_token(array $payload): string
{
    $token = strtolower(trim((string)($payload['token'] ?? '')));
    $token = preg_replace('/[^a-z0-9_\\-]+/', '-', $token) ?: '';
    $token = trim($token, '-_');
    return $token !== '' ? substr($token, 0, 48) : 'dashboard-default-layout';
}

function fixture_identity(int $viewId, int $websiteId): array
{
    return [
        'layout_option' => DashboardView::LAYOUT_OPTION,
        'scope' => 'dashboard_view:' . $viewId,
        'target_type' => DashboardView::TARGET_TYPE_WEBSITE,
        'target_id' => $websiteId,
    ];
}

function fixture_apply_identity($query, array $identity, string $modelClass)
{
    return $query
        ->where($modelClass::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
        ->where($modelClass::schema_fields_SCOPE, $identity['scope'])
        ->where($modelClass::schema_fields_TARGET_TYPE, $identity['target_type'])
        ->where($modelClass::schema_fields_TARGET_ID, $identity['target_id']);
}

function fixture_cleanup_layout(int $themeId, int $websiteId, int $viewId): void
{
    if ($themeId <= 0 || $websiteId < 0 || $viewId <= 0) {
        return;
    }

    $identity = fixture_identity($viewId, $websiteId);
    /** @var ThemeLayout $layout */
    $layout = clone ObjectManager::getInstance(ThemeLayout::class);
    /** @var ThemeLayoutVersion $version */
    $version = clone ObjectManager::getInstance(ThemeLayoutVersion::class);

    $layoutQuery = $layout->clearQuery()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE);
    fixture_apply_identity($layoutQuery, $identity, ThemeLayout::class)->delete()->fetch();

    $versionQuery = $version->clearQuery()
        ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE);
    fixture_apply_identity($versionQuery, $identity, ThemeLayoutVersion::class)->delete()->fetch();

    try {
        /** @var ThemeWidgetDefaultInjection $record */
        $record = clone ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $recordQuery = $record->clearQuery()->clearData()
            ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE);
        fixture_apply_identity($recordQuery, $identity, ThemeWidgetDefaultInjection::class)->delete()->fetch();
    } catch (Throwable) {
        // The ledger table may not exist before setup:upgrade in local fixture preparation.
    }
}

function fixture_cleanup_website(string $code, int $themeId): void
{
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
        fixture_cleanup_layout($themeId, $websiteId, $viewId);
    }

    $dashboardView->clearQuery()->clearData()
        ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
        ->delete()
        ->fetch();

    $website->clearQuery()->clearData()->load($websiteId);
    if ($website->getWebsiteId() > 0 && $website->getCode() !== Website::CODE_DEFAULT) {
        $website->delete();
    }
}

function fixture_create_website(string $token): Website
{
    $code = 'e2e-dashboard-' . $token;
    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $website->clearQuery()->clearData()
        ->setName('E2E Dashboard ' . $token)
        ->setCode($code)
        ->setUrl($code . '.test')
        ->setDefaultCurrency('CNY')
        ->setDefaultLanguage('zh_Hans_CN')
        ->setDefaultTimezone('Asia/Shanghai')
        ->setScope('e2e-dashboard')
        ->save();

    return $website;
}

function fixture_create_empty_view(Website $website, string $code = 'default', string $name = 'E2E 默认概览', bool $isDefault = true): DashboardView
{
    /** @var DashboardView $view */
    $view = clone ObjectManager::getInstance(DashboardView::class);
    $view->clearQuery()->clearData()
        ->setWebsiteId($website->getWebsiteId())
        ->setOwnerAdminId(null)
        ->setName($name)
        ->setCode($code)
        ->setVisibility($isDefault ? DashboardView::VISIBILITY_SYSTEM : DashboardView::VISIBILITY_PUBLIC)
        ->setIsDefault($isDefault)
        ->setIsActive(true)
        ->setSortOrder($isDefault ? 0 : 10)
        ->save();

    return $view;
}

function fixture_create_empty_default_view(Website $website): DashboardView
{
    return fixture_create_empty_view($website);
}

function fixture_load_website(string $code): ?Website
{
    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $websiteRow = $website->clearQuery()->clearData()
        ->where(Website::schema_fields_CODE, $code)
        ->find()
        ->fetchArray();
    $websiteId = is_array($websiteRow) ? (int)($websiteRow[Website::schema_fields_ID] ?? 0) : 0;
    if ($websiteId <= 0) {
        return null;
    }

    $website->clearQuery()->clearData()->load($websiteId);
    return $website->getWebsiteId() > 0 ? $website : null;
}

function fixture_load_default_view(string $code, string $viewCode = 'default', int $viewId = 0): ?DashboardView
{
    $website = fixture_load_website($code);
    if (!$website) {
        return null;
    }

    /** @var DashboardView $view */
    $view = clone ObjectManager::getInstance(DashboardView::class);
    $query = $view->clearQuery()->clearData()
        ->where(DashboardView::schema_fields_WEBSITE_ID, $website->getWebsiteId());
    if ($viewId > 0) {
        $query->where(DashboardView::schema_fields_ID, $viewId);
    } else {
        $query->where(DashboardView::schema_fields_CODE, $viewCode !== '' ? $viewCode : 'default');
    }
    $row = $query->find()->fetchArray();
    $viewId = is_array($row) ? (int)($row[DashboardView::schema_fields_ID] ?? 0) : 0;
    if ($viewId <= 0) {
        return null;
    }

    $view->clearQuery()->clearData()->load($viewId);
    return $view->getViewId() > 0 ? $view : null;
}

function fixture_load_selected_view(array $payload, string $code, DashboardViewService $dashboardService): ?DashboardView
{
    if (!empty($payload['system_default'])) {
        $websiteId = $dashboardService->getDefaultWebsiteId();
        return $dashboardService->ensureDefaultView($websiteId);
    }

    return fixture_load_default_view(
        $code,
        trim((string)($payload['view_code'] ?? 'default')),
        (int)($payload['view_id'] ?? 0)
    );
}

function fixture_snapshot(int $themeId, DashboardView $view): array
{
    /** @var ThemeLayout $layout */
    $layout = clone ObjectManager::getInstance(ThemeLayout::class);
    $identity = $view->layoutIdentity();
    $rows = $layout->clearQuery()->clearData()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE);
    $rows = fixture_apply_identity($rows, $identity, ThemeLayout::class)
        ->order(ThemeLayout::schema_fields_STATUS, 'ASC')
        ->order(ThemeLayout::schema_fields_AREA, 'ASC')
        ->order(ThemeLayout::schema_fields_SLOT_ID, 'ASC')
        ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
        ->order(ThemeLayout::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();

    return is_array($rows) ? array_values($rows) : [];
}

function fixture_modules(array $payload): array
{
    $modules = $payload['modules'] ?? ['Weline_Dashboard', 'Weline_Visitor'];
    if (is_string($modules)) {
        $modules = preg_split('/[,\s]+/', $modules) ?: [];
    }
    if (!is_array($modules)) {
        return [];
    }

    $result = [];
    foreach ($modules as $module) {
        $module = trim((string)$module);
        if ($module !== '') {
            $result[$module] = $module;
        }
    }

    return array_values($result);
}

function fixture_reset_widget_registry_entries(array $modules): void
{
    /** @var WidgetRegistryEntry $entry */
    $entry = clone ObjectManager::getInstance(WidgetRegistryEntry::class);
    foreach ($modules as $module) {
        $module = trim((string)$module);
        if ($module === '') {
            continue;
        }
        $entry->clearQuery()->clearData()
            ->where(WidgetRegistryEntry::schema_fields_WIDGET_MODULE, $module)
            ->delete()
            ->fetch();
    }
}

function fixture_clear_layout(int $themeId, DashboardView $view): void
{
    /** @var ThemeLayoutService $layoutService */
    $layoutService = ObjectManager::getInstance(ThemeLayoutService::class);
    $layoutService->saveLayout($themeId, DashboardView::PAGE_TYPE, [], ThemeLayout::STATUS_DRAFT, $view->layoutIdentity());
    $layoutService->publishLayout($themeId, DashboardView::PAGE_TYPE, $view->layoutIdentity(), true);
}

$payload = fixture_payload();
$action = trim((string)($payload['action'] ?? ''));
$token = fixture_token($payload);
$code = 'e2e-dashboard-' . $token;

/** @var DashboardViewService $dashboardService */
$dashboardService = ObjectManager::getInstance(DashboardViewService::class);
$themeId = $dashboardService->getBackendThemeId();
if ($themeId <= 0) {
    fixture_fail('Missing backend theme.');
}

try {
    if ($action === 'cleanup') {
        if (!empty($payload['system_default'])) {
            $view = fixture_load_selected_view($payload, $code, $dashboardService);
            if ($view) {
                fixture_cleanup_layout($themeId, $view->getWebsiteId(), $view->getViewId());
                $dashboardService->ensureLayoutInitialized($view);
                if (!empty($payload['modules'])) {
                    fixture_reset_widget_registry_entries(fixture_modules($payload));
                    /** @var WidgetRegistryRefreshService $refreshService */
                    $refreshService = ObjectManager::getInstance(WidgetRegistryRefreshService::class);
                    $refreshService->refresh('e2e_widget_registry_cleanup');
                }
            }
            fixture_json(['success' => true]);
            exit(0);
        }

        fixture_cleanup_website($code, $themeId);
        fixture_json(['success' => true]);
        exit(0);
    }

    if ($action === 'prepare-system-default-view') {
        $view = fixture_load_selected_view(['system_default' => true], $code, $dashboardService);
        if (!$view || $view->getViewId() <= 0) {
            fixture_fail('Failed to create system default dashboard view.');
        }
        fixture_cleanup_layout($themeId, $view->getWebsiteId(), $view->getViewId());
        $dashboardService->ensureLayoutInitialized($view);

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $view->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'prepare-empty-default-view') {
        fixture_cleanup_website($code, $themeId);
        $website = fixture_create_website($token);
        $view = fixture_create_empty_default_view($website);
        $dashboardService->ensureLayoutInitialized($view);

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $website->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'prepare-extra-empty-view') {
        $website = fixture_load_website($code);
        if (!$website) {
            fixture_fail('Missing prepared dashboard website.');
        }
        $viewCode = trim((string)($payload['view_code'] ?? 'secondary'));
        $viewCode = $viewCode !== '' ? $viewCode : 'secondary';
        $view = fixture_create_empty_view(
            $website,
            $viewCode,
            trim((string)($payload['name'] ?? 'E2E 第二身份视图')) ?: 'E2E 第二身份视图',
            false
        );
        $dashboardService->ensureLayoutInitialized($view);

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $website->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'snapshot') {
        $view = fixture_load_selected_view($payload, $code, $dashboardService);
        if (!$view) {
            fixture_fail('Missing prepared dashboard view.');
        }

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $view->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'seed') {
        fixture_cleanup_website($code, $themeId);
        $website = fixture_create_website($token);
        $view = $dashboardService->ensureDefaultView($website->getWebsiteId());
        if (!$view || $view->getViewId() <= 0) {
            fixture_fail('Failed to create default dashboard view.');
        }
        $dashboardService->ensureLayoutInitialized($view);

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $website->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'refresh-widget-registry') {
        $view = fixture_load_selected_view($payload, $code, $dashboardService);
        if (!$view) {
            fixture_fail('Missing prepared dashboard view.');
        }

        if (!empty($payload['reset_registry'])) {
            fixture_reset_widget_registry_entries(fixture_modules($payload));
        }

        /** @var WidgetRegistryRefreshService $refreshService */
        $refreshService = ObjectManager::getInstance(WidgetRegistryRefreshService::class);
        $report = $refreshService->refresh('e2e_widget_registry_collection');

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $view->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'report' => $report,
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'clear-layout') {
        $view = fixture_load_selected_view($payload, $code, $dashboardService);
        if (!$view) {
            fixture_fail('Missing prepared dashboard view.');
        }

        fixture_clear_layout($themeId, $view);

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $view->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'default-injections') {
        $view = fixture_load_selected_view($payload, $code, $dashboardService);
        if (!$view) {
            fixture_fail('Missing prepared dashboard view.');
        }

        /** @var WidgetDefaultInjectionService $defaultInjectionService */
        $defaultInjectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
        $items = $defaultInjectionService->getMissingForLayout(
            $themeId,
            DashboardView::PAGE_TYPE,
            $view->layoutIdentity(),
            'backend'
        );

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $view->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'items' => $items,
            'total' => count($items),
        ]);
        exit(0);
    }

    if ($action === 'apply-default-injection') {
        $view = fixture_load_selected_view($payload, $code, $dashboardService);
        if (!$view) {
            fixture_fail('Missing prepared dashboard view.');
        }
        $injectionKey = trim((string)($payload['injection_key'] ?? ''));
        if ($injectionKey === '') {
            fixture_fail('Missing injection_key.');
        }

        /** @var WidgetDefaultInjectionService $defaultInjectionService */
        $defaultInjectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
        $applyScope = strtolower(trim((string)($payload['apply_scope'] ?? 'current')));
        if ($applyScope === 'all') {
            $result = $defaultInjectionService->applyInjectionByKeyForAllLayoutIdentities(
                $themeId,
                DashboardView::PAGE_TYPE,
                $injectionKey,
                $view->layoutIdentity(),
                ThemeLayout::STATUS_DRAFT,
                'backend'
            );
        } else {
            $item = $defaultInjectionService->applyInjectionByKey(
                $themeId,
                DashboardView::PAGE_TYPE,
                $injectionKey,
                $view->layoutIdentity(),
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

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $view->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'apply_scope' => $applyScope === 'all' ? 'all' : 'current',
            'result' => $result,
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    if ($action === 'ensure-visitor-dashboard-pages') {
        $website = fixture_load_website($code);
        if (!$website) {
            fixture_fail('Missing prepared dashboard website.');
        }

        /** @var VisitorDashboardPageInstaller $installer */
        $installer = ObjectManager::getInstance(VisitorDashboardPageInstaller::class);
        $result = $installer->ensurePages();
        $view = fixture_load_default_view($code, 'weline_visitor_event_statistics');
        if (!$view) {
            fixture_fail('Missing Visitor event statistics dashboard view.');
        }

        fixture_json([
            'success' => true,
            'theme_id' => $themeId,
            'website_id' => $website->getWebsiteId(),
            'view_id' => $view->getViewId(),
            'identity' => $view->layoutIdentity(),
            'result' => $result,
            'layout' => fixture_snapshot($themeId, $view),
        ]);
        exit(0);
    }

    fixture_fail('Unsupported fixture action: ' . $action);
} catch (Throwable $throwable) {
    fixture_fail($throwable->getMessage());
}
