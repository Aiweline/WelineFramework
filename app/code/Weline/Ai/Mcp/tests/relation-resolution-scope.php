<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LearningMcp\Config;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectResolver;

$temporary = sys_get_temp_dir() . '/weline-relation-scope-' . bin2hex(random_bytes(5));
$root = $temporary . '/project';
mkdir($root . '/src', 0700, true);
file_put_contents($root . '/src/Base.php', "<?php\nclass Base {}\n");
file_put_contents($root . '/src/Consumer.php', "<?php\nclass Consumer extends Base {}\n");
file_put_contents($temporary . '/config.json', json_encode([
    'data_dir' => $temporary . '/data',
    'analysis' => ['provider' => 'none'],
    'index' => ['sidecar_enabled' => false, 'refresh_interval' => '60s'],
], JSON_THROW_ON_ERROR));

$config = Config::load($temporary . '/config.json', $temporary . '/data');
$index = new ProjectIndex($config, ProjectResolver::resolve($root, false));
$indexer = new ProjectIndexer($index, $config);
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    fwrite($ok ? STDOUT : STDERR, ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failures[] = $label;
    }
};
$relationUid = static function () use ($index): string {
    $statement = $index->pdo()->prepare(
        'SELECT r.target_symbol_uid
           FROM relations AS r
           JOIN indexed_files AS f ON f.id = r.file_id
          WHERE f.path = ? AND r.target_name = ?
          LIMIT 1'
    );
    $statement->execute(['src/Consumer.php', 'Base']);

    return (string) $statement->fetchColumn();
};

$indexer->index(['mode' => 'full']);
$check($relationUid() !== '', 'full indexing resolves the initial relation');
$index->pdo()->exec(
    "UPDATE relations SET target_symbol_uid = NULL
      WHERE relation_id = (
          SELECT r.relation_id
            FROM relations AS r
            JOIN indexed_files AS f ON f.id = r.file_id
           WHERE f.path = 'src/Consumer.php' AND r.target_name = 'Base'
           LIMIT 1
      )"
);
$check($relationUid() === '', 'fixture can represent an unresolved relation');

file_put_contents($root . '/src/Noise.php', "<?php\nclass Noise { public function run(): void {} }\n");
$unrelatedRefresh = $indexer->index(['mode' => 'incremental']);
$check(
    $relationUid() === '',
    'an unrelated incremental file does not trigger a global relation backfill',
);
$check(
    ($unrelatedRefresh['relation_resolution']['mode'] ?? '') === 'scoped'
        && (int) ($unrelatedRefresh['relation_resolution']['resolved'] ?? -1) === 0,
    'incremental index results expose a scoped zero-resolution cost record',
);

file_put_contents($root . '/src/Base.php', "<?php\nclass Base { public function changed(): bool { return true; } }\n");
$targetRefresh = $indexer->index(['mode' => 'incremental']);
$check(
    $relationUid() !== '',
    'changing the target definition re-resolves relations that name it',
);
$check(
    ($targetRefresh['relation_resolution']['mode'] ?? '') === 'scoped'
        && (int) ($targetRefresh['relation_resolution']['resolved'] ?? 0) >= 1,
    'targeted relation refresh reports the resolved relation count',
);

file_put_contents($root . '/src/Fallback.php', "<?php\nclass Base {}\n");
$indexer->index(['mode' => 'incremental']);
file_put_contents($root . '/src/Base.php', "<?php\nclass Renamed {}\n");
$renamedRefresh = $indexer->index(['mode' => 'incremental']);
$check(
    $relationUid() !== '',
    'renaming a target re-resolves its cleared relation against a remaining same-name definition',
);
$check(
    ($renamedRefresh['relation_resolution']['mode'] ?? '') === 'scoped'
        && (int) ($renamedRefresh['relation_resolution']['target_names'] ?? 0) >= 2,
    'renaming a target reports both old and new symbol names in the scoped refresh',
);

$index->close();
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};
$remove($temporary);
exit($failures === [] ? 0 : 1);
