<?php
declare(strict_types=1);

/**
 * Task 6 hard-cut helpers: inspect v3 layout-entity tree + conversion status.
 * Invoked via stdin JSON {action, ...}; stdout JSON.
 */

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Framework\Database\DbManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionResourceSnapshot;
use Weline\Theme\Model\ThemeScopeVersionRevision;
use Weline\Theme\Model\ThemeScopeVersionSelection;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\Scoped\ThemeScopedWorkspaceRequestService;
use Weline\Theme\Service\Version\ThemeVersionArtifactConverter;
use Weline\Theme\Service\Version\ThemeVersionPublicationService;

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function fail(string $message): never
{
    out(['success' => false, 'error' => $message]);
    exit(1);
}

/**
 * 解析并强制「独立测试 scope」。
 *
 * 禁止把线上活跃主题的主 owner（default.default.default）当作测试目标：
 * 本夹具历史上默认用它，导致 e2e 反复发布把线上 owner 的 selection 推到空版本
 * （即缺口 #5 的污染源）。所有写库 action 必须显式传入独立 scope。
 *
 * @param array<string, mixed> $payload
 * @return array{0:string,1:string,2:string} [storage_scope, store_mode, area]
 */
function isolation_require_dedicated_scope(array $payload): array
{
    $storageScope = trim((string)($payload['scope'] ?? ''));
    $storeMode = trim((string)($payload['store_mode'] ?? 'normal'));
    $area = trim((string)($payload['area'] ?? 'frontend'));
    if ($storageScope === '' || $storageScope === 'default.default.default') {
        fail('dedicated_test_scope_required:' . ($storageScope === '' ? '(empty)' : $storageScope));
    }

    return [
        $storageScope,
        $storeMode !== '' ? $storeMode : 'normal',
        $area !== '' ? $area : 'frontend',
    ];
}

/**
 * 构建可探测 scope-version payload 方法的 ThemeEditor 控制器外壳（与 publish_flow 同构）。
 *
 * @return array{draft_version_id?:int}
 */
function isolation_editor_harness(int $themeId, string $pageType, string $storageScope = '', string $storeMode = 'normal', string $area = 'frontend'): array
{
    if ($storageScope === '' || $storageScope === 'default.default.default') {
        fail('dedicated_test_scope_required:' . ($storageScope === '' ? '(empty)' : $storageScope));
    }
    /** @var \Weline\Framework\Http\Request $request */
    $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
    $editor = new \Weline\Theme\Controller\Backend\ThemeEditor(
        ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class),
        ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class),
        ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class),
        ObjectManager::getInstance(\Weline\Theme\Service\ThemeCacheGenerator::class),
        ObjectManager::getInstance(\Weline\Theme\Service\WidgetPositionResolver::class),
        ObjectManager::getInstance(\Weline\Widget\Api\WidgetRegistryInterface::class),
        ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class),
        null,
        ObjectManager::getInstance(\Weline\Theme\Service\PreviewTokenService::class),
        ObjectManager::getInstance(\Weline\Theme\Service\EditorLockService::class),
        ObjectManager::getInstance(\Weline\Widget\Api\Param\ParamFormRendererInterface::class),
    );
    $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
    if (method_exists($session, 'start')) {
        $session->start(null);
    }
    $setProp = static function (object $controller, string $propertyName, mixed $value): void {
        $reflection = new ReflectionObject($controller);
        while ($reflection !== false) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($controller, $value);
                return;
            }
            $reflection = $reflection->getParentClass() ?: false;
        }
    };
    $setProp($editor, 'request', $request);
    $setProp($editor, '_objectManager', ObjectManager::getInstance());
    $setProp($editor, '_url', ObjectManager::getInstance(\Weline\Framework\Http\Url::class));
    $setProp($editor, 'session', $session);

    $run = static function (array $params, callable $fn) use ($request): array {
        $request->setData('__theme_editor_request_params', $params);
        // postSaveWidget 等控制器直接读 getBodyParams()，不读合成参数键；
        // 这里同步注入 body_params，否则夹具驱动不了真实的写部件通路（只会拿到「参数不完整」）。
        $request->setData('body_params', $params);
        try {
            $result = $fn();
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            $result = $e->getBody();
        } finally {
            $request->unsetData('__theme_editor_request_params');
            $request->unsetData('body_params');
        }
        if (is_string($result) && trim($result) !== '') {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
        return is_array($result) ? $result : ['success' => false, 'error' => 'non_array_result'];
    };

    $scope = ['storage_scope' => $storageScope, 'store_mode' => $storeMode];
    $base = [
        'theme_id' => $themeId,
        'page_type' => $pageType,
        'layout_type' => $pageType,
        'layout_option' => 'default',
        'locale' => 'default',
        'target_type' => 'global',
        'target_id' => 0,
        'editor_area' => $area,
        'area' => $area,
        'resource_type' => 'layout',
        'scope' => $scope,
        'editor_context' => [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'layout_type' => $pageType,
            'layout_option' => 'default',
            'locale' => 'default',
            'target_type' => 'global',
            'target_id' => 0,
            'area' => $area,
            'editor_area' => $area,
            'resource_type' => 'layout',
            'scope' => $scope,
        ],
    ];

    return ['editor' => $editor, 'run' => $run, 'base' => $base];
}

/**
 * 一份合法的最小页面内容节点（真实物化器可接受的最小输入）。
 *
 * @return array<string, array<string, mixed>>
 */
function isolation_content_nodes(string $marker): array
{
    $uid = hash('sha256', 'e2e-artifact-reuse:' . $marker);
    return [$uid => [
        'node_uid' => $uid,
        'area' => 'content',
        'slot_id' => 'content',
        'widget_code' => 'basic/button',
        'widget_module' => 'Weline_Theme',
        'widget_type' => 'theme_component',
        'config' => ['text' => $marker, 'type' => 'primary', 'size' => 'md'],
    ]];
}

/**
 * 走真实固化器写入指定版本的页面产物，返回落地路径。
 */
function isolation_materialize(
    object $materializer,
    int $themeId,
    string $storageScope,
    string $storeMode,
    int $versionId,
    string $layoutHash,
    array $contentNodes,
    string $pageType,
    string $mode = ThemeVersionIdentity::MODE_FORMAL,
): string {
    $identity = new ThemeVersionIdentity(
        $themeId,
        $storageScope,
        $storeMode,
        ThemeVersionIdentity::AREA_FRONTEND,
        $versionId,
        $mode,
        1,
    );
    return (string)$materializer->materializePage($identity, $layoutHash, 'e2e-source', $contentNodes, [], $pageType);
}

/**
 * 把绝对路径归一成「与版本无关」的结构身份键：
 * 剔除 tv{V}/mode 段与其中的 v{V} 结构目录段，保留 layoutIdentityHash + structureKey。
 */
function isolation_versionless_key(string $absolutePath, string $versionModeDir): string
{
    $dir = rtrim($versionModeDir, '/') . '/';
    $rel = str_starts_with($absolutePath, $dir) ? substr($absolutePath, strlen($dir)) : $absolutePath;
    $rel = (string)preg_replace('#/v\d+/#', '/', $rel);
    return (string)preg_replace('#//+#', '/', $rel);
}

/**
 * 把一次「创建→封存→发布」的结果压成可诊断的摘要（供失败定位）。
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function isolation_publish_step_summary(array $result): array
{
    $messageOf = static function (mixed $step): mixed {
        if (!is_array($step)) {
            return is_scalar($step) ? $step : null;
        }
        return $step['message'] ?? $step['error'] ?? $step['reason'] ?? null;
    };
    return [
        'success' => !empty($result['success']),
        'failed_step' => $result['step'] ?? null,
        'create' => $messageOf($result['create'] ?? null),
        'seal' => $messageOf($result['seal'] ?? null),
        'publish' => $messageOf($result['publish'] ?? null),
        'draft_version_id' => $result['draft_version_id'] ?? null,
        'version_id' => $result['version_id'] ?? null,
        'detail' => isset($result['result']) && is_array($result['result'])
            ? array_intersect_key($result['result'], array_flip(['success', 'message', 'error', 'code', 'data']))
            : null,
    ];
}

/**
 * 从固化返回的 layout.phtml 绝对路径里取出 structureKey。
 */
function isolation_structure_key_from_path(string $phtmlPath): string
{
    if (preg_match('#/structures/v\d+/([a-f0-9]{64})/#', $phtmlPath, $m) === 1) {
        return $m[1];
    }
    return '';
}

/**
 * 跑一次「创建草稿 → 可选存部件 → 封存 → 发布」，返回版本与发布证据。
 *
 * @param array<string, mixed> $createParams
 * @param array<string, mixed> $widgetParams
 * @return array<string, mixed>
 */
function isolation_run_publish(object $editor, callable $run, array $base, array $createParams, string $versionName, array $widgetParams = [], string $publishSet = 'all'): array
{
    $chromeCount = static function (int $id): int {
        if ($id < 1) {
            return -1;
        }
        $row = isolation_version_row($id);
        $decoded = json_decode((string)($row['chrome_payload_json'] ?? ''), true);
        return is_array($decoded) ? count($decoded) : 0;
    };

    $created = $run($base + $createParams, static fn() => $editor->createScopeDraftPayload());
    if (empty($created['success'])) {
        return ['success' => false, 'step' => 'create_scope_draft', 'result' => $created];
    }
    $draftId = (int)($created['data']['theme_version_id'] ?? 0);
    if ($draftId < 1) {
        return ['success' => false, 'step' => 'create_scope_draft_no_id', 'result' => $created];
    }
    $chromeAfterCreate = $chromeCount($draftId);

    // 允许一次传多个部件：layout（area=content）与 chrome（area=header）是两个**互相独立**的
    // 资源指纹，单个部件只能改动其中一个。需要「父同时改了 layout 与 chrome」的场景
    // （例如 UC-08 的「未覆盖值进入 C' + 本级覆盖保留」同时成立）必须能一次保存两个。
    $widgetList = $widgetParams === []
        ? []
        : ((isset($widgetParams[0]) && is_array($widgetParams[0])) ? $widgetParams : [$widgetParams]);

    $savedWidget = ['success' => true, 'skipped' => true];
    foreach ($widgetList as $widgetIndex => $oneWidget) {
        try {
            $oneSaved = $run($base + $oneWidget, static fn() => $editor->postSaveWidget());
        } catch (Throwable $widgetError) {
            $oneSaved = ['success' => false, 'error' => $widgetError->getMessage()];
        }
        if ($widgetIndex === 0) {
            $savedWidget = $oneSaved;
            $savedWidget['extra_widgets_saved'] = 0;
        } else {
            $savedWidget['extra_widgets_saved'] = (int)($savedWidget['extra_widgets_saved'] ?? 0) + 1;
        }
        if (empty($oneSaved['success'])) {
            $savedWidget['success'] = false;
        }
    }

    $sealed = $run($base + [
        'theme_version_id' => $draftId,
        'version_name' => $versionName,
        'description' => 'Task 6 artifact reuse fixture',
    ], static fn() => $editor->saveScopeVersionPayload());
    if (empty($sealed['success'])) {
        return ['success' => false, 'step' => 'save_scope_version', 'result' => $sealed, 'create' => $created, 'save_widget' => $savedWidget];
    }
    $versionId = (int)($sealed['data']['theme_version_id'] ?? $sealed['data']['version_id'] ?? $draftId);
    $chromeAfterSeal = $chromeCount($versionId);

    $published = $run($base + [
        'theme_version_id' => $versionId,
        'publish_set' => $publishSet,
    ], static fn() => $editor->publishScopeVersionPayload());
    // 发布链路直接写 selection 模型，不清服务侧 memo；这里按请求边界清理。
    isolation_flush_theme_request_cache();

    return [
        'success' => !empty($published['success']),
        'draft_version_id' => $draftId,
        'version_id' => $versionId,
        'chrome_count_after_create' => $chromeAfterCreate,
        'chrome_count_after_seal' => $chromeAfterSeal,
        'chrome_count_after_publish' => $chromeCount((int)($published['data']['theme_version_id'] ?? $versionId)),
        'create' => $created,
        'save_widget' => [
            'success' => !empty($savedWidget['success']),
            'node_uid' => $savedWidget['data']['node_uid'] ?? null,
            'skipped' => !empty($savedWidget['skipped']),
        ],
        'seal' => $sealed,
        'publish' => $published,
    ];
}

/**
 * 读取 owner 的 published/draft 选择行。
 *
 * @return array<string, mixed>
 */
function isolation_selection(int $themeId, string $storageScope, string $storeMode, string $area): array
{
    /** @var ThemeScopeVersionSelection $sel */
    $sel = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
    $row = $sel->reset()->clearData()
        ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
        ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $storageScope)
        ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
        ->where(ThemeScopeVersionSelection::schema_fields_AREA, $area)
        ->find()
        ->fetchArray();
    if (is_array($row) && isset($row[0]) && is_array($row[0])) {
        $row = $row[0];
    }
    return is_array($row) ? $row : [];
}

/**
 * 读取版本行（用于断言 lifecycle / structure_key / creation_source）。
 *
 * @return array<string, mixed>
 */
function isolation_version_row(int $versionId): array
{
    if ($versionId < 1) {
        return [];
    }
    /** @var ThemeScopeVersion $model */
    $model = ObjectManager::getInstance(ThemeScopeVersion::class);
    $model->reset()->clearData()->load($versionId);

    return $model->getVersionId() === $versionId ? $model->getData() : [];
}

/**
 * 读取不可变修订头（后代传播把「新父来源」固定在 scope_source_version_id）。
 *
 * @return array<string, mixed>
 */
function isolation_revision_head(int $versionId, int $contentRevision = 1): array
{
    if ($versionId < 1) {
        return [];
    }
    /** @var ThemeScopeVersionRevision $model */
    $model = ObjectManager::getInstance(ThemeScopeVersionRevision::class);
    $rows = $model->reset()->clearData()
        ->where(ThemeScopeVersionRevision::schema_fields_THEME_VERSION_ID, $versionId)
        ->where(ThemeScopeVersionRevision::schema_fields_CONTENT_REVISION, $contentRevision)
        ->limit(1)
        ->select()
        ->fetchArray();
    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return is_array($rows[0] ?? null) ? $rows[0] : $rows;
}

/**
 * 选定「建草稿」的基准来源。
 *
 * owner 已有基准（已发布 P 或草稿 D）时续编；独立测试 scope 首次没有基准，
 * `continue_current` 会报 `create_draft_base_required`，此时必须从 package defaults 起步
 * （与线上「首次发布」同一条路径）。
 */
function isolation_creation_source_kind(int $themeId, string $storageScope, string $storeMode, string $area): string
{
    $row = isolation_selection($themeId, $storageScope, $storeMode, $area);
    $hasBase = (int)($row['published_version_id'] ?? 0) > 0
        || (int)($row['draft_version_id'] ?? 0) > 0;

    return $hasBase
        ? ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT
        : ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS;
}

/**
 * 对某个版本目录下的全部 .phtml 取指纹：相对路径 → {sha256, inode, size}。
 * 用于「写入不得污染其它版本同 inode 文件」的比对。
 *
 * @return array<string, array{sha256:string, inode:int, size:int}>
 */
function isolation_fingerprint_phtml(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.phtml')) {
            continue;
        }
        $path = $file->getPathname();
        $stat = @stat($path);
        $out[substr($path, strlen($dir))] = [
            'sha256' => (string)hash_file('sha256', $path),
            'inode' => (int)($stat['ino'] ?? 0),
            'size' => (int)($stat['size'] ?? 0),
        ];
    }
    ksort($out);
    return $out;
}

/**
 * 比较两份指纹，返回差异描述。
 *
 * @param array<string, array{sha256:string, inode:int, size:int}> $before
 * @param array<string, array{sha256:string, inode:int, size:int}> $after
 * @return array{bytes_changed:list<string>, inode_changed:list<string>, only_before:list<string>, only_after:list<string>}
 */
function isolation_fingerprint_diff(array $before, array $after): array
{
    $bytesChanged = [];
    $inodeChanged = [];
    foreach ($before as $rel => $row) {
        if (!isset($after[$rel])) {
            continue;
        }
        if ($after[$rel]['sha256'] !== $row['sha256']) {
            $bytesChanged[] = $rel;
        }
        if ($after[$rel]['inode'] !== $row['inode']) {
            $inodeChanged[] = $rel;
        }
    }
    return [
        'bytes_changed' => $bytesChanged,
        'inode_changed' => $inodeChanged,
        'only_before' => array_values(array_diff(array_keys($before), array_keys($after))),
        'only_after' => array_values(array_diff(array_keys($after), array_keys($before))),
    ];
}

/**
 * 从真实产物文件读出版本 chrome 的节点集合。
 *
 * 观测点是版本 chrome 的 config.json 侧车（每个 artifact_key 一条节点记录，含
 * slot_id / widget_module / widget_code / is_active / source），不复用发布或烘焙的
 * 返回值，避免「自造数据自证」。
 *
 * @return array<string, array<string, mixed>>
 */
function isolation_chrome_nodes_on_disk(
    ThemeLayoutEntityPaths $paths,
    int $themeId,
    string $storageScope,
    string $storeMode,
    int $versionId,
    string $mode,
): array {
    if ($versionId < 1) {
        return [];
    }
    $identity = new ThemeVersionIdentity(
        $themeId,
        $storageScope,
        $storeMode,
        ThemeVersionIdentity::AREA_FRONTEND,
        $versionId,
        $mode,
        1,
    );
    $versionDir = (string)$paths->versionModeDir($identity);
    $pattern = rtrim($versionDir, '/\\') . DIRECTORY_SEPARATOR . 'chrome'
        . DIRECTORY_SEPARATOR . 'configs' . DIRECTORY_SEPARATOR . 'v' . $versionId
        . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config.json';
    $nodes = [];
    foreach (glob($pattern) ?: [] as $file) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $uid => $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodes[strtolower((string)$uid)] = $node;
        }
    }
    ksort($nodes);

    return $nodes;
}

/**
 * 清掉本进程内 Theme 相关的「请求级」memo，模拟生产「一次编辑动作 = 一个请求」。
 *
 * 背景：ThemeScopeVersionService::loadSelection() 把 selection 行按
 * (theme, scope, store_mode, area) 缓存在 RequestContext 里，只由 forgetSelection() 失效；
 * 而控制器写 selection 是直接写模型，不会清这个 memo。夹具在**同一进程**里连续跑
 * 「建草稿 → 封存 → 发布 → 再建草稿」，于是 getCurrent()/ensureCurrent() 会一直读到
 * 第一次缓存下来的旧 selection，把 chrome 固化写到**上一个版本**上（实测：
 * selection.draft 已是 483，getCurrent() 仍返回 482）。生产里每个动作都是独立请求，
 * 不会跨动作看到陈旧 memo，所以这里按请求边界清理，才是对生产语义的忠实模拟。
 */
function isolation_flush_theme_request_cache(): void
{
    foreach (array_keys(RequestContext::all()) as $key) {
        $key = (string)$key;
        if (str_starts_with($key, 'theme.')) {
            RequestContext::remove($key);
        }
    }
}

$raw = stream_get_contents(STDIN);
$payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
$payload = is_array($payload) ? $payload : [];
$action = trim((string)($payload['action'] ?? ''));

