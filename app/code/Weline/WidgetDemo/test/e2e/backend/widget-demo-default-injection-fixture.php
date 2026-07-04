<?php

declare(strict_types=1);

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Websites\Model\Website;
use Weline\Widget\Model\WidgetRegistryEntry;

const FIXTURE_WIDGET_MODULE = 'Weline_WidgetDemo';
const FIXTURE_WIDGET_TYPE = 'stats';
const FIXTURE_WIDGET_CODE = 'install_default_card';
const FIXTURE_WIDGET_AREA = 'backend';

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
    return $token !== '' ? substr($token, 0, 48) : 'widget-demo-default';
}

function fixture_website_code(string $token): string
{
    return 'e2e-widget-demo-' . $token;
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
    if ($themeId <= 0 || $websiteId <= 0 || $viewId <= 0) {
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

    fixture_cleanup_default_injection_records($themeId, $identity);
}

function fixture_cleanup_default_injection_records(int $themeId, array $identity): void
{
    try {
        /** @var ThemeWidgetDefaultInjection $record */
        $record = clone ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $query = $record->clearQuery()->clearData()
            ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE);
        fixture_apply_identity($query, $identity, ThemeWidgetDefaultInjection::class)
            ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, FIXTURE_WIDGET_MODULE)
            ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_TYPE, FIXTURE_WIDGET_TYPE)
            ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, FIXTURE_WIDGET_CODE)
            ->delete()
            ->fetch();
    } catch (Throwable) {
    }
}

function fixture_cleanup_demo_widget_everywhere(): void
{
    try {
        /** @var ThemeLayout $layout */
        $layout = clone ObjectManager::getInstance(ThemeLayout::class);
        $layout->clearQuery()->clearData()
            ->where(ThemeLayout::schema_fields_WIDGET_MODULE, FIXTURE_WIDGET_MODULE)
            ->where(ThemeLayout::schema_fields_WIDGET_TYPE, FIXTURE_WIDGET_TYPE)
            ->where(ThemeLayout::schema_fields_WIDGET_CODE, FIXTURE_WIDGET_CODE)
            ->delete()
            ->fetch();
    } catch (Throwable) {
    }

    try {
        /** @var ThemeWidgetDefaultInjection $record */
        $record = clone ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $record->clearQuery()->clearData()
            ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, FIXTURE_WIDGET_MODULE)
            ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_TYPE, FIXTURE_WIDGET_TYPE)
            ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, FIXTURE_WIDGET_CODE)
            ->delete()
            ->fetch();
    } catch (Throwable) {
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
    $code = fixture_website_code($token);
    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $website->clearQuery()->clearData()
        ->setName('E2E Widget Demo ' . $token)
        ->setCode($code)
        ->setUrl($code . '.test')
        ->setDefaultCurrency('CNY')
        ->setDefaultLanguage('zh_Hans_CN')
        ->setDefaultTimezone('Asia/Shanghai')
        ->setScope('e2e-widget-demo')
        ->save();

    return $website;
}

function fixture_create_empty_default_view(Website $website): DashboardView
{
    /** @var DashboardView $view */
    $view = clone ObjectManager::getInstance(DashboardView::class);
    $view->clearQuery()->clearData()
        ->setWebsiteId($website->getWebsiteId())
        ->setOwnerAdminId(null)
        ->setName('E2E Widget Demo Default')
        ->setCode('default')
        ->setVisibility(DashboardView::VISIBILITY_SYSTEM)
        ->setIsDefault(true)
        ->setIsActive(true)
        ->setSortOrder(0)
        ->save();

    return $view;
}

function fixture_load_default_view(string $code): ?DashboardView
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

    /** @var DashboardView $view */
    $view = clone ObjectManager::getInstance(DashboardView::class);
    $row = $view->clearQuery()->clearData()
        ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
        ->where(DashboardView::schema_fields_CODE, 'default')
        ->find()
        ->fetchArray();
    $viewId = is_array($row) ? (int)($row[DashboardView::schema_fields_ID] ?? 0) : 0;
    if ($viewId <= 0) {
        return null;
    }

    $view->clearQuery()->clearData()->load($viewId);
    return $view->getViewId() > 0 ? $view : null;
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

