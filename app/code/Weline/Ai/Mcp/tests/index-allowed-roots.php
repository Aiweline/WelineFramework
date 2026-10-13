<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectResolver;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-index-allowed-roots-' . bin2hex(random_bytes(5));
$failures = [];
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$passed) {
        $failures[] = $message;
    }
    fwrite($passed ? STDOUT : STDERR, ($passed ? '[PASS] ' : '[FAIL] ') . $message . "\n");
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};

try {
    $root = $temporary . '/project';
    $put = static function (string $path, string $body = "<?php\n") use ($root): void {
        $absolute = $root . '/' . $path;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0700, true);
        }
        file_put_contents($absolute, $body);
    };
    $put('app/code/Weline/Demo/Service/Keep.php');
    $put('app/code/Weline/Demo/view/tpl/compiled.php', "<?php /* tpl */\n");
    $put('app/code/Weline/Demo/tpl/cache.php', "<?php /* module tpl */\n");
    $put('app/code/Weline/Demo/view/statics/vendor.js', "console.log(1);\n");
    $put('app/code/Weline/Demo/view/templates/keep.phtml', "<div/>\n");
    $put('app/code/Weline/Demo/data/locale-packs/en.json', "{\"a\":1}\n");
    $put('app/code/Weline/Demo/data/keep-meta.json', "{\"ok\":true}\n");
    $put('app/code/Weline/Demo/i18n/en_US.csv', "\"a\",\"b\"\n");
    $put('app/code/Weline/Demo/view/fonts/x.txt', "font meta\n");
    $put('app/code/generated/html/live/x.html', "<html/>\n");
    $put('bin/w', "#!/usr/bin/env php\n<?php\n");
    $put('websites/demo/README.md', "# site\n");
    $put('app/design/Weline/theme/theme.xml', "<theme/>\n");
    $put('dev/session/note.md', "session\n");
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        'index' => [
            'sidecar_enabled' => false,
            'allowed_roots' => ['app/code', 'bin'],
        ],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $temporary . '/data');
    $check(
        ProjectIndexer::resolveAllowedRoots($config) === ['app/code', 'bin'],
        'default allowlist resolves to app/code + bin',
    );
    $check(
        ProjectIndexer::filterMonitoredPaths($config, [
            'app/code/Weline/Demo/Service/Keep.php',
            'app/code/Weline/Demo/view/tpl/compiled.php',
            'app/code/Weline/Demo/tpl/cache.php',
            'app/code/Weline/Demo/view/statics/vendor.js',
            'app/code/Weline/Demo/data/locale-packs/en.json',
            'app/code/Weline/Demo/i18n/en_US.csv',
            'app/code/Weline/Demo/view/fonts/x.txt',
            'app/code/generated/html/live/x.html',
            'app/code/Weline/Demo/view/templates/keep.phtml',
            'websites/demo/README.md',
            'bin/w',
        ]) === [
            'app/code/Weline/Demo/Service/Keep.php',
            'app/code/Weline/Demo/view/templates/keep.phtml',
            'bin/w',
        ],
        'post-tool filter keeps allowlisted paths and drops tpl/statics/i18n/fonts/locale-packs/generated + out-of-root',
    );

    $index = new ProjectIndex($config, ProjectResolver::resolve($root));
    $indexer = new ProjectIndexer($index, $config);
    $full = $indexer->index(['mode' => 'full']);
    $paths = $index->pdo()->query('SELECT path FROM indexed_files ORDER BY path')->fetchAll(PDO::FETCH_COLUMN);
    $check(
        $paths === [
            'app/code/Weline/Demo/Service/Keep.php',
            'app/code/Weline/Demo/data/keep-meta.json',
            'app/code/Weline/Demo/view/templates/keep.phtml',
            'bin/w',
        ],
        'full index keeps code/templates/data meta and drops tpl/statics/i18n/fonts/locale-packs',
    );
    $check(
        !in_array('websites/demo/README.md', $full['changed_paths'] ?? [], true)
            && !in_array('app/design/Weline/theme/theme.xml', $full['changed_paths'] ?? [], true),
        'non-allowlisted trees stay outside changed_paths',
    );

    $outside = $indexer->indexPaths([
        'websites/demo',
        'app/code/Weline/Demo/tpl',
        'app/code/Weline/Demo/view/statics',
        'app/code/Weline/Demo/data/locale-packs',
        'app/code/Weline/Demo/i18n',
        'app/code/Weline/Demo/view/fonts',
        'app/code/generated',
    ]);
    $check(
        ($outside['discovered'] ?? -1) === 0,
        'explicit selection of websites/tpl/statics/i18n/fonts/locale-packs/generated discovers nothing',
    );
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    unset($indexer, $index);
    gc_collect_cycles();
    $removeTree($temporary);
}

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . "\n");
exit($failures === [] ? 0 : 1);
