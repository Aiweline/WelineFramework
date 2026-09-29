<?php

declare(strict_types=1);

// 仅观测本轮隔离 owner；缓存操作严格匹配其历史 V/R，不写默认 owner。
require dirname(__DIR__, 7) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;
use Weline\Theme\Model\ThemeScopeVersionResourceSnapshot;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityOwnerLock;
use Weline\Theme\Service\Scoped\ThemeScopedWorkspace;

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$fixturePath = (string)($input['fixture_path'] ?? (getenv('PHTML_FIXTURE_PATH') ?: BP . '/dev/tmp/theme-phtml-solidification/runtime-browser-store.json'));
$evidenceDir = (string)($input['evidence_dir'] ?? dirname($fixturePath));
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$token = (string)($fixture['token'] ?? '');
$identityData = $fixture['identity'] ?? [];
if (!preg_match('/^phtml_[a-z0-9_-]{1,40}$/D', $token)
    || ($identityData['store_code'] ?? '') !== 'e2e_theme_' . $token
    || ($identityData['scope_kind'] ?? '') !== 'channel'
    || (int)($fixture['store_id'] ?? 0) < 1
    || (int)($fixture['channel_id'] ?? 0) < 1
    || (int)($fixture['theme_id'] ?? 0) < 1
) {
    throw new RuntimeException('测试 fixture 必须是独立 e2e_theme_phtml_* 店铺渠道，禁止默认 owner');
}
$action = (string)($input['action'] ?? 'inspect');
if ($action === 'fixture_status') {
    $store = ObjectManager::getInstance(\Weline\Websites\Model\Store::class)->clearData()->clearQuery()->load((int)$fixture['store_id']);
    $channel = ObjectManager::getInstance(\Weline\Websites\Model\SalesChannel::class)->clearData()->clearQuery()->load((int)$fixture['channel_id']);
    $available = $store->getStoreId() === (int)$fixture['store_id']
        && $store->getWebsiteId() === (int)$identityData['website_id']
        && $store->getCode() === $identityData['store_code']
        && $store->getStoreMode() === ($identityData['store_mode'] ?? '')
        && $channel->getChannelId() === (int)$fixture['channel_id']
        && $channel->getWebsiteId() === $store->getWebsiteId()
        && $channel->getStoreId() === $store->getStoreId()
        && $channel->getCode() === $identityData['channel_code'];
    if ($available) {
        $resolved = ObjectManager::getInstance(ScopeHierarchyInterface::class)->contextFromIdentity(ScopeIdentity::fromArray($identityData));
        if ($resolved->storageScope !== (string)($fixture['scope'] ?? '')) {
            throw new RuntimeException('测试 fixture canonical scope 与 typed identity 不一致');
        }
    }
    echo json_encode(['available' => $available, 'reason' => $available ? '' : 'owned_store_or_channel_missing'], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
$scope = ObjectManager::getInstance(ScopeHierarchyInterface::class)
    ->contextFromIdentity(ScopeIdentity::fromArray($fixture['identity']));
if ($scope->storageScope !== (string)($fixture['scope'] ?? '')) {
    throw new RuntimeException('测试 fixture canonical scope 与 typed identity 不一致');
}
$context = new ThemeEditorContext($scope, 'frontend', 'layout', (int)$fixture['theme_id'], 'homepage', 'default');
$owner = new ThemeVersionIdentity((int)$fixture['theme_id'], $scope->storageScope, $scope->storeMode, 'frontend');
$paths = ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
$ownerDir = $paths->ownerDir($owner);
if ($action === 'revision_status') {
    // Model SELECT only: observing reads must never create/initialize a workspace.
    $selections = ObjectManager::getInstance(ThemeScopeVersionSelection::class)->clearQuery()->clearData()
        ->where('theme_id', $owner->themeId)->where('scope', $owner->canonicalScope)
        ->where('store_mode', $owner->storeMode)->where('area', $owner->area)
        ->select()->fetchArray();
    if (count($selections) !== 1 || (int)($selections[0]['draft_version_id'] ?? 0) < 1) {
        throw new RuntimeException('测试 owner 必须已选中唯一真实草稿');
    }
    $versionId = (int)$selections[0]['draft_version_id'];
    $versions = ObjectManager::getInstance(ThemeScopeVersion::class)->clearQuery()->clearData()
        ->where('version_id', $versionId)->where('theme_id', $owner->themeId)
        ->where('scope', $owner->canonicalScope)->where('store_mode', $owner->storeMode)
        ->where('area', $owner->area)->select()->fetchArray();
    $workspaces = ObjectManager::getInstance(ThemeScopeWorkspace::class)->clearQuery()->clearData()
        ->where('theme_version_id', $versionId)->where('theme_id', $owner->themeId)
        ->where('scope', $owner->canonicalScope)->where('store_mode', $owner->storeMode)
        ->where('area', $owner->area)->order('workspace_id', 'ASC')->select()->fetchArray();
    $layouts = array_values(array_filter($workspaces, static fn(array $row): bool =>
        $row['resource_type'] === 'layout' && $row['layout_type'] === 'homepage'
        && $row['layout_option'] === 'default' && $row['locale'] === 'default'
        && $row['target_type'] === 'global' && (int)$row['target_id'] === 0
    ));
    if (count($versions) !== 1 || count($layouts) !== 1) {
        throw new RuntimeException('测试 owner 当前版本/首页 workspace 必须唯一');
    }
    $history = ObjectManager::getInstance(ThemeScopeRevision::class)->clearQuery()->clearData()
        ->where('workspace_id', (int)$layouts[0]['workspace_id'])->select()->fetchArray();
    $historyCounts = [];
    foreach ($history as $revision) {
        $key = (string)$revision['actor_id'] . '|' . (string)($revision['summary'] ?? '');
        $historyCounts[$key] = ($historyCounts[$key] ?? 0) + 1;
    }
    ksort($historyCounts);
    $fields = array_flip(['workspace_id', 'resource_type', 'layout_type', 'layout_option', 'locale', 'target_type', 'target_id', 'revision', 'draft_revision_id']);
    echo json_encode([
        'verification' => 'direct_model_select_no_workspace_load',
        'cursor' => ['version' => $versionId, 'content_revision' => (int)$versions[0]['content_revision'], 'resource_revision' => (int)$layouts[0]['revision']],
        'resources' => array_map(static fn(array $row): array => array_intersect_key($row, $fields), $workspaces),
        'layout_history_counts' => $historyCounts,
        'editor_context' => $context->toArray(), 'owner_dir' => $ownerDir,
        'selection' => array_intersect_key($selections[0], array_flip(['selection_id', 'draft_version_id', 'published_version_id', 'selection_revision'])),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if ($action === 'trace') {
    $requestId = (string)($input['request_id'] ?? '');
    $detail = ObjectManager::getInstance(\Weline\Server\Service\WlsPerformanceTraceStore::class)->getDetail($requestId);
    $complete = (new \Weline\DeveloperWorkspace\Service\DevToolPayloadStore())->get('trace', 'trace:' . $requestId);
    echo json_encode(['request_id' => $requestId, 'detail' => $detail, 'complete_trace' => $complete], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if ($action === 'revision_head') {
    $identity = $owner->withVersion((int)($input['theme_version_id'] ?? 0), 'draft', (int)($input['content_revision'] ?? 0));
    $head = ObjectManager::getInstance(\Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService::class)->head($identity);
    echo json_encode(['identity' => $identity->toArray(), 'head' => $head], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if (in_array($action, ['history_reference_suspend', 'history_reference_restore'], true)) {
    $version = (int)($input['theme_version_id'] ?? 0);
    $revision = (int)($input['content_revision'] ?? 0);
    $versionRow = ObjectManager::getInstance(ThemeScopeVersion::class)->clearData()->clearQuery()->load($version);
    if ($version < 1 || $revision < 1 || $versionRow->getVersionId() !== $version || $versionRow->toVersionIdentity()->ownerHash() !== $owner->ownerHash()) {
        throw new InvalidArgumentException('历史缺引用负例必须属于隔离 owner');
    }
    $backupPath = $evidenceDir . '/history-reference-' . $version . '-' . $revision . '.json';
    $result = ThemeLayoutEntityOwnerLock::write($owner, function () use ($action, $version, $revision, $context, $owner, $backupPath): array {
        if ($action === 'history_reference_restore') {
            $backup = json_decode(file_get_contents($backupPath), true, flags: JSON_THROW_ON_ERROR);
            if (($backup['owner_hash'] ?? '') !== $owner->ownerHash()) {
                throw new RuntimeException('历史负例备份 owner 不匹配');
            }
            $row = $backup['row'];
            $model = ObjectManager::getInstance(ThemeScopeRevision::class)->clearData()->clearQuery();
            $model->setData($row)->save();
            $check = (clone $model)->clearData()->clearQuery()->load((int)$row['revision_id']);
            if ($check->getId() !== (int)$row['revision_id']) {
                throw new RuntimeException('历史引用恢复失败');
            }
            return ['success' => true, 'restored' => true, 'intent_revision_id' => $check->getId(), 'backup_path' => $backupPath];
        }
        $rows = ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class)->clearData()->clearQuery()
            ->where('theme_version_id', $version)->where('content_revision', $revision)
            ->where('resource_identity_hash', $context->identityHash())->select()->fetchArray();
        $snapshot = isset($rows['snapshot_id']) ? $rows : ($rows[0] ?? []);
        $intentId = (int)($snapshot['intent_revision_id'] ?? 0);
        $rowModel = ObjectManager::getInstance(ThemeScopeRevision::class)->clearData()->clearQuery()->load($intentId);
        $workspace = ObjectManager::getInstance(ThemeScopeWorkspace::class)->clearData()->clearQuery()->load((int)$rowModel->getData('workspace_id'));
        if ($intentId < 1 || $rowModel->getId() !== $intentId
            || (string)$workspace->getData('identity_hash') !== $context->identityHash()
            || (int)$workspace->getData('draft_revision_id') === $intentId) {
            throw new RuntimeException('负例只能移除隔离 owner 的非当前历史引用');
        }
        $row = $rowModel->getData();
        file_put_contents($backupPath, json_encode(['owner_hash' => $owner->ownerHash(), 'row' => $row], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $rowModel->clearQuery()->where('revision_id', $intentId)->delete();
        $checkRows = (clone $rowModel)->clearData()->clearQuery()->where('revision_id', $intentId)->select()->fetchArray();
        if ($checkRows !== []) {
            throw new RuntimeException('历史引用删除未落库');
        }
        return ['success' => true, 'suspended' => true, 'intent_revision_id' => $intentId, 'snapshot_id' => $snapshot['snapshot_id'], 'backup_path' => $backupPath];
    });
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if ($action === 'compiled_history') {
    $version = (int)($input['theme_version_id'] ?? 0);
    $revision = (int)($input['content_revision'] ?? 0);
    if ($version < 1 || $revision < 1) {
        throw new InvalidArgumentException('历史编译缓存需要明确 V/R');
    }
    $expected = $owner->withVersion($version, (string)($input['mode'] ?? 'draft'), $revision);
    $roots = [
        BP . '/app/code/Weline/Theme/view/tpl',
        BP . '/app/code/Weline/Index/view/tpl',
        Env::path_framework_generated_complicate . 'Weline/Theme/view',
        Env::path_framework_generated_complicate . 'Weline/Index/view',
        Env::path_framework_generated_complicate . '_unscoped/view',
    ];
    $matched = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink() || !str_starts_with($file->getBasename(), 'com_') || $file->getExtension() !== 'phtml') {
                continue;
            }
            $bytes = file_get_contents($file->getPathname());
            if (!preg_match('/weline-source:([A-Za-z0-9+\/=]+)/', $bytes, $match)) {
                continue;
            }
            $metadata = json_decode((string)base64_decode($match[1], true), true);
            try {
                $actual = ThemeVersionIdentity::fromArray($metadata['identity'] ?? []);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($actual->cacheKey() !== $expected->cacheKey()) {
                continue;
            }
            $matched[] = ['path' => $file->getPathname(), 'sha256' => hash('sha256', $bytes), 'identity' => $actual->toArray()];
            if (!empty($input['delete'])) {
                if (!unlink($file->getPathname())) {
                    throw new RuntimeException('无法清理匹配的隔离历史编译缓存');
                }
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file->getPathname(), true);
                }
            }
        }
    }
    echo json_encode(['success' => true, 'deleted' => !empty($input['delete']), 'identity' => $expected->toArray(), 'roots' => $roots, 'files' => $matched], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if ($action !== 'inspect') {
    throw new InvalidArgumentException('未知测试观测动作');
}
$state = ObjectManager::getInstance(ThemeScopedWorkspace::class)->load($context, true);
$versions = ObjectManager::getInstance(ThemeScopeVersion::class)->clearQuery()->clearData()
    ->where('theme_id', $owner->themeId)->where('scope', $owner->canonicalScope)
    ->where('store_mode', $owner->storeMode)->where('area', $owner->area)
    ->order('version_id', 'DESC')->select()->fetchArray();
$selection = ObjectManager::getInstance(ThemeScopeVersionSelection::class)->clearQuery()->clearData()
    ->where('theme_id', $owner->themeId)->where('scope', $owner->canonicalScope)
    ->where('store_mode', $owner->storeMode)->where('area', $owner->area)
    ->select()->fetchArray();
$files = [];
if (is_dir($ownerDir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ownerDir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        preg_match_all('/[a-f0-9]{32}/', $source, $matches);
        $files[] = [
            'path' => $file->getPathname(), 'extension' => $file->getExtension(),
            'bytes' => $file->getSize(), 'sha256' => hash('sha256', $source),
            'mtime' => $file->getMTime(), 'inode' => $file->getInode(),
            'uids' => array_values(array_unique($matches[0])),
        ];
    }
}
echo json_encode([
    'success' => true, 'fixture' => $fixture, 'editor_context' => $context->toArray(),
    'binding_context' => $context->withResource(ThemeEditorContext::RESOURCE_THEME_BINDING)->toArray(),
    'binding' => ObjectManager::getInstance(ThemeScopedWorkspace::class)->load($context->withResource(ThemeEditorContext::RESOURCE_THEME_BINDING), true),
    'published_binding' => ObjectManager::getInstance(ThemeScopedWorkspace::class)->resolvePublishedTheme($scope, 'frontend'),
    'required_declarations' => \Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract::requiredInjections((new \Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger())->loadDeclarations(), 'homepage'),
    'owner_dir' => $ownerDir, 'state' => $state, 'versions' => $versions,
    'selection' => $selection, 'files' => $files,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
