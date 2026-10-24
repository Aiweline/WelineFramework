<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/autoload.php';

use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Deploy\StaticBuildPublisher;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$finish = static function () use (&$failures): never {
    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        echo 'T49D STATIC-BUILD FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T49D TIMING: historical manual benchmark, not measured by this test; see inventory/t49d-benchmark.md' . PHP_EOL;
    echo 'T49D REMAINING: current tree still links/copies every file; optimized: historical 12-hex generations excluded + target-directory mkdir cache.' . PHP_EOL;
    echo 'T49D STATIC-BUILD PARTIAL' . PHP_EOL;
    exit(0);
};

$root = sys_get_temp_dir() . '/weline_t49d_' . getmypid() . '_' . bin2hex(random_bytes(4));
$rmTree = static function (string $path) use (&$rmTree): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

try {
    foreach (['a', 'a/b', 'c', 'c/d'] as $dir) {
        @mkdir($root . '/' . $dir, 0775, true);
    }
    $mtime = time() - 120;
    $files = [
        'a/one.txt' => 'one',
        'a/b/two.css' => 'body{color:red}',
        'c/three.js' => 'console.log(1);',
        'c/d/four.json' => '{"ok":true}',
    ];
    for ($i = 0; $i < 20; $i++) {
        $files['c/d/bulk-' . $i . '.txt'] = 'bulk-' . $i;
    }
    foreach ($files as $relative => $content) {
        $path = $root . '/' . $relative;
        file_put_contents($path, $content);
        touch($path, $mtime);
    }
    clearstatcache();

    $id1 = StaticBuildPublisher::publish($root);
    $check(preg_match('/^[0-9a-f]{12}$/', $id1) === 1, 'publish must return a 12-hex build id');
    $build1 = $root . '/' . $id1;
    $check(is_file($build1 . '/' . StaticBuildId::BUILD_MARKER), 'build marker must exist');
    $manifest = json_decode((string) file_get_contents($root . '/' . StaticBuildId::MANIFEST), true);
    $check(is_array($manifest) && ($manifest['build_id'] ?? '') === $id1, '.build.json must point at the build id');

    foreach ($files as $relative => $content) {
        $built = $build1 . '/' . $relative;
        $check(is_file($built), 'build must contain ' . $relative);
        if (is_file($built)) {
            $check((string) file_get_contents($built) === $content, 'build content mismatch: ' . $relative);
        }
    }

    $id1Again = StaticBuildPublisher::publish($root);
    $check($id1Again === $id1, 'unchanged tree must reuse the existing build id');
    $check(is_file($build1 . '/' . StaticBuildId::BUILD_MARKER), 'second publish must keep the marker');

    // Content changes while size and mtime stay identical: the build id must
    // still move. A size/mtime-only cache is not allowed to hide this change.
    $source = $root . '/a/one.txt';
    $originalMtime = (int) filemtime($source);
    $originalContent = (string) file_get_contents($source);
    $changedContent = str_repeat('X', strlen($originalContent));
    $check($changedContent !== $originalContent, 'test must actually change the content');
    file_put_contents($source, $changedContent);
    touch($source, $originalMtime);
    clearstatcache(true, $source);
    $check((int) filesize($source) === strlen($originalContent), 'test must keep the same file size');
    $check((int) filemtime($source) === $originalMtime, 'test must keep the same file mtime');

    $id2 = StaticBuildPublisher::computeBuildId($root);
    $check($id2 !== $id1, 'content change with same size/mtime must change the build id');
    $check($id2 !== '', 'content change must still produce a build id');

    $id2Published = StaticBuildPublisher::publish($root);
    $check($id2Published === $id2, 'publish must use the content-derived build id');
    $build2 = $root . '/' . $id2;
    $check(is_file($build2 . '/' . StaticBuildId::BUILD_MARKER), 'new build marker must exist');
    $check(
        (string) file_get_contents($build2 . '/a/one.txt') === $changedContent,
        'new build must contain the changed bytes'
    );
    $check(
        (string) file_get_contents($build2 . '/a/b/two.css') === $files['a/b/two.css'],
        'new build must still contain unchanged files'
    );

    // Historical 12-hex generations with a build marker are not part of the
    // current source tree; they must not affect the build id or be linked.
    $legacyIds = ['abcdef123456', '0123456789ab'];
    foreach ($legacyIds as $legacyId) {
        @mkdir($root . '/' . $legacyId . '/legacy', 0775, true);
        file_put_contents($root . '/' . $legacyId . '/' . StaticBuildId::BUILD_MARKER, $legacyId);
        file_put_contents($root . '/' . $legacyId . '/legacy/asset.txt', 'legacy-' . $legacyId);
    }
    $check(
        StaticBuildPublisher::computeBuildId($root) === $id2,
        'historical build generations must not change the build id'
    );
    $check(
        StaticBuildPublisher::publish($root) === $id2,
        'historical build generations must not trigger a new build'
    );
    $check(
        !is_dir($build2 . '/' . $legacyIds[0])
            && !is_dir($build2 . '/' . $legacyIds[1]),
        'historical generation directories must not be linked into the build'
    );

    // Force a real new build after the historical generations exist; linkTree
    // must still skip them.
    file_put_contents($root . '/c/three.js', 'console.log(2);');
    $id3 = StaticBuildPublisher::publish($root);
    $check($id3 !== $id2, 'a real source change must produce a new build');
    foreach ($legacyIds as $legacyId) {
        $check(
            !is_dir($root . '/' . $id3 . '/' . $legacyId),
            'new build must not contain historical generation ' . $legacyId
        );
    }

    $iterateMethod = new ReflectionMethod(StaticBuildPublisher::class, 'iterateSourceTree');
    $iterateMethod->setAccessible(true);
    $iterated = [];
    foreach ($iterateMethod->invoke(null, $root) as $item) {
        if ($item->isFile()) {
            $iterated[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($root) + 1));
        }
    }
    foreach ($legacyIds as $legacyId) {
        foreach ($iterated as $relative) {
            $check(
                !str_starts_with($relative, $legacyId . '/'),
                'iterateSourceTree must not scan historical generation ' . $legacyId
            );
        }
    }

    $upgradeSource = (string) file_get_contents(
        BP . 'app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php'
    );
    $check(
        str_contains($upgradeSource, "preg_match('/^[0-9a-f]{12}$/', \$entry) === 1"),
        'normalizePermissions must recognize 12-hex build generations'
    );
    $check(
        str_contains($upgradeSource, 'StaticBuildId::BUILD_MARKER'),
        'normalizePermissions must only skip real build generations'
    );

    $publisherSource = (string) file_get_contents(
        BP . 'app/code/Weline/Framework/Deploy/StaticBuildPublisher.php'
    );
    $check(
        str_contains($publisherSource, '$directories[$targetDir]'),
        'linkTree must cache created target directories'
    );
    $check(
        !str_contains($publisherSource, 'hasEntries') && !str_contains($publisherSource, '$resume'),
        'dead staging-resume branch must not be present'
    );
} finally {
    $rmTree($root);
}

$finish();
