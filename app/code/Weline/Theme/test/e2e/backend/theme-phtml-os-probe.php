<?php

declare(strict_types=1);

// 用两个真实进程与实际文件系统验证发布器和短读锁；不替换任何生产依赖。
require dirname(__DIR__, 7) . '/app/bootstrap.php';

use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBatchPublisher;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;

$fixture = json_decode(file_get_contents(BP . '/dev/tmp/theme-phtml-solidification/runtime-browser-store.json'), true, flags: JSON_THROW_ON_ERROR);
$root = BP . '/dev/tmp/theme-phtml-solidification/os-probe-' . $fixture['token'];
$identity = new ThemeVersionIdentity((int)$fixture['theme_id'], $fixture['scope'], 'normal', 'frontend', 1, 'draft', 1);
$paths = new ThemeLayoutEntityPaths($root);
$page = $paths->pageLayoutPhtml($identity, 'homepage');
$partial = $paths->chromeRoot($identity) . 'partials/header/default.phtml';
$publisher = new ThemeLayoutEntityBatchPublisher();

function source_for(ThemeVersionIdentity $identity, string $type, int $generation): string
{
    $metadata = ['identity' => $identity->toArray(), 'resource_type' => $type, 'origin' => __FILE__];
    if ($type === 'partial') {
        $metadata += ['partial_type' => 'header', 'partial_option' => 'default'];
    }
    return '<' . '?php /* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)) . ' */ ?>' . "\nGENERATION=$generation\n";
}

function marker(?array $source): int
{
    if (!preg_match('/GENERATION=(\d+)/', $source['bytes'] ?? '', $match)) {
        throw new RuntimeException('真实源快照缺少代次');
    }
    return (int)$match[1];
}

$publisher->publish($identity, [$page => source_for($identity, 'page', 0), $partial => source_for($identity, 'partial', 0)]);
$fixed = ThemeLayoutSourceSnapshot::capture($paths, $identity, 'homepage');
$child = pcntl_fork();
if ($child === -1) {
    throw new RuntimeException('无法创建真实并发进程');
}
if ($child === 0) {
    for ($generation = 1; $generation <= 200; ++$generation) {
        $publisher->publish($identity, [$page => source_for($identity, 'page', $generation), $partial => source_for($identity, 'partial', $generation)]);
        usleep(1000);
    }
    exit(0);
}
$samples = [];
$maxReadMs = 0;
for ($attempt = 0; $attempt < 400; ++$attempt) {
    $started = hrtime(true);
    $snapshot = ThemeLayoutSourceSnapshot::capture($paths, $identity, 'homepage');
    $duration = (hrtime(true) - $started) / 1_000_000;
    $maxReadMs = max($maxReadMs, $duration);
    $pageGeneration = marker($snapshot->source($page));
    $partialGeneration = marker($snapshot->source($partial));
    if ($pageGeneration !== $partialGeneration) {
        throw new RuntimeException("真实并发请求读到混合文件组：$pageGeneration/$partialGeneration");
    }
    $samples[] = $pageGeneration;
    usleep(1000);
}
pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('真实发布子进程失败');
}
if (marker($fixed->source($page)) !== 0 || marker($fixed->source($partial)) !== 0) {
    throw new RuntimeException('已固定的请求源字节被后续保存改变');
}
$before = [fileinode($page), filemtime($page), hash_file('sha256', $page)];
$publisher->publish($identity, [$page => source_for($identity, 'page', 200), $partial => source_for($identity, 'partial', 200)]);
clearstatcache();
$after = [fileinode($page), filemtime($page), hash_file('sha256', $page)];
if ($before !== $after) {
    throw new RuntimeException('未变输入重写了真实文件');
}
$oldPage = file_get_contents($page);
$oldPartial = file_get_contents($partial);
$blockedDirectory = dirname($partial);
$previousPermissions = fileperms($blockedDirectory) & 0777;
chmod($blockedDirectory, 0555);
$failure = null;
try {
    $publisher->publish($identity, [$page => source_for($identity, 'page', 201), $partial => source_for($identity, 'partial', 201)]);
} catch (Throwable $error) {
    $failure = $error->getMessage();
} finally {
    chmod($blockedDirectory, $previousPermissions);
}
if ($failure === null || file_get_contents($page) !== $oldPage || file_get_contents($partial) !== $oldPartial) {
    throw new RuntimeException('真实只读目录写入失败未保护已有页面和公共片段');
}

// 大文件组让独立进程在首项替换后收紧末项目录权限，触发真实 rename 失败。
$rollbackRoot = $root . '/rollback-files';
@mkdir($rollbackRoot . '/bulk', 0775, true);
@mkdir($rollbackRoot . '/last', 0775, true);
$firstPath = $rollbackRoot . '/first.phtml';
$lastPath = $rollbackRoot . '/last/final.phtml';
$oldCandidates = [$firstPath => 'OLD'];
for ($index = 0; $index < 1500; ++$index) {
    $oldCandidates[$rollbackRoot . '/bulk/' . $index . '.phtml'] = 'OLD';
}
$oldCandidates[$lastPath] = 'OLD';
$publisher->publish($identity, $oldCandidates);
$fault = pcntl_fork();
if ($fault === -1) {
    throw new RuntimeException('无法创建真实文件故障进程');
}
if ($fault === 0) {
    $deadline = microtime(true) + 10;
    do {
        if (file_get_contents($firstPath) === 'NEW') {
            exit(chmod(dirname($lastPath), 0555) ? 0 : 2);
        }
        usleep(100);
    } while (microtime(true) < $deadline);
    exit(3);
}
$replaceFailure = null;
try {
    $publisher->publish($identity, array_fill_keys(array_keys($oldCandidates), 'NEW'));
} catch (Throwable $error) {
    $replaceFailure = $error->getMessage();
} finally {
    chmod(dirname($lastPath), 0775);
    pcntl_waitpid($fault, $faultStatus);
}
if (!pcntl_wifexited($faultStatus) || pcntl_wexitstatus($faultStatus) !== 0 || $replaceFailure === null) {
    throw new RuntimeException('真实中途替换故障未成功注入');
}
foreach ($oldCandidates as $candidatePath => $expectedBytes) {
    if (file_get_contents($candidatePath) !== $expectedBytes) {
        throw new RuntimeException('真实中途失败未补偿已替换文件：' . $candidatePath);
    }
}
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($paths->ownerDir($identity), FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $files[] = ['path' => $file->getPathname(), 'extension' => $file->getExtension()];
        if ($file->getExtension() !== 'phtml') {
            throw new RuntimeException('真实固化目录存在非 PHTML 文件');
        }
    }
}
echo json_encode([
    'success' => true, 'scope' => $fixture['scope'], 'processes' => 2,
    'published_batches' => 200, 'read_samples' => count($samples), 'observed_generations' => count(array_unique($samples)),
    'max_read_lock_ms' => $maxReadMs, 'immutable_snapshot_generation' => 0,
    'unchanged_file_identity' => $before, 'os_failure' => $failure, 'failure_kept_old_group' => true,
    'os_replace_failure' => $replaceFailure, 'restored_after_first_promotion' => count($oldCandidates),
    'files' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
