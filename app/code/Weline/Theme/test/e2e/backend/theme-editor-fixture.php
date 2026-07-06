<?php
declare(strict_types=1);

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Websites\Model\Website;

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
    return [
        'layout_option' => DashboardView::LAYOUT_OPTION,
        'scope' => 'dashboard_view:' . $viewId,
        'target_type' => DashboardView::TARGET_TYPE_WEBSITE,
        'target_id' => $websiteId,
    ];
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

    $website->clearQuery()->clearData()->load($websiteId);
    if ($website->getWebsiteId() > 0 && $website->getCode() !== Website::CODE_DEFAULT) {
        $website->delete();
    }
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