function fixture_delete_demo_widget_status(int $themeId, DashboardView $view, string $status): void
{
    $status = $status === ThemeLayout::STATUS_PUBLISHED
        ? ThemeLayout::STATUS_PUBLISHED
        : ThemeLayout::STATUS_DRAFT;
    $identity = $view->layoutIdentity();

    /** @var ThemeLayout $layout */
    $layout = clone ObjectManager::getInstance(ThemeLayout::class);
    $query = $layout->clearQuery()->clearData()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE)
        ->where(ThemeLayout::schema_fields_STATUS, $status)
        ->where(ThemeLayout::schema_fields_WIDGET_MODULE, FIXTURE_WIDGET_MODULE)
        ->where(ThemeLayout::schema_fields_WIDGET_TYPE, FIXTURE_WIDGET_TYPE)
        ->where(ThemeLayout::schema_fields_WIDGET_CODE, FIXTURE_WIDGET_CODE);
    fixture_apply_identity($query, $identity, ThemeLayout::class)
        ->delete()
        ->fetch();
}

function fixture_demo_registry_entry(): array
{
    /** @var WidgetRegistryEntry $entry */
    $entry = clone ObjectManager::getInstance(WidgetRegistryEntry::class);
    $row = $entry->clearQuery()->clearData()
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_AREA, FIXTURE_WIDGET_AREA)
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_MODULE, FIXTURE_WIDGET_MODULE)
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_TYPE, FIXTURE_WIDGET_TYPE)
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_CODE, FIXTURE_WIDGET_CODE)
        ->find()
        ->fetchArray();

    return is_array($row) ? $row : [];
}

function fixture_reset_demo_registry_entry(): void
{
    /** @var WidgetRegistryEntry $entry */
    $entry = clone ObjectManager::getInstance(WidgetRegistryEntry::class);
    $entry->clearQuery()->clearData()
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_AREA, FIXTURE_WIDGET_AREA)
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_MODULE, FIXTURE_WIDGET_MODULE)
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_TYPE, FIXTURE_WIDGET_TYPE)
        ->where(WidgetRegistryEntry::schema_fields_WIDGET_CODE, FIXTURE_WIDGET_CODE)
        ->delete()
        ->fetch();
}

$payload = fixture_payload();
$action = trim((string)($payload['action'] ?? ''));
$token = fixture_token($payload);
$code = fixture_website_code($token);

/** @var DashboardViewService $dashboardService */
$dashboardService = ObjectManager::getInstance(DashboardViewService::class);
$themeId = $dashboardService->getBackendThemeId();
if ($themeId <= 0) {
    fixture_fail('Missing backend theme.');
}

try {
    if ($action === 'cleanup') {
        fixture_cleanup_website($code, $themeId);
        fixture_json(['success' => true]);
        exit(0);
    }

    if ($action === 'cleanup-demo-widget-everywhere') {
        fixture_cleanup_demo_widget_everywhere();
        fixture_json(['success' => true]);
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

    if ($action === 'snapshot') {
        $view = fixture_load_default_view($code);
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

    if ($action === 'default-injections') {
        $view = fixture_load_default_view($code);
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

    if ($action === 'delete-demo-widget-status') {
        $view = fixture_load_default_view($code);
        if (!$view) {
            fixture_fail('Missing prepared dashboard view.');
        }

        fixture_delete_demo_widget_status($themeId, $view, (string)($payload['status'] ?? 'draft'));

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

    if ($action === 'reset-demo-widget-registry') {
        fixture_reset_demo_registry_entry();
        fixture_json(['success' => true, 'entry' => fixture_demo_registry_entry()]);
        exit(0);
    }

    if ($action === 'registry-entry') {
        fixture_json(['success' => true, 'entry' => fixture_demo_registry_entry()]);
        exit(0);
    }

    fixture_fail('Unsupported fixture action: ' . $action);
} catch (Throwable $throwable) {
    fixture_fail($throwable->getMessage());
}