try {
    /** @var ThemeLayoutEntityPaths $paths */
    $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
    $root = $paths->root();

    if ($action === 'inspect_tree') {
        $themeId = (int)($payload['theme_id'] ?? 0);
        $legacySegments = [];
        $v3Segments = [];
        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($root));
                $rel = str_replace('\\', '/', (string)$rel);
                if (preg_match('#/(?:pages|chrome)/(?:r|d|s)[0-9a-f]#', $rel)
                    || preg_match('#/chrome/s[0-9a-f]{8,}#', $rel)
                    || preg_match('#/current\\.json$#', $rel)
                ) {
                    if (!preg_match('#^\\d+/(frontend|backend)/[a-f0-9]{64}/tv\\d+/(formal|draft)/#', $rel)) {
                        $legacySegments[] = $rel;
                    }
                }
                if (preg_match('#^\\d+/(frontend|backend)/[a-f0-9]{64}/tv(\\d+)/(formal|draft)/#', $rel, $m)) {
                    $v3Segments[] = [
                        'path' => $rel,
                        'theme_version_id' => (int)$m[2],
                        'mode' => $m[3],
                        'area' => $m[1],
                    ];
                }
            }
        }
        out([
            'success' => true,
            'root' => $root,
            'legacy_count' => count($legacySegments),
            'legacy_sample' => array_slice($legacySegments, 0, 8),
            'v3_count' => count($v3Segments),
            'v3_sample' => array_slice($v3Segments, 0, 12),
            'theme_filter' => $themeId,
        ]);
        exit(0);
    }

    if ($action === 'selection') {
        // 只读 action：默认读线上主 owner 仅用于人工排查，不写任何数据。
        // 写库 action 一律走 isolation_require_dedicated_scope()，禁止用主 owner。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $scope = trim((string)($payload['scope'] ?? 'default.default.default'));
        $storeMode = trim((string)($payload['store_mode'] ?? 'normal'));
        $area = trim((string)($payload['area'] ?? 'frontend'));
        if ($themeId < 1) {
            fail('theme_id_required');
        }
        /** @var ThemeScopeVersionSelection $sel */
        $sel = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
        $row = $sel->reset()->clearData()
            ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersionSelection::schema_fields_AREA, $area)
            ->find()
            ->fetchArray();
        if (is_array($row) && isset($row[0]) && is_array($row[0])) {
            $row = $row[0];
        }
        $publishedId = (int)($row['published_version_id'] ?? 0);
        $draftId = (int)($row['draft_version_id'] ?? 0);
        $pathsFor = static function (int $vid, string $mode) use ($paths, $themeId, $scope, $storeMode, $area): ?string {
            if ($vid < 1) {
                return null;
            }
            $identity = new ThemeVersionIdentity($themeId, $scope, $storeMode, $area, $vid, $mode, 1);
            return $paths->versionModeDir($identity);
        };
        out([
            'success' => true,
            'selection' => is_array($row) ? $row : null,
            'published_version_id' => $publishedId,
            'draft_version_id' => $draftId,
            'published_dir' => $pathsFor($publishedId, ThemeVersionIdentity::MODE_FORMAL),
            'draft_dir' => $pathsFor($draftId, ThemeVersionIdentity::MODE_DRAFT),
            'published_dir_exists' => $publishedId > 0 && is_dir((string)$pathsFor($publishedId, ThemeVersionIdentity::MODE_FORMAL)),
        ]);
        exit(0);
    }

    if ($action === 'convert_status') {
        /** @var ThemeVersionArtifactConverter $converter */
        $converter = ObjectManager::getInstance(ThemeVersionArtifactConverter::class);
        $legacy = $converter->loadLegacySnapshotFromDatabase();
        $report = $converter->dryRun(
            $legacy['versions'] ?? [],
            $legacy['releases'] ?? [],
            $legacy['intents'] ?? [],
            'e2e-isolation-status',
        );
        out([
            'success' => true,
            'pending_count' => (int)($report['pending_count'] ?? count($report['mappings'] ?? [])),
            'already_converted_count' => (int)($report['already_converted_count'] ?? count($report['already_converted'] ?? [])),
            'archive_only_count' => (int)($report['archive_only_count'] ?? count($report['archive_only'] ?? [])),
        ]);
        exit(0);
    }

    if ($action === 'purge_test_scope') {
        // 清理独立测试 scope 在 v3 表与产物树里留下的全部痕迹。
        //
        // 为什么需要它：editor 夹具的 cleanup_scope_hierarchy 只清 v2 表
        // （batches/patches/revisions/releases/workspaces），v3 的
        // selection / version 行不会被删 —— 每跑一轮 e2e 就多出一批
        // e2e-theme-scope-* owner，且 UC-14 的 GC 会把它们当作「可达」而保留。
        $themeId = (int)($payload['theme_id'] ?? 0);
        if ($themeId < 1) {
            fail('theme_id_required');
        }
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);

        /** @var \PDO $conn */
        $conn = ObjectManager::getInstance(\Weline\Framework\Database\DbManager::class)
            ->getConnector()
            ->getLink();

        $ownerWhere = 'theme_id = :t AND scope = :s AND store_mode = :m AND area = :a';
        $ownerParams = [':t' => $themeId, ':s' => $storageScope, ':m' => $storeMode, ':a' => $area];

        $versionStmt = $conn->prepare('SELECT version_id FROM w_theme_scope_version WHERE ' . $ownerWhere);
        $versionStmt->execute($ownerParams);
        $versionIds = array_map('intval', $versionStmt->fetchAll(PDO::FETCH_COLUMN));

        // 子表都按 theme_version_id 归属，先删子表再删主表。
        $deletedChildren = [];
        if ($versionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($versionIds), '?'));
            foreach ([
                'w_theme_scope_version_revision',
                'w_theme_scope_version_resource_snapshot',
                'w_theme_scope_version_widget_decision',
            ] as $childTable) {
                $stmt = $conn->prepare('DELETE FROM ' . $childTable . ' WHERE theme_version_id IN (' . $placeholders . ')');
                $stmt->execute($versionIds);
                $deletedChildren[$childTable] = $stmt->rowCount();
            }
        }

        $stmt = $conn->prepare('DELETE FROM w_theme_scope_version WHERE ' . $ownerWhere);
        $stmt->execute($ownerParams);
        $deletedVersions = $stmt->rowCount();

        $stmt = $conn->prepare('DELETE FROM w_theme_scope_version_selection WHERE ' . $ownerWhere);
        $stmt->execute($ownerParams);
        $deletedSelections = $stmt->rowCount();

        // 产物目录：purgeVersionModeDirectory 只接受 .../tv{N}/{formal|draft}，
        // 故按 mode 逐个清，再回收空目录。
        $scopeKey = (new ThemeVersionIdentity($themeId, $storageScope, $storeMode, $area))->scopeKey();
        $ownerDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $paths->root()), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $themeId
            . DIRECTORY_SEPARATOR . $area
            . DIRECTORY_SEPARATOR . $scopeKey;
        $deletedDirs = 0;
        if (is_dir($ownerDir)) {
            foreach (scandir($ownerDir) ?: [] as $entry) {
                if (preg_match('/^tv\d+$/', $entry) !== 1) {
                    continue;
                }
                $tvDir = $ownerDir . DIRECTORY_SEPARATOR . $entry;
                foreach (ThemeVersionIdentity::MODES as $mode) {
                    try {
                        if ($paths->purgeVersionModeDirectory($tvDir . DIRECTORY_SEPARATOR . $mode) > 0) {
                            $deletedDirs++;
                        }
                    } catch (Throwable) {
                        // 单个 mode 目录缺失/不合规不影响整体清理。
                    }
                }
                @rmdir($tvDir);
            }
            @rmdir($ownerDir);
        }

        out([
            'success' => true,
            'scope' => $storageScope,
            'version_ids' => $versionIds,
            'deleted_versions' => $deletedVersions,
            'deleted_selections' => $deletedSelections,
            'deleted_children' => $deletedChildren,
            'deleted_version_dirs' => $deletedDirs,
        ]);
        exit(0);
    }

    if ($action === 'version_lifecycle') {
        $versionId = (int)($payload['theme_version_id'] ?? $payload['version_id'] ?? 0);
        if ($versionId < 1) {
            fail('version_id_required');
        }
        /** @var ThemeScopeVersion $model */
        $model = ObjectManager::getInstance(ThemeScopeVersion::class);
        $row = $model->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_ID, $versionId)
            ->find()
            ->fetchArray();
        if (is_array($row) && isset($row[0]) && is_array($row[0])) {
            $row = $row[0];
        }
        out([
            'success' => true,
            'version_id' => $versionId,
            'lifecycle' => trim((string)($row['lifecycle'] ?? '')),
            'area' => trim((string)($row['area'] ?? '')),
            'content_revision' => (int)($row['content_revision'] ?? 0),
        ]);
        exit(0);
    }

    if ($action === 'publish_flow') {
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        $marker = trim((string)($payload['marker'] ?? 'ISO E2E Button'));
        if ($themeId < 1 || $pageType === '') {
            fail('theme_id_and_page_type_required');
        }
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);

        /** @var \Weline\Framework\Http\Request $request */
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $editor = new \Weline\Theme\Controller\Backend\ThemeEditor(
            ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeCacheGenerator::class),
            ObjectManager::getInstance(\Weline\Theme\Service\WidgetPositionResolver::class),
            ObjectManager::getInstance(\Weline\Widget\Api\WidgetRegistryInterface::class),
            ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class),
            null,
            ObjectManager::getInstance(\Weline\Theme\Service\PreviewTokenService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\EditorLockService::class),
            ObjectManager::getInstance(\Weline\Widget\Api\Param\ParamFormRendererInterface::class),
        );
        $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
        if (method_exists($session, 'start')) {
            $session->start(null);
        }
        $setProp = static function (object $controller, string $propertyName, mixed $value): void {
            $reflection = new ReflectionObject($controller);
            while ($reflection !== false) {
                if ($reflection->hasProperty($propertyName)) {
                    $property = $reflection->getProperty($propertyName);
                    $property->setAccessible(true);
                    $property->setValue($controller, $value);
                    return;
                }
                $reflection = $reflection->getParentClass() ?: false;
            }
        };
        $setProp($editor, 'request', $request);
        $setProp($editor, '_objectManager', ObjectManager::getInstance());
        $setProp($editor, '_url', ObjectManager::getInstance(\Weline\Framework\Http\Url::class));
        $setProp($editor, 'session', $session);

        $run = static function (array $params, callable $fn) use ($request): array {
            $request->setData('__theme_editor_request_params', $params);
            // postSaveWidget 等控制器直接读 getBodyParams()，不读合成参数键；
            // 这里同步注入 body_params，否则夹具驱动不了真实的写部件通路（只会拿到「参数不完整」）。
            $request->setData('body_params', $params);
            try {
                $result = $fn();
            } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
                $result = $e->getBody();
            } finally {
                $request->unsetData('__theme_editor_request_params');
                $request->unsetData('body_params');
            }
            if (is_string($result) && trim($result) !== '') {
                $decoded = json_decode($result, true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
            return is_array($result) ? $result : ['success' => false, 'error' => 'non_array_result', 'raw' => is_scalar($result) ? $result : gettype($result)];
        };

        $base = [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'layout_type' => $pageType,
            'layout_option' => 'default',
            'locale' => 'default',
            'target_type' => 'global',
            'target_id' => 0,
            'editor_area' => 'frontend',
            'area' => 'frontend',
            'resource_type' => 'layout',
            'scope' => [
                'storage_scope' => $storageScope,
                'store_mode' => $storeMode,
            ],
            'editor_context' => [
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'layout_type' => $pageType,
                'layout_option' => 'default',
                'locale' => 'default',
                'target_type' => 'global',
                'target_id' => 0,
                'area' => 'frontend',
                'editor_area' => 'frontend',
                'resource_type' => 'layout',
                'scope' => [
                    'storage_scope' => $storageScope,
                    'store_mode' => $storeMode,
                ],
            ],
        ];

        $created = $run($base + [
            'creation_source_kind' => isolation_creation_source_kind($themeId, $storageScope, $storeMode, $area),
            'force_new' => true,
        ], static fn () => $editor->createScopeDraftPayload());
        if (empty($created['success'])) {
            out(['success' => false, 'step' => 'create_scope_draft', 'result' => $created]);
            exit(1);
        }
        $draftId = (int)($created['data']['theme_version_id'] ?? 0);

        // Optional content mutation: save-widget may need richer editor_context; seal/publish alone prove formal tree.
        $savedWidget = ['success' => true, 'skipped' => true];
        try {
            $savedWidget = $run($base + [
                'area' => 'content',
                'slot_id' => 'content',
                'widget_module' => 'Weline_Theme',
                'widget_type' => 'theme_component',
                'widget_code' => 'basic/button',
                'config' => [
                    'text' => $marker,
                    'type' => 'primary',
                    'size' => 'md',
                ],
                'sort_order' => 0,
                'exclusive' => false,
            ], static fn () => $editor->postSaveWidget());
        } catch (Throwable $widgetError) {
            $savedWidget = ['success' => false, 'error' => $widgetError->getMessage()];
        }

        $sealed = $run($base + [
            'theme_version_id' => $draftId,
            'version_name' => 'ISO E2E sealed',
            'description' => 'Task 6 isolation publish_flow',
        ], static fn () => $editor->saveScopeVersionPayload());
        if (empty($sealed['success'])) {
            out(['success' => false, 'step' => 'save_scope_version', 'result' => $sealed, 'create' => $created, 'save_widget' => $savedWidget]);
            exit(1);
        }
        $versionId = (int)($sealed['data']['theme_version_id'] ?? $sealed['data']['version_id'] ?? $draftId);

        // 缺口 #2 证据：记录发布调用「之前」该版本 formal 目录的状态。
        // 发布前不存在、发布后存在，才能证明产物是发布这一步烘焙出来的（而非残留）。
        $versionFormalIdentity = new ThemeVersionIdentity(
            $themeId,
            $storageScope,
            $storeMode,
            $area,
            $versionId,
            ThemeVersionIdentity::MODE_FORMAL,
            1,
        );
        $versionFormalDir = $paths->versionModeDir($versionFormalIdentity);
        $formalDirExistedBeforePublish = $versionId > 0 && is_dir($versionFormalDir);

        $published = $run($base + [
            'theme_version_id' => $versionId,
            'publish_set' => 'all',
        ], static fn () => $editor->publishScopeVersionPayload());
        if (empty($published['success'])) {
            out(['success' => false, 'step' => 'publish_scope_version', 'result' => $published, 'create' => $created, 'save_widget' => $savedWidget, 'seal' => $sealed]);
            exit(1);
        }

        /** @var ThemeScopeVersionSelection $sel */
        $sel = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
        $row = $sel->reset()->clearData()
            ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $storageScope)
            ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersionSelection::schema_fields_AREA, 'frontend')
            ->find()
            ->fetchArray();
        if (is_array($row) && isset($row[0]) && is_array($row[0])) {
            $row = $row[0];
        }
        $publishedId = (int)($row['published_version_id'] ?? 0);
        $identity = new ThemeVersionIdentity(
            $themeId,
            $storageScope,
            $storeMode,
            'frontend',
            $publishedId,
            ThemeVersionIdentity::MODE_FORMAL,
            1,
        );
        $publishedDir = $paths->versionModeDir($identity);

        // 缺口 #2/#4 证据：产物必须真的落在 formal 树上、指纹必须与磁盘一致、
        // 快照行必须记录该产物。全部从磁盘/DB 直接读，不看发布返回值自证。
        $bake = is_array($published['data']['artifacts'] ?? null) ? $published['data']['artifacts'] : [];
        $pagePath = (string)($bake['page_path'] ?? '');
        $pageArtifactId = (string)($bake['page_artifact_id'] ?? '');
        $onDiskSha256 = is_file($pagePath) ? (string)hash_file('sha256', $pagePath) : '';
        $artifactMatchesDisk = $onDiskSha256 !== ''
            && $pageArtifactId !== ''
            && hash_equals($pageArtifactId, $onDiskSha256);

        $markerFiles = [];
        $bindingFiles = [];
        if (is_dir($publishedDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($publishedDir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $filePath = $file->getPathname();
                $fileName = $file->getFilename();
                if ($fileName === 'binding.json') {
                    $bindingFiles[] = $filePath;
                    continue;
                }
                if ($marker === '' || $file->getSize() > 524288) {
                    continue;
                }
                $contents = (string)file_get_contents($filePath);
                if (str_contains($contents, $marker)) {
                    $markerFiles[] = $filePath;
                }
            }
        }

        $snapshotRows = [];
        try {
            $conn = ObjectManager::getInstance(DbManager::class)->getConnector()->getLink();
            $stmt = $conn->prepare(
                'SELECT snapshot_id, theme_version_id, content_revision, resource_type,'
                . ' resource_identity_hash, source_fingerprint'
                . ' FROM w_theme_scope_version_resource_snapshot WHERE theme_version_id = :v ORDER BY snapshot_id'
            );
            $stmt->execute([':v' => $publishedId]);
            $snapshotRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $snapshotRows = [];
        }
        $snapshotFingerprints = [];
        $snapshotTypes = [];
        foreach ($snapshotRows as $snapshotRow) {
            $snapshotTypes[] = (string)($snapshotRow['resource_type'] ?? '');
            $fingerprint = (string)($snapshotRow['source_fingerprint'] ?? '');
            if ($fingerprint !== '') {
                $snapshotFingerprints[] = $fingerprint;
            }
        }

        // 烘焙必须 fail-closed：用同层级但不同 owner 的 scope 上下文调用，
        // 必须按身份错误抛出，而不是静默烘焙到别的目录。
        // 发布路径据此在翻转发布指针之前中止，所以这条断言是「指针可见 ⇒ 产物存在」的另一半。
        $bakeMismatchError = '';
        $mismatchScope = trim((string)($payload['mismatch_scope'] ?? ''));
        if ($mismatchScope !== '' && $mismatchScope !== $storageScope && $publishedId > 0) {
            try {
                $mismatchInput = $base;
                $mismatchInput['scope'] = ['storage_scope' => $mismatchScope, 'store_mode' => $storeMode];
                $mismatchInput['editor_context']['scope'] = [
                    'storage_scope' => $mismatchScope,
                    'store_mode' => $storeMode,
                ];
                $mismatchContext = ObjectManager::getInstance(ThemeEditorContextFactory::class)
                    ->fromInput($mismatchInput, \Weline\Theme\Api\Scoped\ThemeEditorContext::RESOURCE_LAYOUT);
                ObjectManager::getInstance(
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
                )->bakePublishArtifactsForVersion($mismatchContext, $publishedId);
            } catch (Throwable $bakeError) {
                $bakeMismatchError = $bakeError->getMessage();
            }
        }

        out([
            'success' => true,
            'draft_version_id' => $draftId,
            'published_version_id' => $publishedId,
            'published_dir' => $publishedDir,
            'published_dir_exists' => $publishedId > 0 && is_dir($publishedDir),
            'formal_dir_existed_before_publish' => $formalDirExistedBeforePublish,
            'bake_mismatch_error' => $bakeMismatchError,
            'node_count' => (int)($bake['node_count'] ?? 0),
            'page_path' => $pagePath,
            'page_path_exists' => $pagePath !== '' && is_file($pagePath),
            'page_artifact_id' => $pageArtifactId,
            'on_disk_sha256' => $onDiskSha256,
            'artifact_matches_disk' => $artifactMatchesDisk,
            'marker_in_formal_tree' => $markerFiles !== [],
            'marker_files' => $markerFiles,
            'binding_files' => $bindingFiles,
            'snapshot_count' => count($snapshotRows),
            'snapshot_types' => $snapshotTypes,
            'snapshot_fingerprints' => $snapshotFingerprints,
            'snapshot_records_page_artifact' => $onDiskSha256 !== ''
                && in_array($onDiskSha256, $snapshotFingerprints, true),
            'snapshot_rows' => $snapshotRows,
            'marker' => $marker,
            'create' => $created,
            'save_widget' => [
                'success' => !empty($savedWidget['success']),
                'node_uid' => $savedWidget['data']['node_uid'] ?? null,
                // 保留原始返回，save_widget 失败时能直接看到原因，而不是只看到一个 false。
                'result' => $savedWidget,
            ],
            'seal' => $sealed,
            'publish' => $published,
        ]);
        exit(0);
    }

    if ($action === 'draft_durability') {
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ('e2e_iso_draft_' . time())));
        if ($themeId < 1) {
            fail('theme_id_required');
        }
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);

        /** @var \Weline\Framework\Http\Request $request */
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $editor = new \Weline\Theme\Controller\Backend\ThemeEditor(
            ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeCacheGenerator::class),
            ObjectManager::getInstance(\Weline\Theme\Service\WidgetPositionResolver::class),
            ObjectManager::getInstance(\Weline\Widget\Api\WidgetRegistryInterface::class),
            ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class),
            null,
            ObjectManager::getInstance(\Weline\Theme\Service\PreviewTokenService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\EditorLockService::class),
            ObjectManager::getInstance(\Weline\Widget\Api\Param\ParamFormRendererInterface::class),
        );
        $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
        if (method_exists($session, 'start')) {
            $session->start(null);
        }
        $setProp = static function (object $controller, string $propertyName, mixed $value): void {
            $reflection = new ReflectionObject($controller);
            while ($reflection !== false) {
                if ($reflection->hasProperty($propertyName)) {
                    $property = $reflection->getProperty($propertyName);
                    $property->setAccessible(true);
                    $property->setValue($controller, $value);
                    return;
                }
                $reflection = $reflection->getParentClass() ?: false;
            }
        };
        $setProp($editor, 'request', $request);
        $setProp($editor, '_objectManager', ObjectManager::getInstance());
        $setProp($editor, '_url', ObjectManager::getInstance(\Weline\Framework\Http\Url::class));
        $setProp($editor, 'session', $session);

        $base = [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'layout_type' => $pageType,
            'layout_option' => 'default',
            'locale' => 'default',
            'target_type' => 'global',
            'target_id' => 0,
            'editor_area' => 'frontend',
            'area' => 'frontend',
            'resource_type' => 'layout',
            'scope' => [
                'storage_scope' => $storageScope,
                'store_mode' => $storeMode,
            ],
            'editor_context' => [
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'layout_type' => $pageType,
                'layout_option' => 'default',
                'locale' => 'default',
                'target_type' => 'global',
                'target_id' => 0,
                'area' => 'frontend',
                'editor_area' => 'frontend',
                'resource_type' => 'layout',
                'scope' => [
                    'storage_scope' => $storageScope,
                    'store_mode' => $storeMode,
                ],
            ],
        ];

        $run = static function (array $params, callable $fn) use ($request): array {
            $request->setData('__theme_editor_request_params', $params);
            // postSaveWidget 等控制器直接读 getBodyParams()，不读合成参数键；
            // 这里同步注入 body_params，否则夹具驱动不了真实的写部件通路（只会拿到「参数不完整」）。
            $request->setData('body_params', $params);
            try {
                $result = $fn();
            } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
                $result = $e->getBody();
            } finally {
                $request->unsetData('__theme_editor_request_params');
                $request->unsetData('body_params');
            }
            if (is_string($result) && trim($result) !== '') {
                $decoded = json_decode($result, true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
            return is_array($result) ? $result : ['success' => false, 'error' => 'non_array_result'];
        };

        /** @var ThemeScopeVersionSelection $sel */
        $sel = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
        $beforeRow = $sel->reset()->clearData()
            ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $storageScope)
            ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersionSelection::schema_fields_AREA, 'frontend')
            ->find()
            ->fetchArray();
        if (is_array($beforeRow) && isset($beforeRow[0]) && is_array($beforeRow[0])) {
            $beforeRow = $beforeRow[0];
        }
        $publishedBefore = (int)($beforeRow['published_version_id'] ?? 0);

        // UC-02 断言「清派生物后 P 不变」必须以真实存在的 P 为基准。
        // 独立测试 scope 是全新的、没有已发布版本，若不先建 P，
        // published_unchanged（0 === 0）就是空转。故先跑一次
        // 创建→封存→发布把正式版本 P 建起来。
        $baselineSteps = ['skipped' => true];
        if ($publishedBefore < 1) {
            $baseline = isolation_run_publish(
                $editor,
                $run,
                $base,
                [
                    'creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS,
                    'force_new' => true,
                ],
                'UC02 baseline P ' . $pageType,
            );
            if (empty($baseline['success'])) {
                out([
                    'success' => false,
                    'step' => 'baseline_publish',
                    'result' => isolation_publish_step_summary($baseline),
                ]);
                exit(1);
            }
            $baselineSteps = isolation_publish_step_summary($baseline);

            $beforeRow = $sel->reset()->clearData()
                ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
                ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $storageScope)
                ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
                ->where(ThemeScopeVersionSelection::schema_fields_AREA, 'frontend')
                ->find()
                ->fetchArray();
            if (is_array($beforeRow) && isset($beforeRow[0]) && is_array($beforeRow[0])) {
                $beforeRow = $beforeRow[0];
            }
            $publishedBefore = (int)($beforeRow['published_version_id'] ?? 0);
        }
        if ($publishedBefore < 1) {
            out([
                'success' => false,
                'step' => 'baseline_publish_missing_published',
                'baseline' => $baselineSteps,
            ]);
            exit(1);
        }

        $created = $run($base + [
            'creation_source_kind' => isolation_creation_source_kind($themeId, $storageScope, $storeMode, $area),
            'force_new' => true,
        ], static fn () => $editor->createScopeDraftPayload());
        if (empty($created['success'])) {
            out(['success' => false, 'step' => 'create_scope_draft', 'result' => $created]);
            exit(1);
        }
        $draftId = (int)($created['data']['theme_version_id'] ?? 0);
        $draftRevision = (int)($created['data']['content_revision'] ?? 1);

        $draftIdentity = new ThemeVersionIdentity(
            $themeId,
            $storageScope,
            $storeMode,
            'frontend',
            $draftId,
            ThemeVersionIdentity::MODE_DRAFT,
            $draftRevision > 0 ? $draftRevision : 1,
        );
        $draftDir = $paths->versionModeDir($draftIdentity);
        $purgedFiles = 0;
        if (is_dir($draftDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($draftDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    @unlink($file->getPathname());
                    $purgedFiles++;
                } elseif ($file->isDir()) {
                    @rmdir($file->getPathname());
                }
            }
        }

        $afterRow = $sel->reset()->clearData()
            ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $storageScope)
            ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersionSelection::schema_fields_AREA, 'frontend')
            ->find()
            ->fetchArray();
        if (is_array($afterRow) && isset($afterRow[0]) && is_array($afterRow[0])) {
            $afterRow = $afterRow[0];
        }
        $publishedAfter = (int)($afterRow['published_version_id'] ?? 0);
        $draftAfter = (int)($afterRow['draft_version_id'] ?? 0);

        /** @var ThemeScopeVersion $versionModel */
        $versionModel = ObjectManager::getInstance(ThemeScopeVersion::class);
        $draftRow = $versionModel->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_ID, $draftId)
            ->find()
            ->fetchArray();
        if (is_array($draftRow) && isset($draftRow[0]) && is_array($draftRow[0])) {
            $draftRow = $draftRow[0];
        }

        out([
            'success' => true,
            'published_before' => $publishedBefore,
            'published_after' => $publishedAfter,
            'draft_version_id' => $draftId,
            'draft_after' => $draftAfter,
            'draft_lifecycle' => trim((string)($draftRow['lifecycle'] ?? '')),
            'draft_content_revision' => (int)($draftRow['content_revision'] ?? 0),
            'draft_dir' => $draftDir,
            'purged_derivative_files' => $purgedFiles,
            'baseline_publish' => $baselineSteps,
            'published_unchanged' => $publishedBefore > 0 && $publishedBefore === $publishedAfter,
            'draft_survived' => $draftAfter === $draftId
                && trim((string)($draftRow['lifecycle'] ?? '')) === ThemeScopeVersion::LIFECYCLE_DRAFT,
        ]);
        exit(0);
    }

    if ($action === 'gc_orphan') {
        $themeId = (int)($payload['theme_id'] ?? 0);
        if ($themeId < 1) {
            fail('theme_id_required');
        }
        // 孤儿必须落在独立测试 scope 下：不得把测试垃圾写进线上 owner 的产物树。
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);

        // 离线 GC 是「全树扫描」：reachable 必须覆盖**全部** selection 行。
        // 旧实现只把单个 owner 放进 reachable，而 sweepOrphanLayoutEntityDerivatives()
        // 枚举的是整棵 entity root —— 于是其它 owner 的可达产物会被当孤儿误删
        // （实测会删掉 website scope 的 tv9 真产物、channel scope 的 tv10）。
        /** @var ThemeScopeVersionSelection $sel */
        $sel = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
        $selectionRows = $sel->reset()->clearData()->select()->fetchArray();
        if (!is_array($selectionRows)) {
            $selectionRows = [];
        }

        $reachable = [];
        $reachableDirs = [];
        foreach ($selectionRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowThemeId = (int)($row[ThemeScopeVersionSelection::schema_fields_THEME_ID] ?? 0);
            $rowScope = trim((string)($row[ThemeScopeVersionSelection::schema_fields_SCOPE] ?? ''));
            $rowStoreMode = trim((string)($row[ThemeScopeVersionSelection::schema_fields_STORE_MODE] ?? ''));
            $rowArea = trim((string)($row[ThemeScopeVersionSelection::schema_fields_AREA] ?? ''));
            if ($rowThemeId < 1 || $rowScope === '' || $rowStoreMode === '' || $rowArea === '') {
                continue;
            }
            $scopeKey = (new ThemeVersionIdentity($rowThemeId, $rowScope, $rowStoreMode, $rowArea))->scopeKey();
            $selected = [
                (int)($row[ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID] ?? 0) => ThemeVersionIdentity::MODE_FORMAL,
                (int)($row[ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID] ?? 0) => ThemeVersionIdentity::MODE_DRAFT,
            ];
            foreach ($selected as $versionId => $mode) {
                if ($versionId < 1) {
                    continue;
                }
                $reachable[] = [
                    'theme_id' => $rowThemeId,
                    'area' => $rowArea,
                    'scope_key' => $scopeKey,
                    'theme_version_id' => $versionId,
                    'mode' => $mode,
                ];
                $reachableIdentity = new ThemeVersionIdentity(
                    $rowThemeId,
                    $rowScope,
                    $rowStoreMode,
                    $rowArea,
                    $versionId,
                    $mode,
                    1,
                );
                $reachableDirs[$rowThemeId . '|' . $rowScope . '|' . $rowStoreMode . '|' . $rowArea . '|' . $versionId . '|' . $mode]
                    = rtrim(str_replace('\\', '/', $paths->versionModeDir($reachableIdentity)), '/');
            }
        }

        // 断言非空转：必须真的存在「可达且已落盘」的版本目录，否则「不误删」无从谈起。
        $protectedExisting = array_values(array_filter($reachableDirs, 'is_dir'));

        $orphanId = 999001;
        $orphanIdentity = new ThemeVersionIdentity(
            $themeId,
            $storageScope,
            $storeMode,
            $area,
            $orphanId,
            ThemeVersionIdentity::MODE_FORMAL,
            1,
        );
        $orphanDir = $paths->versionModeDir($orphanIdentity);
        if (!is_dir($orphanDir) && !@mkdir($orphanDir, 0770, true) && !is_dir($orphanDir)) {
            fail('orphan_dir_create_failed:' . $orphanDir);
        }
        $markerFile = rtrim($orphanDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'orphan-marker.txt';
        file_put_contents($markerFile, 'uc14-orphan');

        /** @var \Weline\Theme\Service\ThemeRuntimeCacheCleaner $cleaner */
        $cleaner = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeCacheCleaner::class);
        $sweep = $cleaner->sweepOrphanLayoutEntityDerivatives($reachable);

        $orphanStillExists = is_dir($orphanDir) || is_file($markerFile);
        $deletedNormalized = array_map(
            static fn ($p) => rtrim(str_replace('\\', '/', (string)$p), '/'),
            $sweep['deleted'] ?? [],
        );
        $orphanNormalized = rtrim(str_replace('\\', '/', $orphanDir), '/');
        // 核心不变量：任何一个「可达且原本存在」的版本目录都不得被删。
        $reachableDirDeleted = array_values(array_filter(
            $protectedExisting,
            static fn (string $dir): bool => in_array($dir, $deletedNormalized, true),
        ));

        out([
            'success' => true,
            'reachable_count' => (int)($sweep['reachable_count'] ?? 0),
            'reachable_dir_total' => count($reachableDirs),
            'reachable_dir_existing' => count($protectedExisting),
            'reachable_dir_deleted' => $reachableDirDeleted,
            'deleted_count' => count($sweep['deleted'] ?? []),
            'kept_count' => count($sweep['kept'] ?? []),
            'orphan_dir' => $orphanDir,
            'orphan_removed' => !$orphanStillExists && in_array($orphanNormalized, $deletedNormalized, true),
            'published_not_deleted' => $reachableDirDeleted === [],
            'sweep' => [
                'deleted_sample' => array_slice($sweep['deleted'] ?? [], 0, 5),
                'kept_sample' => array_slice($sweep['kept'] ?? [], 0, 5),
            ],
        ]);
        exit(0);
    }

    if ($action === 'artifact_reuse') {
        // UC-06 文件复用：未选历史继承时同 hash 仍路径/inode 独立；
        // 显式继承写目标版本不得污染源版本字节；hardlink 不支持时回落 copy。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $marker = trim((string)($payload['marker'] ?? ('REUSE ' . time())));
        if ($themeId < 1 || $pageType === '') {
            fail('theme_id_and_page_type_required');
        }

        $harness = isolation_editor_harness($themeId, $pageType, $storageScope, $storeMode);
        $editor = $harness['editor'];
        $run = $harness['run'];
        $base = $harness['base'];

        $dirFor = static function (int $versionId, string $mode) use ($paths, $themeId, $storageScope, $storeMode): string {
            if ($versionId < 1) {
                return '';
            }
            return $paths->versionModeDir(new ThemeVersionIdentity(
                $themeId,
                $storageScope,
                $storeMode,
                ThemeVersionIdentity::AREA_FRONTEND,
                $versionId,
                $mode,
                1,
            ));
        };

        $widgetParams = [
            'area' => 'content',
            'slot_id' => 'content',
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'theme_component',
            'widget_code' => 'basic/button',
            'config' => ['text' => $marker, 'type' => 'primary', 'size' => 'md'],
            'sort_order' => 0,
            'exclusive' => false,
        ];

        // V1：有基准则从当前 P 续编，独立测试 scope 首次无基准则从 package defaults 起步
        //     → 存部件 → 封存 → 全量发布
        $v1 = isolation_run_publish($editor, $run, $base, [
            'creation_source_kind' => isolation_creation_source_kind($themeId, $storageScope, $storeMode, $area),
            'force_new' => true,
        ], 'REUSE V1 ' . $marker, $widgetParams);
        if (empty($v1['success'])) {
            out(['success' => false, 'step' => 'publish_v1', 'result' => $v1]);
            exit(1);
        }
        $selectionOne = isolation_selection($themeId, $storageScope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND);
        $v1PublishedId = (int)($selectionOne['published_version_id'] ?? 0);
        if ($v1PublishedId < 1) {
            out(['success' => false, 'step' => 'publish_v1_selection', 'selection' => $selectionOne, 'result' => $v1]);
            exit(1);
        }
        $materializer = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer::class);
        $layoutHash = hash('sha256', 'e2e-artifact-reuse:' . $pageType);
        $contentNodes = isolation_content_nodes($marker);

        $v1Dir = $dirFor($v1PublishedId, ThemeVersionIdentity::MODE_FORMAL);
        $v1LayoutPath = isolation_materialize($materializer, $themeId, $storageScope, $storeMode, $v1PublishedId, $layoutHash, $contentNodes, $pageType);
        $v1Fingerprint = isolation_fingerprint_phtml($v1Dir);

        // V2：同 owner 直接续编、不改内容→结构 hash 相同；未选历史继承不得共用 inode。
        $v2 = isolation_run_publish($editor, $run, $base, [
            'creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT,
            'force_new' => true,
        ], 'REUSE V2 ' . $marker);
        if (empty($v2['success'])) {
            out(['success' => false, 'step' => 'publish_v2', 'result' => $v2]);
            exit(1);
        }
        $selectionTwo = isolation_selection($themeId, $storageScope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND);
        $v2PublishedId = (int)($selectionTwo['published_version_id'] ?? 0);
        $v2Dir = $dirFor($v2PublishedId, ThemeVersionIdentity::MODE_FORMAL);
        // 同 owner、同内容、同 mode，仅 V 不同：structureKey 应一致但路径必须各属其版本。
        $v2LayoutPath = isolation_materialize($materializer, $themeId, $storageScope, $storeMode, $v2PublishedId, $layoutHash, $contentNodes, $pageType);
        $v2Fingerprint = isolation_fingerprint_phtml($v2Dir);

        $v1Inodes = array_column($v1Fingerprint, 'inode');
        $v2Inodes = array_column($v2Fingerprint, 'inode');
        $sharedInodes = array_values(array_intersect($v1Inodes, $v2Inodes));
        // 同名相对路径下内容是否一致（证明「同 hash」前提成立）
        $samePathSameBytes = [];
        foreach ($v1Fingerprint as $rel => $row) {
            if (isset($v2Fingerprint[$rel]) && $v2Fingerprint[$rel]['sha256'] === $row['sha256']) {
                $samePathSameBytes[] = $rel;
            }
        }

        // V3：显式历史继承（源 = V1 正式版）→ 封存 → 发布；写 V3 不得动 V1。
        $v3 = isolation_run_publish($editor, $run, $base, [
            'creation_source_kind' => ThemeVersionPublicationService::CREATION_EXPLICIT_HISTORICAL,
            'source_theme_version_id' => $v1PublishedId,
            'force_new' => true,
        ], 'REUSE V3 ' . $marker, $widgetParams);
        if (empty($v3['success'])) {
            out([
                'success' => false,
                'step' => 'publish_v3_explicit_historical',
                'source_theme_version_id' => $v1PublishedId,
                'result' => $v3,
            ]);
            exit(1);
        }
        $selectionThree = isolation_selection($themeId, $storageScope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND);
        $v3PublishedId = (int)($selectionThree['published_version_id'] ?? 0);
        $v3Dir = $dirFor($v3PublishedId, ThemeVersionIdentity::MODE_FORMAL);
        $v3LayoutPath = isolation_materialize($materializer, $themeId, $storageScope, $storeMode, $v3PublishedId, $layoutHash, $contentNodes, $pageType);
        $v3Fingerprint = isolation_fingerprint_phtml($v3Dir);

        // 强制重写 V2：删掉其 layout.phtml 后用变更内容重新物化。
        // 若 V1/V2 共用 inode（隐式硬链），V1 的字节会被改坏——这是本用例要防的核心风险。
        $v1BeforeRewrite = isolation_fingerprint_phtml($v1Dir);
        if ($v2LayoutPath !== '' && is_file($v2LayoutPath)) {
            @unlink($v2LayoutPath);
        }
        $v2RebakedPath = isolation_materialize(
            $materializer,
            $themeId,
            $storageScope,
            $storeMode,
            $v2PublishedId,
            $layoutHash,
            isolation_content_nodes($marker . ' v2-rebake'),
            $pageType,
        );
        $v1AfterRewrite = isolation_fingerprint_phtml($v1Dir);
        $rewritePollution = isolation_fingerprint_diff($v1BeforeRewrite, $v1AfterRewrite);

        // 关键不变量：创建+发布 V3 之后，V1 的字节与 inode 必须完全不变。
        $v1AfterDir = $dirFor($v1PublishedId, ThemeVersionIdentity::MODE_FORMAL);
        $v1After = isolation_fingerprint_phtml($v1AfterDir);
        $pollution = isolation_fingerprint_diff($v1Fingerprint, $v1After);

        $v1InodesAfter = array_column($v1After, 'inode');
        $v3Inodes = array_column($v3Fingerprint, 'inode');
        $v1V3SharedInodes = array_values(array_intersect($v1InodesAfter, $v3Inodes));

        out([
            'success' => true,
            'marker' => $marker,
            'scope' => $storageScope,
            'store_mode' => $storeMode,
            'v1_published_version_id' => $v1PublishedId,
            'v2_published_version_id' => $v2PublishedId,
            'v3_published_version_id' => $v3PublishedId,
            'v1_dir' => $v1Dir,
            'v2_dir' => $v2Dir,
            'v3_dir' => $v3Dir,
            'v1_artifact_count' => count($v1Fingerprint),
            'v2_artifact_count' => count($v2Fingerprint),
            'v3_artifact_count' => count($v3Fingerprint),
            'artifacts_materialized' => $v1Fingerprint !== [],
            // 同 owner/同内容/同 mode，仅 V 不同：结构身份相同但路径必须不同
            'v1_v2_same_structure_key' => isolation_versionless_key($v1LayoutPath, $v1Dir) === isolation_versionless_key($v2LayoutPath, $v2Dir),
            'v1_v3_same_structure_key' => isolation_versionless_key($v1LayoutPath, $v1Dir) === isolation_versionless_key($v3LayoutPath, $v3Dir),
            'v2_rebake_structure_key_changed' => isolation_versionless_key($v2LayoutPath, $v2Dir) !== isolation_versionless_key($v2RebakedPath, $v2Dir),
            'v1_versionless_artifact' => isolation_versionless_key($v1LayoutPath, $v1Dir),
            'v3_versionless_artifact' => isolation_versionless_key($v3LayoutPath, $v3Dir),
            // 路径隔离：三个版本各自目录，且互不相同
            'paths_distinct' => $v1Dir !== $v2Dir && $v2Dir !== $v3Dir && $v1Dir !== $v3Dir,
            // 未选历史继承：V1/V2 不得共用 inode
            'v1_v2_shared_inodes' => $sharedInodes,
            'v1_v2_inode_isolated' => $sharedInodes === [],
            'v1_v2_same_path_same_bytes' => count($samePathSameBytes),
            // 显式历史继承：是否发生硬链（inode 复用）
            'v1_v3_shared_inodes' => $v1V3SharedInodes,
            'explicit_historical_hardlinked' => $v1V3SharedInodes !== [],
            // 写目标不得污染源
            'v1_bytes_changed_after_v3' => $pollution['bytes_changed'],
            'v1_inode_changed_after_v3' => $pollution['inode_changed'],
            'v1_only_before' => $pollution['only_before'],
            'v1_only_after' => $pollution['only_after'],
            'source_untouched' => $pollution['bytes_changed'] === []
                && $pollution['inode_changed'] === []
                && $pollution['only_before'] === []
                && $pollution['only_after'] === [],
            // 强制重烘 V2 之后，V1 仍不得有任何字节/inode 变化
            'v2_rebaked_path' => $v2RebakedPath,
            'v1_bytes_changed_after_v2_rebake' => $rewritePollution['bytes_changed'],
            'v1_inode_changed_after_v2_rebake' => $rewritePollution['inode_changed'],
            'source_untouched_after_target_rebake' => $rewritePollution['bytes_changed'] === []
                && $rewritePollution['inode_changed'] === [],
            'v1_sample' => array_slice($v1Fingerprint, 0, 6, true),
            'publish_evidence' => [
                'v1' => ['draft' => $v1['draft_version_id'], 'seal' => $v1['seal']['success'] ?? null, 'publish' => $v1['publish']['success'] ?? null],
                'v2' => ['draft' => $v2['draft_version_id'], 'seal' => $v2['seal']['success'] ?? null, 'publish' => $v2['publish']['success'] ?? null],
                'v3' => ['draft' => $v3['draft_version_id'], 'seal' => $v3['seal']['success'] ?? null, 'publish' => $v3['publish']['success'] ?? null],
            ],
            'v3_base_version_id' => $v3['create']['data']['base_version_id'] ?? null,
        ]);
        exit(0);
    }

    if ($action === 'scope_propagation_probe') {
        // UC-08 作用范围传播真实通路实测（全部读真实 DB 行 / 真实发布返回值）：
        //  A 写入端祖先基准继承：子无本级覆盖时用祖先已发布版本做基准；
        //  B 无冲突后代版本 C'：继承型子未覆盖的值随父变更进入系统派生版本；
        //  C 本级覆盖保留：覆盖型子在父再发布后版本不变；
        //  D 历史不追今日父版：C' 记录的父来源固定，不随后续父发布漂移；
        //  E 无本级覆盖者用祖先 owner：无版本行的 scope 由祖先 owner 解析出已发布版本。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        $parentScope = trim((string)($payload['parent_scope'] ?? ''));
        $childScope = trim((string)($payload['child_scope'] ?? ''));
        $overrideScope = trim((string)($payload['grandchild_scope'] ?? ''));
        $storeMode = trim((string)($payload['store_mode'] ?? 'normal'));
        $marker = trim((string)($payload['marker'] ?? ('SCOPE ' . time())));
        if ($themeId < 1 || $pageType === '' || $parentScope === '' || $childScope === '' || $overrideScope === '') {
            fail('theme_id_page_type_and_scopes_required');
        }
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = ThemeVersionIdentity::AREA_FRONTEND;

        $widget = static fn(string $text): array => [
            'area' => 'content',
            'slot_id' => 'content',
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'theme_component',
            'widget_code' => 'basic/button',
            'config' => ['text' => $text, 'type' => 'primary', 'size' => 'md'],
            'sort_order' => 0,
            'exclusive' => false,
        ];
        $chromeWidget = static fn(string $text): array => [
            'area' => 'header',
            'slot_id' => 'header',
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'theme_component',
            'widget_code' => 'basic/button',
            'config' => ['text' => $text, 'type' => 'secondary', 'size' => 'sm'],
            'sort_order' => 0,
            'exclusive' => false,
        ];
        $harnessFor = static fn(string $scope): array => isolation_editor_harness($themeId, $pageType, $scope, $storeMode);
        $publishedOf = static fn(string $scope): int => (int)(
            isolation_selection($themeId, $scope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND)['published_version_id'] ?? 0
        );

        // 1) 父（website）首发 P1（package_defaults：新 owner 无基准）。
        $parentHarness = $harnessFor($parentScope);
        $p1 = isolation_run_publish(
            $parentHarness['editor'],
            $parentHarness['run'],
            $parentHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS, 'force_new' => true],
            'P1 ' . $marker,
            $widget($marker . ' p1'),
        );
        $p1Id = $publishedOf($parentScope);

        // E) 尚无任何版本行的 scope：必须由祖先 owner 解析出已发布版本。
        $fallbackResolved = [];
        try {
            $fallbackVersion = ObjectManager::getInstance(\Weline\Theme\Service\ThemeScopeVersionService::class)
                ->getPublished($themeId, $overrideScope, $storeMode, $area);
            if ($fallbackVersion instanceof ThemeScopeVersion) {
                $fallbackResolved = [
                    'version_id' => $fallbackVersion->getVersionId(),
                    'scope' => $fallbackVersion->getScope(),
                    'resolved_from_ancestor' => $fallbackVersion->getScope() !== $overrideScope,
                ];
            }
        } catch (Throwable $fallbackError) {
            $fallbackResolved = ['error' => $fallbackError->getMessage()];
        }

        // 2) 覆盖型子（channel）先发布自己的内容，建立「本级覆盖」。
        $overrideHarness = $harnessFor($overrideScope);
        $overridePublish = isolation_run_publish(
            $overrideHarness['editor'],
            $overrideHarness['run'],
            $overrideHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS, 'force_new' => true],
            'C-own ' . $marker,
            $widget($marker . ' child own'),
        );
        $overrideVersionBefore = $publishedOf($overrideScope);

        // 3) 冲突型子（store）：本级无已发布版本 → 必须回落到父的 P1 做基准。
        //    它只发布 chrome 一个资源：chrome 成为「本级覆盖」，layout 仍是继承来的。
        //    这样父 P2 一旦 chrome 结构变化，它就是「覆盖 chrome + 有未覆盖变更」的后代，
        //    必须落进冲突桶、整套保留 C（不得把页面留在 C、chrome 换成 C'）。
        $childHarness = $harnessFor($childScope);
        $childCreate = $childHarness['run'](
            $childHarness['base'] + [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT,
                'force_new' => true,
            ],
            static fn() => $childHarness['editor']->createScopeDraftPayload(),
        );
        $childCreateData = is_array($childCreate['data'] ?? null) ? $childCreate['data'] : [];
        $childDraftId = (int)($childCreateData['theme_version_id'] ?? 0);
        $childBaseVersionId = (int)($childCreateData['base_version_id'] ?? 0);
        $childBaseSourceScope = (string)($childCreateData['base_source_scope'] ?? '');
        $childBaseInherited = !empty($childCreateData['base_inherited_from_ancestor']);

        $childSeal = $childDraftId > 0
            ? $childHarness['run'](
                $childHarness['base'] + ['theme_version_id' => $childDraftId, 'version_name' => 'C1 ' . $marker],
                static fn() => $childHarness['editor']->saveScopeVersionPayload(),
            )
            : [];
        $childVersionId = (int)($childSeal['data']['theme_version_id'] ?? $childSeal['data']['version_id'] ?? $childDraftId);
        $childPublish = $childVersionId > 0
            ? $childHarness['run'](
                $childHarness['base'] + [
                    'theme_version_id' => $childVersionId,
                    'publish_set' => ['chrome'],
                    'draft_resources' => ['chrome'],
                    'prepared_published_resources' => ['chrome'],
                ],
                static fn() => $childHarness['editor']->publishScopeVersionPayload(),
            )
            : [];
        $childVersionC1 = $publishedOf($childScope);
        $childSnapshotC1 = (static function (int $versionId): array {
            if ($versionId < 1) {
                return [];
            }
            $rows = ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class)->reset()->clearData()
                ->where(ThemeScopeVersionResourceSnapshot::schema_fields_THEME_VERSION_ID, $versionId)
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = json_decode(
                    (string)($row[ThemeScopeVersionResourceSnapshot::schema_fields_RESOURCE_KEY_JSON] ?? ''),
                    true,
                );
                $key = is_array($decoded) ? trim((string)($decoded['resource'] ?? '')) : '';
                if ($key === '') {
                    continue;
                }
                $out[$key] = (string)($row[ThemeScopeVersionResourceSnapshot::schema_fields_SOURCE_FINGERPRINT] ?? '');
            }

            return $out;
        })($childVersionC1);

        // 4) 父 P2：同时改动 layout（内容区部件）与 chrome（header 部件）→ 继承型子未覆盖的
        //    chrome 穿透进系统派生 C'，本级覆盖的 layout 留在后代手里；覆盖 chrome 的冲突型子
        //    因「父 chrome 结构变化」整套保留 C。
        //
        //    注意（2026-09-26 修正）：必须**同时**改两个资源。area=content 与 area=header 是
        //    两个互相独立的指纹（实测：content 只动 layout、header 只动 chrome）。此前这里只保存
        //    内容区部件，却断言 `inherited_resources=['chrome']` 与 `overridden_resources` 含
        //    layout —— 二者同时成立要求 changed_resources 里两个资源都在。旧实现之所以「通过」，
        //    是因为新分配的版本行是空壳（chrome_payload 塌成空数组 sha256('[]')），父自身的
        //    chrome 指纹每次都"变化"，属于把缺陷当预期。
        $p2 = isolation_run_publish(
            $parentHarness['editor'],
            $parentHarness['run'],
            $parentHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT, 'force_new' => true],
            'P2 ' . $marker,
            [$widget($marker . ' p2'), $chromeWidget($marker . ' p2 chrome')],
        );
        $p2Id = $publishedOf($parentScope);
        $childVersionC1p = $publishedOf($childScope);
        $overrideVersionAfter = $publishedOf($overrideScope);
        $childSelAfterP2 = isolation_selection($themeId, $childScope, $storeMode, $area);
        $derivedRevision = isolation_revision_head($overrideVersionAfter);
        $derivedRow = isolation_version_row($overrideVersionAfter);
        $c1Row = isolation_version_row($childVersionC1);

        // P2 这一批后代分类结果：channel 应进 updates（C'），store 应进 conflicts（整套留 C）。
        $p2PublishResult = is_array($p2['publish'] ?? null) ? $p2['publish'] : [];
        $p2Descendants = is_array($p2PublishResult['data']['descendants'] ?? null)
            ? $p2PublishResult['data']['descendants']
            : [];
        $p2UpdateScopes = [];
        $p2InheritedResources = [];
        $p2OverriddenResources = [];
        foreach ((array)($p2Descendants['updates'] ?? []) as $updateRow) {
            if (!is_array($updateRow)) {
                continue;
            }
            $p2UpdateScopes[] = (string)($updateRow['scope'] ?? '');
            if ((string)($updateRow['scope'] ?? '') === $overrideScope) {
                $p2InheritedResources = is_array($updateRow['inherited_resources'] ?? null)
                    ? $updateRow['inherited_resources']
                    : [];
                $p2OverriddenResources = is_array($updateRow['overridden_resources'] ?? null)
                    ? $updateRow['overridden_resources']
                    : [];
            }
        }
        $p2ConflictScopes = [];
        $p2ConflictKept = 0;
        foreach ((array)($p2Descendants['conflicts'] ?? []) as $conflictRow) {
            if (!is_array($conflictRow)) {
                continue;
            }
            $p2ConflictScopes[] = (string)($conflictRow['scope'] ?? '');
            if ((string)($conflictRow['scope'] ?? '') === $childScope) {
                $p2ConflictKept = (int)($conflictRow['kept_version_id'] ?? 0);
            }
        }
        $p2ChangedResources = is_array($p2Descendants['changed_resources'] ?? null)
            ? $p2Descendants['changed_resources']
            : [];

        // 5) 父 P3：再发一次（同样同时改 layout 与 chrome）→ C' 记录的父来源不得漂移
        //    （H 不追今日父版）：P3 会为继承型子再派生一代 C''，但 P2 那一代 C' 记的父来源仍是 P2。
        $p3 = isolation_run_publish(
            $parentHarness['editor'],
            $parentHarness['run'],
            $parentHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT, 'force_new' => true],
            'P3 ' . $marker,
            [$widget($marker . ' p3'), $chromeWidget($marker . ' p3 chrome')],
        );
        $p3Id = $publishedOf($parentScope);
        $overrideVersionAfterP3 = $publishedOf($overrideScope);
        // 同一个派生版本再读一次：它记录的父来源必须还是 P2，而不是最新的 P3。
        // 必须读 P2 那一刻生成的 C' 本身（$overrideVersionAfter），不能读 P3 新生成的 C''——
        // 后者本来就该以 P3 为父，读它证明不了「历史不追今日父版」。
        $derivedRevisionAfterP3 = isolation_revision_head($overrideVersionAfter);

        // 6) 冲突探针：父 chrome 结构变化（P3 之后追加 header 部件）+ 覆盖型子自己占有 chrome。
        $p4 = isolation_run_publish(
            $parentHarness['editor'],
            $parentHarness['run'],
            $parentHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT, 'force_new' => true],
            'P4 ' . $marker,
            $chromeWidget($marker . ' p4 chrome'),
        );
        $p4Id = $publishedOf($parentScope);

        $readFallbackChain = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome::class)
            ->scopeFallbackChain($childScope);

        // 后代枚举必须非空且真的是严格后代：否则「传播已接入」就是空转。
        $propagatorForEvidence = ObjectManager::getInstance(
            \Weline\Theme\Service\Version\ThemeVersionScopePropagator::class,
        );
        $parentOwnerForEvidence = new ThemeVersionIdentity(
            $themeId,
            $parentScope,
            $storeMode,
            ThemeVersionIdentity::AREA_FRONTEND,
            0,
            ThemeVersionIdentity::MODE_FORMAL,
            0,
        );
        $enumeratedDescendantScopes = [];
        foreach ($propagatorForEvidence->enumerateDescendantOwners($parentOwnerForEvidence) as $descendantOwner) {
            $enumeratedDescendantScopes[] = $descendantOwner->canonicalScope;
        }
        $childAncestorChain = $propagatorForEvidence->scopeAncestorChain($childScope);

        out([
            'success' => true,
            'marker' => $marker,
            'scopes' => ['parent' => $parentScope, 'child' => $childScope, 'override_child' => $overrideScope],
            'store_mode' => $storeMode,
            // 后代枚举证据：非空且真的是严格后代，证明传播不是空转。
            'descendant_owners_enumerated' => $enumeratedDescendantScopes,
            'child_ancestor_chain' => $childAncestorChain,
            'child_is_strict_descendant_of_parent' => $propagatorForEvidence->isStrictDescendant($parentScope, $childScope),
            // 真实发布是否成功（非空转）
            'parent_publish_ok' => [
                !empty($p1['success']),
                !empty($p2['success']),
                !empty($p3['success']),
                !empty($p4['success']),
            ],
            'override_child_publish_ok' => !empty($overridePublish['success']),
            'child_publish_ok' => !empty($childPublish['success']),
            'parent_published_versions' => [$p1Id, $p2Id, $p3Id, $p4Id],
            'parent_publish_steps' => [
                'p1' => isolation_publish_step_summary($p1),
                'p2' => isolation_publish_step_summary($p2),
                'p3' => isolation_publish_step_summary($p3),
                'p4' => isolation_publish_step_summary($p4),
            ],
            'child_publish_steps' => isolation_publish_step_summary($childPublish),
            'child_create_steps' => isolation_publish_step_summary($childCreate),
            // E) 读取端祖先回落（既有能力，作为对照）
            'read_fallback_chain' => $readFallbackChain,
            'read_fallback_reaches_ancestor' => in_array($parentScope, $readFallbackChain, true),
            'fallback_resolved_without_local_versions' => $fallbackResolved,
            'fallback_resolved_from_ancestor_owner' => !empty($fallbackResolved['resolved_from_ancestor']),
            // A) 写入端祖先基准继承
            'child_can_inherit_ancestor_base' => !empty($childCreate['success']),
            'child_base_version_id' => $childBaseVersionId,
            'child_base_source_scope' => $childBaseSourceScope,
            'child_base_inherited_from_ancestor' => $childBaseInherited,
            'child_base_is_ancestor_published' => $childBaseVersionId > 0 && $childBaseVersionId === $p1Id,
            'child_base_source_is_parent_scope' => $childBaseSourceScope === $parentScope,
            // 写入端后代传播确实接进了发布响应（不是写死的常量）：以 P2 那一批的
            // rows/plan 是否真实存在为准。
            'write_path_descendant_propagation_wired' => isset($p2Descendants['plan']) && $p2Descendants['plan'] !== [],
            // B) 无冲突后代版本 C'：channel 覆盖 layout、未覆盖 chrome → 父变更穿透到 C'
            'c_prime_child_scope' => $overrideScope,
            'c_prime_version_before_parent_republish' => $overrideVersionBefore,
            'c_prime_version_after_parent_republish' => $overrideVersionAfter,
            'c_prime_got_derived_version' => $overrideVersionAfter > 0 && $overrideVersionAfter !== $overrideVersionBefore,
            'c_prime_inherited_resources' => $p2InheritedResources,
            'c_prime_overridden_resources' => $p2OverriddenResources,
            'c_prime_derived_revision' => [
                'theme_version_id' => (int)($derivedRevision['theme_version_id'] ?? 0),
                'content_revision' => (int)($derivedRevision['content_revision'] ?? 0),
                'base_version_id' => (int)($derivedRevision['base_version_id'] ?? 0),
                'scope_source_version_id' => (int)($derivedRevision['scope_source_version_id'] ?? 0),
                'kind' => (string)($derivedRevision['kind'] ?? ''),
                'actor_id' => (string)($derivedRevision['actor_id'] ?? ''),
            ],
            'c_prime_records_new_parent' => (int)($derivedRevision['scope_source_version_id'] ?? 0) === $p2Id,
            // 本地意图基准必须是 channel 自己原来的那个 C：本级覆盖不因父发布而丢失。
            'c_prime_base_is_previous_child_version' => (int)($derivedRevision['base_version_id'] ?? 0) === $overrideVersionBefore,
            'c_prime_actor_is_system' => (string)($derivedRevision['actor_id'] ?? '') === 'system:scope-propagation',
            'c_prime_lifecycle' => (string)($derivedRow['lifecycle'] ?? ''),
            'c_prime_version_type' => (string)($derivedRow['version_type'] ?? ''),
            // ★ 不变量：C' 是「立即成为后代 owner 的 published」的版本，因此它必须带上
            // 可比的 structure_key —— 否则 structureKeyChanged() 的 `$prevKey !== ''` 守卫会
            // 把「该 owner 的上一已发布版本」判成「结构未变化」，后代里覆盖 chrome 的冲突子
            // 会被误判成可自动前进。C' 的 chrome 是从父 P2 继承来的，故键必须等于 P2 的键。
            'c_prime_structure_key' => (string)($derivedRow['structure_key'] ?? ''),
            'c_prime_inherited_chrome_source_version_id' => $p2Id,
            'c_prime_inherited_chrome_source_structure_key' => (string)(isolation_version_row($p2Id)['structure_key'] ?? ''),
            'c_prime_structure_key_matches_inherited_chrome' => (string)($derivedRow['structure_key'] ?? '') !== ''
                && (string)($derivedRow['structure_key'] ?? '') === (string)(isolation_version_row($p2Id)['structure_key'] ?? ''),
            'c_prime_creation_source_version_id' => (int)($derivedRow['creation_source_version_id'] ?? 0),
            'c_prime_creation_source_is_previous_child_version' => (int)($derivedRow['creation_source_version_id'] ?? 0) === $overrideVersionBefore,
            // C) 冲突子（store）：覆盖 chrome + 有未覆盖变更 + 父 chrome 结构变化 → 整套留 C
            'conflict_child_scope' => $childScope,
            'conflict_child_version_before' => $childVersionC1,
            'conflict_child_version_after' => $childVersionC1p,
            'conflict_child_kept_c' => $childVersionC1 > 0 && $childVersionC1p === $childVersionC1,
            'conflict_child_bucket_scopes' => $p2ConflictScopes,
            'conflict_child_kept_version_id' => $p2ConflictKept,
            'conflict_child_kept_version_matches_c' => $p2ConflictKept === $childVersionC1 && $childVersionC1 > 0,
            'conflict_child_c1_lifecycle' => (string)($c1Row['lifecycle'] ?? ''),
            'conflict_child_snapshot_fingerprints_c1' => $childSnapshotC1,
            'p2_descendant_update_scopes' => $p2UpdateScopes,
            'p2_descendant_changed_resources' => $p2ChangedResources,
            'p2_publish_ok' => !empty($p2['success']),
            // D) 历史不追今日父版
            'c_prime_version_after_second_republish' => $overrideVersionAfterP3,
            'c_prime_got_second_derived_version' => $overrideVersionAfterP3 > 0 && $overrideVersionAfterP3 !== $overrideVersionAfter,
            'derived_revision_after_parent_republish' => [
                'scope_source_version_id' => (int)($derivedRevisionAfterP3['scope_source_version_id'] ?? 0),
                'base_version_id' => (int)($derivedRevisionAfterP3['base_version_id'] ?? 0),
            ],
            'derived_source_unchanged_after_parent_republish' => (int)($derivedRevisionAfterP3['scope_source_version_id'] ?? 0) === $p2Id
                && $p3Id !== $p2Id,
            // F) 父 chrome 结构逐版本可比（冲突判定的唯一跨版本信号）
            'parent_version_after_chrome_change' => $p4Id,
            'parent_structure_keys' => [
                'p1' => (string)(isolation_version_row($p1Id)['structure_key'] ?? ''),
                'p2' => (string)(isolation_version_row($p2Id)['structure_key'] ?? ''),
                'p4' => (string)(isolation_version_row($p4Id)['structure_key'] ?? ''),
            ],
            'parent_p1_p2_structure_changed' => (string)(isolation_version_row($p1Id)['structure_key'] ?? '') !== ''
                && (string)(isolation_version_row($p2Id)['structure_key'] ?? '') !== ''
                && (string)(isolation_version_row($p1Id)['structure_key'] ?? '')
                    !== (string)(isolation_version_row($p2Id)['structure_key'] ?? ''),
            // 观测点：各代父版本的 chrome 载荷节点数。用来判定「P2 到底改没改 chrome 结构」
            // —— P1/P2 用的是 area=content 的页面部件，P4 才用 area=header 的 chrome 部件。
            'parent_chrome_node_counts' => (static function () use ($p1Id, $p2Id, $p4Id): array {
                $countOf = static function (int $id): int {
                    if ($id < 1) {
                        return -1;
                    }
                    $decoded = json_decode((string)(isolation_version_row($id)['chrome_payload_json'] ?? ''), true);

                    return is_array($decoded) ? count($decoded) : 0;
                };

                return ['p1' => $countOf($p1Id), 'p2' => $countOf($p2Id), 'p4' => $countOf($p4Id)];
            })(),
            'parent_p4_fingerprints' => is_array($p4['publish']['data']['artifacts']['fingerprints'] ?? null)
                ? $p4['publish']['data']['artifacts']['fingerprints']
                : [],
            // 观测点：每一步父发布实际动了哪些资源（父自身 prev→new 指纹）。用来判定
            // 「P2 是否真的改了 chrome」「chrome 变化是否连带改动 layout 指纹」。
            'parent_step_fingerprints' => (static function () use ($p1, $p2, $p3, $p4): array {
                $pick = static fn(array $step): array => is_array($step['publish']['data']['artifacts']['fingerprints'] ?? null)
                    ? $step['publish']['data']['artifacts']['fingerprints']
                    : [];

                return ['p1' => $pick($p1), 'p2' => $pick($p2), 'p3' => $pick($p3), 'p4' => $pick($p4)];
            })(),
            'parent_selection_rows' => [
                'p1' => isolation_selection($themeId, $parentScope, $storeMode, $area),
            ],
            'child_selection_rows' => [
                'after_c1_publish' => $childSelAfterP2,
            ],
        ]);
        exit(0);
    }

    if ($action === 'derived_parent_republish') {
        // ★ 缺陷 D（系统派生版本 C' 不带 structure_key ⇒ 冲突判定假阴性）真实通路实测。
        //
        // 场景（3 级 owner）：root（祖先）→ mid（中间）→ leaf（最深，本级覆盖 chrome）。
        //   ① root 首发 P1；
        //   ② mid 建立本级覆盖（自己的 chrome）；
        //   ③ leaf 建立本级 chrome 覆盖（真实 create → 真实保存 header 部件 → seal → publish(chrome)）；
        //   ④ root 再发 P2（同时改 layout 与 chrome）⇒ mid 未覆盖 chrome ⇒ 获系统派生 C'_mid，
        //      且 mid 的 published 指针**立即**指向 C'_mid；
        //   ⑤ ★ 触发点：mid 再发布 —— 此刻 mid 的「上一已发布版本」正是 C'_mid。
        //      若 C'_mid 没有 structure_key，structureKeyChanged(mid, C'_mid, P_mid2) 会因
        //      `$prevKey !== '' && $nextKey !== ''` 守卫返回 false ⇒ 覆盖 chrome 的冲突子 leaf
        //      被误判成「可自动前进」落进 updates 桶，其本级 chrome 覆盖被 C'_leaf 顶掉。
        //
        // 观测全部读真实 DB 行 / 真实发布返回的桶，无自造返回值自证。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        $rootScope = trim((string)($payload['root_scope'] ?? ''));
        $midScope = trim((string)($payload['mid_scope'] ?? ''));
        $leafScope = trim((string)($payload['leaf_scope'] ?? ''));
        $storeMode = trim((string)($payload['store_mode'] ?? 'normal'));
        $marker = trim((string)($payload['marker'] ?? ('DERIVED-PARENT ' . time())));
        if ($themeId < 1 || $pageType === '' || $rootScope === '' || $midScope === '' || $leafScope === '') {
            fail('theme_id_page_type_and_scopes_required');
        }
        $storeMode = $storeMode !== '' ? $storeMode : 'normal';
        $area = ThemeVersionIdentity::AREA_FRONTEND;

        $contentWidget = static fn(string $text): array => [
            'area' => 'content',
            'slot_id' => 'content',
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'theme_component',
            'widget_code' => 'basic/button',
            'config' => ['text' => $text, 'type' => 'primary', 'size' => 'md'],
            'sort_order' => 0,
            'exclusive' => false,
        ];
        $chromeWidget = static fn(string $text): array => [
            'area' => 'header',
            'slot_id' => 'header',
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'theme_component',
            'widget_code' => 'basic/button',
            'config' => ['text' => $text, 'type' => 'secondary', 'size' => 'sm'],
            'sort_order' => 0,
            'exclusive' => false,
        ];
        $harnessFor = static fn(string $scope): array => isolation_editor_harness($themeId, $pageType, $scope, $storeMode);
        $publishedOf = static fn(string $scope): int => (int)(
            isolation_selection($themeId, $scope, $storeMode, $area)['published_version_id'] ?? 0
        );
        $structureKeyOf = static fn(int $versionId): string => $versionId > 0
            ? (string)(isolation_version_row($versionId)['structure_key'] ?? '')
            : '';
        $bucketScopes = static function (array $descendants, string $bucket): array {
            $out = [];
            foreach ((array)($descendants[$bucket] ?? []) as $row) {
                if (is_array($row)) {
                    $out[] = (string)($row['scope'] ?? '');
                }
            }
            return $out;
        };

        // ① root 首发 P1（package_defaults：新 owner 无基准）。
        $rootHarness = $harnessFor($rootScope);
        $p1 = isolation_run_publish(
            $rootHarness['editor'],
            $rootHarness['run'],
            $rootHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS, 'force_new' => true],
            'R1 ' . $marker,
            $contentWidget($marker . ' r1'),
        );
        $p1Id = $publishedOf($rootScope);

        // ② mid 建立本级覆盖：只发布**内容区**部件（publish_set 默认 all 也只保存了 layout）。
        //    关键：mid 必须**不动 chrome**，这样它的 chrome 与祖先 P1 完全相同 ⇒ 判成「继承」，
        //    后续 root 再发布时它才落 updates 桶拿到系统派生 C'_mid。若这里改了 chrome，
        //    chrome 会被判成「本级覆盖」，mid 就会落 conflicts 桶、永远拿不到 C'。
        $midHarness = $harnessFor($midScope);
        $midOwn = isolation_run_publish(
            $midHarness['editor'],
            $midHarness['run'],
            $midHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS, 'force_new' => true],
            'M-own ' . $marker,
            $contentWidget($marker . ' mid own content'),
        );
        $midOwnId = $publishedOf($midScope);

        // ③ leaf 建立本级 chrome 覆盖（真实 create → 真实保存 header 部件 → seal → publish(chrome)）。
        //    必须真存一个 header 部件：否则 leaf 的 chrome 载荷与祖先 P1 完全相同、指纹相同，
        //    会被判定成「继承」而不是「本级覆盖」，整个场景就不成立。
        $leafHarness = $harnessFor($leafScope);
        $leafCreate = $leafHarness['run'](
            $leafHarness['base'] + [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT,
                'force_new' => true,
            ],
            static fn() => $leafHarness['editor']->createScopeDraftPayload(),
        );
        $leafDraftId = (int)($leafCreate['data']['theme_version_id'] ?? 0);
        $leafWidgetSave = $leafDraftId > 0
            ? $leafHarness['run'](
                $leafHarness['base'] + $chromeWidget($marker . ' leaf own chrome'),
                static fn() => $leafHarness['editor']->postSaveWidget(),
            )
            : [];
        $leafSeal = $leafDraftId > 0
            ? $leafHarness['run'](
                $leafHarness['base'] + ['theme_version_id' => $leafDraftId, 'version_name' => 'L-own ' . $marker],
                static fn() => $leafHarness['editor']->saveScopeVersionPayload(),
            )
            : [];
        $leafVersionId = (int)($leafSeal['data']['theme_version_id'] ?? $leafSeal['data']['version_id'] ?? $leafDraftId);
        $leafPublish = $leafVersionId > 0
            ? $leafHarness['run'](
                $leafHarness['base'] + [
                    'theme_version_id' => $leafVersionId,
                    'publish_set' => ['chrome'],
                    'draft_resources' => ['chrome'],
                    'prepared_published_resources' => ['chrome'],
                ],
                static fn() => $leafHarness['editor']->publishScopeVersionPayload(),
            )
            : [];
        isolation_flush_theme_request_cache();
        $leafOwnId = $publishedOf($leafScope);

        // ④ root 再发 P2（同时改 layout 与 chrome）⇒ mid 获 C'_mid；leaf 覆盖 chrome ⇒ 整套留 C。
        $p2 = isolation_run_publish(
            $rootHarness['editor'],
            $rootHarness['run'],
            $rootHarness['base'],
            ['creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT, 'force_new' => true],
            'R2 ' . $marker,
            [$contentWidget($marker . ' r2'), $chromeWidget($marker . ' r2 chrome')],
        );
        $p2Id = $publishedOf($rootScope);
        $midAfterP2 = $publishedOf($midScope);
        $leafAfterP2 = $publishedOf($leafScope);
        $midCPrimeRevision = isolation_revision_head($midAfterP2);
        $midCPrimeRow = isolation_version_row($midAfterP2);
        $p2Descendants = is_array($p2['publish']['data']['descendants'] ?? null)
            ? $p2['publish']['data']['descendants']
            : [];
        $p2ConflictScopes = $bucketScopes($p2Descendants, 'conflicts');
        $p2UpdateScopes = $bucketScopes($p2Descendants, 'updates');

        // ⑤ ★ 触发点：mid 再发布。此刻 mid 的「上一已发布版本」正是 C'_mid。
        //    这里手工展开 create → 存部件 → seal → publish，以便在 publish **之前**读一次
        //    selection.draft_version_id —— 它决定这一步走 publish() 还是 selectHistory()
        //    （后者整段跳过后代传播，会让本用例失去判别力）。
        $midRepublishCreate = $midHarness['run'](
            $midHarness['base'] + [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT,
                'force_new' => true,
            ],
            static fn() => $midHarness['editor']->createScopeDraftPayload(),
        );
        $midRepublishDraftId = (int)($midRepublishCreate['data']['theme_version_id'] ?? 0);
        isolation_flush_theme_request_cache();
        $midSelectionAfterRepublishCreate = isolation_selection($themeId, $midScope, $storeMode, $area);
        $midRepublishWidgetSaves = [];
        foreach ([$contentWidget($marker . ' m2'), $chromeWidget($marker . ' m2 chrome')] as $oneWidget) {
            $oneSave = $midHarness['run'](
                $midHarness['base'] + $oneWidget,
                static fn() => $midHarness['editor']->postSaveWidget(),
            );
            $midRepublishWidgetSaves[] = !empty($oneSave['success']);
        }
        $midRepublishSeal = $midRepublishDraftId > 0
            ? $midHarness['run'](
                $midHarness['base'] + [
                    'theme_version_id' => $midRepublishDraftId,
                    'version_name' => 'M-republish ' . $marker,
                ],
                static fn() => $midHarness['editor']->saveScopeVersionPayload(),
            )
            : [];
        $midRepublishVersionId = (int)(
            $midRepublishSeal['data']['theme_version_id'] ?? $midRepublishSeal['data']['version_id'] ?? $midRepublishDraftId
        );
        isolation_flush_theme_request_cache();
        $midSelectionAfterRepublishSeal = isolation_selection($themeId, $midScope, $storeMode, $area);
        isolation_flush_theme_request_cache();
        $midSelectionBeforeRepublishPublish = isolation_selection($themeId, $midScope, $storeMode, $area);
        $midRepublishPublish = $midRepublishVersionId > 0
            ? $midHarness['run'](
                $midHarness['base'] + ['theme_version_id' => $midRepublishVersionId, 'publish_set' => 'all'],
                static fn() => $midHarness['editor']->publishScopeVersionPayload(),
            )
            : [];
        isolation_flush_theme_request_cache();
        $midRepublish = ['success' => !empty($midRepublishPublish['success']), 'publish' => $midRepublishPublish];
        $midNewId = $publishedOf($midScope);
        $leafAfterMidRepublish = $publishedOf($leafScope);
        $midDescendants = is_array($midRepublish['publish']['data']['descendants'] ?? null)
            ? $midRepublish['publish']['data']['descendants']
            : [];
        $midConflictScopes = $bucketScopes($midDescendants, 'conflicts');
        $midUpdateScopes = $bucketScopes($midDescendants, 'updates');
        $midChangedResources = is_array($midDescendants['changed_resources'] ?? null)
            ? $midDescendants['changed_resources']
            : [];

        out([
            'success' => true,
            'marker' => $marker,
            'scopes' => ['root' => $rootScope, 'mid' => $midScope, 'leaf' => $leafScope],
            // 非空转：五步全部真实成功。
            'step_ok' => [
                'root_p1' => !empty($p1['success']),
                'mid_own' => !empty($midOwn['success']),
                'leaf_own' => !empty($leafPublish['success']),
                'root_p2' => !empty($p2['success']),
                'mid_republish' => !empty($midRepublish['success']),
            ],
            'leaf_widget_saved' => !empty($leafWidgetSave['success']),
            'versions' => [
                'p1' => $p1Id,
                'mid_own' => $midOwnId,
                'leaf_own' => $leafOwnId,
                'p2' => $p2Id,
                'mid_c_prime' => $midAfterP2,
                'mid_new' => $midNewId,
                'leaf_after_p2' => $leafAfterP2,
                'leaf_after_mid_republish' => $leafAfterMidRepublish,
            ],
            // ④ 之后 mid 的 published 必须已经是系统派生版本 C'_mid（否则场景没建立）。
            'mid_got_derived_version_after_root_p2' => $midAfterP2 > 0 && $midAfterP2 !== $midOwnId,
            'mid_c_prime_version_type' => (string)($midCPrimeRow['version_type'] ?? ''),
            'mid_c_prime_lifecycle' => (string)($midCPrimeRow['lifecycle'] ?? ''),
            'mid_c_prime_scope_source_version_id' => (int)($midCPrimeRevision['scope_source_version_id'] ?? 0),
            'mid_c_prime_scope_source_is_p2' => (int)($midCPrimeRevision['scope_source_version_id'] ?? 0) === $p2Id,
            // ★ 不变量：C'_mid 立即成为 mid 的 published，必须带可比 structure_key，
            //   且它继承的是 P2 的 chrome ⇒ 键必须等于 P2 的键。
            'mid_c_prime_structure_key' => $structureKeyOf($midAfterP2),
            'p2_structure_key' => $structureKeyOf($p2Id),
            'mid_c_prime_structure_key_matches_p2' => $structureKeyOf($midAfterP2) !== ''
                && $structureKeyOf($midAfterP2) === $structureKeyOf($p2Id),
            // ④ 的分类（作为对照）：leaf 覆盖 chrome ⇒ 应整套留 C。
            'root_p2_conflict_scopes' => $p2ConflictScopes,
            'root_p2_update_scopes' => $p2UpdateScopes,
            // 诊断：root 的 P2 到底枚举出哪些后代、判定动了哪些资源、逐行分类结果。
            'root_p2_descendant_rows' => is_array($p2Descendants['rows'] ?? null) ? $p2Descendants['rows'] : [],
            'root_p2_changed_resources' => is_array($p2Descendants['changed_resources'] ?? null)
                ? $p2Descendants['changed_resources']
                : [],
            'root_p2_fallback_scopes' => $bucketScopes($p2Descendants, 'fallback'),
            'root_p2_enumerated_scopes' => (static function () use ($themeId, $rootScope, $storeMode): array {
                $propagator = ObjectManager::getInstance(
                    \Weline\Theme\Service\Version\ThemeVersionScopePropagator::class,
                );
                $owner = new ThemeVersionIdentity(
                    $themeId,
                    $rootScope,
                    $storeMode,
                    ThemeVersionIdentity::AREA_FRONTEND,
                    0,
                    ThemeVersionIdentity::MODE_FORMAL,
                    0,
                );
                $scopes = [];
                foreach ($propagator->enumerateDescendantOwners($owner) as $descendantOwner) {
                    $scopes[] = $descendantOwner->canonicalScope;
                }
                return $scopes;
            })(),
            'leaf_kept_c_after_root_p2' => $leafOwnId > 0 && $leafAfterP2 === $leafOwnId,
            // ⑤ 触发点的核心证据：mid 的 prev(上一已发布) 与 new 的 structure_key 都必须非空，
            //    否则 structureKeyChanged() 会直接返回 false（这正是缺陷的机制）。
            'mid_prev_published_before_republish' => $midAfterP2,
            'mid_prev_structure_key_before_republish' => $structureKeyOf($midAfterP2),
            'mid_new_structure_key_after_republish' => $structureKeyOf($midNewId),
            'mid_republish_changed_resources' => $midChangedResources,
            // ★ 行为后果：mid 的 chrome 结构确实变了 ⇒ 覆盖 chrome 的 leaf 必须整套留 C（conflicts），
            //   不得被自动前进到 C'_leaf（那会把 leaf 自己的 chrome 覆盖顶掉）。
            'mid_republish_conflict_scopes' => $midConflictScopes,
            'mid_republish_update_scopes' => $midUpdateScopes,
            // 诊断：mid 再发布这一次到底有没有真的跑后代传播（走 publish() 还是 selectHistory()）。
            'mid_republish_descendant_rows' => is_array($midDescendants['rows'] ?? null) ? $midDescendants['rows'] : null,
            'mid_republish_descendant_plan' => is_array($midDescendants['plan'] ?? null) ? $midDescendants['plan'] : null,
            'mid_republish_draft_id' => $midRepublishDraftId,
            'mid_republish_version_id' => $midRepublishVersionId,
            'mid_republish_widget_saves' => $midRepublishWidgetSaves,
            // ★ 缺陷 F 的证据：封存前后 selection 必须保持「published 不变 + draft 指向新草稿」。
            // 修复前这里是 published 被改成新版本、draft 被清空（bootstrap 把草稿当已发布发布）。
            'mid_selection_after_republish_create' => $midSelectionAfterRepublishCreate,
            'mid_selection_after_republish_seal' => $midSelectionAfterRepublishSeal,
            'mid_selection_before_republish_publish' => $midSelectionBeforeRepublishPublish,
            'mid_republish_kept_draft_pointer' => (int)($midSelectionAfterRepublishSeal['draft_version_id'] ?? 0) === $midRepublishVersionId
                && (int)($midSelectionAfterRepublishSeal['published_version_id'] ?? 0) !== $midRepublishVersionId,
            'mid_republish_went_publish_path' => (int)($midSelectionBeforeRepublishPublish['draft_version_id'] ?? 0) === $midRepublishVersionId
                && $midRepublishVersionId > 0,
            'leaf_bucket_is_conflict_after_mid_republish' => \in_array($leafScope, $midConflictScopes, true),
            'leaf_not_auto_advanced_after_mid_republish' => !\in_array($leafScope, $midUpdateScopes, true),
            'leaf_kept_c_after_mid_republish' => $leafOwnId > 0 && $leafAfterMidRepublish === $leafOwnId,
        ]);
        exit(0);
    }

    if ($action === 'config_only_publish') {
        // UC-10 纯配置：只改配置/资源侧车，关系 PHTML 的 hash 与 mtime 必须不变；
        // 旧请求绑定仍能完成读取，且不混新旧配置。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $marker = trim((string)($payload['marker'] ?? ('CFG ' . time())));
        if ($themeId < 1 || $pageType === '') {
            fail('theme_id_and_page_type_required');
        }

        $harness = isolation_editor_harness($themeId, $pageType, $storageScope, $storeMode);
        $editor = $harness['editor'];
        $run = $harness['run'];
        $base = $harness['base'];

        // 真实发布一个版本（新 owner 用 package_defaults）。
        $published = isolation_run_publish($editor, $run, $base, [
            'creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS,
            'force_new' => true,
        ], 'CFG version ' . $marker);
        if (empty($published['success'])) {
            out(['success' => false, 'step' => 'publish', 'result' => isolation_publish_step_summary($published)]);
            exit(1);
        }
        $selection = isolation_selection($themeId, $storageScope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND);
        $versionId = (int)($selection['published_version_id'] ?? 0);
        if ($versionId < 1) {
            out(['success' => false, 'step' => 'selection', 'selection' => $selection]);
            exit(1);
        }

        $materializer = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer::class);
        $bindingStore = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore::class);
        $layoutHash = hash('sha256', 'e2e-config-only:' . $pageType);
        $uid = hash('sha256', 'e2e-artifact-reuse:' . $marker);
        $nodes = isolation_content_nodes($marker);

        // 1) 固化结构（写 layout.phtml / shell.phtml）
        $layoutPath = isolation_materialize(
            $materializer,
            $themeId,
            $storageScope,
            $storeMode,
            $versionId,
            $layoutHash,
            $nodes,
            $pageType,
        );
        $structureKey = isolation_structure_key_from_path($layoutPath);
        if ($structureKey === '' || !is_file($layoutPath)) {
            out(['success' => false, 'step' => 'materialize', 'layout_path' => $layoutPath]);
            exit(1);
        }

        $identity = new ThemeVersionIdentity(
            $themeId,
            $storageScope,
            $storeMode,
            ThemeVersionIdentity::AREA_FRONTEND,
            $versionId,
            ThemeVersionIdentity::MODE_FORMAL,
            1,
        );

        // 2) 真实写入配置侧车（config A）
        $bindingA = $bindingStore->publishPageBinding($identity, $layoutHash, $structureKey, [
            $uid => ['node_uid' => $uid, 'config' => ['text' => $marker . ' config A']],
        ], [], 0);
        $phtmlBefore = [
            'hash' => (string)hash_file('sha256', $layoutPath),
            'mtime' => (int)@filemtime($layoutPath),
            'inode' => (int)(@stat($layoutPath)['ino'] ?? 0),
        ];
        $configPathA = $bindingA->configPath;
        $configHashA = is_file($configPathA) ? (string)hash_file('sha256', $configPathA) : '';

        // 3) 只改配置（config B）——结构 key 不变。注意：配置内容变化会得到不同的 configKey 目录，
        //    这正是「不混新旧配置」的基础：旧绑定仍指向旧目录。
        $bindingB = $bindingStore->publishPageBinding($identity, $layoutHash, $structureKey, [
            $uid => ['node_uid' => $uid, 'config' => ['text' => $marker . ' config B']],
        ], [], 0);
        $configPathB = $bindingB->configPath;
        $configHashB = is_file($configPathB) ? (string)hash_file('sha256', $configPathB) : '';

        $readTextAt = static function (string $path): string {
            if (!is_file($path)) {
                return '';
            }
            $data = json_decode((string)file_get_contents($path), true);
            if (!is_array($data)) {
                return '';
            }
            $first = reset($data);
            return is_array($first) ? (string)($first['config']['text'] ?? $first['text'] ?? '') : '';
        };
        // 旧绑定（A）与当前绑定（B）各读其目录：严禁混新旧。
        $textViaA = $readTextAt($configPathA);
        $textViaB = $readTextAt($configPathB);

        // 4) 同 nodes 再次固化：既有 PHTML 必须被跳过（mtime/hash/inode 不变）
        $layoutPathAgain = isolation_materialize(
            $materializer,
            $themeId,
            $storageScope,
            $storeMode,
            $versionId,
            $layoutHash,
            $nodes,
            $pageType,
        );
        clearstatcache(true, $layoutPath);
        $phtmlAfter = [
            'hash' => (string)hash_file('sha256', $layoutPath),
            'mtime' => (int)@filemtime($layoutPath),
            'inode' => (int)(@stat($layoutPath)['ino'] ?? 0),
        ];

        // 5) 旧请求绑定仍能完成读取（不为 null）
        $reread = $bindingStore->readPageBinding($identity, $layoutHash);

        out([
            'success' => true,
            'marker' => $marker,
            'scope' => $storageScope,
            'published_version_id' => $versionId,
            'structure_key' => $structureKey,
            'layout_path' => $layoutPath,
            'layout_path_stable' => $layoutPathAgain === $layoutPath,
            'phtml_artifacts' => ['before' => $phtmlBefore, 'after' => $phtmlAfter],
            // 核心断言：只改配置不得重写 PHTML
            'phtml_hash_unchanged' => $phtmlBefore['hash'] === $phtmlAfter['hash'],
            'phtml_mtime_unchanged' => $phtmlBefore['mtime'] === $phtmlAfter['mtime'],
            'phtml_inode_unchanged' => $phtmlBefore['inode'] === $phtmlAfter['inode'],
            // 配置确实变了，且新旧配置各在各自目录（不混）
            'config_key_a' => basename(dirname($configPathA)),
            'config_key_b' => basename(dirname($configPathB)),
            'config_changed' => $configHashA !== '' && $configHashA !== $configHashB,
            'config_paths_distinct' => $configPathA !== $configPathB,
            'text_via_binding_a' => $textViaA,
            'text_via_binding_b' => $textViaB,
            'old_binding_keeps_old_config' => str_contains($textViaA, 'config A') && !str_contains($textViaA, 'config B'),
            'new_binding_keeps_new_config' => str_contains($textViaB, 'config B'),
            'no_mixed_config' => str_contains($textViaA, 'config A') && str_contains($textViaB, 'config B'),
            // 旧请求绑定仍可读取
            'binding_reread_ok' => $reread !== null,
        ]);
        exit(0);
    }

    if ($action === 'injection_fanout') {
        // UC-12 注入扇出（只读选择语义 + 真实重烘调用）：
        // 变更集指定某 layout 时，枚举必须覆盖该 layout 的各 owner（正式/草稿），且排除其它 layout。
        // 注意：InjectionTargets 枚举的是旧 v2 表（workspace/release），只有真实 layout 才有行。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $targetType = trim((string)($payload['page_type'] ?? ''));
        $unrelatedType = trim((string)($payload['unrelated_page_type'] ?? ''));
        if ($themeId < 1 || $targetType === '' || $unrelatedType === '') {
            fail('theme_id_and_two_page_types_required');
        }

        $enumerator = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::class);
        $layoutChange = static fn(string $type): array => [[
            'widget_identity' => ['area' => 'frontend', 'slot' => 'content'],
            'before' => [['layout_type' => $type, 'layout_option' => 'default', 'slot' => 'content']],
            'after' => [['layout_type' => $type, 'layout_option' => 'default', 'slot' => 'content']],
        ]];
        // chrome 槽（header-*/footer-*/delivery-*）不受 layout_type 限定，应波及所有布局。
        $chromeChange = [[
            'widget_identity' => ['area' => 'frontend', 'slot' => 'footer-help-links'],
            'before' => [['layout_type' => '*', 'layout_option' => '*', 'slot' => 'footer-help-links']],
            'after' => [['layout_type' => '*', 'layout_option' => '*', 'slot' => 'footer-help-links']],
        ]];

        // affects() 是公开静态：直接断言选择精度（「不相关布局不重烘」的机制本身）。
        $affects = [
            'target_matches_target' => \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects($layoutChange($targetType), $targetType),
            'target_excludes_unrelated' => !\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects($layoutChange($targetType), $unrelatedType),
            'unrelated_matches_unrelated' => \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects($layoutChange($unrelatedType), $unrelatedType),
            'unrelated_excludes_target' => !\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects($layoutChange($unrelatedType), $targetType),
            'chrome_is_layout_agnostic' => \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects($chromeChange, $targetType)
                && \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects($chromeChange, $unrelatedType),
            'empty_change_affects_all' => \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects([], $targetType),
            'backend_change_skipped' => !\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets::affects([[
                'widget_identity' => ['area' => 'backend', 'slot' => 'content'],
                'before' => [['layout_type' => '*', 'layout_option' => '*', 'slot' => 'content']],
                'after' => [['layout_type' => '*', 'layout_option' => '*', 'slot' => 'content']],
            ]], $targetType),
        ];

        // resolve() 返回的是目标行列表（纯 list），不是含 target_count 的包装。
        $summarize = static function (array $rows): array {
            $types = [];
            $scopes = [];
            $published = 0;
            $draft = 0;
            $resolvedVersions = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = (string)($row['layout_type'] ?? '');
                if ($type !== '') {
                    $types[$type] = true;
                }
                $scope = (string)($row['scope'] ?? '');
                if ($scope !== '') {
                    $scopes[$scope] = true;
                }
                if (!empty($row['published'])) {
                    ++$published;
                } else {
                    ++$draft;
                }
                if (!empty($row['version_resolved'])) {
                    ++$resolvedVersions;
                }
            }
            return [
                'target_count' => count($rows),
                'layout_types' => array_keys($types),
                'scope_count' => count($scopes),
                'published_targets' => $published,
                'draft_targets' => $draft,
                'version_resolved_targets' => $resolvedVersions,
            ];
        };

        $targetFanout = $summarize($enumerator->resolve($layoutChange($targetType), $themeId));
        $unrelatedFanout = $summarize($enumerator->resolve($layoutChange($unrelatedType), $themeId));
        $chromeFanout = $summarize($enumerator->resolve($chromeChange, $themeId));

        // 真实重烘调用（与生产观察者同一入口）：只针对目标布局。
        $coordinator = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class);
        $migrated = $coordinator->rebakeAfterInjectionCollect($themeId, $layoutChange($targetType));
        $report = $coordinator->getLastRebakeReport();

        out([
            'success' => true,
            'theme_id' => $themeId,
            'target_page_type' => $targetType,
            'unrelated_page_type' => $unrelatedType,
            'affects' => $affects,
            'target_fanout' => $targetFanout,
            'unrelated_fanout' => $unrelatedFanout,
            'chrome_fanout' => $chromeFanout,
            // 核心断言 1：选择精度（不相关布局不重烘的机制）
            'selection_precise' => $affects['target_matches_target']
                && $affects['target_excludes_unrelated']
                && $affects['unrelated_excludes_target']
                && $affects['empty_change_affects_all']
                && $affects['backend_change_skipped'],
            // 核心断言 2：目标布局能枚举到真实 owner（非空转）
            'target_fanout_non_empty' => $targetFanout['target_count'] > 0,
            // 核心断言 3：枚举结果只含目标布局，排除不相关
            'target_fanout_excludes_unrelated' => !in_array($unrelatedType, $targetFanout['layout_types'], true),
            'target_fanout_types_are_target_only' => $targetFanout['layout_types'] === [$targetType],
            'unrelated_fanout_excludes_target' => !in_array($targetType, $unrelatedFanout['layout_types'], true),
            // 核心断言 4：同 layout 下正式/草稿 owner 均在覆盖内
            'target_covers_published_or_draft' => $targetFanout['published_targets'] > 0
                || $targetFanout['draft_targets'] > 0,
            // 核心断言 5：chrome 槽不受 layout_type 限定
            'chrome_is_layout_agnostic' => $affects['chrome_is_layout_agnostic']
                && $chromeFanout['target_count'] >= $targetFanout['target_count'],
            'migrated' => $migrated,
            // rebakeAfterInjectionCollect 只重烘 version_resolved 的目标（其余跳过），
            // 故 migrated 应与 version_resolved_targets 一致，而不是全量 fanout。
            'migrated_matches_resolved_fanout' => $migrated === $targetFanout['version_resolved_targets'],
            'report' => $report,
            'report_wellformed' => is_array($report)
                && array_key_exists('migrated', $report)
                && array_key_exists('unmapped', $report),
        ]);
        exit(0);
    }

    if ($action === 'required_default_uninstall') {
        // UC-09 必装/删除的决定权威：决定必按目标版本存储（不得影响源/其它版本），
        // 且能往返读回（供固化时的「不复活」过滤消费）。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $marker = trim((string)($payload['marker'] ?? ('UC09 ' . time())));
        if ($themeId < 1 || $pageType === '') {
            fail('theme_id_and_page_type_required');
        }

        $harness = isolation_editor_harness($themeId, $pageType, $storageScope, $storeMode);
        $editor = $harness['editor'];
        $run = $harness['run'];
        $base = $harness['base'];

        $decisions = ObjectManager::getInstance(\Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService::class);

        // V1：真实发布
        $v1 = isolation_run_publish($editor, $run, $base, [
            'creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS,
            'force_new' => true,
        ], 'UC09 V1 ' . $marker);
        if (empty($v1['success'])) {
            out(['success' => false, 'step' => 'publish_v1', 'result' => isolation_publish_step_summary($v1)]);
            exit(1);
        }
        $sel1 = isolation_selection($themeId, $storageScope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND);
        $v1Id = (int)($sel1['published_version_id'] ?? 0);
        if ($v1Id < 1) {
            out(['success' => false, 'step' => 'selection_v1', 'selection' => $sel1]);
            exit(1);
        }

        // 目标版本解析：决定必须落在 V1
        $resolvedTarget = $decisions->resolveTargetThemeVersionId($themeId, $storageScope);
        $chromeHash = $decisions->chromeResourceIdentityHash($themeId, $storageScope, $storeMode);
        $omissionsBefore = $decisions->listUninstallOmissions($v1Id);

        // 记录一次卸载决定（模拟用户删掉某个必装部件）
        $injectionKey = 'e2e-uc09-' . $marker;
        $slotIdentity = 'footer-help-links';
        $widgetIdentity = 'Weline_Theme|basic/button';
        $recorded = $decisions->recordUninstall($v1Id, 1, $chromeHash, $injectionKey, $slotIdentity, $widgetIdentity);
        $omissionsAfter = $decisions->listUninstallOmissions($v1Id);
        $mine = null;
        foreach ($omissionsAfter as $row) {
            if (is_array($row) && (string)($row['injection_key'] ?? '') === $injectionKey) {
                $mine = $row;
                break;
            }
        }

        // V2：再发一版，验证源/其它版本不受影响
        $v2 = isolation_run_publish($editor, $run, $base, [
            'creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT,
            'force_new' => true,
        ], 'UC09 V2 ' . $marker);
        $sel2 = isolation_selection($themeId, $storageScope, $storeMode, ThemeVersionIdentity::AREA_FRONTEND);
        $v2Id = (int)($sel2['published_version_id'] ?? 0);
        $omissionsV2 = $v2Id > 0 ? $decisions->listUninstallOmissions($v2Id) : [];
        $v2HasMine = false;
        foreach ($omissionsV2 as $row) {
            if (is_array($row) && (string)($row['injection_key'] ?? '') === $injectionKey) {
                $v2HasMine = true;
                break;
            }
        }

        out([
            'success' => true,
            'marker' => $marker,
            'v1_version_id' => $v1Id,
            'v2_version_id' => $v2Id,
            'v2_publish_ok' => !empty($v2['success']),
            // 目标版本解析
            'resolved_target_version_id' => $resolvedTarget,
            'resolve_targets_v1' => $resolvedTarget === $v1Id,
            'chrome_resource_identity_hash' => $chromeHash,
            'chrome_hash_is_sha256' => preg_match('/^[a-f0-9]{64}$/', $chromeHash) === 1,
            // 卸载决定往返
            'recorded' => $recorded,
            'omissions_before_count' => count($omissionsBefore),
            'omissions_after_count' => count($omissionsAfter),
            'omission_roundtrip' => $mine !== null
                && (string)($mine['slot_id'] ?? '') === $slotIdentity
                && (string)($mine['widget_module'] ?? '') === 'Weline_Theme'
                && (string)($mine['widget_code'] ?? '') === 'basic/button',
            'mine' => $mine,
            // 决定按版本章节隔离：其它版本不得拿到该卸载
            'other_version_has_omission' => $v2HasMine,
            'decision_isolated_per_version' => $mine !== null && !$v2HasMine,
        ]);
        exit(0);
    }

    if ($action === 'binding_version_guard') {
        // 缺口 #3：主题绑定必须留在版本外（theme↔version 无循环）。
        // 两处真实通路断言，全部落真实 DB 行、抛真实异常：
        //   1) 模型层 save_before()：绑定行带版本号必须被拒绝，而不是被静默改写成版本外；
        //   2) 服务层 apply() 前置守卫：存量漂移（绑定行被版本占有）必须拒绝绑定补丁。
        // 另设健康对照：未漂移时同一路径不得触发守卫，证明断言不是恒真。
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $themeId = (int)($payload['theme_id'] ?? 0);
        if ($themeId < 1) {
            fail('theme_id_required');
        }

        /** @var \PDO $conn */
        $conn = ObjectManager::getInstance(DbManager::class)->getConnector()->getLink();

        $keyColumn = ThemeScopeWorkspace::schema_fields_BINDING_IDENTITY_KEY;
        $guardMessage = 'theme_binding_must_not_own_theme_version';

        $bindingInput = [
            'editor_context' => [
                'theme_id' => 0,
                'area' => $area,
                'resource_type' => 'theme_binding',
                'scope' => ['storage_scope' => $storageScope, 'store_mode' => $storeMode],
            ],
            'changes' => [['op' => 'set', 'path' => '/theme_id', 'value' => $themeId]],
        ];

        // 绑定行的规范身份键就是上下文的 identityHash（resolveBindingIdentityKey 对绑定原样返回），
        // 所以必须先由工厂算出上下文，才能写出守卫真正会读到的那一行；
        // 否则守卫查不到行、退回「版本外」判定，断言就会空转。
        /** @var ThemeEditorContextFactory $factory */
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
        $bindingContext = $factory->fromInput($bindingInput);
        $identityHash = $bindingContext->identityHash();
        $bindingKey = ThemeScopeWorkspace::resolveBindingIdentityKey('theme_binding', 0, $identityHash);

        // 先清历史残留，保证下面读到的是本次写入的行（防空转）。
        $cleanup = $conn->prepare('DELETE FROM w_theme_scope_workspace WHERE ' . $keyColumn . ' = :k');
        $cleanup->execute([':k' => $bindingKey]);

        // --- 1) 模型层：合法的「绑定 + 版本外」写入必须落盘 ---
        /** @var ThemeScopeWorkspace $row */
        $row = ObjectManager::getInstance(ThemeScopeWorkspace::class);
        $row->clearData()->clearQuery();
        $row->setData([
            ThemeScopeWorkspace::schema_fields_IDENTITY_HASH => $identityHash,
            ThemeScopeWorkspace::schema_fields_SCOPE => $storageScope,
            ThemeScopeWorkspace::schema_fields_SCOPE_KIND => 'store',
            ThemeScopeWorkspace::schema_fields_STORE_MODE => $storeMode,
            ThemeScopeWorkspace::schema_fields_AREA => $area,
            ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE => 'theme_binding',
            ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE => 'default',
            ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION => 'default',
            ThemeScopeWorkspace::schema_fields_LOCALE => 'default',
            ThemeScopeWorkspace::schema_fields_TARGET_TYPE => 'global',
            ThemeScopeWorkspace::schema_fields_TARGET_ID => 0,
            ThemeScopeWorkspace::schema_fields_REVISION => 0,
            ThemeScopeWorkspace::schema_fields_THEME_VERSION_ID => ThemeScopeWorkspace::THEME_VERSION_EXTERNAL,
        ]);
        $row->save();

        $readStmt = $conn->prepare(
            'SELECT workspace_id, theme_version_id, ' . $keyColumn . ' AS binding_key
               FROM w_theme_scope_workspace WHERE ' . $keyColumn . ' = :k'
        );
        $readStmt->execute([':k' => $bindingKey]);
        $persisted = $readStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $workspaceId = (int)($persisted['workspace_id'] ?? 0);
        $persistedVersionId = (int)($persisted['theme_version_id'] ?? -1);
        $persistedKey = (string)($persisted['binding_key'] ?? '');

        // --- 2) 模型层：绑定 + 真实版本号必须抛错 ---
        $modelRejected = false;
        $modelError = '';
        try {
            /** @var ThemeScopeWorkspace $dirty */
            $dirty = ObjectManager::getInstance(ThemeScopeWorkspace::class);
            $dirty->clearData()->clearQuery()->load($workspaceId);
            $dirty->setData(ThemeScopeWorkspace::schema_fields_THEME_VERSION_ID, 42);
            $dirty->save();
        } catch (Throwable $e) {
            $modelRejected = true;
            $modelError = $e->getMessage();
        }

        // 按控制器同一握手取 revision / 父发布再调用 apply()：
        // 否则 apply() 会因「父发布冲突」提前抛错，守卫断言就变成
        // 「因别的错误而拒绝」的空转（曾实测踩到 theme_scope_parent_release_conflict）。
        /** @var ThemeScopedWorkspaceInterface $workspaceSvc */
        $workspaceSvc = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        $bindingState = $workspaceSvc->load($bindingContext, true);
        $bindingInput['expected_revision'] = (int)($bindingState['revision'] ?? 0);
        $bindingInput['expected_parent_release_id'] = $bindingState['expected_parent_release_id'] ?? null;

        // --- 3a) 健康对照：未漂移时守卫不得触发 ---
        /** @var ThemeScopedWorkspaceRequestService $requests */
        $requests = ObjectManager::getInstance(ThemeScopedWorkspaceRequestService::class);
        $healthyError = '';
        try {
            $requests->apply($bindingInput, 'e2e', 'e2e');
        } catch (Throwable $e) {
            $healthyError = $e->getMessage();
        }
        $healthyGuardFired = str_contains($healthyError, $guardMessage);

        // --- 3b) 服务层：绕过模型直接写漂移，真实 apply() 必须拒绝 ---
        // 健康 apply() 可能重建工作区行，故按绑定键重新定位当前行再写漂移，
        // 并把落库结果回读出来，证明漂移确实写进去了（否则断言会空转）。
        $readStmt->execute([':k' => $bindingKey]);
        $current = $readStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $currentWorkspaceId = (int)($current['workspace_id'] ?? 0);

        $driftStmt = $conn->prepare(
            'UPDATE w_theme_scope_workspace SET theme_version_id = 42 WHERE workspace_id = :id'
        );
        $driftStmt->execute([':id' => $currentWorkspaceId]);
        $driftRowsAffected = $driftStmt->rowCount();

        $readStmt->execute([':k' => $bindingKey]);
        $drifted = $readStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $driftedVersionId = (int)($drifted['theme_version_id'] ?? -1);

        $serviceRejected = false;
        $serviceError = '';
        try {
            $requests->apply($bindingInput, 'e2e', 'e2e');
        } catch (Throwable $e) {
            $serviceRejected = true;
            $serviceError = $e->getMessage();
        }

        // 清理本次测试行。
        $cleanup->execute([':k' => $bindingKey]);

        out([
            'success' => true,
            'scope' => $storageScope,
            'workspace_id' => $workspaceId,
            'persisted_version_id' => $persistedVersionId,
            'persisted_binding_key' => $persistedKey,
            'binding_key_has_no_version_prefix' => $persistedKey === $identityHash,
            'model_rejected_binding_with_version' => $modelRejected,
            'model_error' => $modelError,
            'healthy_guard_fired' => $healthyGuardFired,
            'healthy_error' => $healthyError,
            'drift_workspace_id' => $currentWorkspaceId,
            'drift_rows_affected' => $driftRowsAffected,
            'drift_version_id_after_write' => $driftedVersionId,
            'service_rejected_drifted_binding' => $serviceRejected,
            'service_error' => $serviceError,
        ]);
        exit(0);
    }

    if ($action === 'source_upgrade_probe') {
        // UC-11 源升级（真实通路）。五段：
        //   S1 源模板就位 → 独立 scope 走真实控制器通路发布 → 磁盘出产物、结构键含源指纹；
        //   S2 变更源模板（删掉旧默认节点）→ 源文件哈希变化，而节点输入完全不变；
        //   S3 跑真实的 setup:upgrade 清盘观察者 → 磁盘清空、DB 保留（证明没有按 DB 旧结构重放）；
        //   S4 走真实「首访」入口 dynamicSolidifyPublishedPage 重建 → 新源指纹被采纳、用户覆盖保留；
        //   S5 失效操作有诊断（越界 purge 根被拒 / 首访入口非法输入 fail-closed）。
        //
        // 源模板是本探针自建的 `layouts/{probe}/default.phtml`：它只被 sourceLayoutFingerprint()
        // 哈希（不进产物内容），结束时整目录删除，不触碰 design 主题的既有模板。
        // 因此「删旧默认节点」的可观测后果就是「源指纹变 → 结构键变 → 重烘」，这正是系统机制；
        // 源模板正文本身不会被拷进 layout.phtml（固化写的是 slot↔widget 关系壳），故不做「旧节点
        // 不出现在产物里」这类恒真断言。required 默认注入账本一侧由 UC-12 与 required-injection
        // smoke 覆盖，本探针不重复也不伪造。
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $themeId = (int)($payload['theme_id'] ?? 0);
        $probeLayout = trim((string)($payload['probe_layout_type'] ?? ''));
        if ($themeId < 1 || $probeLayout === '') {
            fail('theme_id_and_probe_layout_type_required');
        }

        /** @var \Weline\Theme\Model\WelineTheme $themeModel */
        $themeModel = clone ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
        $themeModel->load($themeId);
        /** @var \Weline\Theme\Service\ThemeDirectoryResolver $resolver */
        $resolver = ObjectManager::getInstance(\Weline\Theme\Service\ThemeDirectoryResolver::class);
        $sourceRoots = [];
        foreach ($resolver->getAreaDirectories($area, $themeModel) as $dir) {
            $candidate = rtrim((string)($dir['path'] ?? ''), '/\\');
            if ($candidate !== '') {
                $sourceRoots[] = $candidate;
            }
        }
        if ($sourceRoots === []) {
            fail('theme_source_roots_missing');
        }
        // sourceLayoutFingerprint() 取「第一个存在该文件的目录」，故探针模板必须落在同一处，
        // 否则它命中的是别人目录里的同名文件，指纹断言就失去意义。
        $probeRoot = $sourceRoots[0];
        $probeDir = $probeRoot . '/layouts/' . $probeLayout;
        $probeFile = $probeDir . '/default.phtml';
        if (is_dir($probeDir)) {
            fail('probe_layout_dir_already_exists:' . $probeLayout);
        }

        $probeSourceV1 = '<section data-e2e-uc11-probe="source-v1">' . "\n"
            . '    <w:slot id="content"></w:slot>' . "\n"
            . '    <span data-e2e-uc11-old-default-node="1">old default node</span>' . "\n"
            . '</section>' . "\n";
        // v2 = 删掉旧默认节点（模拟源模板升级）。
        $probeSourceV2 = '<section data-e2e-uc11-probe="source-v2">' . "\n"
            . '    <w:slot id="content"></w:slot>' . "\n"
            . '</section>' . "\n";

        $removeDirRecursive = static function (string $dir): void {
            if (!is_dir($dir)) {
                return;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($dir);
        };
        $scanContains = static function (string $dir, string $needle): array {
            $hits = [];
            if (!is_dir($dir) || $needle === '') {
                return $hits;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $content = (string)@file_get_contents($entry->getPathname());
                if ($content !== '' && str_contains($content, $needle)) {
                    $hits[] = $entry->getPathname();
                }
            }
            sort($hits);
            return $hits;
        };
        $countFiles = static function (string $dir): int {
            if (!is_dir($dir)) {
                return 0;
            }
            $n = 0;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $entry) {
                if ($entry->isFile()) {
                    ++$n;
                }
            }
            return $n;
        };
        // 同一个版本目录下同时有页面与 chrome 两套结构；源模板指纹只影响**页面**那一套，
        // 所以必须按路径段区分，否则会误取 chrome 键（它对源模板变更恒不变，断言会恒假）。
        // 注意 isolation_fingerprint_phtml() 的相对键不带前导斜杠（$dir 已以 / 结尾），
        // 故比较前统一补一个前导斜杠。
        $structureKeyOf = static function (array $fingerprints, string $segment): string {
            $needle = '/' . $segment . '/';
            foreach (array_keys($fingerprints) as $rel) {
                $hay = '/' . ltrim((string)$rel, '/');
                if (!str_contains($hay, $needle)) {
                    continue;
                }
                $key = isolation_structure_key_from_path($hay);
                if ($key !== '') {
                    return $key;
                }
            }
            return '';
        };
        $allStructureKeys = static function (array $fingerprints): array {
            $keys = [];
            foreach (array_keys($fingerprints) as $rel) {
                $key = isolation_structure_key_from_path((string)$rel);
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
            return array_keys($keys);
        };

        $evidence = [
            'probe_layout_type' => $probeLayout,
            'probe_source_root' => $probeRoot,
            'probe_source_file' => $probeFile,
            'entity_root' => (string)$paths->root(),
        ];
        // 注意：exit() 不会执行 finally，所以这里不用 finally 兜底，
        // 统一「先算完证据 → 清探针目录 → 再输出」，任何早退都必须先过清理。
        $probeDirCreated = false;
        $failure = null;

        // ---- S1：源模板 v1 + 真实发布 ----
        if (!@mkdir($probeDir, 0755, true) && !is_dir($probeDir)) {
            $failure = ['success' => false, 'step' => 'probe_layout_dir_create_failed', 'evidence' => $evidence];
        } else {
            $probeDirCreated = true;
        }

        if ($failure === null) {
            file_put_contents($probeFile, $probeSourceV1);
            clearstatcache(true, $probeFile);
            $evidence['source_exists_before'] = is_file($probeFile);
            $evidence['source_sha_v1'] = (string)hash_file('sha256', $probeFile);

            try {
                $harness = isolation_editor_harness($themeId, $probeLayout, $storageScope, $storeMode, $area);
                $editor = $harness['editor'];
                $run = $harness['run'];
                $base = $harness['base'];

                $marker = 'uc11-' . substr(hash('sha256', $storageScope . '|' . $probeLayout), 0, 12);
                $evidence['user_override_marker'] = $marker;
                $widgetParams = [
                    'area' => 'content',
                    'slot_id' => 'content',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'theme_component',
                    'widget_code' => 'basic/button',
                    'config' => ['text' => $marker, 'type' => 'primary', 'size' => 'md'],
                    'sort_order' => 0,
                    'exclusive' => false,
                ];

                $published = isolation_run_publish($editor, $run, $base, [
                    'creation_source_kind' => isolation_creation_source_kind($themeId, $storageScope, $storeMode, $area),
                    'force_new' => true,
                ], 'UC11 source upgrade ' . $marker, $widgetParams);
                $evidence['publish_v1'] = isolation_publish_step_summary($published);
                if (empty($published['success'])) {
                    $failure = ['success' => false, 'step' => 'publish_v1', 'result' => $published, 'evidence' => $evidence];
                }
            } catch (Throwable $probeError) {
                $failure = [
                    'success' => false,
                    'step' => 's1_publish',
                    'error' => $probeError->getMessage(),
                    'trace' => $probeError->getFile() . ':' . $probeError->getLine(),
                    'evidence' => $evidence,
                ];
            }
        }

        if ($failure === null) {
            try {
                $selection = isolation_selection($themeId, $storageScope, $storeMode, $area);
                $publishedVersionId = (int)($selection['published_version_id'] ?? 0);
                $evidence['published_version_id'] = $publishedVersionId;
                if ($publishedVersionId < 1) {
                    $failure = ['success' => false, 'step' => 'publish_v1_selection', 'selection' => $selection, 'evidence' => $evidence];
                }
            } catch (Throwable $probeError) {
                $failure = [
                    'success' => false,
                    'step' => 's1_selection',
                    'error' => $probeError->getMessage(),
                    'evidence' => $evidence,
                ];
            }
        }

        if ($failure === null) {
            try {
                $formalIdentity = new ThemeVersionIdentity(
                    $themeId,
                    $storageScope,
                    $storeMode,
                    ThemeVersionIdentity::AREA_FRONTEND,
                    $publishedVersionId,
                    ThemeVersionIdentity::MODE_FORMAL,
                    1,
                );
                $formalDir = $paths->versionModeDir($formalIdentity);
                $evidence['version_formal_dir'] = $formalDir;

                $harnessContext = ObjectManager::getInstance(ThemeEditorContextFactory::class)
                    ->fromInput($base['editor_context']);
                $identityHash = (string)$harnessContext->identityHash();
                $evidence['page_identity_hash'] = $identityHash;

                // 从真实产物路径取结构键（产物目录名即结构键），不从发布返回值自证。
                $artifactsV1 = isolation_fingerprint_phtml($formalDir);
                $evidence['artifact_count_v1'] = count($artifactsV1);
                $evidence['structure_keys_v1'] = $allStructureKeys($artifactsV1);
                $evidence['structure_key_v1'] = $structureKeyOf($artifactsV1, 'pages');
                $evidence['chrome_structure_key_v1'] = $structureKeyOf($artifactsV1, 'chrome');
                $evidence['baked_before_purge'] = $evidence['artifact_count_v1'] > 0;

                // 版本持久用户意图（DB）——重建要用的就是它。
                /** @var ThemeScopedWorkspaceInterface $workspaceSvc */
                $workspaceSvc = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
                $workspaceState = $workspaceSvc->load($harnessContext, true);
                $publishedPayload = is_array($workspaceState['published_payload'] ?? null)
                    ? $workspaceState['published_payload']
                    : [];
                $draftPayload = is_array($workspaceState['draft_payload'] ?? null)
                    ? $workspaceState['draft_payload']
                    : [];
                $nodesOf = static function (array $payload): array {
                    $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : $payload;
                    return is_array($nodes) ? $nodes : [];
                };
                $dbNodes = $nodesOf($publishedPayload);
                if ($dbNodes === []) {
                    $dbNodes = $nodesOf($draftPayload);
                }
                $evidence['workspace_state_keys'] = array_keys($workspaceState);
                $evidence['published_payload_keys'] = array_keys($publishedPayload);
                $evidence['draft_payload_keys'] = array_keys($draftPayload);
                $evidence['db_published_node_count'] = count($nodesOf($publishedPayload));
                $evidence['db_draft_node_count'] = count($nodesOf($draftPayload));
                $evidence['db_payload_node_count'] = count($dbNodes);
                $evidence['user_override_in_artifact_before_purge'] = $scanContains($formalDir, $marker) !== [];
                $rebuildNodes = $dbNodes !== [] ? $dbNodes : isolation_content_nodes($marker);
                $evidence['rebuild_nodes_source'] = $dbNodes !== []
                    ? 'db_version_payload'
                    : 'fixture_content_nodes';

                // ---- S2：变更源模板（删掉旧默认节点），节点输入不变 ----
                file_put_contents($probeFile, $probeSourceV2);
                clearstatcache(true, $probeFile);
                $evidence['source_sha_v2'] = (string)hash_file('sha256', $probeFile);
                $evidence['source_hash_changed'] = $evidence['source_sha_v1'] !== $evidence['source_sha_v2'];

                // ---- S3：真实 setup:upgrade 清盘观察者 ----
                \Weline\Theme\Observer\SetupUpgradeAfterPurgeLayoutEntities::resetHasRunFlag();
                $purgeObserver = ObjectManager::getInstance(
                    \Weline\Theme\Observer\SetupUpgradeAfterPurgeLayoutEntities::class,
                );
                $purgeError = '';
                $purgeOutput = '';
                // 观察者会用 Printing 把中文进度直接打到 stdout，而本夹具的契约是
                // 「stdout 就是 JSON」。这里做输出缓冲把进度截下来，既保住契约，
                // 又把这段诊断文本变成可断言的证据。
                ob_start();
                try {
                    // 必须带真实事件名：观察者按 $event->getName() 决定 purge 归因
                    // （Weline_Framework_Setup::upgrade_after → setup_upgrade_layout_entities_invalidated）。
                    // Event::$name 是「无默认值的 typed property」，裸 new Event() 会让 getName() 抛
                    // "must not be accessed before initialization"，清盘根本不执行。
                    $purgeEvent = new \Weline\Framework\Event\Event('Weline_Framework_Setup::upgrade_after');
                    $purgeObserver->execute($purgeEvent);
                } catch (Throwable $purgeThrowable) {
                    $purgeError = $purgeThrowable->getMessage();
                } finally {
                    $purgeOutput = (string)ob_get_clean();
                }
                $evidence['purge_error'] = $purgeError;
                $evidence['purge_output'] = trim($purgeOutput);
                $evidence['artifact_count_after_purge'] = $countFiles($formalDir);
                $evidence['version_formal_dir_gone_after_purge'] = !is_dir($formalDir);

                // DB 必须原样保留（清盘只删派生磁盘产物）。
                $stateAfterPurge = $workspaceSvc->load($harnessContext, true);
                $payloadAfterPurge = is_array($stateAfterPurge['published_payload'] ?? null)
                    ? $stateAfterPurge['published_payload']
                    : [];
                $draftAfterPurge = is_array($stateAfterPurge['draft_payload'] ?? null)
                    ? $stateAfterPurge['draft_payload']
                    : [];
                $nodesAfterPurge = $nodesOf($payloadAfterPurge);
                if ($nodesAfterPurge === []) {
                    $nodesAfterPurge = $nodesOf($draftAfterPurge);
                }
                $evidence['db_payload_node_count_after_purge'] = count($nodesAfterPurge);
                $evidence['db_payload_intact_after_purge'] = $evidence['db_payload_node_count_after_purge']
                    === $evidence['db_payload_node_count'];
                $evidence['selection_intact_after_purge'] = (int)(isolation_selection(
                    $themeId,
                    $storageScope,
                    $storeMode,
                    $area,
                )['published_version_id'] ?? 0) === $publishedVersionId;
                $evidence['version_row_survives_purge'] = (int)(isolation_version_row(
                    $publishedVersionId,
                )['version_id'] ?? 0) === $publishedVersionId;

                // ---- S4：真实「首访」入口重建 ----
                $coordinator = ObjectManager::getInstance(
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
                );
                $rebuiltPath = $coordinator->dynamicSolidifyPublishedPage(
                    $themeId,
                    $storageScope,
                    $identityHash,
                    $probeLayout,
                    $rebuildNodes,
                    null,
                    'default',
                    $area,
                );
                $evidence['rebuilt_path'] = $rebuiltPath;
                $evidence['rebuilt_exists'] = $rebuiltPath !== '' && is_file($rebuiltPath);

                $artifactsV2 = isolation_fingerprint_phtml($formalDir);
                $evidence['artifact_count_v2'] = count($artifactsV2);
                $evidence['structure_keys_v2'] = $allStructureKeys($artifactsV2);
                $evidence['structure_key_v2'] = $structureKeyOf($artifactsV2, 'pages');
                $evidence['chrome_structure_key_v2'] = $structureKeyOf($artifactsV2, 'chrome');
                $evidence['user_override_present_after_rebuild'] = $scanContains($formalDir, $marker) !== [];

                // ---- S5：失效操作有诊断 ----
                $invalidTheme = $coordinator->dynamicSolidifyPublishedPage(0, $storageScope, $identityHash, $probeLayout, $rebuildNodes);
                $invalidScope = $coordinator->dynamicSolidifyPublishedPage($themeId, '', $identityHash, $probeLayout, $rebuildNodes);
                $invalidLayout = $coordinator->dynamicSolidifyPublishedPage($themeId, $storageScope, $identityHash, '', $rebuildNodes);
                $evidence['first_visit_invalid_theme_returns_empty'] = $invalidTheme === '';
                $evidence['first_visit_invalid_scope_returns_empty'] = $invalidScope === '';
                $evidence['first_visit_invalid_layout_returns_empty'] = $invalidLayout === '';

                // 越界 purge 根必须被拒（诊断而非静默删除）。
                $outOfRootRejected = false;
                $outOfRootError = '';
                try {
                    $paths->assertPurgeableEntityRoot(\dirname((string)$paths->root()));
                } catch (Throwable $rootThrowable) {
                    $outOfRootRejected = true;
                    $outOfRootError = $rootThrowable->getMessage();
                }
                $evidence['out_of_root_purge_rejected'] = $outOfRootRejected;
                $evidence['out_of_root_purge_error'] = $outOfRootError;
                $inRootAccepted = false;
                try {
                    $inRootAccepted = $paths->assertPurgeableEntityRoot((string)$paths->root()) !== '';
                } catch (Throwable) {
                    $inRootAccepted = false;
                }
                $evidence['in_root_purge_accepted'] = $inRootAccepted;

                // ---- 汇总断言 ----
                // 页面结构键必须变；chrome 结构键必须**不变** —— 后者排除「任何重烘都会换键」，
                // 把变化唯一归因到源模板指纹。
                $evidence['uc11_source_fingerprint_adopted'] = $evidence['source_hash_changed']
                    && $evidence['structure_key_v1'] !== ''
                    && $evidence['structure_key_v2'] !== ''
                    && $evidence['structure_key_v1'] !== $evidence['structure_key_v2']
                    && $evidence['chrome_structure_key_v1'] !== ''
                    && $evidence['chrome_structure_key_v1'] === $evidence['chrome_structure_key_v2'];
                $evidence['uc11_purge_cleared_disk'] = $evidence['artifact_count_after_purge'] === 0;
                $evidence['uc11_db_survived_purge'] = $evidence['db_payload_intact_after_purge']
                    && $evidence['selection_intact_after_purge']
                    && $evidence['version_row_survives_purge']
                    && $evidence['user_override_present_after_rebuild'];
                $evidence['uc11_first_visit_uses_new_source'] = $evidence['rebuilt_exists']
                    && $evidence['structure_key_v2'] !== ''
                    && $evidence['structure_key_v2'] !== $evidence['structure_key_v1'];
                $evidence['uc11_user_override_preserved'] = $evidence['user_override_present_after_rebuild']
                    && $evidence['user_override_in_artifact_before_purge'];
                $evidence['uc11_invalid_ops_diagnosed'] = $evidence['first_visit_invalid_theme_returns_empty']
                    && $evidence['first_visit_invalid_scope_returns_empty']
                    && $evidence['first_visit_invalid_layout_returns_empty']
                    && $evidence['out_of_root_purge_rejected']
                    && $evidence['in_root_purge_accepted'];

                $evidence['success'] = $evidence['baked_before_purge']
                    && $evidence['purge_error'] === ''
                    && $evidence['uc11_source_fingerprint_adopted']
                    && $evidence['uc11_purge_cleared_disk']
                    && $evidence['uc11_db_survived_purge']
                    && $evidence['uc11_first_visit_uses_new_source']
                    && $evidence['uc11_user_override_preserved']
                    && $evidence['uc11_invalid_ops_diagnosed'];
            } catch (Throwable $probeError) {
                $failure = [
                    'success' => false,
                    'step' => 's2_s5',
                    'error' => $probeError->getMessage(),
                    'trace' => $probeError->getFile() . ':' . $probeError->getLine(),
                    'evidence' => $evidence,
                ];
            }
        }

        if ($probeDirCreated) {
            $removeDirRecursive($probeDir);
        }
        if ($failure !== null) {
            out($failure);
            exit(1);
        }
        out($evidence);
        exit(0);
    }

    if ($action === 'required_default_no_resurrect') {
        // UC-09 完整通路复验：真实必装声明 → 删除 → 刷新/续编/继承均不复活 → 恢复默认复现。
        //
        // 与 required_default_uninstall 的差别：那个用例只验「卸载决定记录/读回 + 按版本隔离」，
        // 且用的是夹具自选的 slot/模块（不匹配任何真实 required 声明），因此证明不了固化端
        // 会不会把必装部件重新塞回槽里 —— 也就证明不了 UC-09 的核心承诺「删除不复活」。
        // 本用例改用真实声明 Weline_Customer|footer-my-account-link → 槽 footer-help-links
        // （部件模板 @widget.default_injections required=true），观测点放在版本 chrome 的
        // config.json 侧车（真实磁盘文件）与 w_theme_scope_version_widget_decision（真实 DB 行）。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $marker = trim((string)($payload['marker'] ?? ('UC09R ' . time())));
        if ($themeId < 1 || $pageType === '') {
            fail('theme_id_and_page_type_required');
        }

        // 必装节点 uid 由固化端按 pageType|slot|module|code 派生（见 RequiredDefaultInjectionContract）。
        //
        // ⚠️ 这里的 pageType 必须是 'homepage' 而不是当前页类型：chrome 固化入口
        // ThemeLayoutEntityBakeCoordinator::bakeChromeFromNodes() 对必装合并**固定**传 'homepage'
        // （「Chrome carrier (homepage) nests footer-*-links …」），所以真实落盘 uid 只可能是
        // homepage 派生值。用其它 page_type 派生出的 uid 在产物里根本不存在，会让后面的
        // 「删除」找不到节点、断言全部空转（实测踩过：合成页类型派生 uid 不在 22 个真实节点中）。
        $slotId = 'footer-help-links';
        $module = 'Weline_Customer';
        $code = 'footer-my-account-link';
        $nodeUid = substr(hash('sha256', 'homepage|' . $slotId . '|' . $module . '|' . $code), 0, 32);

        $harness = isolation_editor_harness($themeId, $pageType, $storageScope, $storeMode, $area);
        $editor = $harness['editor'];
        $run = $harness['run'];
        $base = $harness['base'];

        /** @var ThemeLayoutEntityPaths $paths */
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator $bake */
        $bake = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
        );
        /** @var \Weline\Theme\Service\ThemeScopeVersionService $versions */
        $versions = ObjectManager::getInstance(\Weline\Theme\Service\ThemeScopeVersionService::class);
        /** @var \Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService $decisions */
        $decisions = ObjectManager::getInstance(
            \Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService::class,
        );
        /** @var \Weline\Theme\Service\ThemeChromeWidgetRemovalService $removal */
        $removal = ObjectManager::getInstance(\Weline\Theme\Service\ThemeChromeWidgetRemovalService::class);
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);

        $evidence = [
            'marker' => $marker,
            'page_type' => $pageType,
            'scope' => $storageScope,
            'required_declaration' => [
                'slot_id' => $slotId,
                'widget_module' => $module,
                'widget_code' => $code,
            ],
            'required_node_uid' => $nodeUid,
        ];

        $nodesOf = static fn(int $versionId, string $mode): array => isolation_chrome_nodes_on_disk(
            $paths,
            $themeId,
            $storageScope,
            $storeMode,
            $versionId,
            $mode,
        );
        // DB 侧同口径读数：版本行的 chrome_payload_json。与磁盘侧车互为独立观测点，
        // 用来区分「产物没写」与「载荷本身为空」——后者会让「不复活」断言变成空转。
        $chromeCountOf = static function (int $versionId): int {
            $row = isolation_version_row($versionId);
            $decoded = json_decode((string)($row['chrome_payload_json'] ?? ''), true);
            return is_array($decoded) ? count($decoded) : 0;
        };
        // 版本目录模式由 lifecycle 推导：封存版进 formal，草稿进 draft。
        $modeOf = static function (int $versionId): string {
            $row = isolation_version_row($versionId);
            return (string)($row['lifecycle'] ?? '') === ThemeScopeVersion::LIFECYCLE_SEALED
                ? ThemeVersionIdentity::MODE_FORMAL
                : ThemeVersionIdentity::MODE_DRAFT;
        };
        // 槽内必装部件的「在槽」判定：真实产物里必须有该 uid 且 is_active 非假。
        $presentOf = static function (array $nodes) use ($nodeUid): bool {
            $node = $nodes[$nodeUid] ?? null;
            return is_array($node) && !empty($node['is_active']);
        };
        $decisionOf = static function (int $versionId) use ($decisions, $slotId, $module, $code): ?array {
            if ($versionId < 1) {
                return null;
            }
            foreach ($decisions->listUninstallOmissions($versionId) as $row) {
                if (is_array($row)
                    && (string)($row['slot_id'] ?? '') === $slotId
                    && (string)($row['widget_module'] ?? '') === $module
                    && (string)($row['widget_code'] ?? '') === $code
                ) {
                    return $row;
                }
            }
            return null;
        };
        // 固化端真实的「重烘/刷新」入口：读持久 chrome 载荷 → 合必装 + 过滤卸载 → 原子重写产物。
        $refresh = static function () use ($bake, $versions, $themeId, $storageScope): int {
            isolation_flush_theme_request_cache();
            $current = $versions->getCurrent($themeId, $storageScope);
            if (!$current instanceof ThemeScopeVersion) {
                return 0;
            }
            $bake->bakeChromeFromNodes(
                $themeId,
                $storageScope,
                $current->getChromePayload(),
                true,
                true,
                $current->getVersionId(),
            );
            return $current->getVersionId();
        };
        $selectionOf = static fn(): array => isolation_selection(
            $themeId,
            $storageScope,
            $storeMode,
            ThemeVersionIdentity::AREA_FRONTEND,
        );

        try {
            // ---- S1 无目标决定时 required 入槽 ----
            $v1 = isolation_run_publish($editor, $run, $base, [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS,
                'force_new' => true,
            ], 'UC09R V1 ' . $marker);
            if (empty($v1['success'])) {
                out([
                    'success' => false,
                    'step' => 'publish_v1',
                    'result' => isolation_publish_step_summary($v1),
                    'evidence' => $evidence,
                ]);
                exit(1);
            }
            $v1Id = (int)($selectionOf()['published_version_id'] ?? 0);
            $evidence['v1_version_id'] = $v1Id;
            $evidence['v1_chrome_counts'] = [
                'after_create' => $v1['chrome_count_after_create'] ?? null,
                'after_seal' => $v1['chrome_count_after_seal'] ?? null,
                'after_publish' => $v1['chrome_count_after_publish'] ?? null,
            ];
            // 发布本身只物化版本已有载荷（bakePublishArtifactsForVersion 不做必装合并），
            // 故先记录「发布后」原始状态，再走真实固化入口刷新一次。
            $evidence['v1_node_count_after_publish'] = count($nodesOf($v1Id, $modeOf($v1Id)));
            $evidence['v1_chrome_payload_db_count'] = $chromeCountOf($v1Id);
            $refresh();
            $nodesV1 = $nodesOf($v1Id, $modeOf($v1Id));
            $evidence['v1_node_count'] = count($nodesV1);
            $evidence['v1_slots'] = array_values(array_unique(array_map(
                static fn(array $n): string => (string)($n['slot_id'] ?? ($n['area'] ?? '?')),
                $nodesV1,
            )));
            $evidence['required_present_before_delete'] = $presentOf($nodesV1);
            $evidence['required_node_before_delete'] = $nodesV1[$nodeUid] ?? null;

            // ---- S2 真实删除（编辑器卸载通路）----
            $removeContext = $factory->fromInput(
                $base['editor_context'],
                \Weline\Theme\Api\Scoped\ThemeEditorContext::RESOURCE_LAYOUT,
            );
            $removeResult = null;
            $removeError = '';
            try {
                $removeResult = $removal->remove($removeContext, $nodeUid, 'e2e-uc09-no-resurrect');
            } catch (Throwable $removeException) {
                $removeError = $removeException->getMessage();
            }
            $removeVersionId = is_array($removeResult) ? (int)($removeResult['version_id'] ?? 0) : 0;
            isolation_flush_theme_request_cache();
            $evidence['remove_result'] = $removeResult;
            $evidence['remove_error'] = $removeError;
            $evidence['remove_version_id'] = $removeVersionId;
            $evidence['remove_version_mode'] = $removeVersionId > 0 ? $modeOf($removeVersionId) : '';
            $evidence['remove_decision_row'] = $decisionOf($removeVersionId);
            $evidence['decision_recorded'] = $evidence['remove_decision_row'] !== null;

            // 删除自身已重烘一次；再走一次真实刷新入口，证明「刷新不复活」。
            $refresh();
            $nodesAfterDelete = $nodesOf($removeVersionId, $modeOf($removeVersionId));
            $evidence['node_count_after_delete'] = count($nodesAfterDelete);
            $evidence['required_absent_after_refresh'] = !$presentOf($nodesAfterDelete);
            $evidence['required_node_after_refresh'] = $nodesAfterDelete[$nodeUid] ?? null;
            // 删除只落在目标草稿：已发布 V1 的正式产物不得被改动。
            $nodesV1AfterRemove = $nodesOf($v1Id, $modeOf($v1Id));
            $evidence['published_base_unaffected_by_delete'] = $presentOf($nodesV1AfterRemove)
                && $nodesV1AfterRemove === $nodesV1;

            // ---- S3 续编不复活（真实「继续编辑」修订通路 createRevisionFrom）----
            //
            // 这里用 createRevisionFrom() 而不是控制器 createScopeDraftPayload(continue_current, force_new)：
            // 真实编辑器在**已发布版本**上「继续编辑」走的就是 createRevisionFrom（它同时把 selection
            // 的草稿指针翻到新修订），而 createScopeDraftPayload 走的是「新建版本」语义。
            //
            // 注：2.2.638 起 allocateScopeVersionDraftRow() 也会把基准版本的 chrome_payload 搬过来
            // （缺陷 B 已修），所以「新草稿行 chrome 载荷为空」这个旧理由不再成立；换通路纯粹是为了
            // 对齐真实编辑器的「继续编辑」行为。
            $draftSource = ObjectManager::getInstance(ThemeScopeVersion::class);
            $draftSource->reset()->clearData()->load($removeVersionId);
            $revision = $versions->createRevisionFrom(
                $draftSource,
                'UC09R D2 ' . $marker,
                'e2e-uc09-continue',
            );
            isolation_flush_theme_request_cache();
            $d2Id = $revision->getVersionId();
            $evidence['continue_version_id'] = $d2Id;
            $evidence['continue_from_version_id'] = $removeVersionId;
            $evidence['continue_chrome_payload_db_count'] = $chromeCountOf($d2Id);
            $refresh();
            $nodesD2 = $nodesOf($d2Id, $modeOf($d2Id));
            $evidence['continue_node_count'] = count($nodesD2);
            $evidence['continue_decision_row'] = $decisionOf($d2Id);
            $evidence['continue_node_after_refresh'] = $nodesD2[$nodeUid] ?? null;
            $evidence['continue_required_absent'] = !$presentOf($nodesD2);

            // ---- S4 单页发布不复活（publish_set 非 all，只发布 chrome）----
            $singlePage = $run($base + [
                'theme_version_id' => $d2Id,
                'publish_set' => ['chrome'],
                'draft_resources' => ['chrome'],
                'prepared_published_resources' => ['chrome'],
            ], static fn() => $editor->publishScopeVersionPayload());
            $evidence['single_page_publish_ok'] = !empty($singlePage['success']);
            $evidence['single_page_publish_message'] = (string)($singlePage['message'] ?? '');
            $evidence['single_page_publish_code'] = (string)($singlePage['code'] ?? '');
            $singlePageId = (int)($selectionOf()['published_version_id'] ?? 0);
            $evidence['single_page_version_id'] = $singlePageId;
            if (!empty($singlePage['success'])) {
                $refresh();
                $nodesSingle = $nodesOf($singlePageId, $modeOf($singlePageId));
                $evidence['single_page_node_count'] = count($nodesSingle);
                $evidence['single_page_required_absent'] = !$presentOf($nodesSingle);
            } else {
                $evidence['single_page_node_count'] = 0;
                $evidence['single_page_required_absent'] = null;
            }

            // ---- S5 显式历史继承不复活（从「已带卸载决定的封存版」继承）----
            $inheritSourceId = $singlePageId > 0 ? $singlePageId : $d2Id;
            $sourceNodes = $nodesOf($inheritSourceId, $modeOf($inheritSourceId));
            $evidence['inherit_source_version_id'] = $inheritSourceId;
            $evidence['inherit_source_node_count'] = count($sourceNodes);
            $v3 = isolation_run_publish($editor, $run, $base, [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_EXPLICIT_HISTORICAL,
                'source_theme_version_id' => $inheritSourceId,
                'force_new' => true,
            ], 'UC09R V3 ' . $marker);
            $v3Id = (int)($selectionOf()['published_version_id'] ?? 0);
            $evidence['inherit_version_id'] = $v3Id;
            $evidence['inherit_publish_ok'] = !empty($v3['success']);
            $evidence['inherit_chrome_counts'] = [
                'after_create' => $v3['chrome_count_after_create'] ?? null,
                'after_seal' => $v3['chrome_count_after_seal'] ?? null,
                'after_publish' => $v3['chrome_count_after_publish'] ?? null,
            ];
            $evidence['inherit_chrome_payload_db_count'] = $chromeCountOf($v3Id);
            $evidence['inherit_selection_after_publish'] = $selectionOf();
            $evidence['inherit_current_before_bake'] = $versions->getCurrent($themeId, $storageScope)?->getVersionId();
            // 控制器新分配的历史继承版本行初始 chrome 载荷为空（allocateScopeVersionDraftRow 不复制
            // chrome_payload，且 ensurePublishedChromeForScope 在本 scope 已有已发布 chrome 时提前返回），
            // 真实编辑器随后会把「继承来的 chrome 载荷」写回该版本并重烘。这里用**源版本的真实载荷**
            // 走同一固化入口（bakeChromeFromNodes structural=true），既复现该写回，又顺带验证
            // 「固化端的必装合并 + 用户删除保留不会把删掉的部件复活」。
            isolation_flush_theme_request_cache();
            $bake->bakeChromeFromNodes($themeId, $storageScope, $sourceNodes, true, true, $v3Id);
            $nodesV3 = $nodesOf($v3Id, $modeOf($v3Id));
            $evidence['inherit_node_count'] = count($nodesV3);
            $evidence['inherit_decision_row'] = $decisionOf($v3Id);
            $evidence['inherit_required_node'] = $nodesV3[$nodeUid] ?? null;
            $evidence['inherit_required_absent'] = !$presentOf($nodesV3);
            // 源版本（被继承的版本）自身必须原样：产物节点集合不受影响。
            $evidence['inherit_source_unchanged'] =
                $nodesOf($inheritSourceId, $modeOf($inheritSourceId)) === $sourceNodes;

            // ---- S6 恢复默认后目标项重新出现 ----
            $restore = $run($base, static fn() => $editor->restoreScopeDefaultsPayload());
            $evidence['restore_ok'] = !empty($restore['success']);
            $evidence['restore_message'] = (string)($restore['message'] ?? '');
            $restoreDraftId = (int)($selectionOf()['draft_version_id'] ?? 0);
            $evidence['restore_draft_version_id'] = $restoreDraftId;
            $evidence['restore_decision_before_bake'] = $decisionOf($restoreDraftId);
            // 恢复默认产出的新草稿同样是「空 chrome 载荷 + 无卸载决定」；走真实固化入口
            // （空载荷 + 必装声明 → 重建槽位）。若恢复默认没有清掉卸载决定，这里就会复活失败。
            isolation_flush_theme_request_cache();
            $bake->bakeChromeFromNodes($themeId, $storageScope, [], true, true, $restoreDraftId);
            $nodesRestore = $nodesOf($restoreDraftId, $modeOf($restoreDraftId));
            $evidence['restore_node_count'] = count($nodesRestore);
            $evidence['restore_decision_row'] = $decisionOf($restoreDraftId);
            $evidence['restore_required_node'] = $nodesRestore[$nodeUid] ?? null;
            $evidence['restore_required_present'] = $presentOf($nodesRestore);

            // 断言不得空转：所有产物读数都必须来自真实存在且非空的文件集合。
            $evidence['disk_reads_non_empty'] = $evidence['v1_node_count'] > 0
                && $evidence['node_count_after_delete'] > 0
                && $evidence['continue_node_count'] > 0
                && $evidence['inherit_node_count'] > 0
                && $evidence['restore_node_count'] > 0;

            $evidence['success'] = $evidence['required_present_before_delete']
                && $evidence['decision_recorded']
                && $evidence['required_absent_after_refresh']
                && $evidence['published_base_unaffected_by_delete']
                && $evidence['continue_required_absent']
                && $evidence['single_page_required_absent'] === true
                && $evidence['inherit_required_absent']
                && $evidence['inherit_source_unchanged']
                && $evidence['restore_required_present']
                && $evidence['disk_reads_non_empty'];

            out($evidence);
            exit(0);
        } catch (Throwable $uc09Error) {
            out([
                'success' => false,
                'step' => 'uc09_no_resurrect',
                'error' => $uc09Error->getMessage(),
                'trace' => $uc09Error->getFile() . ':' . $uc09Error->getLine(),
                'evidence' => $evidence,
            ]);
            exit(1);
        }
    }

    if ($action === 'single_page_publish_remainder') {
        // UC-03 单页发布：D 同时改了页面与 chrome，只发布当前页。
        //
        // 与 UC-09 的 S4 差别：那里 draft_resources 只有 ['chrome']，未选中集合为空，
        // 因此**根本不需要 D'**，证明不了 D' 分配通路。这里刻意让未选中集合非空
        // （page + chrome + appearance + theme_binding，只发布 page），才能真正打到
        // publish() 的 single_page_publish_requires_draft_prime_id 分支。
        $themeId = (int)($payload['theme_id'] ?? 0);
        $pageType = trim((string)($payload['page_type'] ?? ''));
        [$storageScope, $storeMode, $area] = isolation_require_dedicated_scope($payload);
        $marker = trim((string)($payload['marker'] ?? ('UC03 ' . time())));
        if ($themeId < 1 || $pageType === '') {
            fail('theme_id_and_page_type_required');
        }

        $slotId = 'footer-help-links';
        $module = 'Weline_Customer';
        $code = 'footer-my-account-link';
        // 必装节点 uid 固定按 'homepage' 派生（bakeChromeFromNodes 对必装合并固定传 homepage）。
        $nodeUid = substr(hash('sha256', 'homepage|' . $slotId . '|' . $module . '|' . $code), 0, 32);
        $pageResource = 'layout:' . $pageType;
        $allResources = [$pageResource, 'chrome', 'appearance', 'theme_binding'];

        $harness = isolation_editor_harness($themeId, $pageType, $storageScope, $storeMode, $area);
        $editor = $harness['editor'];
        $run = $harness['run'];
        $base = $harness['base'];

        /** @var ThemeLayoutEntityPaths $paths */
        $paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator $bake */
        $bake = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
        );
        /** @var \Weline\Theme\Service\ThemeScopeVersionService $versions */
        $versions = ObjectManager::getInstance(\Weline\Theme\Service\ThemeScopeVersionService::class);
        /** @var \Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService $decisions */
        $decisions = ObjectManager::getInstance(
            \Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService::class,
        );
        /** @var \Weline\Theme\Service\ThemeChromeWidgetRemovalService $removal */
        $removal = ObjectManager::getInstance(\Weline\Theme\Service\ThemeChromeWidgetRemovalService::class);
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);

        $evidence = [
            'marker' => $marker,
            'page_type' => $pageType,
            'scope' => $storageScope,
            'page_resource' => $pageResource,
            'required_node_uid' => $nodeUid,
        ];

        $chromeCountOf = static function (int $versionId): int {
            $row = isolation_version_row($versionId);
            $decoded = json_decode((string)($row['chrome_payload_json'] ?? ''), true);
            return is_array($decoded) ? count($decoded) : 0;
        };
        $modeOf = static function (int $versionId): string {
            $row = isolation_version_row($versionId);
            return (string)($row['lifecycle'] ?? '') === ThemeScopeVersion::LIFECYCLE_SEALED
                ? ThemeVersionIdentity::MODE_FORMAL
                : ThemeVersionIdentity::MODE_DRAFT;
        };
        $nodesOf = static fn(int $versionId, string $mode): array => isolation_chrome_nodes_on_disk(
            $paths,
            $themeId,
            $storageScope,
            $storeMode,
            $versionId,
            $mode,
        );
        $activeOf = static function (array $nodes) use ($nodeUid): bool {
            $node = $nodes[$nodeUid] ?? null;
            return is_array($node) && !empty($node['is_active']);
        };
        $decisionOf = static function (int $versionId) use ($decisions, $slotId, $module, $code): ?array {
            if ($versionId < 1) {
                return null;
            }
            foreach ($decisions->listUninstallOmissions($versionId) as $row) {
                if (is_array($row)
                    && (string)($row['slot_id'] ?? '') === $slotId
                    && (string)($row['widget_module'] ?? '') === $module
                    && (string)($row['widget_code'] ?? '') === $code
                ) {
                    return $row;
                }
            }
            return null;
        };
        $refresh = static function () use ($bake, $versions, $themeId, $storageScope): int {
            isolation_flush_theme_request_cache();
            $current = $versions->getCurrent($themeId, $storageScope);
            if (!$current instanceof ThemeScopeVersion) {
                return 0;
            }
            $bake->bakeChromeFromNodes(
                $themeId,
                $storageScope,
                $current->getChromePayload(),
                true,
                true,
                $current->getVersionId(),
            );
            return $current->getVersionId();
        };
        $selectionOf = static fn(): array => isolation_selection(
            $themeId,
            $storageScope,
            $storeMode,
            ThemeVersionIdentity::AREA_FRONTEND,
        );

        try {
            // ---- S1 基线 P0：整版发布，确保 selection.published 有值且 chrome 有真实节点 ----
            $p0 = isolation_run_publish($editor, $run, $base, [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_PACKAGE_DEFAULTS,
                'force_new' => true,
            ], 'UC03 P0 ' . $marker);
            if (empty($p0['success'])) {
                out(['success' => false, 'step' => 'uc03_baseline_publish', 'evidence' => $evidence, 'result' => $p0]);
                exit(1);
            }
            $p0Id = (int)($selectionOf()['published_version_id'] ?? 0);
            $evidence['p0_version_id'] = $p0Id;
            $refresh();
            $nodesP0 = $nodesOf($p0Id, $modeOf($p0Id));
            $evidence['p0_node_count'] = count($nodesP0);
            $evidence['p0_required_present'] = $activeOf($nodesP0);

            // ---- S2 续编成 D（真实控制器 create-scope-draft 通路）----
            $created = $run($base + [
                'creation_source_kind' => ThemeVersionPublicationService::CREATION_CONTINUE_CURRENT,
                'force_new' => true,
            ], static fn() => $editor->createScopeDraftPayload());
            if (empty($created['success'])) {
                out(['success' => false, 'step' => 'uc03_create_draft', 'evidence' => $evidence, 'result' => $created]);
                exit(1);
            }
            $dId = (int)($created['data']['theme_version_id'] ?? 0);
            isolation_flush_theme_request_cache();
            $evidence['d_version_id'] = $dId;
            $evidence['d_chrome_count_after_create'] = $chromeCountOf($dId);
            // 缺陷 B 的观测点：新分配的版本行是否已承接基准版本的 chrome 载荷
            // （allocateScopeVersionDraftRow 以前只建空壳行）。
            $evidence['d_carried_chrome_nodes'] = (int)($created['data']['carried_chrome_nodes'] ?? -1);
            // 基线观测点（不刷新、不写盘）：**刚分配的草稿**在磁盘上有没有烘焙产物。
            // 用来判定「要求 D' 立即有磁盘产物」是否与平台对任何新草稿的既有行为一致。
            $evidence['d_node_count_after_create'] = count($nodesOf($dId, $modeOf($dId)));

            // ---- S3 在 D 上真实卸载必装部件：chrome 载荷带 user_deleted 标记 + 决定落库 ----
            $removeContext = $factory->fromInput(
                $base['editor_context'],
                \Weline\Theme\Api\Scoped\ThemeEditorContext::RESOURCE_LAYOUT,
            );
            $removeResult = null;
            $removeError = '';
            try {
                $removeResult = $removal->remove($removeContext, $nodeUid, 'e2e-uc03-single-page');
            } catch (Throwable $removeException) {
                $removeError = $removeException->getMessage();
            }
            isolation_flush_theme_request_cache();
            $removeVersionId = is_array($removeResult) ? (int)($removeResult['version_id'] ?? 0) : 0;
            $evidence['remove_error'] = $removeError;
            $evidence['remove_version_id'] = $removeVersionId;
            $evidence['d_decision_row'] = $decisionOf($removeVersionId);
            $evidence['d_decision_recorded'] = $evidence['d_decision_row'] !== null;
            $nodesD = $nodesOf($removeVersionId, $modeOf($removeVersionId));
            $evidence['d_node_count_after_remove'] = count($nodesD);
            $evidence['d_required_absent'] = !$activeOf($nodesD);

            // ---- S4 单页发布：只发布当前页，未选中的三项资源留给 D' ----
            $published = $run($base + [
                'theme_version_id' => $removeVersionId,
                'publish_set' => [$pageResource],
                'draft_resources' => $allResources,
                'prepared_published_resources' => $allResources,
            ], static fn() => $editor->publishScopeVersionPayload());
            isolation_flush_theme_request_cache();
            $evidence['publish_ok'] = !empty($published['success']);
            $evidence['publish_message'] = (string)($published['message'] ?? '');
            $evidence['publish_code'] = (string)($published['code'] ?? '');
            $data = is_array($published['data'] ?? null) ? $published['data'] : [];
            $evidence['published_resources'] = $data['published_resources'] ?? null;
            $evidence['remaining_draft_resources'] = $data['remaining_draft_resources'] ?? null;
            $dPrimeId = (int)($data['draft_version_id'] ?? 0);
            $evidence['d_prime_version_id'] = $dPrimeId;
            $selection = $selectionOf();
            $nId = (int)($selection['published_version_id'] ?? 0);
            $evidence['selection_published_version_id'] = $nId;
            $evidence['selection_draft_version_id'] = (int)($selection['draft_version_id'] ?? 0);
            $evidence['d_prime_allocated'] = $dPrimeId > 0 && $dPrimeId !== $removeVersionId;
            $evidence['selection_points_to_d_prime'] = (int)($selection['draft_version_id'] ?? 0) === $dPrimeId;
            $evidence['n_equals_d'] = $nId === $removeVersionId;

            if ($evidence['publish_ok'] && $dPrimeId > 0) {
                // D' 必须承接 chrome 载荷与卸载决定，否则「D' 保留 chrome 及决定」不成立。
                $evidence['d_prime_chrome_count'] = $chromeCountOf($dPrimeId);
                $evidence['d_prime_decision_row'] = $decisionOf($dPrimeId);
                $evidence['d_prime_decision_carried'] = $evidence['d_prime_decision_row'] !== null;
                $nodesDPrime = $nodesOf($dPrimeId, $modeOf($dPrimeId));
                $evidence['d_prime_node_count'] = count($nodesDPrime);
                $evidence['d_prime_required_absent'] = !$activeOf($nodesDPrime);
                $evidence['n_chrome_count'] = $chromeCountOf($nId);
                $nodesN = $nodesOf($nId, $modeOf($nId));
                $evidence['n_node_count'] = count($nodesN);
                // 「其余采用准备时 P」的观测点：本次只发布了 page，chrome 未被选中，
                // 因此 N 的 chrome 产物是否等于 D 的 chrome（含卸载）决定该条是否成立。
                $evidence['n_required_absent'] = !$activeOf($nodesN);
            }

            $evidence['success'] = $evidence['p0_required_present']
                && $evidence['d_decision_recorded']
                && $evidence['d_required_absent']
                && $evidence['publish_ok']
                && $evidence['d_prime_allocated']
                && $evidence['selection_points_to_d_prime']
                && !empty($evidence['d_prime_decision_carried'])
                && !empty($evidence['d_prime_required_absent'])
                && !empty($evidence['n_equals_d']);

            out($evidence);
            exit(0);
        } catch (Throwable $uc03Error) {
            out([
                'success' => false,
                'step' => 'uc03_single_page_publish',
                'error' => $uc03Error->getMessage(),
                'trace' => $uc03Error->getFile() . ':' . $uc03Error->getLine(),
                'evidence' => $evidence,
            ]);
            exit(1);
        }
    }

    fail('unknown_action:' . $action);
} catch (Throwable $e) {
    out(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getFile() . ':' . $e->getLine()]);
    exit(1);
}
