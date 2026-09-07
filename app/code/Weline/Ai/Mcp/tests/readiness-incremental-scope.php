<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LearningMcp\{Config,ProcessRunner,ProjectIndex,ProjectReadinessService,ProjectResolver};

$temporary = sys_get_temp_dir() . '/weline-readiness-scope-' . bin2hex(random_bytes(5));
$root = $temporary . '/project';
mkdir($root . '/app/code/Acme/Demo/doc', 0700, true);
foreach (['README.md', '需求.md', '开发日志.md'] as $name) {
    file_put_contents($root . '/app/code/Acme/Demo/doc/' . $name, "# Demo\n\nInitial document.\n");
}
mkdir($root . '/src', 0700, true);
$target = 'src/Target.php';
$before = "<?php\nfunction target(): string { return 'before'; }\n";
file_put_contents($root . '/' . $target, $before);
file_put_contents($temporary . '/config.json', json_encode([
    'data_dir' => $temporary . '/data',
    'analysis' => ['provider' => 'none'],
    'index' => ['sidecar_enabled' => false, 'refresh_interval' => '1s'],
], JSON_THROW_ON_ERROR));
$config = Config::load($temporary . '/config.json', $temporary . '/data');
$index = new ProjectIndex($config, ProjectResolver::resolve($root, false));
$service = new ProjectReadinessService($config, new ProcessRunner());
$prepared = $service->prepare($index, ['client_session_id' => 'scope']);
$session = ['client_session_id' => 'scope', 'readiness_id' => $prepared['readiness_id']];
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    fwrite($ok ? STDOUT : STDERR, ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) { $failures[] = $label; }
};
$fileHash = static function (string $path) use ($index): string {
    $statement = $index->pdo()->prepare('SELECT content_hash FROM indexed_files WHERE path = ?');
    $statement->execute([$path]);
    return (string) $statement->fetchColumn();
};

file_put_contents($root . '/src/Unrelated.php', "<?php\nfunction unrelated(): bool { return true; }\n");
$lastScan = $index->state()['last_started_at'];
$service->assertReady($index, $session);
$check($index->state()['last_started_at'] === $lastScan, 'an unchanged ready session does not run another project scan');
$check($fileHash('src/Unrelated.php') === '', 'an unrelated newly created file waits for periodic discovery');

$mtime = filemtime($root . '/' . $target);
$changed = str_replace('before', 'after!', $before);
file_put_contents($root . '/' . $target, $changed);
touch($root . '/' . $target, $mtime);
$service->assertReady($index, $session + ['path' => $target]);
$check($fileHash($target) === 'sha256:' . hash('sha256', $changed), 'exact targets refresh even when size and mtime are unchanged');
$check($fileHash('src/Unrelated.php') === '', 'target freshness does not discover unrelated files');

$doc = 'app/code/Acme/Demo/doc/README.md';
$changedDoc = "# Demo\n\nChanged document.\n";
file_put_contents($root . '/' . $doc, $changedDoc);
$service->assertReady($index, $session);
$check($fileHash($doc) === 'sha256:' . hash('sha256', $changedDoc), 'changed required documents are indexed immediately');

unlink($root . '/' . $target);
$service->assertReady($index, $session + ['paths' => [$target]]);
$check($fileHash($target) === '', 'deleted explicit targets leave no stale index entry');

usleep(1_100_000);
$service->assertReady($index, $session);
$check($fileHash('src/Unrelated.php') !== '', 'periodic discovery still indexes newly added files');
mkdir($root . '/outside');
file_put_contents($root . '/outside/Unrequested.php', "<?php\nfunction outside(): bool { return true; }\n");
$service->assertReady($index, $session + ['paths' => ['src']]);
$check($fileHash('src/Unrelated.php') !== '', 'directory discovery preserves indexed child files');
$check($fileHash('outside/Unrequested.php') === '', 'directory requests do not discover files outside their scope');
$index->close();
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { $remove($path . '/' . $entry); }
        }
        rmdir($path);
    } else { unlink($path); }
};
$remove($temporary);
exit($failures === [] ? 0 : 1);
