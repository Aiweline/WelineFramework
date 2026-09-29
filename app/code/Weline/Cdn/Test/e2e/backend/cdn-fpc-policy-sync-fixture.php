<?php
declare(strict_types=1);

// 正式验收辅助：真实公开目录、生产 Service、PostgreSQL 与 Queue；不替换任何被测依赖。
use Weline\Cdn\Service\FpcPolicyManagementService;
use Weline\Cdn\Service\FpcPolicyStateService;
use Weline\Cdn\Queue\FpcPolicySync;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;

$root = dirname(__DIR__, 7);
$input = json_decode((string)file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
$action = (string)($input['action'] ?? '');
if ($action === 'collect_route') {
    // 与bin/w相同的正式独占租约，先于任何应用/数据库初始化；保留至PHP进程退出。
    if (!defined('BP')) define('BP', $root . DIRECTORY_SEPARATOR);
    require_once $root . '/app/code/Weline/Framework/Setup/Lock/SetupDatabaseAccessLock.php';
    $lease = \Weline\Framework\Setup\Lock\SetupDatabaseAccessLock::borrowCliBootstrapExclusiveLease();
    if ($lease === null) {
        if (\Weline\Framework\Setup\Lock\SetupDatabaseAccessLock::borrowCliBootstrapSharedLease() !== null) {
            \Weline\Framework\Setup\Lock\SetupDatabaseAccessLock::releaseCliBootstrapLease();
        }
        $lease = new \Weline\Framework\Setup\Lock\SetupDatabaseAccessLock();
        if (!$lease->acquireExclusive()) {
            echo json_encode(['ok' => false, 'error' => '真实升级租约正在使用，未bootstrap或收集']) . PHP_EOL;
            exit(75);
        }
        \Weline\Framework\Setup\Lock\SetupDatabaseAccessLock::retainCliBootstrapLease($lease);
    }
}
require $root . '/app/bootstrap.php';
$token = (string)($input['token'] ?? '');
if (preg_match('/^fpc-sync-[a-z0-9-]+$/D', $token) !== 1) {
    throw new InvalidArgumentException('验收 token 不合法');
}
$directory = $root . '/dev/team/cdn-fpc-policy-sync/evidence/' . $token;
$manifestPath = $directory . '/fixture-manifest.json';
$sourcePath = $root . '/app/code/Weline/Currency/Controller/Frontend/Index.php';
$om = ObjectManager::getInstance();
$manager = $om->getInstance(FpcPolicyManagementService::class);
$states = $om->getInstance(FpcPolicyStateService::class);
$hierarchy = $om->getInstance(ScopeHierarchyInterface::class);

function fixtureData(array $result): array
{
    if (($result['success'] ?? false) !== true) {
        throw new RuntimeException(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    return $result['data'];
}

function fixturePolicy(FpcPolicyManagementService $manager, string $id, array $cell): array
{
    $data = fixtureData($manager->listPolicies($cell + ['page_size' => 100]));
    foreach ($data['items'] as $row) {
        if ($row['declaration_id'] === $id) {
            return $row;
        }
    }
    throw new RuntimeException('真实声明行不存在：' . $id);
}

function fixturePair(array $row): array
{
    return ['enabled' => $row['override_enabled'], 'ttl' => $row['override_ttl']];
}

function fixtureWriteManifest(string $path, array $manifest): void
{
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
}

function fixtureQueues(): array
{
    $typeId = (int)w_query('queue', 'getTypeIdByClass', ['class' => FpcPolicySync::class]);
    if ($typeId < 1) {
        throw new RuntimeException('正式 FpcPolicySync Queue type 未登记');
    }
    // PM批准的测试只读PG回查：与正式list同筛选，避免CLI不需要的分页HTML渲染。
    $queue = clone ObjectManager::getInstance(\Weline\Queue\Model\Queue::class);
    $queue->clearData()->reset();
    $queue->joinModel(\Weline\Queue\Model\Queue\Type::class, 't', 'main_table.type_id=t.type_id', 'left');
    $queue->where('main_table.type_id', $typeId)
        ->additional('AND (t.enable = 1 OR t.enable IS NULL)')
        ->order('main_table.queue_id', 'DESC')
        ->pagination(1, 100)->select()->fetch();
    $rows = [];
    foreach ($queue->getItems() as $row) {
        if (is_object($row)) {
            $row = $row->getData();
        }
        $content = $row['content'] ?? [];
        if (is_string($content)) {
            $content = json_decode($content, true) ?? [];
        }
        $rows[] = array_intersect_key($row, array_flip(['queue_id', 'type_id', 'status', 'finished', 'biz_key', 'result', 'create_time', 'update_time'])) + ['content' => $content];
    }
    return $rows;
}

if ($action === 'prepare') {
    if (is_file($manifestPath)) {
        throw new RuntimeException('已有验收 manifest，不能覆盖基线');
    }
    $catalog = $om->getInstance(ScopeIdentityCatalogInterface::class)->options();
    $main = null;
    $other = null;
    foreach ($catalog as $website) {
        if ((int)$website['website_id'] === 0 && $website['code'] === 'default') {
            $main = $website;
        }
        if ($website['code'] === 'daocharms') {
            $other = $website;
        }
    }
    if ($main === null) {
        throw new RuntimeException('真实目录缺默认 website=0');
    }
    if ($other === null) {
        foreach ($catalog as $website) {
            if ((int)$website['website_id'] !== (int)$main['website_id']) {
                $other = $website;
                break;
            }
        }
    }
    if ($other === null) {
        throw new RuntimeException('UC2 缺第二真实目录 Scope，不能造假 Scope');
    }
    $store = null;
    foreach ($main['stores'] ?? [] as $candidate) {
        if ($candidate['code'] === 'default' && $candidate['store_mode'] === 'normal' && $candidate['channels'] !== []) {
            $store = $candidate;
            break;
        }
    }
    if ($store === null) {
        throw new RuntimeException('默认 normal 店铺/渠道缺失');
    }
    $channel = current(array_filter($store['channels'], static fn(array $row): bool => $row['code'] === 'default')) ?: $store['channels'][0];
    $scopeObjects = [
        'global' => ScopeIdentity::global(),
        'website_a' => ScopeIdentity::website((int)$main['website_id'], $main['code']),
        'store_a' => ScopeIdentity::store((int)$main['website_id'], $main['code'], $store['code'], 'normal'),
        'channel_a' => ScopeIdentity::channel((int)$main['website_id'], $main['code'], $store['code'], $channel['code'], 'normal'),
        'website_b' => ScopeIdentity::website((int)$other['website_id'], $other['code']),
    ];
    $scopes = [];
    foreach ($scopeObjects as $name => $scope) {
        $scopes[$name] = ['target_scope' => $hierarchy->toStorageScope($scope), 'canonical_key' => $scope->canonicalKey(), 'identity' => $scope->toArray()];
    }
    $id = '5190b7512a5c215958ec75e6e4016bac4780ebce5681b1da181174a1700e7652';
    $cells = [];
    foreach ($scopes as $name => $scope) {
        foreach (in_array($name, ['global', 'website_a', 'website_b'], true) ? ['normal', 'test'] : ['normal'] as $mode) {
            $params = ['target_scope' => $scope['target_scope'], 'store_mode' => $mode];
            $cells[$name . ':' . $mode] = ['params' => $params, 'baseline' => fixturePair(fixturePolicy($manager, $id, $params)), 'owned_pairs' => [], 'touched' => false];
        }
    }
    $source = (string)file_get_contents($sourcePath);
    if (preg_match_all('/^\h*\*\h*@Extra\h+type=fpc\b[^\r\n]*/m', $source, $matches) !== 1) {
        throw new RuntimeException('Currency 源声明不是唯一单行，需归属席核对');
    }
    mkdir($directory, 0775, true);
    file_put_contents($directory . '/currency-source-dirty-baseline.php', $source, LOCK_EX);
    $state = $states->read();
    $manifest = ['token' => $token, 'created_at' => gmdate(DATE_ATOM), 'declaration_id' => $id, 'public_path' => '/currency', 'scopes' => $scopes, 'cells' => $cells, 'initial_desired_version' => $state['desired_version'], 'source' => ['path' => $sourcePath, 'dirty_sha256' => hash('sha256', $source), 'original_hunk' => $matches[0][0], 'last_hunk' => $matches[0][0], 'touched' => false], 'events' => []];
    fixtureWriteManifest($manifestPath, $manifest);
} else {
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
}

if ($action === 'collect_route') {
    $events = $om->getInstance(\Weline\Framework\Event\EventsManager::class);
    $before = [];
    $events->dispatch('Weline_Framework_Setup::before_route_collection', $before);
    $om->getInstance(\Weline\Framework\Router\Service\RouteUpdateService::class)->updateRoutes(['Weline_Currency']);
    $om->getInstance(\Weline\Framework\Module\Helper\Data::class)->flushDeferredControllerAttributes();
    $after = ['touched_modules' => ['Weline_Currency']];
    $events->dispatch('Weline_Framework_Setup::after_route_collection', $after);
    $manifest['events'][] = ['at' => gmdate(DATE_ATOM), 'real_production_route_service' => true, 'module' => 'Weline_Currency', 'pid' => getmypid()];
    fixtureWriteManifest($manifestPath, $manifest);
}

if ($action === 'traces') {
    $store = $om->getInstance(\Weline\Server\Service\WlsPerformanceTraceStore::class);
    $since = (int)($input['since'] ?? strtotime($manifest['created_at']));
    $rows = $store->requests(200, $since, false, 'default');
    $result = [];
    foreach ($rows as $row) {
        if (!in_array(parse_url((string)($row['uri'] ?? ''), PHP_URL_PATH), ['/currency', '/currency/frontend/index'], true)) continue;
        $detail = $store->getDetail((string)$row['request_id']);
        $result[] = ['summary' => array_intersect_key($row, array_flip(['request_id', 'ts', 'uri', 'host', 'status', 'worker_id', 'pid', 'fpc_hit', 'fpc_source'])), 'runtime' => $detail['runtime'] ?? [], 'fpc' => $detail['fpc'] ?? []];
    }
    echo json_encode(['ok' => true, 'observed_at' => gmdate(DATE_ATOM), 'since' => $since, 'records' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

if ($action === 'intent') {
    $cellKey = (string)$input['cell'];
    if (!isset($manifest['cells'][$cellKey])) {
        throw new InvalidArgumentException('不属于本次真实目录的覆盖');
    }
    $expected = $input['expected'];
    if (array_keys($expected) !== ['enabled', 'ttl']) {
        throw new InvalidArgumentException('需准确登记两个本层值以安全清理');
    }
    $manifest['cells'][$cellKey]['owned_pairs'][] = $expected;
    $manifest['cells'][$cellKey]['touched'] = true;
    $manifest['events'][] = ['at' => gmdate(DATE_ATOM), 'intent' => $cellKey, 'expected' => $expected];
    fixtureWriteManifest($manifestPath, $manifest);
}

if ($action === 'declaration') {
    $variant = (string)$input['variant'];
    $original = $manifest['source']['original_hunk'];
    $replacement = match ($variant) {
        'ttl90' => preg_replace('/\bttl=\d+/', 'ttl=90', $original),
        'disabled' => preg_replace('/\benabled=true\b/', 'enabled=false', $original),
        'ttl0' => preg_replace('/\bttl=\d+/', 'ttl=0', $original),
        'path_b' => preg_replace('/\bpublic_path_patterns=\S+/', 'public_path_patterns=/currency-fpc-acceptance-b', $original),
        'path_c' => preg_replace('/\bpublic_path_patterns=\S+/', 'public_path_patterns=/currency-fpc-acceptance-c', $original),
        'canonical_path' => preg_replace('/\bpublic_path_patterns=\S+/', 'public_path_patterns=/currency/frontend/index', $original),
        'withdrawn' => ' * 本机 FPC 验收暂时撤回此声明，原文保存在本次验收记录。',
        'original' => $original,
        default => throw new InvalidArgumentException('未批准的声明变体'),
    };
    $current = (string)file_get_contents($sourcePath);
    $last = $manifest['source']['last_hunk'];
    if (substr_count($current, $last) !== 1) {
        throw new RuntimeException('Currency 声明 hunk 已被他人修改，保留现盘并上报');
    }
    // 只替换本席当前拥有的一条注释，不用旧整文件覆盖其他脏改。
    $updated = str_replace($last, $replacement, $current);
    file_put_contents($sourcePath, $updated, LOCK_EX);
    $manifest['source']['last_hunk'] = $replacement;
    $manifest['source']['touched'] = true;
    $manifest['events'][] = ['at' => gmdate(DATE_ATOM), 'source_variant' => $variant, 'before_sha256' => hash('sha256', $current), 'after_sha256' => hash('sha256', $updated)];
    fixtureWriteManifest($manifestPath, $manifest);
}

if ($action === 'cleanup') {
    $conflicts = [];
    foreach ($manifest['cells'] as $cellKey => $cell) {
        if (!$cell['touched']) {
            continue;
        }
        $current = fixturePair(fixturePolicy($manager, $manifest['declaration_id'], $cell['params']));
        if ($current === $cell['baseline']) {
            continue;
        }
        if (!in_array($current, $cell['owned_pairs'], true)) {
            $conflicts[] = $cellKey;
            continue;
        }
        fixtureData($manager->saveOverride($cell['params'] + ['declaration_id' => $manifest['declaration_id']] + $cell['baseline']));
        if (fixturePair(fixturePolicy($manager, $manifest['declaration_id'], $cell['params'])) !== $cell['baseline']) {
            throw new RuntimeException('自己的覆盖未恢复：' . $cellKey);
        }
    }
    $manifest['cleanup_at'] = gmdate(DATE_ATOM);
    $manifest['cleanup_conflicts'] = $conflicts;
    fixtureWriteManifest($manifestPath, $manifest);
    if ($conflicts !== []) {
        throw new RuntimeException('保留他人覆盖：' . implode(', ', $conflicts));
    }
}

$state = $states->read();
$rows = [];
foreach ($manifest['cells'] as $cellKey => $cell) {
    $rows[$cellKey] = fixturePolicy($manager, $manifest['declaration_id'], $cell['params']);
}
$jobs = fixtureData($manager->listSyncRecords(['page_size' => 100]));
$output = ['ok' => true, 'observed_at' => gmdate(DATE_ATOM), 'token' => $token, 'manifest_path' => $manifestPath, 'declaration_id' => $manifest['declaration_id'], 'public_path' => $manifest['public_path'], 'scopes' => $manifest['scopes'], 'rows' => $rows, 'desired_version' => $state['desired_version'], 'origin_version' => $state['origin_version'], 'snapshot_version' => $jobs['snapshot_version'], 'snapshot_revision' => $jobs['snapshot_revision'], 'jobs' => $jobs['items'], 'queues' => fixtureQueues(), 'initial_desired_version' => $manifest['initial_desired_version'], 'source_touched' => $manifest['source']['touched'], 'source_sha256' => hash_file('sha256', $sourcePath), 'source_baseline_sha256' => $manifest['source']['dirty_sha256']];
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
