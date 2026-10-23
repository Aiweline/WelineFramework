<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$factory = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Database\ConnectionFactory::class);
$connector = $factory->getConnector();
$reader = new \Weline\Framework\Database\Schema\DbSchemaReader();

$tables = ['w_weline_cache_namespace_version', 'w_acl', 'w_menu'];
$single = [];
foreach ($tables as $table) {
    $schema = $reader->readTable($connector, $table);
    if ($schema !== null) {
        $single[$table] = $schema;
    }
}
$batch = $reader->readTablesBatch($connector, $tables);

$check(count($single) >= 2, 'fixture tables not found');
foreach ($single as $table => $schema) {
    $other = $batch[$table] ?? null;
    $check($other instanceof \Weline\Framework\Database\Schema\TableSchema, $table . ': batch reader lost table');
    if ($other instanceof \Weline\Framework\Database\Schema\TableSchema) {
        $check($schema->columns == $other->columns, $table . ': batch columns differ from single-read');
        $check($schema->indexes == $other->indexes, $table . ': batch indexes differ from single-read');
        $check($schema->foreignKeys == $other->foreignKeys, $table . ': batch foreign keys differ from single-read');
        $check($schema->comment === $other->comment, $table . ': batch table comment differs');
    }
}

$check(method_exists($connector, 'getTablesBatchCore'), 'PG connector must expose a batched metadata reader');

$start = microtime(true);
$output = [];
$code = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(BP . 'bin/w') . ' setup:schema:check 2>&1', $output, $code);
$elapsed = microtime(true) - $start;
$text = implode("\n", $output);
$check($code === 0 || $code === 1, 'schema:check must run (0=clean, 1=drift), got ' . $code);
$check(str_contains($text, 'Schema checkpoint check') || str_contains($text, 'Drift'), 'schema:check output missing summary');
// 时间仅作参考：并发 setup/DB 负载会显著拉长，不作为通过条件（性能基准见 setup-upgrade-perf/plan.md）。
fwrite(STDERR, sprintf("[info] schema:check elapsed %.1fs\n", $elapsed));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
